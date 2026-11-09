<?php
/**
 * bin/teste_job_noturno.php — VERIFICAÇÃO do job noturno (Fase 1) em BANCO DESCARTÁVEL
 * ─────────────────────────────────────────────────────────────────────────
 * Cobre: retry (R6), lock (R5), R1 (resposta parcial/vazia), R2/R3 (limpeza
 * por prefixo, LIKE escapado), atomicidade (rollback), fallback por sprint
 * (origem_pi), notificação (mail() ausente/falhando só loga; CR/LF), resolução
 * de PI (datas simuladas), fila/worker (R7), extract_diaria (--dry-run, códigos
 * de saída), extract_check (verificador) e a recusa de filtro de sprint.
 *
 * NUNCA toca em metricas_agil: cria o banco 'metricas_job_test' a partir de
 * deploy/sql/01_schema.sql num servidor MySQL DESCARTÁVEL e o remove ao final.
 * Recusa rodar se o servidor tiver um banco 'metricas_agil'.
 *
 * Servidor descartável (Windows, MySQL 8.4 instalado), numa pasta temporária:
 *   mysqld --no-defaults --initialize-insecure --datadir=%TEMP%\mysql_job_test
 *   mysqld --no-defaults --datadir=%TEMP%\mysql_job_test --port=3399 --bind-address=127.0.0.1 --mysqlx=OFF --skip-log-bin
 *
 * Uso:  php bin/teste_job_noturno.php [--host=127.0.0.1] [--porta=3399] [--usuario=root]
 *       (senha, se houver, em TESTE_DB_PASS). Saída 0 = tudo passou; 1 = alguma falha.
 * Não vai para produção (deploy/excluir.txt). Compatível com PHP 7.4 e 8.x.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}

require_once __DIR__ . '/../app/compat.php';

define('BASE_DIR', dirname(__DIR__));
date_default_timezone_set((require BASE_DIR . '/config/app.php')['timezone'] ?? 'America/Sao_Paulo');
spl_autoload_register(function (string $class): void {
    $file = BASE_DIR . '/' . str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';
    if (is_file($file)) require_once $file;
});

use App\Models\JiraModel;
use App\Repositories\ExtractionQueueRepository;
use App\Services\ExtractionService;
use App\Services\LogNotifier;
use App\Services\Logger;
use App\Srv\srvExtracaoDiaria;
use App\Srv\srvLock;
use App\Srv\srvNotificadorEmail;
use App\Srv\srvProcessadorFila;
use App\Srv\srvResolvedorPi;
use App\Srv\srvVerificadorExtracao;

const BANCO_TESTE = 'metricas_job_test';

// ── Infra de teste ───────────────────────────────────────────────────────────
$resultados = ['ok' => 0, 'falha' => 0];

function t(string $nome, callable $fn): void
{
    global $resultados;
    try {
        $fn();
        $resultados['ok']++;
        echo "  PASSOU  {$nome}\n";
    } catch (Throwable $e) {
        $resultados['falha']++;
        echo "  FALHOU  {$nome}\n          " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function igual($esperado, $real, string $msg = ''): void
{
    if ($esperado !== $real) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($real, true));
    }
}

function verdade($cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function deveLancar(callable $fn, int $codigo, string $msg = ''): RuntimeException
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        igual($codigo, (int)$e->getCode(), $msg !== '' ? $msg : 'código da exceção (' . $e->getMessage() . ')');
        return $e;
    }
    throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . "esperava RuntimeException code {$codigo}, nada foi lançado");
}

function rmdir_rec(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? rmdir_rec($p) : @unlink($p);
    }
    @rmdir($d);
}

/** Cliente Jira de mentira: respostas por JQL (principal x fallback). */
class ScriptedJira
{
    public $chamadas = [];
    /** @var callable function(string $jql, bool $fallback): array|throws */
    public $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function fetchAll(string $jql, int $maxPerPage = 100): array
    {
        $fb = strpos($jql, 'fixVersion is EMPTY') !== false;
        $this->chamadas[] = ['jql' => $jql, 'fallback' => $fb];
        $itens = call_user_func($this->handler, $jql, $fb);
        return ['items' => $itens, 'pages' => 1, 'logs' => ['Página 1: ' . count($itens) . ' itens']];
    }

    public function mapRawIssues(array $raw): array
    {
        return $raw;
    }
}

function item(string $chave, ?string $versoes = '[X] PI 4/26', $sp = null, string $projeto = 'Proj'): array
{
    return [
        'chave' => $chave, 'projeto' => $projeto, 'tipo_item' => 'História', 'status' => 'Aceito',
        'versoes_corrigidas' => $versoes, 'story_points' => $sp, 'sprint' => 'SP 26.4.1',
        'resumo' => 'x', 'categoria_status' => 'Done',
    ];
}

function gera(string $prefixo, int $n, ?string $versoes = '[X] PI 4/26'): array
{
    $o = [];
    for ($i = 1; $i <= $n; $i++) $o[] = item("{$prefixo}-{$i}", $versoes, 1);
    return $o;
}

/** Chaves de projeto do JQL: "project in (A, B)". */
function projetosDoJql(string $jql): array
{
    if (!preg_match('/project in \(([^)]*)\)/', $jql, $m)) return [];
    return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
}

function cfgJira(): array
{
    $c = require BASE_DIR . '/config/jira.php';
    // Credenciais fictícias: os testes nunca falam com o Jira real.
    $c['host'] = 'https://jira.invalid';
    $c['email'] = 'teste@exemplo.invalid';
    $c['token'] = 'TOKEN_SECRETO_123';
    return $c;
}

function cfgBaseline(): array
{
    return require BASE_DIR . '/config/baseline.php';
}

// ── Argumentos ───────────────────────────────────────────────────────────────
$o = getopt('', ['host::', 'porta::', 'usuario::', 'help']);
if (array_key_exists('help', $o)) {
    echo "Uso: php bin/teste_job_noturno.php [--host=127.0.0.1] [--porta=3399] [--usuario=root]  (senha em TESTE_DB_PASS)\n";
    exit(0);
}
$host = (string)($o['host'] ?? '127.0.0.1');
$porta = (int)($o['porta'] ?? 3399);
$user = (string)($o['usuario'] ?? 'root');
$pass = (string)(getenv('TESTE_DB_PASS') ?: '');

echo 'PHP ' . PHP_VERSION . " | servidor de teste {$host}:{$porta}\n";

// ── Banco descartável ────────────────────────────────────────────────────────
try {
    $adm = new PDO("mysql:host={$host};port={$porta};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Não foi possível conectar ao servidor de teste: ' . Logger::sanitize($e->getMessage()) . "\n");
    exit(2);
}
$bancos = $adm->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
if (in_array('metricas_agil', $bancos, true)) {
    fwrite(STDERR, "RECUSADO: este servidor tem o banco 'metricas_agil'. Use um servidor descartável (ver cabeçalho).\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/jobtest_' . getmypid();
@mkdir($tmp . '/logs', 0775, true);
@mkdir($tmp . '/locks', 0775, true);
Logger::setDir($tmp . '/logs');
$arqNotif = $tmp . '/notificacoes.log';

$adm->exec('DROP DATABASE IF EXISTS ' . BANCO_TESTE);
$ddl = str_replace('metricas_agil', BANCO_TESTE, (string)file_get_contents(BASE_DIR . '/deploy/sql/01_schema.sql'));
$adm->exec($ddl);

$pdo = new PDO("mysql:host={$host};port={$porta};dbname=" . BANCO_TESTE . ';charset=utf8mb4', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

try {
    // ── Sementes ─────────────────────────────────────────────────────────────
    $hoje = new DateTimeImmutable('today');
    $d = function (int $dias) use ($hoje): string {
        return $hoje->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    };
    $pis = [
        'PI 3/26' => [$d(-130), $d(-41)],   // termina na véspera da PI 4/26
        'PI 4/26' => [$d(-40), $d(50)],     // corrente
        'PI 1/27' => [$d(80), $d(170)],     // futura
    ];
    $piId = [];
    $insPi = $pdo->prepare("INSERT INTO configuracao_pi (desc_pi, qtde_sprints, data_ini, data_fim, ativo) VALUES (?, 8, ?, ?, 'S')");
    foreach ($pis as $nome => $per) {
        $insPi->execute([$nome, $per[0], $per[1]]);
        $piId[$nome] = (int)$pdo->lastInsertId();
    }
    $insSp = $pdo->prepare('INSERT INTO sprints (configuracao_pi_id, nome, tipo, ativo) VALUES (?, ?, ?, ?)');
    foreach ([['SP 26.4.1', 'valor', 'S'], ['SP 26.4.2', 'valor', 'S'], ['SP 26.4.3', 'sustentacao', 'S'], ['SP 26.4.9', 'valor', 'N']] as $s) {
        $insSp->execute([$piId['PI 4/26'], $s[0], $s[1], $s[2]]);
    }
    $pdo->exec("INSERT INTO jornada (nome, ativo) VALUES ('J1','S'),('J2','S'),('J3','N')");
    $jid = [];
    foreach ($pdo->query('SELECT id_jornada, nome FROM jornada') as $r) $jid[$r['nome']] = (int)$r['id_jornada'];
    $insT = $pdo->prepare("INSERT INTO times (jornada_id, nome, projeto_key, ativo) VALUES (?, ?, ?, ?)");
    foreach ([['J1', 'T1', 'AAA', 'S'], ['J1', 'T2', 'BBB', 'S'], ['J1', 'T-inativo', 'CCC', 'N'], ['J2', 'T3', 'A_B', 'S'], ['J3', 'T4', 'DDD', 'S']] as $x) {
        $insT->execute([$jid[$x[0]], $x[1], $x[2], $x[3]]);
    }

    $extCfg = [
        'limiar_parcial' => 0.8,
        'retry' => ['tentativas' => 4, 'espera_base_s' => 0, 'espera_max_s' => 0, 'timeout_s' => 5],
        'lock_dir' => $tmp . '/locks',
        'lock_espera_s' => 1,
        'periodo_graca_dias' => 14,
        'fallback_sprints' => true,
        'checagem_horas_max' => 25,
        'checagem_queda_pct' => 0.8,
        'estado_check' => $tmp . '/estado.json',
        'ambiente' => 'teste',
        'email_destino_padrao' => 'padrao@exemplo.invalid',
        'zumbi_horas' => 2,
        'log_notificacoes' => $arqNotif,
    ];

    $limpar = function () use ($pdo, $tmp): void {
        $pdo->exec('DELETE FROM extracao_log');
        $pdo->exec('DELETE FROM extracao_agendamento');
        $pdo->exec('DELETE FROM extracao_jira');
        foreach (glob($tmp . '/locks/*.lock') ?: [] as $f) @unlink($f);
    };
    $semear = function (string $chave, string $pi, ?string $carga = null) use ($pdo, $piId): void {
        $pdo->prepare("INSERT INTO extracao_jira (configuracao_pi_id, chave, projeto, tipo_item, status, data_carga, ativo) VALUES (?, ?, 'Proj', 'História', 'Aceito', ?, 'S')")
            ->execute([$piId[$pi], $chave, $carga ?? date('Y-m-d H:i:s')]);
    };
    $contar = function (string $where = '1=1', array $p = []) use ($pdo): int {
        $s = $pdo->prepare("SELECT COUNT(*) FROM extracao_jira WHERE {$where}");
        $s->execute($p);
        return (int)$s->fetchColumn();
    };
    $servico = function (ScriptedJira $c, array $over = []) use ($pdo, $extCfg): ExtractionService {
        return new ExtractionService($pdo, cfgJira(), cfgBaseline(), $c, $over + $extCfg);
    };
    $pi4 = $piId['PI 4/26'];

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[1] Retry com backoff no JiraModel (R6)\n";
    $issue = ['key' => 'AAA-1', 'fields' => ['issuetype' => ['name' => 'História'], 'summary' => 's', 'project' => ['name' => 'P'], 'status' => ['name' => 'Aceito']]];
    $mkModel = function (array $respostas, array &$sono, array &$tentativas) use ($extCfg): JiraModel {
        $cfg = cfgJira();
        $cfg['retry'] = ['tentativas' => 4, 'espera_base_s' => 2, 'espera_max_s' => 60, 'timeout_s' => 5];
        $fila = $respostas;
        $transporte = function (string $url, array $h, int $to) use (&$fila, &$tentativas): array {
            $tentativas[] = $url;
            $r = array_shift($fila);
            return $r ?? ['status' => 500, 'body' => '', 'erro' => null, 'retry_after' => null];
        };
        $sleeper = function (float $s) use (&$sono): void { $sono[] = $s; };
        return new JiraModel($cfg, $transporte, $sleeper);
    };
    $ok = ['status' => 200, 'body' => json_encode(['issues' => [$issue], 'isLast' => true]), 'erro' => null, 'retry_after' => null];

    t('falha 2x (timeout/5xx) e passa: 3 tentativas, esperas 2s e 4s', function () use ($mkModel, $ok) {
        $sono = []; $tent = [];
        $m = $mkModel([['status' => 0, 'body' => '', 'erro' => 'Operation timed out', 'retry_after' => null],
                       ['status' => 503, 'body' => 'x', 'erro' => null, 'retry_after' => null], $ok], $sono, $tent);
        $r = $m->fetchAll('project = X');
        igual(1, count($r['items']));
        igual(3, count($tent), 'tentativas');
        igual([2.0, 4.0], $sono, 'esperas');
    });
    foreach ([400, 401, 403] as $http) {
        t("HTTP {$http} NÃO tenta de novo", function () use ($mkModel, $http) {
            $sono = []; $tent = [];
            $m = $mkModel([['status' => $http, 'body' => 'negado', 'erro' => null, 'retry_after' => null]], $sono, $tent);
            deveLancar(function () use ($m) { $m->fetchAll('project = X'); }, 0);
            igual(1, count($tent), 'tentativas');
            igual([], $sono);
        });
    }
    t('HTTP 429 honra Retry-After (7s)', function () use ($mkModel, $ok) {
        $sono = []; $tent = [];
        $m = $mkModel([['status' => 429, 'body' => '', 'erro' => null, 'retry_after' => 7], $ok], $sono, $tent);
        igual(1, count($m->fetchAll('project = X')['items']));
        igual([7.0], $sono);
        igual(2, count($tent));
    });
    t('5xx persistente: 4 tentativas, esperas 2/4/8 e falha', function () use ($mkModel) {
        $sono = []; $tent = [];
        $f = ['status' => 502, 'body' => '', 'erro' => null, 'retry_after' => null];
        $m = $mkModel([$f, $f, $f, $f], $sono, $tent);
        $e = deveLancar(function () use ($m) { $m->fetchAll('project = X'); }, 0);
        verdade(strpos($e->getMessage(), 'HTTP 502') !== false, 'mensagem sem HTTP 502');
        igual(4, count($tent));
        igual([2.0, 4.0, 8.0], $sono);
    });
    t('JSON inválido/truncado é tentado de novo', function () use ($mkModel, $ok) {
        $sono = []; $tent = [];
        $m = $mkModel([['status' => 200, 'body' => '{"issues":[', 'erro' => null, 'retry_after' => null], $ok], $sono, $tent);
        igual(1, count($m->fetchAll('project = X')['items']));
        igual(2, count($tent));
    });
    t('resposta parcial (isLast=false sem nextPageToken) falha', function () use ($mkModel, $issue) {
        $sono = []; $tent = [];
        $m = $mkModel([['status' => 200, 'body' => json_encode(['issues' => [$issue], 'isLast' => false]), 'erro' => null, 'retry_after' => null]], $sono, $tent);
        $e = deveLancar(function () use ($m) { $m->fetchAll('project = X'); }, 0);
        verdade(stripos($e->getMessage(), 'parcial') !== false, 'mensagem sem "parcial"');
    });
    t('token e Authorization nunca aparecem nos logs', function () use ($tmp) {
        $conteudo = '';
        foreach (glob($tmp . '/logs/*.log') ?: [] as $f) $conteudo .= (string)file_get_contents($f);
        verdade($conteudo !== '', 'nenhum log gravado (esperava avisos de retry)');
        verdade(strpos($conteudo, 'TOKEN_SECRETO_123') === false, 'token no log');
        verdade(strpos($conteudo, base64_encode('teste@exemplo.invalid:TOKEN_SECRETO_123')) === false, 'Authorization no log');
    });

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[2] Lock (R5)\n";
    t('segunda instância não obtém o lock; após liberar, obtém', function () use ($tmp) {
        $a = new srvLock($tmp . '/locks/x.lock');
        $b = new srvLock($tmp . '/locks/x.lock');
        verdade($a->tentar(), 'a deveria obter');
        verdade(!$b->tentar(), 'b NÃO deveria obter');
        verdade(!$b->esperar(1), 'b não deveria obter nem esperando 1 s');
        $a->liberar();
        verdade($b->tentar(), 'b deveria obter após liberar');
        $b->liberar();
    });

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[3] ExtractionService: R1, R2/R3, atomicidade, fallback, origem_pi\n";
    t('R1: Jira devolve parcial (5 de 10) => ERR_PARCIAL e NADA é apagado', function () use ($limpar, $semear, $contar, $servico, $pi4) {
        $limpar();
        for ($i = 1; $i <= 10; $i++) $semear("AAA-{$i}", 'PI 4/26', '2026-01-01 00:00:00');
        $svc = $servico(new ScriptedJira(function () { return gera('AAA', 5); }));
        deveLancar(function () use ($svc) { $svc->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]); }, ExtractionService::ERR_PARCIAL);
        igual(10, $contar('configuracao_pi_id = ?', [$pi4]), 'linhas');
        igual(10, $contar("data_carga = '2026-01-01 00:00:00'"), 'data_carga original');
    });
    t('R1: Jira devolve vazio => ERR_PARCIAL e nada apagado', function () use ($contar, $servico, $pi4) {
        $svc = $servico(new ScriptedJira(function () { return []; }));
        deveLancar(function () use ($svc) { $svc->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]); }, ExtractionService::ERR_PARCIAL);
        igual(10, $contar('configuracao_pi_id = ?', [$pi4]));
    });
    t('R1: 9 de 10 (90% >= 80%) passa', function () use ($contar, $servico, $pi4) {
        $svc = $servico(new ScriptedJira(function () { return gera('AAA', 9); }));
        $r = $svc->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]);
        igual(9, $r['total_itens']);
        igual(10, $r['registros_apagados']);
        igual(10, $r['existentes_antes']);
        igual(9, $contar('configuracao_pi_id = ?', [$pi4]));
    });
    t('R1: --forcar aceita 3 de 9', function () use ($contar, $servico, $pi4) {
        $svc = $servico(new ScriptedJira(function () { return gera('AAA', 3); }));
        $r = $svc->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false, 'forcar' => true]);
        igual(3, $r['total_itens']);
        igual(3, $contar('configuracao_pi_id = ?', [$pi4]));
    });
    t('R2/R3: projeto que volta com 0 itens é limpo; projeto fora do lote, prefixo parecido e outra PI NÃO são tocados', function () use ($limpar, $semear, $contar, $servico, $pi4, $piId) {
        $limpar();
        for ($i = 1; $i <= 10; $i++) $semear("AAA-{$i}", 'PI 4/26');
        for ($i = 1; $i <= 4; $i++) $semear("BBB-{$i}", 'PI 4/26');
        for ($i = 1; $i <= 7; $i++) $semear("CCC-{$i}", 'PI 4/26');      // fora do lote
        $semear('AAAB-1', 'PI 4/26');                                    // prefixo parecido (AAAB != AAA)
        $semear('AAA-900', 'PI 3/26');                                   // mesma chave-prefixo, OUTRA PI
        // Lote [AAA,BBB]: só AAA volta (12 itens); BBB volta com 0 e deve ser limpo.
        $svc = $servico(new ScriptedJira(function () { return gera('AAA', 12); }));
        $r = $svc->extract(['AAA', 'BBB'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]);
        igual(14, $r['registros_apagados'], 'apagados (10 AAA + 4 BBB)');
        igual(12, $contar("configuracao_pi_id = ? AND chave LIKE 'AAA-%'", [$pi4]), 'AAA');
        igual(0, $contar("configuracao_pi_id = ? AND chave LIKE 'BBB-%'", [$pi4]), 'BBB limpo');
        igual(7, $contar("configuracao_pi_id = ? AND chave LIKE 'CCC-%'", [$pi4]), 'CCC intocado');
        igual(1, $contar("chave = 'AAAB-1'"), 'AAAB intocado');
        igual(1, $contar('chave = ? AND configuracao_pi_id = ?', ['AAA-900', $piId['PI 3/26']]), 'outra PI intocada');
    });
    t('LIKE seguro: projeto A_B não apaga AXB-1 (underscore literal); chave com % é recusada', function () use ($limpar, $semear, $contar, $servico, $pi4) {
        $limpar();
        $semear('A_B-1', 'PI 4/26');
        $semear('A_B-2', 'PI 4/26');
        $semear('AXB-1', 'PI 4/26');
        $svc = $servico(new ScriptedJira(function () { return gera('A_B', 2); }));
        $svc->extract(['A_B'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]);
        igual(1, $contar("chave = 'AXB-1'"), 'AXB-1 deve sobreviver');
        igual(2, $contar("chave LIKE 'A\\_B-%'"), 'A_B regravado');
        deveLancar(function () use ($svc) { $svc->extract(['A%'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]); }, ExtractionService::ERR_ARGS);
        deveLancar(function () use ($svc) { $svc->extract(["AAA' OR 1=1 --"], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]); }, ExtractionService::ERR_ARGS);
        igual(3, $contar(), 'nada apagado pelas recusas');
    });
    t('filtro de sprint é recusado (ERR_SPRINT_FILTRO) sem tocar no banco nem no Jira', function () use ($limpar, $semear, $contar, $servico) {
        $limpar();
        $semear('AAA-1', 'PI 4/26');
        $c = new ScriptedJira(function () { return gera('AAA', 1); });
        $svc = $servico($c);
        deveLancar(function () use ($svc) { $svc->extract(['AAA'], 'PI 4/26', ['SP 26.4.1'], 'jira'); }, ExtractionService::ERR_SPRINT_FILTRO);
        igual(0, count($c->chamadas), 'chamadas ao Jira');
        igual(1, $contar());
    });
    t('fallback: une sem duplicar, marca origem_pi, descarta dup/PI já presente/fora do lote', function () use ($limpar, $contar, $servico, $pi4) {
        $limpar();
        $c = new ScriptedJira(function (string $jql, bool $fb) {
            if (!$fb) return [item('AAA-1', '[AAA] PI 4/26', 3), item('AAA-2', '[AAA] PI 4/26', 5), item('AAA-1', '[AAA] PI 4/26', 3)];
            return [
                item('AAA-2', '[AAA] PI 3/26', 5),          // já veio na principal
                item('AAA-3', '[AAA] PI 3/26', 8),          // carry-over de PI anterior
                item('AAA-4', null, 2),                     // fixVersion vazio
                item('AAA-5', '[AAA] PI 3/26; [AAA] PI 4/26', 1), // já tem a PI (dup)
                item('ZZZ-1', null, 1),                     // fora do lote
            ];
        });
        $svc = $servico($c);
        $r = $svc->extract(['AAA'], 'PI 4/26', null, 'jira');
        igual(4, $r['total_itens'], 'total');
        igual(2, $r['itens_principal'], 'principal');
        igual(2, $r['itens_fallback'], 'fallback');
        igual(3, $r['fallback_dup'], 'dup (1 repetida na principal + AAA-2 + AAA-5)');
        igual(1, $r['fora_do_lote'], 'fora do lote');
        igual(2, $contar("origem_pi = 'fixversion' AND configuracao_pi_id = ?", [$pi4]));
        igual(2, $contar("origem_pi = 'sprint' AND configuracao_pi_id = ?", [$pi4]));
        igual(1, $contar("chave = 'AAA-3' AND origem_pi = 'sprint'"));
        igual(['itens' => 2, 'sp' => 10.0], $r['fallback_por_projeto']['AAA']);
        igual(1, $r['fallback_versoes']['[AAA] PI 3/26'] ?? null, 'versão de origem PI 3/26');
        igual(1, $r['fallback_versoes']['(vazio)'] ?? null, 'versão de origem vazia');
        igual(2, count($c->chamadas), 'principal + fallback');
        verdade(strpos($r['jql_fallback'], 'Sprint in ("SP 26.4.1", "SP 26.4.2", "SP 26.4.3")') !== false, 'JQL do fallback: ' . $r['jql_fallback']);
        verdade(strpos($r['jql_fallback'], 'SP 26.4.9') === false, 'sprint inativa não deve entrar');
    });
    t('fallback desligado (opção) e PI sem sprints: só 1 chamada ao Jira', function () use ($limpar, $servico) {
        $limpar();
        $c = new ScriptedJira(function () { return gera('AAA', 2); });
        $servico($c)->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]);
        igual(1, count($c->chamadas), 'fallback=false');
        $c2 = new ScriptedJira(function () { return gera('AAA', 2, '[AAA] PI 3/26'); });
        $r = $servico($c2)->extract(['AAA'], 'PI 3/26', null, 'jira');
        igual(1, count($c2->chamadas), 'PI sem sprints');
        igual(0, $r['itens_fallback']);
    });
    t('falha do FALLBACK => ERR_FETCH e nada é gravado/apagado', function () use ($limpar, $semear, $contar, $servico) {
        $limpar();
        for ($i = 1; $i <= 5; $i++) $semear("AAA-{$i}", 'PI 4/26', '2026-01-01 00:00:00');
        $c = new ScriptedJira(function (string $jql, bool $fb) {
            if ($fb) throw new RuntimeException('Jira API HTTP 500 (após 4 tentativa(s))');
            return gera('AAA', 6);
        });
        deveLancar(function () use ($servico, $c) { $servico($c)->extract(['AAA'], 'PI 4/26', null, 'jira'); }, ExtractionService::ERR_FETCH);
        igual(5, $contar("data_carga = '2026-01-01 00:00:00'"));
    });
    t('falha da busca PRINCIPAL => ERR_FETCH e lote anterior preservado', function () use ($contar, $servico) {
        $c = new ScriptedJira(function () { throw new RuntimeException('cURL: Could not resolve host'); });
        deveLancar(function () use ($servico, $c) { $servico($c)->extract(['AAA'], 'PI 4/26', null, 'jira'); }, ExtractionService::ERR_FETCH);
        igual(5, $contar("data_carga = '2026-01-01 00:00:00'"));
    });
    t('atomicidade: erro no INSERT (chave > 50) => ERR_PERSIST, rollback preserva o lote', function () use ($limpar, $semear, $contar, $servico) {
        $limpar();
        for ($i = 101; $i <= 105; $i++) $semear("AAA-{$i}", 'PI 4/26', '2026-01-01 00:00:00');
        $svc = $servico(new ScriptedJira(function () {
            $l = gera('AAA', 5);
            $l[] = item('AAA-' . str_repeat('9', 60), '[X] PI 4/26');
            return $l;
        }));
        deveLancar(function () use ($svc) { $svc->extract(['AAA'], 'PI 4/26', null, 'jira', false, null, ['fallback' => false]); }, ExtractionService::ERR_PERSIST);
        igual(5, $contar("data_carga = '2026-01-01 00:00:00' AND chave LIKE 'AAA-10_'"), 'lote anterior intacto');
        igual(5, $contar(), 'nenhuma linha nova');
    });
    t('lock por PI: extração concorrente da mesma PI => ERR_LOCK (sem tocar no banco)', function () use ($contar, $servico, $pi4, $tmp) {
        $lock = new srvLock($tmp . '/locks/extract_pi_' . $pi4 . '.lock');
        verdade($lock->tentar(), 'lock de teste');
        $c = new ScriptedJira(function () { return gera('AAA', 5); });
        deveLancar(function () use ($servico, $c) { $servico($c)->extract(['AAA'], 'PI 4/26', null, 'jira'); }, ExtractionService::ERR_LOCK);
        igual(0, count($c->chamadas), 'sem chamadas ao Jira');
        $lock->liberar();
    });

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[4] Notificação (mail() ausente/falhando só loga)\n";
    $capt = [];
    $mkNot = function (callable $mailer) use ($arqNotif): srvNotificadorEmail {
        return new srvNotificadorEmail($arqNotif, 'teste', $mailer);
    };
    t('mailer lança exceção: não propaga, log=true, email=false', function () use ($mkNot, $arqNotif) {
        putenv('JOB_NOTIFY_TO=dest@exemplo.invalid');
        putenv('JOB_NOTIFY_FROM=job@exemplo.invalid');
        $r = $mkNot(function () { throw new RuntimeException('sendmail: not found'); })->notificarFalha('PI 4/26', ['codigo_saida' => 4, 'erros' => ['x']]);
        igual(['log' => true, 'email' => false], $r);
        verdade(strpos((string)file_get_contents($arqNotif), 'Extracao PI 4/26: FALHA') !== false, 'assunto no log');
    });
    t('mailer retorna false (Windows/sem MTA): email=false, log=true', function () use ($mkNot) {
        $r = $mkNot(function () { return false; })->notificarFalha('PI 4/26', ['codigo_saida' => 1]);
        igual(['log' => true, 'email' => false], $r);
    });
    t('mailer ok: e-mail entregue ao MTA', function () use ($mkNot) {
        $r = $mkNot(function () { return true; })->notificarFalha('PI 4/26', ['codigo_saida' => 1]);
        igual(['log' => true, 'email' => true], $r);
    });
    t('JOB_NOTIFY_TO vazio: não chama o mailer, só log', function () use ($mkNot) {
        putenv('JOB_NOTIFY_TO=');
        $chamou = false;
        $r = $mkNot(function () use (&$chamou) { $chamou = true; return true; })->notificarFalha('PI 4/26', ['codigo_saida' => 1]);
        igual(false, $chamou, 'mailer chamado');
        igual(['log' => true, 'email' => false], $r);
    });
    t('CR/LF removidos de assunto, remetente e destinatários (sem injeção de cabeçalho)', function () use ($mkNot) {
        putenv("JOB_NOTIFY_TO=ok@exemplo.invalid,\r\nBcc: vitima@exemplo.invalid");
        putenv("JOB_NOTIFY_FROM=job@exemplo.invalid\r\nBcc: outro@exemplo.invalid");
        $cap = null;
        $mkNot(function ($to, $assunto, $corpo, $cab) use (&$cap) { $cap = compact('to', 'assunto', 'corpo', 'cab'); return true; })
            ->notificarFalha("PI 4/26\r\nBcc: x@y.invalid", ['codigo_saida' => 1, 'erros' => ["linha1\r\nBcc: z@z.invalid"]]);
        verdade($cap !== null, 'mailer não chamado');
        foreach (['to', 'assunto'] as $k) {
            verdade(strpos($cap[$k], "\n") === false && strpos($cap[$k], "\r") === false, "CR/LF em {$k}");
        }
        verdade(stripos($cap['to'], 'vitima') === false, 'destinatário injetado: ' . $cap['to']);
        foreach (explode("\r\n", $cap['cab']) as $linhaCab) {
            verdade(stripos($linhaCab, 'Bcc') !== 0, 'cabeçalho Bcc injetado: ' . $linhaCab);
            verdade(strpos($linhaCab, "\n") === false && strpos($linhaCab, "\r") === false, 'quebra dentro de cabeçalho');
        }
        verdade(stripos($cap['cab'], 'outro@exemplo') === false, 'remetente injetado');
    });
    t('segredos mascarados no corpo (token=..., Authorization)', function () use ($mkNot) {
        putenv('JOB_NOTIFY_TO=dest@exemplo.invalid');
        putenv('JOB_NOTIFY_FROM=job@exemplo.invalid');
        $cap = null;
        $mkNot(function ($to, $a, $corpo, $cab) use (&$cap) { $cap = $corpo; return true; })
            ->notificarFalha('PI 4/26', ['erros' => ['falha token=ABC123SEGREDO e Authorization: Basic QUJDREVGR0hJSktMTU4=']]);
        verdade(strpos((string)$cap, 'ABC123SEGREDO') === false, 'token vazou');
        verdade(strpos((string)$cap, 'QUJDREVGR0hJSktMTU4') === false, 'Authorization vazou');
    });
    t('contrato Notifier::notify continua funcionando (modal/worker)', function () use ($mkNot) {
        putenv('JOB_NOTIFY_FROM=job@exemplo.invalid');
        igual(true, $mkNot(function () { return false; })->notify('x@exemplo.invalid', 'Assunto', 'Corpo'));
    });
    putenv('JOB_NOTIFY_TO=dest@exemplo.invalid');
    putenv('JOB_NOTIFY_FROM=job@exemplo.invalid');

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[5] Resolução de PI (datas simuladas)\n";
    $res = new srvResolvedorPi($pdo, 14);
    t('dentro da PI corrente: só ela', function () use ($res, $d) {
        $r = $res->resolver($d(0));
        igual('PI 4/26', $r['corrente']);
        igual(['PI 4/26'], $r['pis']);
    });
    t('virada: 5 dias após a PI anterior => corrente + anterior (graça 14 d)', function () use ($res, $d) {
        $r = $res->resolver($d(-35));   // PI 4/26 começou em -40; PI 3/26 terminou em -41
        igual('PI 4/26', $r['corrente']);
        igual(['PI 4/26', 'PI 3/26'], $r['pis']);
    });
    t('20 dias após a PI anterior => só a corrente', function () use ($res, $d) {
        igual(['PI 4/26'], $res->resolver($d(-21))['pis']);
    });
    t('entre PIs dentro da graça => usa a última', function () use ($res, $d) {
        $r = $res->resolver($d(55));
        igual('PI 4/26', $r['corrente']);
        verdade(strpos($r['motivo'], 'entre PIs') !== false, 'motivo: ' . $r['motivo']);
    });
    t('entre PIs além da graça => RuntimeException code 2', function () use ($res, $d) {
        deveLancar(function () use ($res, $d) { $res->resolver($d(70)); }, 2);
    });
    t('antes de qualquer PI iniciada => code 2', function () use ($res, $d) {
        deveLancar(function () use ($res, $d) { $res->resolver($d(-200)); }, 2);
    });
    t('depois da graça da PI 4/26, dentro da PI 1/27 => só PI 1/27', function () use ($res, $d) {
        $r = $res->resolver($d(90));
        igual('PI 1/27', $r['corrente']);
        igual(['PI 1/27'], $r['pis']);
    });
    t('--pi forçada: válida passa, inexistente/inativa => code 2', function () use ($res, $d, $pdo) {
        igual(['PI 3/26'], $res->resolver($d(0), 'PI 3/26')['pis']);
        deveLancar(function () use ($res, $d) { $res->resolver($d(0), 'PI 9/99'); }, 2);
        $pdo->exec("UPDATE configuracao_pi SET ativo = 'N' WHERE desc_pi = 'PI 3/26'");
        deveLancar(function () use ($res, $d) { $res->resolver($d(0), 'PI 3/26'); }, 2);
        $pdo->exec("UPDATE configuracao_pi SET ativo = 'S' WHERE desc_pi = 'PI 3/26'");
    });

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n[6] Fila/worker (R7) e job noturno (extract_diaria)\n";
    $fila = new ExtractionQueueRepository($pdo);
    $notFila = new LogNotifier($arqNotif);
    $novaOrdem = function (array $lotes, array $extra = []) use ($fila): int {
        $o = ['criterios' => [], 'lotes' => $lotes, 'pis' => [['pi' => 'PI 4/26', 'sprints' => []]], 'email' => ''] + $extra;
        return $fila->criar(json_encode($o), sha1(uniqid('', true)), null, 0);
    };
    $statusOrdem = function (int $id) use ($pdo): string {
        $s = $pdo->prepare('SELECT status FROM extracao_agendamento WHERE id_agendamento = ?');
        $s->execute([$id]);
        return (string)$s->fetchColumn();
    };
    $linhas = [];
    $out = function (string $m) use (&$linhas): void { $linhas[] = $m; };

    t('worker: todos os lotes ok => exit 0, ordem concluida, log sucesso', function () use ($limpar, $servico, $fila, $notFila, $extCfg, $out, $novaOrdem, $statusOrdem, $contar) {
        $limpar();
        $c = new ScriptedJira(function ($jql) { $o = []; foreach (projetosDoJql($jql) as $p) $o = array_merge($o, gera($p, 3)); return $o; });
        $proc = new srvProcessadorFila($fila, $servico($c), $notFila, $extCfg, $out, BASE_DIR);
        $id = $novaOrdem([['jornada' => 'J1', 'projetos' => ['AAA', 'BBB']], ['jornada' => 'J2', 'projetos' => ['A_B']]]);
        $r = $proc->processar([$id], 'jira');
        igual(0, $r['exit']);
        igual('concluido', $statusOrdem($id));
        igual(9, $contar(), 'itens gravados');
    });
    t('worker R7: 1 lote falha e 1 ok => ordem concluida MAS exit 1', function () use ($limpar, $servico, $fila, $notFila, $extCfg, $out, $novaOrdem, $statusOrdem, $pdo) {
        $limpar();
        $c = new ScriptedJira(function ($jql) {
            if (in_array('A_B', projetosDoJql($jql), true)) throw new RuntimeException('Jira API HTTP 500 (após 4 tentativa(s))');
            return gera('AAA', 2);
        });
        $proc = new srvProcessadorFila($fila, $servico($c), $notFila, $extCfg, $out, BASE_DIR);
        $id = $novaOrdem([['jornada' => 'J1', 'projetos' => ['AAA']], ['jornada' => 'J2', 'projetos' => ['A_B']]]);
        $r = $proc->processar([$id], 'jira');
        igual(1, $r['exit']);
        igual('concluido', $statusOrdem($id));
        igual(1, count($r['ordens'][0]['falhas']));
        $m = $pdo->query('SELECT mensagem FROM extracao_log ORDER BY id_log DESC LIMIT 1')->fetchColumn();
        verdade(strpos((string)$m, 'FALHAS:') !== false, 'log sem FALHAS:');
    });
    t('worker R7: todos falham => ordem erro e exit 1', function () use ($limpar, $servico, $fila, $notFila, $extCfg, $out, $novaOrdem, $statusOrdem) {
        $limpar();
        $c = new ScriptedJira(function () { throw new RuntimeException('cURL: timeout'); });
        $proc = new srvProcessadorFila($fila, $servico($c), $notFila, $extCfg, $out, BASE_DIR);
        $id = $novaOrdem([['jornada' => 'J1', 'projetos' => ['AAA']]]);
        $r = $proc->processar([$id], 'jira');
        igual(1, $r['exit']);
        igual('erro', $statusOrdem($id));
    });
    t('worker: ordem do modal COM sprints agora falha com mensagem clara (nada apagado)', function () use ($limpar, $semear, $contar, $servico, $fila, $notFila, $extCfg, $out, $statusOrdem) {
        $limpar();
        $semear('AAA-1', 'PI 4/26');
        $c = new ScriptedJira(function () { return gera('AAA', 3); });
        $proc = new srvProcessadorFila($fila, $servico($c), $notFila, $extCfg, $out, BASE_DIR);
        $id = $fila->criar(json_encode(['lotes' => [['jornada' => 'J1', 'projetos' => ['AAA']]], 'pis' => [['pi' => 'PI 4/26', 'sprints' => ['SP 26.4.1']]]]), sha1('modal'), null, 0);
        $r = $proc->processar([$id], 'jira');
        igual(1, $r['exit']);
        verdade(strpos($r['ordens'][0]['falhas'][0], 'Filtro de sprint') !== false, 'mensagem: ' . $r['ordens'][0]['falhas'][0]);
        igual(1, $contar(), 'banco intacto');
    });
    t('claim atômico: ordem já reivindicada é ignorada (perdidas=1, exit 0)', function () use ($limpar, $servico, $fila, $notFila, $extCfg, $out, $novaOrdem) {
        $limpar();
        $c = new ScriptedJira(function () { return gera('AAA', 1); });
        $proc = new srvProcessadorFila($fila, $servico($c), $notFila, $extCfg, $out, BASE_DIR);
        $id = $novaOrdem([['jornada' => 'J1', 'projetos' => ['AAA']]]);
        verdade($fila->reivindicar($id), 'claim do teste');
        $r = $proc->processar([$id], 'jira');
        igual(1, $r['perdidas']);
        igual([], $r['ordens']);
    });

    // extract_diaria --------------------------------------------------------
    $contarTudo = function () use ($pdo): array {
        return [
            'jira' => (int)$pdo->query('SELECT COUNT(*) FROM extracao_jira')->fetchColumn(),
            'ordens' => (int)$pdo->query('SELECT COUNT(*) FROM extracao_agendamento')->fetchColumn(),
            'logs' => (int)$pdo->query('SELECT COUNT(*) FROM extracao_log')->fetchColumn(),
        ];
    };
    $diaria = function (ScriptedJira $c, array &$linhas, array $over = [], ?array &$mails = null) use ($pdo, $extCfg, $arqNotif, $notFila): srvExtracaoDiaria {
        $mailer = function ($to, $assunto, $corpo, $cab) use (&$mails) { $mails[] = ['to' => $to, 'assunto' => $assunto, 'corpo' => $corpo]; return true; };
        $cfg = $over + $extCfg;
        return new srvExtracaoDiaria(
            $pdo, $cfg, BASE_DIR,
            new ExtractionService($pdo, cfgJira(), cfgBaseline(), $c, $cfg),
            $notFila,
            new srvNotificadorEmail($arqNotif, 'teste', $mailer),
            function (string $m) use (&$linhas): void { $linhas[] = $m; }
        );
    };
    $jiraPadrao = function () {
        return new ScriptedJira(function ($jql, $fb) {
            if ($fb) return [];
            $o = [];
            foreach (projetosDoJql($jql) as $p) $o = array_merge($o, gera($p, 4, '[' . $p . '] PI 4/26'));
            return $o;
        });
    };

    t('--dry-run: exit 0, Jira com 0 chamadas, banco SEM nenhuma alteração, imprime JQLs', function () use ($limpar, $semear, $contarTudo, $diaria, $jiraPadrao) {
        $limpar();
        $semear('AAA-1', 'PI 4/26');
        $antes = $contarTudo();
        $c = $jiraPadrao();
        $l = [];
        $cod = $diaria($c, $l)->executar(['dry_run' => true]);
        igual(0, $cod);
        igual(0, count($c->chamadas), 'chamadas ao Jira');
        igual($antes, $contarTudo(), 'contagens do banco');
        $txt = implode("\n", $l);
        verdade(strpos($txt, 'JQL principal [PI 4/26]') !== false, 'sem JQL principal');
        verdade(strpos($txt, 'JQL fallback') !== false && strpos($txt, 'Sprint in (') !== false, 'sem JQL do fallback');
        verdade(strpos($txt, 'Jornada J1: AAA,BBB') !== false && strpos($txt, 'Jornada J2: A_B') !== false, 'lotes: ' . $txt);
        verdade(strpos($txt, 'CCC') === false && strpos($txt, 'DDD') === false, 'times/jornadas inativos fora do lote');
    });
    t('execução normal: exit 0, ordem concluida na fila, itens gravados, linha RESUMO', function () use ($limpar, $contarTudo, $diaria, $jiraPadrao, $pdo, $contar) {
        $limpar();
        $c = $jiraPadrao();
        $l = [];
        $cod = $diaria($c, $l)->executar([]);
        igual(0, $cod, implode(' | ', $l));
        igual(12, $contar(), '3 projetos x 4 itens');
        $o = $pdo->query('SELECT status, parametros_json FROM extracao_agendamento')->fetchAll();
        igual(1, count($o));
        igual('concluido', $o[0]['status']);
        verdade(strpos($o[0]['parametros_json'], '"origem":"extract_diaria"') !== false, 'marcador origem');
        $r = array_values(array_filter($l, function ($x) { return strpos($x, 'RESUMO') !== false; }));
        igual(1, count($r), 'linha RESUMO');
        verdade(strpos($r[0], 'jornadas_ok=2') !== false && strpos($r[0], 'exit=0') !== false, $r[0]);
        igual(12, $contar("origem_pi = 'fixversion'"), 'origem_pi gravada');
    });
    t('segunda execução no mesmo dia: "já executada", exit 0, sem nova ordem; --refazer cria outra', function () use ($contarTudo, $diaria, $jiraPadrao) {
        $antes = $contarTudo();
        $l = [];
        $c = $jiraPadrao();
        igual(0, $diaria($c, $l)->executar([]));
        igual($antes, $contarTudo());
        igual(0, count($c->chamadas), 'sem chamadas ao Jira');
        verdade(strpos(implode("\n", $l), 'já executada') !== false, 'mensagem');
        $l2 = [];
        igual(0, $diaria($jiraPadrao(), $l2)->executar(['refazer' => true]), implode(' | ', $l2));
        $dep = $contarTudo();
        igual($antes['ordens'] + 1, $dep['ordens'], 'nova ordem');
    });
    t('lock ocupado: exit 3, sem ordem, sem chamadas ao Jira, sem notificação', function () use ($limpar, $contarTudo, $diaria, $jiraPadrao, $tmp) {
        $limpar();
        $lock = new srvLock($tmp . '/locks/extract_diaria.lock');
        verdade($lock->tentar(), 'lock do teste');
        $c = $jiraPadrao();
        $l = [];
        $mails = [];
        igual(3, $diaria($c, $l, [], $mails)->executar([]));
        igual(['jira' => 0, 'ordens' => 0, 'logs' => 0], $contarTudo());
        igual(0, count($c->chamadas));
        igual([], (array)$mails, 'e-mails');
        $lock->liberar();
        $l2 = [];
        igual(0, $diaria($jiraPadrao(), $l2)->executar([]), 'depois de liberar roda: ' . implode(' | ', $l2));
    });
    t('falha parcial (jornada J2 cai): exit 1, ordem concluida com FALHAS, e-mail de FALHA enviado, J1 gravada', function () use ($limpar, $contar, $diaria, $pdo) {
        $limpar();
        $c = new ScriptedJira(function ($jql, $fb) {
            if (in_array('A_B', projetosDoJql($jql), true)) throw new RuntimeException('Jira API HTTP 500 (após 4 tentativa(s))');
            return $fb ? [] : array_merge(gera('AAA', 4), gera('BBB', 4));
        });
        $l = [];
        $mails = [];
        igual(1, $diaria($c, $l, [], $mails)->executar([]), implode(' | ', $l));
        igual(8, $contar());
        igual(1, count($mails), 'e-mails');
        verdade(strpos($mails[0]['assunto'], 'FALHA') !== false, 'assunto');
        verdade(strpos($mails[0]['corpo'], 'J2') !== false, 'corpo cita a jornada');
        verdade(strpos((string)$pdo->query('SELECT mensagem FROM extracao_log ORDER BY id_log DESC LIMIT 1')->fetchColumn(), 'FALHAS:') !== false, 'log FALHAS:');
    });
    t('Jira indisponível (tudo falha): exit 4, ordem erro, LOTE ANTERIOR PRESERVADO, e-mail enviado', function () use ($limpar, $semear, $contar, $diaria, $pdo) {
        $limpar();
        for ($i = 1; $i <= 6; $i++) $semear("AAA-{$i}", 'PI 4/26', '2026-01-01 00:00:00');
        $c = new ScriptedJira(function () { throw new RuntimeException('cURL: Could not resolve host: jira.invalid'); });
        $l = [];
        $mails = [];
        igual(4, $diaria($c, $l, [], $mails)->executar([]), implode(' | ', $l));
        igual(6, $contar("data_carga = '2026-01-01 00:00:00'"), 'lote preservado');
        igual('erro', (string)$pdo->query('SELECT status FROM extracao_agendamento ORDER BY id_agendamento DESC LIMIT 1')->fetchColumn());
        igual(1, count($mails));
    });
    t('R1 no job: Jira devolve parcial => jornada falha (exit 4 se todas), nada apagado; --forcar passa', function () use ($limpar, $semear, $contar, $diaria) {
        $limpar();
        for ($i = 1; $i <= 10; $i++) $semear("AAA-{$i}", 'PI 4/26', '2026-01-01 00:00:00');
        $mk = function () { return new ScriptedJira(function ($jql, $fb) { return $fb ? [] : gera('AAA', 2); }); };
        $l = [];
        $mails = [];
        $cod = $diaria($mk(), $l, [], $mails)->executar([]);
        verdade(in_array($cod, [1, 4], true), "exit {$cod}");
        igual(10, $contar("data_carga = '2026-01-01 00:00:00' AND chave LIKE 'AAA-%'"), 'AAA preservado');
        $l2 = [];
        $cod2 = $diaria($mk(), $l2)->executar(['forcar' => true, 'refazer' => true]);
        igual(2, $contar("chave LIKE 'AAA-%'"), 'com --forcar regrava (exit ' . $cod2 . ')');
    });
    t('PI inválida (hoje fora de qualquer PI e além da graça): exit 2 e notificação', function () use ($limpar, $contarTudo, $diaria, $jiraPadrao, $d) {
        $limpar();
        $c = $jiraPadrao();
        $l = [];
        $mails = [];
        igual(2, $diaria($c, $l, [], $mails)->executar(['hoje' => $d(70)]));
        igual(0, count($c->chamadas));
        igual(1, count($mails));
        igual(0, $contarTudo()['ordens']);
    });
    t('--pi inexistente: exit 2', function () use ($diaria, $jiraPadrao) {
        $l = [];
        igual(2, $diaria($jiraPadrao(), $l)->executar(['pi' => 'PI 9/99']));
    });
    t('--sem-fallback: fallback não é consultado', function () use ($limpar, $diaria) {
        $limpar();
        $c = new ScriptedJira(function ($jql, $fb) { return $fb ? [] : gera('AAA', 2); });
        $l = [];
        igual(0, $diaria($c, $l)->executar(['sem_fallback' => true]), implode(' | ', $l));
        foreach ($c->chamadas as $ch) verdade(!$ch['fallback'], 'chamou o fallback');
    });
    t('CLI: opção inválida => exit 2; --help => exit 0 com os códigos documentados (sem tocar no banco)', function () {
        foreach ([['--xyz', 2], ['--help', 0]] as $caso) {
            $p = proc_open([PHP_BINARY, BASE_DIR . '/bin/extract_diaria.php', $caso[0]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $so = stream_get_contents($pipes[1]);
            $se = stream_get_contents($pipes[2]);
            $cod = proc_close($p);
            igual($caso[1], $cod, "exit de {$caso[0]}");
            if ($caso[0] === '--help') {
                verdade(strpos($so, 'Códigos de saída') !== false && strpos($so, '3  já em execução') !== false, '--help sem códigos');
            }
        }
        foreach ([['--xyz', 2], ['--help', 0]] as $caso) {
            $p = proc_open([PHP_BINARY, BASE_DIR . '/bin/extract_check.php', $caso[0]], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            igual($caso[1], proc_close($p), "check exit de {$caso[0]}");
        }
    });

    // extract_check ---------------------------------------------------------
    echo "\n[7] Verificador (extract_check)\n";
    $estado = $tmp . '/estado.json';
    $verif = function () use ($pdo, $extCfg, $estado): srvVerificadorExtracao {
        return new srvVerificadorExtracao($pdo, $extCfg, $estado);
    };
    $rodarJob = function () use ($limpar, $diaria, $jiraPadrao): void {
        $limpar();
        $l = [];
        $cod = $diaria($jiraPadrao(), $l)->executar([]);
        if ($cod !== 0) throw new RuntimeException('job de preparo falhou: ' . implode(' | ', $l));
    };
    t('tudo ok após o job: ok=true, grava estado (dry-run não grava)', function () use ($rodarJob, $verif, $estado) {
        $rodarJob();
        @unlink($estado);
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        igual([], $r['alertas']);
        igual(false, is_file($estado), 'dry-run não grava estado');
        $r = $verif()->verificar(new DateTimeImmutable('now'), false);
        igual(true, $r['ok']);
        igual(true, is_file($estado));
        igual(12, $r['itens']);
    });
    t('jornada defasada (> 25 h) => alerta', function () use ($pdo, $verif) {
        $pdo->exec("UPDATE extracao_jira SET data_carga = NOW() - INTERVAL 30 HOUR WHERE chave LIKE 'A\\_B-%'");
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        igual(false, $r['ok']);
        verdade(strpos(implode("\n", $r['alertas']), 'Jornada J2') !== false, implode(' | ', $r['alertas']));
        verdade(strpos(implode("\n", $r['alertas']), 'Jornada J1') === false, 'J1 não deveria alertar');
    });
    t('jornada sem itens => alerta', function () use ($pdo, $verif) {
        $pdo->exec("DELETE FROM extracao_jira WHERE chave LIKE 'A\\_B-%'");
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        verdade(strpos(implode("\n", $r['alertas']), 'Jornada J2: sem itens') !== false, implode(' | ', $r['alertas']));
    });
    t('job não rodou hoje => alerta; ordem em erro => alerta; ordem com FALHAS => alerta', function () use ($rodarJob, $verif, $pdo) {
        $rodarJob();
        $pdo->exec('DELETE FROM extracao_log');
        $pdo->exec('DELETE FROM extracao_agendamento');
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        verdade(strpos(implode("\n", $r['alertas']), 'não enfileirou') !== false, implode(' | ', $r['alertas']));

        $rodarJob();
        $pdo->exec("UPDATE extracao_agendamento SET status = 'erro'");
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        verdade(strpos(implode("\n", $r['alertas']), 'ERRO') !== false, implode(' | ', $r['alertas']));

        $rodarJob();
        $pdo->exec("UPDATE extracao_log SET mensagem = CONCAT(mensagem, ' || FALHAS: J2 / PI 4/26: x')");
        $r = $verif()->verificar(new DateTimeImmutable('now'), true);
        verdade(strpos(implode("\n", $r['alertas']), 'em falha') !== false, implode(' | ', $r['alertas']));
    });
    t('queda brusca (< 80% do estado anterior) => alerta e estado NÃO é sobrescrito', function () use ($rodarJob, $verif, $estado) {
        $rodarJob();
        file_put_contents($estado, json_encode(['pi' => 'PI 4/26', 'itens' => 100, 'data' => '2026-01-01 00:00:00']));
        $r = $verif()->verificar(new DateTimeImmutable('now'), false);
        igual(false, $r['ok']);
        verdade(strpos(implode("\n", $r['alertas']), 'Queda brusca') !== false, implode(' | ', $r['alertas']));
        igual(100, json_decode((string)file_get_contents($estado), true)['itens'], 'estado preservado');
    });
    t('PI inválida no verificador (sem PI) => code 2', function () use ($verif) {
        deveLancar(function () use ($verif) {
            $verif()->verificar(new DateTimeImmutable('+400 days'), true);
        }, 2);
    });
} finally {
    // ── Limpeza: banco descartável e temporários ─────────────────────────────
    try {
        $pdo = null;
        $adm->exec('DROP DATABASE IF EXISTS ' . BANCO_TESTE);
        $resta = in_array(BANCO_TESTE, $adm->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true);
        echo "\nBanco " . BANCO_TESTE . ($resta ? ' AINDA EXISTE (remova manualmente)' : ' removido') . ".\n";
    } catch (Throwable $e) {
        echo "\nFalha ao remover o banco de teste: " . $e->getMessage() . "\n";
    }
    Logger::setDir(null);
    rmdir_rec($tmp);
}

echo "\nResultado: {$resultados['ok']} passou, {$resultados['falha']} falhou (PHP " . PHP_VERSION . ").\n";
exit($resultados['falha'] === 0 ? 0 : 1);

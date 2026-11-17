<?php
/**
 * bin/teste_cache_visao_geral.php — TESTES do cache de resposta da Visão Geral
 * ─────────────────────────────────────────────────────────────────────────────
 * Parte U  (sem banco): srvRespostaCache com diretório temporário e relógio/
 *          marcadores injetados: MISS→HIT, invalidação, TTL, STALE com trava,
 *          espera sem candidata, atomicidade (processo filho), erros não
 *          gravados, falha de escrita, tamanho máximo, limpeza oportunista,
 *          permissões, nome = hash, arquivo corrompido, validador extra, ETag.
 * Parte R  (sem banco): campos dependentes do relógio em dois instantes
 *          simulados, comparados a um oráculo independente.
 * Parte I  (banco local, SOMENTE SELECT): SEM cache == MISS == HIT byte a byte e
 *          HIT == corpos de referência capturados antes da mudança (--referencia).
 * Parte H  (--base-url): HTTP contra php -S: X-Cache, ETag, 304, 400 sem cache,
 *          parâmetros de URL sem efeito; --base-url-bypass = servidor com VG_CACHE=0.
 *
 * Uso: php bin/teste_cache_visao_geral.php [--referencia=<pasta vg_antes>]
 *        [--base-url=http://127.0.0.1:18174] [--base-url-bypass=http://127.0.0.1:18175]
 *        [--sem-banco]
 * Saída 0 = tudo passou; 1 = alguma falha. Não vai para produção (deploy/excluir.txt).
 * Compatível com PHP 7.4 e 8.x.
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

use App\Controllers\GraficosController;
use App\Database;
use App\Repositories\PrevisibilidadeRepository;
use App\Srv\srvRespostaCache;

// ── Infra de teste ──────────────────────────────────────────────────────────
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
        $e = is_string($esperado) && strlen($esperado) > 200 ? 'string(' . strlen($esperado) . ')' : var_export($esperado, true);
        $r = is_string($real) && strlen($real) > 200 ? 'string(' . strlen($real) . ')' : var_export($real, true);
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'esperado ' . $e . ', obtido ' . $r);
    }
}

function verdade($cond, string $msg): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function dirTemp(string $nome): string
{
    $d = sys_get_temp_dir() . '/vgc_teste_' . $nome . '_' . getmypid() . '_' . bin2hex(random_bytes(3));
    @mkdir($d, 0777, true);
    return $d;
}

function apagarDir(string $d): void
{
    if (!is_dir($d)) return;
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $d . '/' . $f;
        is_dir($p) ? apagarDir($p) : @unlink($p);
    }
    @rmdir($d);
}

function arquivos(string $d, string $padrao): array
{
    return array_map('basename', glob($d . '/' . $padrao) ?: []);
}

/** Relógio controlável. */
class RelogioFalso
{
    public int $t;
    public function __construct(int $t) { $this->t = $t; }
    public function __invoke(): int { return $this->t; }
}

function gerador200(string $corpo, array $meta = [], int &$chamadas = null): callable
{
    return function () use ($corpo, $meta, &$chamadas): array {
        $chamadas = (int) $chamadas + 1;
        return ['status' => 200, 'corpo' => $corpo, 'meta' => $meta];
    };
}

$opts = getopt('', ['referencia::', 'base-url::', 'base-url-bypass::', 'sem-banco']);
$referencia = isset($opts['referencia']) && $opts['referencia'] !== false ? (string) $opts['referencia'] : '';
$baseUrl = isset($opts['base-url']) && $opts['base-url'] !== false ? rtrim((string) $opts['base-url'], '/') : '';
$baseUrlBypass = isset($opts['base-url-bypass']) && $opts['base-url-bypass'] !== false ? rtrim((string) $opts['base-url-bypass'], '/') : '';
$semBanco = array_key_exists('sem-banco', $opts);

echo 'PHP ' . PHP_VERSION . "\n";
if (getenv('VG_CACHE') !== false) {
    echo "Rode sem a variável VG_CACHE no ambiente.\n";
    exit(1);
}

$temps = [];
$logs = [];
$logger = function (string $nivel, string $msg, array $ctx = []) use (&$logs): void {
    $logs[] = $nivel . ': ' . $msg . ' ' . json_encode($ctx);
};

// ═══════════════════════════════════════════════════════════════════════════
echo "\n== Parte U: srvRespostaCache ==\n";

t('MISS na 1a chamada, HIT na 2a (gerador chamado 1 vez, corpo idêntico)', function () use (&$temps, $logger) {
    $d = $temps[] = dirTemp('u1');
    $rel = new RelogioFalso(1000000);
    $c = new srvRespostaCache($d, $rel, $logger);
    $n = 0;
    $r1 = $c->obter('k', function () { return 'v1'; }, gerador200("{\"a\":1}\n\xC3\xA9", ['x' => 1], $n));
    igual('MISS', $r1['estado']);
    igual(true, $r1['gravado']);
    $rel->t += 10;
    $r2 = $c->obter('k', function () { return 'v1'; }, gerador200('outro', [], $n));
    igual('HIT', $r2['estado']);
    igual("{\"a\":1}\n\xC3\xA9", $r2['corpo']);
    igual(['x' => 1], $r2['meta']);
    igual(10, $r2['idade_s']);
    igual(1, $n, 'chamadas do gerador');
});

t('mudança de marcador (validade) invalida: MISS e regrava', function () use (&$temps) {
    $d = $temps[] = dirTemp('u2');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    $c->obter('k', function () { return 'v1'; }, gerador200('A'));
    $r = $c->obter('k', function () { return 'v2'; }, gerador200('B'));
    igual('MISS', $r['estado']);
    igual('B', $r['corpo']);
    $r = $c->obter('k', function () { return 'v2'; }, gerador200('C'));
    igual('HIT', $r['estado']);
    igual('B', $r['corpo']);
});

t('expiração por TTL (899 s = HIT; 900 s = MISS)', function () use (&$temps) {
    $d = $temps[] = dirTemp('u3');
    $rel = new RelogioFalso(1000000);
    $c = new srvRespostaCache($d, $rel, null, ['ttl_s' => 900]);
    $c->obter('k', function () { return 'v'; }, gerador200('A'));
    $rel->t += 899;
    igual('HIT', $c->obter('k', function () { return 'v'; }, gerador200('B'))['estado']);
    $rel->t += 1;
    $r = $c->obter('k', function () { return 'v'; }, gerador200('B'));
    igual('MISS', $r['estado']);
    igual('B', $r['corpo']);
});

t('STALE: outro processo com a trava e versão anterior existente', function () use (&$temps) {
    $d = $temps[] = dirTemp('u4');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    $c->obter('k', function () { return 'v1'; }, gerador200('ANTIGO'));
    $lock = fopen($d . '/vg_' . hash('sha256', 'k') . '.lock', 'c');
    verdade(flock($lock, LOCK_EX | LOCK_NB), 'trava externa');
    $n = 0;
    $ini = microtime(true);
    $r = $c->obter('k', function () { return 'v2'; }, gerador200('NOVO', [], $n));
    $ms = (microtime(true) - $ini) * 1000;
    flock($lock, LOCK_UN);
    fclose($lock);
    igual('STALE', $r['estado']);
    igual('ANTIGO', $r['corpo']);
    igual('v1', $r['validade'], 'validade da entrada antiga');
    igual(0, $n, 'gerador não chamado');
    verdade($ms < 500, "STALE deve ser imediato ({$ms} ms)");
    $r = $c->obter('k', function () { return 'v2'; }, gerador200('NOVO'));
    igual('MISS', $r['estado'], 'sem trava regenera');
});

t('sem candidata: espera a trava (espera_trava_s=1) e então gera (MISS)', function () use (&$temps) {
    $d = $temps[] = dirTemp('u5');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000), null, ['espera_trava_s' => 1]);
    $lock = fopen($d . '/vg_' . hash('sha256', 'k') . '.lock', 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    $ini = microtime(true);
    $r = $c->obter('k', function () { return 'v1'; }, gerador200('X'));
    $s = microtime(true) - $ini;
    flock($lock, LOCK_UN);
    fclose($lock);
    igual('MISS', $r['estado']);
    igual('X', $r['corpo']);
    verdade($s >= 0.9 && $s < 3, "esperou {$s} s");
});

t('servir_stale=false: com candidata e trava ocupada, espera e não serve STALE', function () use (&$temps) {
    $d = $temps[] = dirTemp('u5b');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000), null, ['espera_trava_s' => 1, 'servir_stale' => false]);
    $c->obter('k', function () { return 'v1'; }, gerador200('ANTIGO'));
    $lock = fopen($d . '/vg_' . hash('sha256', 'k') . '.lock', 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    $r = $c->obter('k', function () { return 'v2'; }, gerador200('NOVO'));
    flock($lock, LOCK_UN);
    fclose($lock);
    igual('MISS', $r['estado']);
    igual('NOVO', $r['corpo']);
});

t('atomicidade: filho grava 2 MB alternando A/B; leitor nunca vê arquivo parcial; nenhum .tmp sobra', function () use (&$temps) {
    $d = $temps[] = dirTemp('u6');
    $filho = $d . '/filho.php';
    $classe = var_export(BASE_DIR . '/app/Srv/srvRespostaCache.php', true);
    $dirExp = var_export($d, true);
    file_put_contents($filho, "<?php\nrequire {$classe};\n"
        . "\$c = new App\\Srv\\srvRespostaCache({$dirExp});\n"
        . "\$a = str_repeat('A', 2097152); \$b = str_repeat('B', 2097152); \$ok = 0;\n"
        . "for (\$i = 0; \$i < 40; \$i++) {\n"
        . "  \$corpo = \$i % 2 ? \$a : \$b;\n"
        . "  \$r = \$c->obter('k', function () use (\$i) { return 'v' . \$i; }, function () use (\$corpo) { return ['status' => 200, 'corpo' => \$corpo]; });\n"
        . "  if (\$r['gravado']) \$ok++;\n"
        . "}\n"
        . "echo \$ok;\n");
    $proc = proc_open([PHP_BINARY, '-n', $filho], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    verdade(is_resource($proc), 'proc_open');
    $slot = $d . '/vg_' . hash('sha256', 'k') . '.vgc';
    $leituras = 0;
    $parciais = 0;
    $ini = microtime(true);
    while (true) {
        $st = proc_get_status($proc);
        $raw = @file_get_contents($slot);
        if (is_string($raw) && $raw !== '') {
            $leituras++;
            $p = strpos($raw, "\n");
            $corpo = $p === false ? '' : substr($raw, $p + 1);
            $meta = $p === false ? null : json_decode(substr($raw, 0, $p), true);
            $okCorpo = strlen($corpo) === 2097152 && ($corpo === str_repeat('A', 2097152) || $corpo === str_repeat('B', 2097152));
            if (!$okCorpo || !is_array($meta) || ($meta['md5'] ?? '') !== md5($corpo)) {
                $parciais++;
            }
        }
        if (!$st['running'] || microtime(true) - $ini > 60) break;
    }
    $gravados = (int) stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    echo "          leituras={$leituras} parciais={$parciais} gravações do filho={$gravados}\n";
    igual('', trim($err), 'stderr do filho');
    igual(0, $parciais, 'leituras parciais');
    verdade($leituras > 0 && $gravados > 0, 'houve leituras e gravações');
    igual([], arquivos($d, '*.tmp'), 'temporários restantes');
});

t('status 400 e 500 não são gravados', function () use (&$temps) {
    $d = $temps[] = dirTemp('u7');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    foreach ([400, 500] as $s) {
        $r = $c->obter('k' . $s, function () { return 'v'; }, function () use ($s) { return ['status' => $s, 'corpo' => '{"success":false}']; });
        igual('MISS', $r['estado']);
        igual(false, $r['gravado']);
        igual($s, $r['status']);
    }
    igual([], arquivos($d, '*.vgc'), 'entradas');
});

t('gerador com gravar=false e corpo vazio não são gravados', function () use (&$temps) {
    $d = $temps[] = dirTemp('u7b');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    $r = $c->obter('a', function () { return 'v'; }, function () { return ['status' => 200, 'corpo' => 'X', 'gravar' => false]; });
    igual(false, $r['gravado']);
    igual('X', $r['corpo']);
    $r = $c->obter('b', function () { return 'v'; }, function () { return ['status' => 200, 'corpo' => '']; });
    igual(false, $r['gravado']);
    igual([], arquivos($d, '*.vgc'));
});

t('exceção do gerador propaga, nada é gravado e a trava é liberada', function () use (&$temps) {
    $d = $temps[] = dirTemp('u8');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    $pegou = false;
    try {
        $c->obter('k', function () { return 'v'; }, function () { throw new InvalidArgumentException('filtro'); });
    } catch (InvalidArgumentException $e) {
        $pegou = true;
    }
    verdade($pegou, 'exceção propagada');
    igual([], arquivos($d, '*.vgc'));
    $lock = fopen($d . '/vg_' . hash('sha256', 'k') . '.lock', 'c');
    $livre = flock($lock, LOCK_EX | LOCK_NB);
    fclose($lock);
    verdade($livre, 'trava liberada');
});

t('provedor de marcadores null ou com exceção = BYPASS (não grava)', function () use (&$temps, &$logs, $logger) {
    $d = $temps[] = dirTemp('u9');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000), $logger);
    $r = $c->obter('k', function () { return null; }, gerador200('A'));
    igual('BYPASS', $r['estado']);
    igual('A', $r['corpo']);
    $antes = count($logs);
    $r = $c->obter('k', function () { throw new RuntimeException('banco'); }, gerador200('B'));
    igual('BYPASS', $r['estado']);
    verdade(count($logs) > $antes, 'falha do provedor registrada');
    igual([], arquivos($d, '*.vgc'));
});

t('falha de escrita (pasta impossível) não derruba e registra log', function () use (&$temps, &$logs, $logger) {
    $d = $temps[] = dirTemp('u10');
    file_put_contents($d . '/arquivo', 'x');
    $c = new srvRespostaCache($d . '/arquivo/sub', new RelogioFalso(1000000), $logger);
    $antes = count($logs);
    $r = @$c->obter('k', function () { return 'v'; }, gerador200('CORPO'));
    igual('MISS', $r['estado']);
    igual('CORPO', $r['corpo']);
    igual(false, $r['gravado']);
    verdade(count($logs) > $antes, 'log registrado');
    verdade(strpos(implode("\n", array_slice($logs, $antes)), 'CORPO') === false, 'log sem conteúdo');
});

t('corpo acima de max_bytes não é gravado', function () use (&$temps) {
    $d = $temps[] = dirTemp('u11');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000), null, ['max_bytes' => 100]);
    $r = $c->obter('k', function () { return 'v'; }, gerador200(str_repeat('x', 101)));
    igual(false, $r['gravado']);
    igual(101, strlen($r['corpo']));
    $r = $c->obter('k2', function () { return 'v'; }, gerador200(str_repeat('x', 100)));
    igual(true, $r['gravado']);
});

t('limpeza oportunista: remove antigos; não roda de novo na mesma hora; preserva .gitkeep', function () use (&$temps) {
    $d = $temps[] = dirTemp('u12');
    touch($d . '/.gitkeep');
    $velho = $d . '/vg_' . str_repeat('a', 64) . '.vgc';
    $tmpVelho = $d . '/vg_' . str_repeat('b', 64) . '.1.ab.tmp';
    $lockVelho = $d . '/vg_' . str_repeat('a', 64) . '.lock';
    foreach ([$velho, $tmpVelho, $lockVelho] as $f) {
        file_put_contents($f, 'x');
        touch($f, time() - 90000);
    }
    $c = new srvRespostaCache($d);
    $c->obter('k', function () { return 'v'; }, gerador200('A'));
    clearstatcache();
    verdade(!is_file($velho) && !is_file($tmpVelho) && !is_file($lockVelho), 'antigos removidos');
    verdade(is_file($d . '/.limpeza'), 'marcador criado');
    verdade(is_file($d . '/.gitkeep'), '.gitkeep preservado');
    file_put_contents($velho, 'x');
    touch($velho, time() - 90000);
    $c->obter('k3', function () { return 'v'; }, gerador200('B'));
    clearstatcache();
    verdade(is_file($velho), 'segunda limpeza na mesma hora não roda');
    $n = $c->limpar();
    verdade($n >= 3, "limpar() apagou {$n}");
    igual([], arquivos($d, '*.vgc'));
    verdade(is_file($d . '/.gitkeep'), '.gitkeep preservado após limpar()');
});

t('permissões do arquivo = 0660 & ~umask (exceto Windows)', function () use (&$temps) {
    if (DIRECTORY_SEPARATOR === '\\') {
        echo "          (Windows: permissões POSIX não se aplicam; ignorado)\n";
        return;
    }
    $d = $temps[] = dirTemp('u13');
    $c = new srvRespostaCache($d . '/nova');
    $c->obter('k', function () { return 'v'; }, gerador200('A'));
    $f = glob($d . '/nova/*.vgc')[0];
    igual(0660 & ~umask(), fileperms($f) & 0777, 'perm');
    igual(0770 & ~umask(), fileperms($d . '/nova') & 0777, 'perm da pasta');
});

t('nome do arquivo = hash da chave (chave com ../ e texto não vai ao caminho)', function () use (&$temps) {
    $d = $temps[] = dirTemp('u14');
    $c = new srvRespostaCache($d . '/c');
    $c->obter('../../x/Secretaria', function () { return 'v'; }, gerador200('A'));
    $fs = arquivos($d . '/c', '*');
    verdade(count($fs) >= 1, 'gravou');
    foreach ($fs as $f) {
        verdade(preg_match('/^vg_[0-9a-f]{64}\.(vgc|lock)$/', $f) === 1, "nome {$f}");
    }
    igual(['c'], arquivos($d, '*'), 'nada fora da pasta');
});

t('arquivo corrompido = MISS e é sobrescrito', function () use (&$temps, &$logs, $logger) {
    $d = $temps[] = dirTemp('u15');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000), $logger);
    $c->obter('k', function () { return 'v'; }, gerador200('ORIGINAL'));
    $slot = $d . '/vg_' . hash('sha256', 'k') . '.vgc';
    $raw = file_get_contents($slot);
    file_put_contents($slot, substr($raw, 0, -2) . 'XY'); // corpo alterado, md5 não bate
    $r = $c->obter('k', function () { return 'v'; }, gerador200('NOVO'));
    igual('MISS', $r['estado']);
    igual('NOVO', $r['corpo']);
    file_put_contents($slot, 'lixo sem cabeçalho');
    igual('MISS', $c->obter('k', function () { return 'v'; }, gerador200('N2'))['estado']);
    igual('HIT', $c->obter('k', function () { return 'v'; }, gerador200('N3'))['estado']);
});

t('validadorExtra=false regenera; true mantém HIT', function () use (&$temps) {
    $d = $temps[] = dirTemp('u16');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    $c->obter('k', function () { return 'v'; }, gerador200('A', ['z' => 1]));
    $sim = function (array $m, int $t) { return ($m['z'] ?? 0) === 1; };
    $nao = function (array $m, int $t) { return false; };
    igual('HIT', $c->obter('k', function () { return 'v'; }, gerador200('B'), $sim)['estado']);
    $r = $c->obter('k', function () { return 'v'; }, gerador200('B'), $nao);
    igual('MISS', $r['estado']);
    igual('B', $r['corpo']);
});

t('inspecionar não gera nem grava', function () use (&$temps) {
    $d = $temps[] = dirTemp('u17');
    $c = new srvRespostaCache($d, new RelogioFalso(1000000));
    igual('MISS', $c->inspecionar('k', 'v')['estado']);
    igual([], arquivos($d, '*'));
    $c->obter('k', function () { return 'v'; }, gerador200('A'));
    igual('HIT', $c->inspecionar('k', 'v')['estado']);
    igual('STALE', $c->inspecionar('k', 'v2')['estado']);
});

t('etagCorresponde: exato, lista, W/, -gzip/-br, *, sem correspondência', function () {
    $e = '"vg-abc"';
    igual(true, GraficosController::etagCorresponde('"vg-abc"', $e));
    igual(true, GraficosController::etagCorresponde('"x", "vg-abc"', $e));
    igual(true, GraficosController::etagCorresponde('W/"vg-abc"', $e));
    igual(true, GraficosController::etagCorresponde('"vg-abc-gzip"', $e));
    igual(true, GraficosController::etagCorresponde('"vg-abc-br"', $e));
    igual(true, GraficosController::etagCorresponde('*', $e));
    igual(false, GraficosController::etagCorresponde('"vg-abd"', $e));
    igual(false, GraficosController::etagCorresponde('vg-abc', $e));
    igual(false, GraficosController::etagCorresponde('', $e));
});

// ═══════════════════════════════════════════════════════════════════════════
echo "\n== Parte R: campos dependentes do relógio (dois instantes simulados) ==\n";

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
$maisAntiga = '2026-11-16 08:00:00';
/** Oráculo independente: monta o payload com os valores do instante e codifica. */
$oraculo = function (int $agora, bool $comAviso) use ($flags, $maisAntiga): string {
    $h = round(($agora - strtotime($maisAntiga)) / 3600, 1);
    $avisos = ['Aviso anterior.'];
    if ($comAviso && $h > 25) {
        $avisos[] = 'A carga mais antiga do Jira tem ' . $h . ' h de defasagem.';
    }
    $avisos[] = 'texto com "defasagem_horas":9 e "aviso_defasagem_horas":1';
    return json_encode([
        'success'  => true,
        'vazio'    => new stdClass(),
        'lista'    => [],
        'float'    => 0.1 + 0.2,
        'inteiro_float' => 3.0,
        'extracao' => ['ultima_carga' => '2026-11-16 23:10:00', 'defasagem_dias' => 0,
                       'defasagem_horas' => $h, 'aviso_defasagem_horas' => 25, 'prefixos_sem_time' => []],
        'avisos'   => $avisos,
        'acento'   => 'Visão Geral / ação',
    ], $flags);
};
/** Meta como o controller grava (montarRelogio) para o corpo gerado em $t0. */
$metaDe = function (int $t0) use ($flags, $maisAntiga): array {
    $h = round(($t0 - strtotime($maisAntiga)) / 3600, 1);
    return ['relogio' => [
        'mais_antiga'    => $maisAntiga,
        'limite'         => 25,
        'campo_literal'  => '"defasagem_horas":' . json_encode($h),
        'aviso_literal'  => $h > 25 ? json_encode('A carga mais antiga do Jira tem ' . $h . ' h de defasagem.', $flags) : null,
        'aviso_presente' => $h > 25,
    ]];
};

t('instante +0 h e +2 h abaixo do limite: corpo ajustado == oráculo, byte a byte', function () use ($oraculo, $metaDe, $maisAntiga) {
    $t0 = strtotime($maisAntiga) + 3 * 3600 + 1440; // 3,4 h
    $corpo = $oraculo($t0, true);
    $meta = $metaDe($t0);
    igual($oraculo($t0, true), GraficosController::ajustarRelogio($corpo, $meta, $t0), '+0 h');
    foreach ([60, 179, 180, 2160, 7200, 7230] as $dt) {
        igual($oraculo($t0 + $dt, true), GraficosController::ajustarRelogio($corpo, $meta, $t0 + $dt), "+{$dt} s");
        igual(true, GraficosController::relogioConsistente($meta, $t0 + $dt), "consistente +{$dt} s");
    }
    verdade(strpos(GraficosController::ajustarRelogio($corpo, $meta, $t0 + 7200), '"defasagem_horas":5.4') !== false, '5.4 h após 2 h');
});

t('instante +26 h (cruza 25 h): validador exige regenerar; com aviso, o texto acompanha o relógio', function () use ($oraculo, $metaDe, $maisAntiga) {
    $t0 = strtotime($maisAntiga) + 3 * 3600 + 1440;
    $meta = $metaDe($t0);
    igual(false, GraficosController::relogioConsistente($meta, $t0 + 26 * 3600), 'aviso passaria a existir');
    // Corpo gerado já acima do limite (30,2 h): aviso presente e atualizado a cada leitura.
    $t1 = strtotime($maisAntiga) + 30 * 3600 + 720;
    $corpo = $oraculo($t1, true);
    $meta1 = $metaDe($t1);
    igual(true, $meta1['relogio']['aviso_presente']);
    foreach ([0, 360, 6480, 7200, 26 * 3600] as $dt) {
        igual($oraculo($t1 + $dt, true), GraficosController::ajustarRelogio($corpo, $meta1, $t1 + $dt), "+{$dt} s");
        igual(true, GraficosController::relogioConsistente($meta1, $t1 + $dt));
    }
});

t('stdClass vazio, floats e texto com "defasagem_horas" escapado ficam intactos', function () use ($oraculo, $metaDe, $maisAntiga) {
    $t0 = strtotime($maisAntiga) + 3600;
    $aj = GraficosController::ajustarRelogio($oraculo($t0, true), $metaDe($t0), $t0 + 7200);
    verdade(strpos($aj, '"vazio":{}') !== false, 'stdClass');
    verdade(strpos($aj, '"float":0.30000000000000004') !== false, 'float');
    verdade(strpos($aj, '"inteiro_float":3,') !== false, 'float inteiro (sem PRESERVE_ZERO_FRACTION, como o respond())');
    verdade(strpos($aj, '\"defasagem_horas\":9') !== false, 'texto escapado');
    igual(GraficosController::ajustarRelogio('{"x":1}', [], $t0), '{"x":1}', 'sem meta = intacto');
});

// ═══════════════════════════════════════════════════════════════════════════
$normalizar = function (string $s): string {
    $s = preg_replace('/"defasagem_horas":(-?[0-9.eE+\-]+|null)/', '"defasagem_horas":"<RELOGIO>"', $s);
    return preg_replace('/A carga mais antiga do Jira tem -?[0-9.eE+\-]+ h de defasagem\./', 'A carga mais antiga do Jira tem <RELOGIO> h de defasagem.', $s);
};

$casos = [];
if ($referencia !== '') {
    $idx = json_decode((string) @file_get_contents($referencia . '/indice.json'), true);
    if (!is_array($idx)) {
        echo "Índice de referência ilegível em {$referencia}\n";
        exit(1);
    }
    foreach ($idx as $c) {
        parse_str((string) parse_url($c['url'], PHP_URL_QUERY), $q);
        $casos[] = ['caso' => $c['caso'], 'query' => $q, 'arquivo' => $referencia . '/' . $c['caso'] . '.json'];
    }
} else {
    $casos = [
        ['caso' => 'secretaria_sprint', 'query' => ['jornada' => 'Secretaria e Gestor de Unidades', 'modo' => 'sprint'], 'arquivo' => null],
        ['caso' => 'aprendizagem_pi', 'query' => ['jornada' => 'Aprendizagem Digital', 'time' => 'Aprendizagem', 'modo' => 'pi'], 'arquivo' => null],
    ];
}
$filtrosDe = function (array $q): array {
    $f = [];
    foreach (['jornada', 'time', 'versao', 'sprint', 'modo'] as $k) {
        $f[$k] = trim((string) ($q[$k] ?? ''));
    }
    if ($f['modo'] === '') $f['modo'] = 'sprint';
    return $f;
};

if (!$semBanco) {
    echo "\n== Parte I: integração read-only (banco local) ==\n";
    echo "  Ignorado na comparação com a referência: valor de extracao.defasagem_horas e o número no aviso\n";
    echo "  'A carga mais antiga do Jira tem X h de defasagem.' (dependem do relógio). SEM == MISS == HIT é byte a byte, sem ignorar nada.\n";

    $dirI = $temps[] = dirTemp('int');
    $novoCtrl = function () use ($dirI, $logger): GraficosController {
        $pv = new PrevisibilidadeRepository();
        return new GraficosController($pv, null, null, null, null, null, new srvRespostaCache($dirI, null, $logger));
    };
    $semCache = function (array $f, ?int $agora = null): array {
        putenv('VG_CACHE=0');
        try {
            return (new GraficosController(new PrevisibilidadeRepository()))->obterVisaoGeral($f, $agora);
        } finally {
            putenv('VG_CACHE');
        }
    };

    foreach ($casos as $caso) {
        t("caso {$caso['caso']}: SEM == MISS == HIT (byte a byte) e == referência", function () use ($caso, $novoCtrl, $semCache, $filtrosDe, $normalizar, $dirI) {
            $f = $filtrosDe($caso['query']);
            $slot = null;
            for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
                foreach (glob($dirI . '/*') ?: [] as $x) @unlink($x);
                $sem = $semCache($f);
                $miss = $novoCtrl()->obterVisaoGeral($f);
                $hit = $novoCtrl()->obterVisaoGeral($f);
                if ($sem['corpo'] === $miss['corpo'] && $miss['corpo'] === $hit['corpo']) break;
                echo "          (tentativa {$tentativa}: corpos diferem; repetindo, pode ser virada de 0,1 h)\n";
            }
            igual('BYPASS', $sem['estado']);
            igual(null, $sem['etag'], 'BYPASS sem ETag');
            igual('MISS', $miss['estado']);
            igual('HIT', $hit['estado']);
            igual(1, count(arquivos($dirI, '*.vgc')), 'entrada gravada (PI dos marcadores == PI do corpo e relógio consistente)');
            igual($sem['corpo'], $miss['corpo'], 'SEM vs MISS');
            igual($miss['corpo'], $hit['corpo'], 'MISS vs HIT');
            igual($miss['etag'], $hit['etag'], 'ETag estável');
            verdade(preg_match('/^"vg-[0-9a-f]{32}"$/', (string) $hit['etag']) === 1, 'formato do ETag');
            $p = json_decode($hit['corpo'], true);
            igual(true, $p['success'] ?? null);
            if ($caso['arquivo'] !== null) {
                $ref = file_get_contents($caso['arquivo']);
                igual($normalizar($ref), $normalizar($hit['corpo']), 'HIT vs referência (normalizado)');
                echo '          ' . strlen($hit['corpo']) . ' B; idênticos sem normalizar: ' . ($ref === $hit['corpo'] ? 'sim' : 'não (só relógio)') . "\n";
            }
        });
    }

    t('dois instantes reais: HIT em agora+600 s e +1800 s == cálculo para esse instante', function () use ($dirI, $logger, $filtrosDe) {
        $f = $filtrosDe(['jornada' => 'Secretaria e Gestor de Unidades', 'modo' => 'sprint']);
        $agora = time();
        if (date('Y-m-d', $agora) !== date('Y-m-d', $agora + 1800)) {
            echo "          (virada do dia em menos de 30 min; ignorado)\n";
            return;
        }
        foreach (glob($dirI . '/*') ?: [] as $x) @unlink($x);
        $mk = function () use ($dirI, $logger) {
            return new GraficosController(new PrevisibilidadeRepository(), null, null, null, null, null,
                new srvRespostaCache($dirI, null, $logger, ['ttl_s' => 100000]));
        };
        $miss = $mk()->obterVisaoGeral($f, $agora);
        igual('MISS', $miss['estado']);
        $p0 = json_decode($miss['corpo'], true);
        $st = Database::pdo()->prepare("SELECT MIN(data_carga) FROM extracao_jira WHERE ativo = 'S' AND configuracao_pi_id = (SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = :d) AND SUBSTRING_INDEX(chave, '-', 1) IN ("
            . implode(',', array_map(function ($t) { return Database::pdo()->quote($t['projeto_key']); }, $p0['universo']['times'])) . ')');
        $st->execute([':d' => $p0['janela']['pi']]);
        $ma = (string) $st->fetchColumn();
        foreach ([600, 1800] as $dt) {
            $hit = $mk()->obterVisaoGeral($f, $agora + $dt);
            igual('HIT', $hit['estado'], "+{$dt}");
            $p = json_decode($hit['corpo'], true);
            $esperado = round(($agora + $dt - strtotime($ma)) / 3600, 1);
            igual($esperado, (float) $p['extracao']['defasagem_horas'], "defasagem_horas +{$dt}");
            verdade(strpos($hit['corpo'], '"defasagem_horas":' . json_encode($esperado) . ',') !== false, 'literal no corpo');
            $semAviso = function (array $x) { unset($x['extracao']['defasagem_horas']); $x['avisos'] = array_values(array_filter($x['avisos'], function ($a) { return strpos($a, 'A carga mais antiga do Jira tem') !== 0; })); return $x; };
            igual($semAviso($p0), $semAviso($p), "resto do corpo +{$dt}");
            $temAviso = (bool) array_filter($p['avisos'], function ($a) { return strpos($a, 'A carga mais antiga do Jira tem') === 0; });
            igual($esperado > 25, $temAviso, "aviso coerente +{$dt}");
            if ($esperado > 25) {
                verdade(in_array('A carga mais antiga do Jira tem ' . $esperado . ' h de defasagem.', $p['avisos'], true), 'texto do aviso');
            }
            if ($p['extracao']['defasagem_horas'] !== $p0['extracao']['defasagem_horas']) {
                verdade($hit['etag'] !== $miss['etag'], 'ETag muda quando o relógio muda o corpo');
            }
            echo "          +{$dt} s: defasagem_horas {$p0['extracao']['defasagem_horas']} -> {$p['extracao']['defasagem_horas']}\n";
        }
    });

    t('respostas 400 (jornada inexistente, versão inexistente, modo=xx) não criam arquivo', function () use ($dirI, $novoCtrl, $filtrosDe) {
        foreach (glob($dirI . '/*') ?: [] as $x) @unlink($x);
        foreach ([['jornada' => 'Jornada Que Nao Existe'], ['versao' => 'PI 99/99'], ['modo' => 'xx'], ['jornada' => 'Secretaria e Gestor de Unidades', 'sprint' => 'SP 0.0.0']] as $q) {
            $lancou = false;
            try {
                $novoCtrl()->obterVisaoGeral($filtrosDe($q));
            } catch (InvalidArgumentException $e) {
                $lancou = true;
            }
            verdade($lancou, '400 para ' . json_encode($q));
        }
        igual([], arquivos($dirI, '*.vgc'), 'entradas');
    });

    t('filtros diferentes = slots diferentes; versão ausente e versão=PI corrente não compartilham', function () use ($dirI, $novoCtrl, $filtrosDe) {
        foreach (glob($dirI . '/*') ?: [] as $x) @unlink($x);
        $base = ['jornada' => 'Secretaria e Gestor de Unidades', 'modo' => 'sprint'];
        $a = $novoCtrl()->obterVisaoGeral($filtrosDe($base));
        $pi = json_decode($a['corpo'], true)['janela']['pi'];
        $b = $novoCtrl()->obterVisaoGeral($filtrosDe($base + ['versao' => $pi]));
        igual('MISS', $b['estado'], 'versao explícita');
        verdade($a['corpo'] !== $b['corpo'], 'corpos diferem (versao_padrao)');
        igual('MISS', $novoCtrl()->obterVisaoGeral($filtrosDe(['jornada' => 'Secretaria e Gestor de Unidades', 'modo' => 'pi']))['estado'], 'modo');
        igual('MISS', $novoCtrl()->obterVisaoGeral($filtrosDe(['modo' => 'sprint']))['estado'], 'jornada');
        igual('HIT', $novoCtrl()->obterVisaoGeral($filtrosDe(['jornada' => '  Secretaria e Gestor de Unidades ', 'modo' => '']))['estado'], 'trim e modo vazio = sprint');
        igual(4, count(arquivos($dirI, '*.vgc')), 'slots');
        $c = $novoCtrl()->obterVisaoGeral($filtrosDe(['modo' => 'sprint']));
        $s = $novoCtrl()->obterVisaoGeral($filtrosDe($base));
        verdade($c['etag'] !== $s['etag'], 'ETag difere entre filtros (consolidado x jornada)');
        verdade($a['etag'] !== $b['etag'], 'ETag difere entre versão ausente e explícita');
    });

    t('cache desativado por VG_CACHE=0: BYPASS, sem ETag, nada gravado', function () use ($dirI, $filtrosDe) {
        foreach (glob($dirI . '/*') ?: [] as $x) @unlink($x);
        putenv('VG_CACHE=0');
        try {
            $r = (new GraficosController(new PrevisibilidadeRepository(), null, null, null, null, null, new srvRespostaCache($dirI)))
                ->obterVisaoGeral($filtrosDe(['jornada' => 'Secretaria e Gestor de Unidades']));
        } finally {
            putenv('VG_CACHE');
        }
        igual('BYPASS', $r['estado']);
        igual(null, $r['etag']);
        igual([], arquivos($dirI, '*'));
    });
}

// ═══════════════════════════════════════════════════════════════════════════
function http(string $url, array $cab = []): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $cab), 'ignore_errors' => true, 'timeout' => 120,
    ]]);
    $corpo = @file_get_contents($url, false, $ctx);
    $linhas = isset($http_response_header) ? $http_response_header : [];
    $status = 0;
    $h = [];
    foreach ($linhas as $i => $l) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $l, $m)) {
            $status = (int) $m[1];
            $h = [];
            continue;
        }
        $p = strpos($l, ':');
        if ($p !== false) {
            $h[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }
    }
    return ['status' => $status, 'h' => $h, 'corpo' => $corpo === false ? '' : $corpo];
}

if ($baseUrl !== '') {
    echo "\n== Parte H: HTTP em {$baseUrl} ==\n";
    $dirReal = BASE_DIR . '/data/cache';
    $qs = 'jornada=' . rawurlencode('Secretaria e Gestor de Unidades') . '&modo=sprint';
    $u = $baseUrl . '/graficos/visao-geral?' . $qs;

    t('MISS e depois HIT, ETag e Cache-Control presentes, corpo idêntico, Content-Type JSON', function () use ($u, $dirReal) {
        (new srvRespostaCache($dirReal))->limpar();
        $a = http($u);
        $b = http($u);
        igual(200, $a['status']);
        igual('MISS', $a['h']['x-cache'] ?? null);
        igual('HIT', $b['h']['x-cache'] ?? null);
        verdade(isset($a['h']['etag']), 'ETag');
        igual('private, no-cache', $b['h']['cache-control'] ?? null);
        igual('application/json; charset=utf-8', $b['h']['content-type'] ?? null);
        igual($a['corpo'], $b['corpo'], 'corpo');
        igual($a['h']['etag'], $b['h']['etag'], 'ETag estável');
    });

    t('If-None-Match com o ETag → 304 sem corpo e com X-Cache; W/ e -gzip também; ETag diferente → 200', function () use ($u) {
        $a = http($u);
        $e = $a['h']['etag'];
        $r = http($u, ['If-None-Match: ' . $e]);
        igual(304, $r['status']);
        igual('', $r['corpo']);
        verdade(isset($r['h']['x-cache']), 'X-Cache no 304');
        igual($e, $r['h']['etag'] ?? null, 'ETag no 304');
        verdade(!isset($r['h']['content-length']) || $r['h']['content-length'] === '0', 'sem corpo');
        igual(304, http($u, ['If-None-Match: W/' . $e])['status']);
        igual(304, http($u, ['If-None-Match: "x", ' . substr($e, 0, -1) . '-gzip"'])['status']);
        $outra = http(str_replace('jornada=' . rawurlencode('Secretaria e Gestor de Unidades') . '&', '', $u), ['If-None-Match: ' . $e]);
        igual(200, $outra['status'], 'ETag de outra seleção não vale para o consolidado');
        $d = http($u, ['If-None-Match: "vg-00000000000000000000000000000000"']);
        igual(200, $d['status']);
        igual($a['corpo'], $d['corpo']);
    });

    t('400 não recebe cabeçalhos de cache e não cria arquivo', function () use ($baseUrl, $dirReal) {
        $antes = arquivos($dirReal, '*.vgc');
        foreach (['jornada=Inexistente', 'modo=xx', 'versao=PI%2099%2F99'] as $q) {
            $r = http($baseUrl . '/graficos/visao-geral?' . $q);
            igual(400, $r['status'], $q);
            verdade(!isset($r['h']['x-cache']) && !isset($r['h']['etag']), "sem cabeçalhos de cache ({$q})");
            igual(false, json_decode($r['corpo'], true)['success'] ?? null);
        }
        igual($antes, arquivos($dirReal, '*.vgc'), 'entradas');
    });

    t('parâmetros de URL (?cache=0, ?bypass=1, ?VG_CACHE=0) não desligam o cache', function () use ($u) {
        foreach (['&cache=0', '&bypass=1', '&VG_CACHE=0', '&nocache=1'] as $x) {
            igual('HIT', http($u . $x)['h']['x-cache'] ?? null, $x);
        }
    });

    t('outros endpoints /graficos/* continuam 200 sem X-Cache', function () use ($baseUrl) {
        foreach (['previsibilidade', 'plan-real', 'lead-time', 'velocidade', 'produtividade'] as $ep) {
            $r = http($baseUrl . '/graficos/' . $ep . '?jornada=' . rawurlencode('Secretaria e Gestor de Unidades'));
            igual(200, $r['status'], $ep);
            verdade(!isset($r['h']['x-cache']) && !isset($r['h']['etag']), "{$ep} sem cabeçalhos de cache");
        }
    });

    t('POST continua 405', function () use ($u) {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60, 'header' => 'Content-Length: 0']]);
        @file_get_contents($u, false, $ctx);
        verdade(isset($http_response_header[0]) && strpos($http_response_header[0], '405') !== false, 'status ' . ($http_response_header[0] ?? '?'));
    });
}

if ($baseUrlBypass !== '') {
    echo "\n== Parte H2: HTTP com VG_CACHE=0 em {$baseUrlBypass} ==\n";
    t('servidor com VG_CACHE=0 → X-Cache BYPASS, sem ETag, sem arquivo novo', function () use ($baseUrlBypass, $baseUrl, $normalizar) {
        $dirReal = BASE_DIR . '/data/cache';
        (new srvRespostaCache($dirReal))->limpar();
        $u = $baseUrlBypass . '/graficos/visao-geral?jornada=' . rawurlencode('Secretaria e Gestor de Unidades') . '&modo=sprint';
        $r = http($u);
        igual(200, $r['status']);
        igual('BYPASS', $r['h']['x-cache'] ?? null);
        verdade(!isset($r['h']['etag']) && !isset($r['h']['cache-control']), 'sem ETag/Cache-Control');
        igual([], arquivos($dirReal, '*.vgc'));
        igual(200, http($u, ['If-None-Match: *'])['status'], 'sem 304 no BYPASS');
        if ($baseUrl !== '') {
            $c = http($baseUrl . '/graficos/visao-geral?jornada=' . rawurlencode('Secretaria e Gestor de Unidades') . '&modo=sprint');
            igual($normalizar($r['corpo']), $normalizar($c['corpo']), 'BYPASS vs cache');
        }
    });
}

// ── Limpeza ────────────────────────────────────────────────────────────────
foreach ($temps as $d) apagarDir($d);

echo "\nResultado: {$resultados['ok']} PASSOU, {$resultados['falha']} FALHOU\n";
exit($resultados['falha'] === 0 ? 0 : 1);

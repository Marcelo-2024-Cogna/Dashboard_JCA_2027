<?php
/**
 * bin/aquecer_cache.php — AQUECIMENTO do cache de resposta da Visão Geral
 * ─────────────────────────────────────────────────────────────────────────
 * Gera as entradas de data/cache/ para o consolidado (sem filtro) e para cada
 * jornada ativa, nos modos 'sprint' e 'pi', com a PI corrente (versão ausente,
 * igual ao tráfego da UI). Usa o MESMO caminho de código da rota
 * (GraficosController::obterVisaoGeral), sem HTTP. Só LÊ o banco.
 * Agendar depois do job noturno (ver relatório do cache).
 *
 * Execute `php bin/aquecer_cache.php --help` para opções e códigos de saída.
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
use App\Services\Logger;
use App\Srv\srvLock;
use App\Srv\srvRespostaCache;

function aquecer_cache_ajuda(): string
{
    return <<<'TXT'
Uso: php bin/aquecer_cache.php [opções]
Aquece o cache da Visão Geral: consolidado + cada jornada ativa, modos sprint e pi.
Opções:
  --dry-run       lista as entradas e o estado atual (HIT/STALE/MISS); não gera nem grava
  --pi=<desc_pi>  usa esta PI (parâmetro versao); padrão: PI corrente (versão ausente)
  --limite=<s>    tempo máximo total em segundos (padrão 600)
  --help          esta ajuda
Saída: uma linha por entrada: <rótulo> <modo> <estado> <ms> ms <bytes> B
Códigos de saída:
  0  tudo ok (ou cache desativado, ou já em execução)
  1  alguma entrada falhou ou o limite de tempo estourou
  2  opção inválida
TXT;
}

$dry = false;
$pi = '';
$limite = 600;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help') {
        echo aquecer_cache_ajuda() . "\n";
        exit(0);
    } elseif ($a === '--dry-run') {
        $dry = true;
    } elseif (strncmp($a, '--pi=', 5) === 0 && trim(substr($a, 5)) !== '') {
        $pi = trim(substr($a, 5));
    } elseif (preg_match('/^--limite=([1-9][0-9]*)$/', $a, $m)) {
        $limite = (int) $m[1];
    } else {
        fwrite(STDERR, "Opção inválida: {$a}\n\n" . aquecer_cache_ajuda() . "\n");
        exit(2);
    }
}

$cfgCache = (require BASE_DIR . '/config/visao_geral.php')['cache'] ?? [];
$env = getenv('VG_CACHE');
if (empty($cfgCache['ativo']) || ($env !== false && trim((string) $env) === '0')) {
    echo "Cache da Visão Geral desativado (config ou VG_CACHE=0); nada a aquecer.\n";
    exit(0);
}

$lock = new srvLock(BASE_DIR . '/data/locks/aquecer_cache.lock');
if (!$lock->tentar()) {
    echo "Aquecimento já em execução; saindo.\n";
    exit(0);
}

$codigo = 0;
$inicio = microtime(true);
try {
    $jornadas = Database::pdo()->query("SELECT nome FROM jornada WHERE ativo = 'S' ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
    $alvos = [['rotulo' => '(consolidado)', 'jornada' => '']];
    foreach ($jornadas as $j) {
        $alvos[] = ['rotulo' => (string) $j, 'jornada' => (string) $j];
    }
    $ctrl = new GraficosController(new PrevisibilidadeRepository());
    $total = 0;
    foreach ($alvos as $alvo) {
        foreach (['sprint', 'pi'] as $modo) {
            if (microtime(true) - $inicio > $limite) {
                echo "Limite de {$limite} s estourado; entradas restantes não aquecidas.\n";
                $codigo = 1;
                break 2;
            }
            $filtros = ['jornada' => $alvo['jornada'], 'time' => '', 'versao' => $pi, 'sprint' => '', 'modo' => $modo];
            $t = microtime(true);
            try {
                $r = $ctrl->obterVisaoGeral($filtros, null, $dry);
                $ms = (int) round((microtime(true) - $t) * 1000);
                $bytes = strlen($r['corpo']);
                $total += $bytes;
                echo sprintf("%s %s %s %d ms %d B\n", $alvo['rotulo'], $modo, $r['estado'], $ms, $bytes);
                if (!$dry && $r['estado'] === srvRespostaCache::ESTADO_BYPASS) {
                    $codigo = 1;
                }
            } catch (Throwable $e) {
                $ms = (int) round((microtime(true) - $t) * 1000);
                echo sprintf("%s %s FALHA %d ms 0 B (%s)\n", $alvo['rotulo'], $modo, $ms, Logger::sanitize($e->getMessage()));
                Logger::exception(Logger::CANAL_DASHBOARD, 'aquecer_cache: falha na entrada', $e, ['modo' => $modo]);
                $codigo = 1;
            }
        }
    }
    echo sprintf("Total: %d ms, %d B\n", (int) round((microtime(true) - $inicio) * 1000), $total);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . Logger::sanitize($e->getMessage()) . "\n");
    Logger::exception(Logger::CANAL_DASHBOARD, 'aquecer_cache: erro fatal', $e);
    $codigo = 1;
} finally {
    $lock->liberar();
}
exit($codigo);

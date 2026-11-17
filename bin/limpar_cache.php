<?php
/**
 * bin/limpar_cache.php — esvazia o cache de resposta da Visão Geral
 * ───────────────────────────────────────────────────────────────────
 * Apaga as entradas (*.vgc), temporários (*.tmp) e travas (*.lock) da pasta
 * configurada em config/visao_geral.php ('cache' => 'dir'); preserva .gitkeep.
 *
 * Uso: php bin/limpar_cache.php [--dry-run] [--help]
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

use App\Srv\srvRespostaCache;

$ajuda = "Uso: php bin/limpar_cache.php [opções]\n"
    . "Apaga as entradas do cache da Visão Geral (preserva .gitkeep).\n"
    . "Opções:\n"
    . "  --dry-run   só lista o que seria apagado\n"
    . "  --help      esta ajuda\n"
    . "Códigos de saída: 0 ok; 2 opção inválida\n";

$dry = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help') {
        echo $ajuda;
        exit(0);
    } elseif ($a === '--dry-run') {
        $dry = true;
    } else {
        fwrite(STDERR, "Opção inválida: {$a}\n\n" . $ajuda);
        exit(2);
    }
}

$dir = (string) (((require BASE_DIR . '/config/visao_geral.php')['cache'] ?? [])['dir'] ?? 'data/cache');
if (!preg_match('~^([A-Za-z]:[\\\\/]|/)~', $dir)) {
    $dir = BASE_DIR . '/' . $dir;
}

if ($dry) {
    $n = 0;
    foreach (['*.vgc', '*.tmp', '*.lock'] as $p) {
        foreach (glob($dir . '/' . $p) ?: [] as $f) {
            echo basename($f) . ' (' . filesize($f) . " B)\n";
            $n++;
        }
    }
    echo "{$n} arquivo(s) seriam apagados.\n";
    exit(0);
}
$n = (new srvRespostaCache($dir))->limpar();
echo "{$n} arquivo(s) apagados.\n";
exit(0);

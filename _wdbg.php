<?php
define('BASE_DIR', __DIR__);
spl_autoload_register(function ($c) {
    $f = BASE_DIR . '/' . str_replace(['App\\','\\'], ['app/','/'], $c) . '.php';
    if (file_exists($f)) require_once $f;
});
use App\Services\SprintWindowService;

$cap = require BASE_DIR.'/config/capacity.php';
$base = require BASE_DIR.'/config/baseline.php';

echo "Hoje: " . (new DateTimeImmutable('today'))->format('Y-m-d') . "\n\n";
echo "sprint_dates na config:\n";
foreach (($cap['sprint_dates'] ?? []) as $sp => $d) {
    echo "  $sp: {$d['start']} a {$d['end']}\n";
}

echo "\nsprints da PI (config): " . implode(',', $base['sprints'] ?? []) . "\n";

$svc = new SprintWindowService($base['sprints'] ?? [], $cap['sprint_dates'] ?? [], $base['closing_sprint'] ?? null);
echo "\nlastClosedByDate: " . var_export($svc->lastClosedByDate(), true) . "\n";
echo "resolveClosingSprint(null): " . var_export($svc->resolveClosingSprint(null), true) . "\n";
echo "windowSprints(null): " . implode(',', $svc->windowSprints(null)) . "\n";

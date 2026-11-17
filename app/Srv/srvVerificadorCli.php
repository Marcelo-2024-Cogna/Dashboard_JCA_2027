<?php

namespace App\Srv;

use App\Services\Logger;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * srvVerificadorCli
 * ─────────────────
 * Corpo de bin/extract_check.php (fino), extraído para ser testável com um
 * PDO injetável. SOMENTE LEITURA no banco (a única escrita é o arquivo de
 * estado, feito por srvVerificadorExtracao).
 *
 * Saídas: 0 ok | 1 alerta | 2 configuração/PI inválida | 4 erro fatal
 * (inclusive banco indisponível: registra, notifica e sai com 4).
 */
final class srvVerificadorCli
{
    /**
     * @param callable $pdoFactory function(): \PDO
     * @param callable|null $out function(string $linha): void (padrão: stdout com hora)
     */
    public function executar(
        callable $pdoFactory,
        array $cfg,
        string $baseDir,
        bool $dry,
        ?callable $discoProvider = null,
        ?callable $out = null,
        ?srvNotificadorEmail $notificador = null
    ): int {
        $out = $out ?? function (string $m): void {
            echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
            if (function_exists('flush')) flush();
            if (defined('STDOUT')) @fflush(STDOUT);
        };
        $baseDir = rtrim($baseDir, '/\\');
        $notif = $notificador;
        $getNotif = function () use (&$notif, $cfg, $baseDir): srvNotificadorEmail {
            if ($notif === null) {
                $log = (string)$cfg['log_notificacoes'];
                if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $log)) {
                    $log = $baseDir . '/' . ltrim($log, '/\\');
                }
                $notif = new srvNotificadorEmail($log, (string)$cfg['ambiente']);
            }
            return $notif;
        };

        try {
            $pdo    = call_user_func($pdoFactory);
            $estado = (string)$cfg['estado_check'];
            if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $estado)) {
                $estado = $baseDir . '/' . ltrim($estado, '/\\');
            }

            try {
                $r = (new srvVerificadorExtracao($pdo, $cfg, $estado, $discoProvider))->verificar(new DateTimeImmutable('now'), $dry);
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 2) throw $e;
                $out('CONFIGURAÇÃO/PI INVÁLIDA: ' . $e->getMessage());
                Logger::error('extracao', 'extract_check: configuração/PI inválida', ['erro' => $e->getMessage()]);
                if (!$dry) {
                    $getNotif()->notificarFalha('?', ['tipo' => 'Verificacao', 'codigo_saida' => 2, 'erros' => [$e->getMessage()], 'log' => 'data/logs/extracao.log']);
                }
                return 2;
            }

            $out("PI {$r['pi']}: {$r['itens']} itens em extracao_jira.");
            foreach ($r['jornadas'] as $j) {
                $out(sprintf('  %-28s itens=%-6d MIN data_carga=%s (%s h)', $j['jornada'], $j['itens'], $j['min'] ?? '-', $j['horas'] ?? '-'));
            }
            foreach ($r['avisos'] as $a) {
                $out('AVISO ' . $a);
            }
            foreach ($r['disco'] as $d) {
                if ($d['disponivel']) {
                    $out(sprintf('  Disco %s: livre %.1F MB (%.1F%% de %.1F MB)', $d['caminho'], $d['livre_mb'], $d['livre_pct'], $d['total_mb']));
                }
            }

            if ($r['ok']) {
                $out('VERIFICAÇÃO OK' . ($dry ? ' (dry-run: estado não gravado)' : ($r['estado_gravado'] ? ' (estado gravado)' : '')));
                Logger::info('extracao', "extract_check: ok (PI {$r['pi']}, {$r['itens']} itens)");
                return 0;
            }

            foreach ($r['alertas'] as $a) {
                $out('ALERTA: ' . $a);
                Logger::error('extracao', 'extract_check: alerta: ' . $a);
            }
            if (!$dry) {
                $getNotif()->notificarFalha($r['pi'], ['tipo' => 'Verificacao', 'codigo_saida' => 1, 'erros' => $r['alertas'], 'log' => 'data/logs/extracao.log']);
            } else {
                $out('dry-run: nenhuma notificação enviada.');
            }
            return 1;
        } catch (Throwable $e) {
            $msg = Logger::sanitize($e->getMessage());
            $out('ERRO FATAL: ' . $msg);
            Logger::exception('extracao', 'extract_check: erro fatal', $e);
            if (!$dry) {
                try {
                    $getNotif()->notificarFalha('?', [
                        'tipo' => 'Verificacao', 'codigo_saida' => 4,
                        'erros' => ['Banco indisponível ou erro fatal: ' . $msg], 'log' => 'data/logs/extracao.log',
                    ]);
                } catch (Throwable $e2) {
                    // notificar nunca derruba
                }
            }
            return 4;
        }
    }
}

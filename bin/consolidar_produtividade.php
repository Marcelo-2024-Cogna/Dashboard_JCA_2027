<?php
/**
 * bin/consolidar_produtividade.php — consolida a produtividade de uma PI
 * ─────────────────────────────────────────────────────────────────────────
 * Lê vw_produtividade_pi (ao vivo de extracao_jira) e grava/atualiza
 * produtividade_historico (chave lógica times_id + configuracao_pi_id, UNIQUE).
 *
 * DEFINIÇÃO (horas para entregar 1 ponto), por time e PI, itens de ENTREGA DE VALOR
 * ENTREGUES (Aceito, Pendente de Produção, Em produção) da PI (configuracao_pi_id):
 *   horas_gastas     = SUM(soma_tempo_gasto)/3600
 *   pontos_entregues = SUM(story_points)   (SP nulo NÃO cai para o estimate)
 *   produtividade    = horas_gastas / NULLIF(pontos_entregues, 0)
 *
 * Uso: php bin/consolidar_produtividade.php --pi="PI 1/26" [--forcar] [--dry-run]
 *   --forcar   permite consolidar a PI CORRENTE (a que contém hoje); sem ele é recusada,
 *              pois a PI em andamento segue AO VIVO (vw_produtividade_pi).
 *   --dry-run  só mostra o que seria gravado.
 * Idempotente: rodar de novo atualiza as mesmas linhas. Loga no canal 'importacao'.
 */

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Services/Logger.php';

date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'] ?? 'America/Sao_Paulo');

use App\Database;
use App\Services\Logger;

const CANAL = 'importacao';

$opts   = getopt('', ['pi:', 'forcar', 'dry-run']);
$pi     = isset($opts['pi']) ? trim((string)$opts['pi']) : '';
$forcar = isset($opts['forcar']);
$dry    = isset($opts['dry-run']);

if ($pi === '') {
    fwrite(STDERR, "Uso: php bin/consolidar_produtividade.php --pi=\"PI 1/26\" [--forcar] [--dry-run]\n");
    exit(2);
}

try {
    $pdo = Database::pdo();

    $st = $pdo->prepare('SELECT id_versao_corrigida, data_ini, data_fim, (CURDATE() BETWEEN data_ini AND data_fim) AS corrente
                           FROM configuracao_pi WHERE desc_pi = ? AND ativo = \'S\'');
    $st->execute([$pi]);
    $cat = $st->fetch();
    if (!$cat) {
        fwrite(STDERR, "PI '{$pi}' não existe no catálogo (ativa).\n");
        exit(2);
    }
    if ((int)$cat['corrente'] === 1 && !$forcar) {
        $msg = "PI '{$pi}' é a PI corrente (contém hoje): recusada. A PI em andamento é calculada ao vivo; use --forcar para consolidar mesmo assim.";
        Logger::warning(CANAL, 'consolidar_produtividade: PI corrente recusada', ['pi' => $pi]);
        fwrite(STDERR, $msg . "\n");
        exit(3);
    }

    $st = $pdo->prepare('SELECT v.projeto_key, v.time, t.id_time, v.horas_gastas, v.pontos_entregues, v.produtividade, v.itens, v.itens_sp_nulo
                           FROM vw_produtividade_pi v JOIN times t ON t.projeto_key = v.projeto_key AND t.ativo = \'S\'
                          WHERE v.configuracao_pi_id = ? ORDER BY v.projeto_key');
    $st->execute([(int)$cat['id_versao_corrigida']]);
    $rows = $st->fetchAll();
    if (!$rows) {
        fwrite(STDERR, "Nenhum item de valor entregue na extração para '{$pi}'. Nada a consolidar.\n");
        Logger::warning(CANAL, 'consolidar_produtividade: sem dados', ['pi' => $pi]);
        exit(4);
    }

    $agora = date('Y-m-d H:i:s');
    $sql = 'INSERT INTO produtividade_historico (times_id, configuracao_pi_id, produtividade, pontos_entregues, horas_gastas, data_consolidacao, ativo)
            VALUES (:t, :p, :prod, :pts, :h, :dt, \'S\')
            ON DUPLICATE KEY UPDATE produtividade = VALUES(produtividade), pontos_entregues = VALUES(pontos_entregues),
                                    horas_gastas = VALUES(horas_gastas), data_consolidacao = VALUES(data_consolidacao), ativo = \'S\'';
    $ins = $pdo->prepare($sql);

    echo ($dry ? '[dry-run] ' : '') . "Consolidação {$pi} (" . count($rows) . " times)\n";
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        printf("  %-5s %-30s horas=%10.2f pontos=%8.2f h/ponto=%s itens=%d (SP nulo: %d)\n",
            $r['projeto_key'], $r['time'], $r['horas_gastas'], $r['pontos_entregues'],
            $r['produtividade'] === null ? '—' : number_format((float)$r['produtividade'], 2), $r['itens'], $r['itens_sp_nulo']);
        if (!$dry) {
            $ins->execute([
                ':t' => (int)$r['id_time'], ':p' => (int)$cat['id_versao_corrigida'],
                ':prod' => $r['produtividade'] === null ? null : round((float)$r['produtividade'], 2),
                ':pts' => $r['pontos_entregues'], ':h' => $r['horas_gastas'], ':dt' => $agora,
            ]);
        }
    }
    $dry ? $pdo->rollBack() : $pdo->commit();

    Logger::info(CANAL, $dry ? 'consolidar_produtividade: dry-run' : 'consolidar_produtividade: gravado', [
        'pi' => $pi, 'times' => count($rows), 'forcar' => $forcar,
    ]);
    echo $dry ? "Dry-run: nada gravado.\n" : "Gravado em produtividade_historico.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    Logger::exception(CANAL, 'consolidar_produtividade: falha', $e, ['pi' => $pi]);
    fwrite(STDERR, 'Falha: ' . $e->getMessage() . "\n");
    exit(1);
}

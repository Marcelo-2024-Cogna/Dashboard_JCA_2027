<?php
/**
 * bin/seed_catalogo.php — SEED / carga inicial do catálogo (ETAPA 3, v1.5)
 * ────────────────────────────────────────────────────────────────────────────
 * Popula as tabelas de catálogo (NÃO as de dados brutos de extração):
 *   jornada · times · times_composicao · configuracao_pi · sprints
 *
 * FONTES:
 *   1. config/capacity.php          → jornadas, times e composição (qtde_dev,
 *                                      horas_dev, media_produtividade_pi).
 *   2. Aba "Apoio" da planilha       → catálogo de PIs e sprints de 2026.
 *      (Template Velocity 26-4.xlsx)   SOMENTE o bloco 2026 (colunas A-D).
 *
 * IDEMPOTENTE: cada execução apaga o conteúdo das 5 tabelas (ordem segura via
 * SET FOREIGN_KEY_CHECKS) e reinsere tudo dentro de UMA transação InnoDB.
 * Rodar N vezes não duplica.
 *
 * NÃO cria views (etapa 4) nem camada de conexão reutilizável (etapa 5):
 * a conexão PDO é autocontida aqui.
 *
 * Uso: php bin/seed_catalogo.php
 */

$ROOT     = __DIR__ . '/..';
$CAPACITY = $ROOT . '/config/capacity.php';
$XLSX     = $ROOT . '/Template Velocity 26-4.xlsx';
$SHEET    = 'Apoio';

// ── Conexão PDO autocontida ───────────────────────────────────────────────
$DB_HOST = 'localhost';
$DB_NAME = 'metricas_agil';
$DB_USER = 'metricas_jca';
$DB_PASS = 'metricas_jca';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Falha na conexão: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Catálogo de Jornadas, Times (projeto_key) e nomes ──────────────────────
// Ordem de jornadas e times conforme o cabeçalho de config/capacity.php.
// Jornadas ATIVAS (ativo='S') com seus 29 times.
$JORNADAS_ATIVAS = [
    'Secretaria e Gestor de Unidades' => [
        'SGGU' => 'Gestor de Unidades',
        'SGSE' => 'Secretaria - Enturmação',
        'SGGS' => 'Secretaria - Gestão Acadêmica',
        'SGTB' => 'Thunderbolts',
    ],
    'Ingresso' => [
        'INAP' => 'Aplicativos de Captação',
        'INBI' => 'Backoffice de Inscrição',
        'INBO' => 'Backoffice de Ofertas',
        'INCA' => 'Captar',
        'INFU' => 'Funis e Área do Candidato',
        'INPV' => 'Portais e Vitrines',
    ],
    'Acadêmica' => [
        'JACM' => 'CMS',
        'JACO' => 'Colaborar',
        'JADO' => 'Docência',
        'JAES' => 'Estágio',
        'JAMO' => 'Moodle',
        'JASA' => 'SAG',
    ],
    'Aprendizagem Digital' => [
        'APAP' => 'Aprendizagem',
        'APAV' => 'Avaliação',
        'APCO' => 'Comunicação',
        'APIN' => 'Interação',
        'APPL' => 'Plataforma',
        'APSF' => 'Secretaria e Financeiro',
    ],
    'Atendimento e Serviços' => [
        'ASAT' => 'Atendimento',
        'ASKB' => 'KBots',
        'ASPL' => 'Papa-Léguas',
        'ASR6' => 'Route 66',
    ],
    'Saber' => [
        'SADV' => 'Devengers',
        'SAJO' => 'Joker!',
        'SATT' => 'Team Tregamos',
    ],
];

// Jornadas EXCLUÍDAS (ativo='N', sem times) — ficam no banco mas fora das views.
$JORNADAS_EXCLUIDAS = [
    'Financeira',
    'Pós Graduação',
    'IA',
    'Voomp',
    'Digital Finance',
];

// ── Carrega parâmetros de capacidade ───────────────────────────────────────
if (!is_file($CAPACITY)) {
    fwrite(STDERR, "Arquivo não encontrado: $CAPACITY\n");
    exit(1);
}
$cap = require $CAPACITY;
$devCount  = $cap['dev_count_by_team']               ?? [];
$refProd   = $cap['reference_productivity_by_team']  ?? [];
$prodHours = $cap['productive_hours_by_team']        ?? [];

// ── Leitor xlsx mínimo (mesma técnica de check_apoio_dates.php) ────────────
/** Converte serial Excel (epoch 30/12/1899) para 'Y-m-d'. Vazio se nulo. */
function excelSerialToDate($serial): ?string
{
    if ($serial === '' || $serial === null) return null;
    if (!is_numeric($serial)) return null;
    $days = (int) floor((float)$serial);
    $base = new DateTimeImmutable('1899-12-30');
    return $base->add(new DateInterval("P{$days}D"))->format('Y-m-d');
}

function readSheet(string $file, string $sheetName): array
{
    $z = new ZipArchive();
    if ($z->open($file) !== true) {
        fwrite(STDERR, "Não consegui abrir: $file\n");
        exit(1);
    }
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) { $t .= (string)$r->t; }
            $shared[] = $t;
        }
    }
    $wb   = simplexml_load_string($z->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($z->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $rel) { $relMap[(string)$rel['Id']] = (string)$rel['Target']; }
    $target = null;
    foreach ($wb->sheets->sheet as $s) {
        if ((string)$s['name'] === $sheetName) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $relMap[$rid] ?? null;
            break;
        }
    }
    if (!$target) { fwrite(STDERR, "Aba '$sheetName' não encontrada\n"); exit(1); }
    $sheet = simplexml_load_string($z->getFromName('xl/' . ltrim($target, '/')));
    $z->close();

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)\d+$/', (string)$c['r'], $m);
            $col = $m[1];
            $type = (string)$c['t'];
            $val  = (string)$c->v;
            if ($type === 's') $val = $shared[(int)$val] ?? $val;
            $rows[$r][$col] = $val;
        }
    }
    return $rows;
}

// ── Lê SOMENTE o bloco 2026 (A=Sprint B=DiasUteis C=Serial Ini D=Serial Fim)
$rows = readSheet($XLSX, $SHEET);
$sprints = []; // [ ['nome'=>, 'pi'=>, 'dias'=>, 'ini'=>, 'fim'=>], ... ]
for ($r = 2; $r <= 60; $r++) {
    $nome = trim($rows[$r]['A'] ?? '');
    if ($nome === '') continue;
    // Só aceita sprints no padrão 'SP 26.P.S' (bloco 2026).
    if (!preg_match('/^SP\s+26\.(\d+)\.(\d+)$/', $nome, $m)) continue;
    $sprints[] = [
        'nome' => $nome,
        'pi'   => (int)$m[1],
        'dias' => ($rows[$r]['B'] ?? '') === '' ? null : (int)$rows[$r]['B'],
        'ini'  => excelSerialToDate($rows[$r]['C'] ?? ''),
        'fim'  => excelSerialToDate($rows[$r]['D'] ?? ''),
    ];
}

if (!$sprints) {
    fwrite(STDERR, "Nenhuma sprint 2026 encontrada na aba '$SHEET'.\n");
    exit(1);
}

// ── Agrupa sprints por PI → configuracao_pi ────────────────────────────────
$pis = []; // [ P => ['desc'=>'PI P/26','qtde'=>, 'ini'=>, 'fim'=>] ]
foreach ($sprints as $sp) {
    $p = $sp['pi'];
    if (!isset($pis[$p])) {
        $pis[$p] = ['desc' => "PI {$p}/26", 'qtde' => 0, 'ini' => null, 'fim' => null];
    }
    $pis[$p]['qtde']++;
    if ($sp['ini'] !== null && ($pis[$p]['ini'] === null || $sp['ini'] < $pis[$p]['ini'])) {
        $pis[$p]['ini'] = $sp['ini'];
    }
    if ($sp['fim'] !== null && ($pis[$p]['fim'] === null || $sp['fim'] > $pis[$p]['fim'])) {
        $pis[$p]['fim'] = $sp['fim'];
    }
}
ksort($pis);

// ── Carga transacional idempotente ─────────────────────────────────────────
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();

    // Limpa as 5 tabelas de catálogo (apenas estas; não toca extração/baseline).
    foreach (['sprints', 'configuracao_pi', 'times_composicao', 'times', 'jornada'] as $t) {
        $pdo->exec("DELETE FROM {$t}");
    }

    // 1) Jornadas ativas + times + composição
    $insJornada = $pdo->prepare(
        'INSERT INTO jornada (nome, projeto_baseline, ativo) VALUES (:nome, NULL, :ativo)'
    );
    $insTime = $pdo->prepare(
        'INSERT INTO times (jornada_id, nome, projeto_baseline, projeto_key, ativo)
         VALUES (:jornada_id, :nome, NULL, :projeto_key, :ativo)'
    );
    $insComp = $pdo->prepare(
        'INSERT INTO times_composicao
            (times_id, qtde_dev, horas_dev, media_produtividade_pi, ativo)
         VALUES (:times_id, :qtde_dev, :horas_dev, :media, :ativo)'
    );

    $totTimes = 0;
    $totComp  = 0;
    foreach ($JORNADAS_ATIVAS as $nomeJornada => $times) {
        $insJornada->execute([':nome' => $nomeJornada, ':ativo' => 'S']);
        $jornadaId = (int)$pdo->lastInsertId();

        foreach ($times as $key => $nomeTime) {
            $insTime->execute([
                ':jornada_id'  => $jornadaId,
                ':nome'        => $nomeTime,
                ':projeto_key' => $key,
                ':ativo'       => 'S',
            ]);
            $timeId = (int)$pdo->lastInsertId();
            $totTimes++;

            $insComp->execute([
                ':times_id'  => $timeId,
                ':qtde_dev'  => $devCount[$key]  ?? null,
                ':horas_dev' => $prodHours[$key] ?? null,
                ':media'     => $refProd[$key]   ?? null,
                ':ativo'     => 'S',
            ]);
            $totComp++;
        }
    }

    // Jornadas excluídas (ativo='N', sem times)
    foreach ($JORNADAS_EXCLUIDAS as $nomeJornada) {
        $insJornada->execute([':nome' => $nomeJornada, ':ativo' => 'N']);
    }

    // 2) configuracao_pi (por PI) + mapa P → id
    $insPi = $pdo->prepare(
        'INSERT INTO configuracao_pi (desc_pi, qtde_sprints, data_ini, data_fim, ativo)
         VALUES (:desc, :qtde, :ini, :fim, :ativo)'
    );
    $piIdByNum = [];
    foreach ($pis as $p => $info) {
        $insPi->execute([
            ':desc'  => $info['desc'],
            ':qtde'  => $info['qtde'],
            ':ini'   => $info['ini'],
            ':fim'   => $info['fim'],
            ':ativo' => 'S', // todas as 4 PIs de 2026 são do ano corrente
        ]);
        $piIdByNum[$p] = (int)$pdo->lastInsertId();
    }

    // 3) sprints (catálogo da PI; times_id = NULL)
    $insSprint = $pdo->prepare(
        'INSERT INTO sprints
            (configuracao_pi_id, times_id, nome, dias_uteis, data_ini, data_fim, ativo)
         VALUES (:pi_id, NULL, :nome, :dias, :ini, :fim, :ativo)'
    );
    $totSprints = 0;
    foreach ($sprints as $sp) {
        $insSprint->execute([
            ':pi_id' => $piIdByNum[$sp['pi']],
            ':nome'  => $sp['nome'],
            ':dias'  => $sp['dias'],
            ':ini'   => $sp['ini'],
            ':fim'   => $sp['fim'],
            ':ativo' => 'S',
        ]);
        $totSprints++;
    }

    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, "ERRO na carga, rollback executado: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Relatório de carga ──────────────────────────────────────────────────────
echo str_repeat('=', 70) . "\n";
echo "  SEED DO CATÁLOGO — concluído\n";
echo str_repeat('=', 70) . "\n";
echo "  Jornadas ativas inseridas:   " . count($JORNADAS_ATIVAS) . "\n";
echo "  Jornadas excluídas (ativo=N):" . count($JORNADAS_EXCLUIDAS) . "\n";
echo "  Times inseridos:             {$totTimes}\n";
echo "  Composições inseridas:       {$totComp}\n";
echo "  PIs (configuracao_pi):       " . count($pis) . "\n";
foreach ($pis as $p => $info) {
    echo "     - {$info['desc']}: {$info['qtde']} sprints ({$info['ini']} → {$info['fim']})\n";
}
echo "  Sprints (bloco 2026):        {$totSprints}\n";
echo "\nSeed finalizado com sucesso.\n";

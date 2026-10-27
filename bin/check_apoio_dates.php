<?php
/**
 * bin/check_apoio_dates.php — Relatório de conferência das datas da aba "Apoio"
 * ────────────────────────────────────────────────────────────────────────────
 * ETAPA 2 (v1.5). NÃO grava nada no banco. Apenas lê a aba "Apoio" da
 * planilha Velocity, converte os seriais do Excel para data real (epoch
 * 30/12/1899, que corrige o bug do ano bissexto 1900) e imprime uma tabela
 * de conferência: Sprint · Dias Úteis · Serial Início → Data · Serial Fim → Data.
 *
 * Objetivo: o usuário validar visualmente que nenhuma data diverge da
 * planilha ANTES da carga efetiva (etapa 3 — seed).
 *
 * Uso: php bin/check_apoio_dates.php
 */

$FILE  = __DIR__ . '/../Template Velocity 26-4.xlsx';
$SHEET = 'Apoio';

/** Converte serial Excel (epoch 30/12/1899) para 'Y-m-d'. Vazio se nulo. */
function excelSerialToDate($serial): string
{
    if ($serial === '' || $serial === null) return '';
    if (!is_numeric($serial)) return "(?) $serial";
    // Epoch correto: 30/12/1899. +N dias. Trunca parte fracionária (hora).
    $days = (int) floor((float)$serial);
    $base = new DateTimeImmutable('1899-12-30');
    return $base->add(new DateInterval("P{$days}D"))->format('Y-m-d');
}

// ── Leitor xlsx mínimo (ZipArchive + XML), lê VALOR calculado das células ──
function readSheet(string $file, string $sheetName): array
{
    $z = new ZipArchive();
    if ($z->open($file) !== true) {
        fwrite(STDERR, "Não consegui abrir: $file\n");
        exit(1);
    }
    // shared strings
    $shared = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) { $t .= (string)$r->t; }
            $shared[] = $t;
        }
    }
    // localizar o arquivo da planilha pelo nome
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

    // monta matriz [linha][coluna] = valor calculado
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)\d+$/', (string)$c['r'], $m);
            $col = $m[1];
            $type = (string)$c['t'];
            $val  = (string)$c->v;            // <v> = valor calculado (mesmo em fórmulas)
            if ($type === 's') $val = $shared[(int)$val] ?? $val;
            $rows[$r][$col] = $val;
        }
    }
    return $rows;
}

$rows = readSheet($FILE, $SHEET);

// Blocos de sprints na aba Apoio (conforme cabeçalho lido):
//   2026: A=Sprint B=DiasUteis C=Inicio D=Fim
//   2025: H=Sprint I=DiasUteis J=Inicio K=Fim
//   2024: O=Sprint P=DiasUteis Q=Inicio R=Fim
//   2023: V=Sprint (sem dias/datas no template)
$blocks = [
    '2026' => ['sprint' => 'A', 'dias' => 'B', 'ini' => 'C', 'fim' => 'D'],
    '2025' => ['sprint' => 'H', 'dias' => 'I', 'ini' => 'J', 'fim' => 'K'],
    '2024' => ['sprint' => 'O', 'dias' => 'P', 'ini' => 'Q', 'fim' => 'R'],
];

$totalSprints = 0;
$totalComData = 0;
$suspeitas = [];

foreach ($blocks as $ano => $cols) {
    echo str_repeat('=', 78) . "\n";
    echo "  BLOCO $ano\n";
    echo str_repeat('=', 78) . "\n";
    printf("  %-14s %6s  %-12s %-12s  %-12s %-12s\n",
        'Sprint', 'Dias', 'Serial Ini', 'Data Início', 'Serial Fim', 'Data Fim');
    echo '  ' . str_repeat('-', 74) . "\n";

    // linhas de dados começam em 2 (1 = cabeçalho)
    for ($r = 2; $r <= 60; $r++) {
        $sprint = trim($rows[$r][$cols['sprint']] ?? '');
        if ($sprint === '') continue;
        $dias   = $rows[$r][$cols['dias']] ?? '';
        $serIni = $rows[$r][$cols['ini']]  ?? '';
        $serFim = $rows[$r][$cols['fim']]  ?? '';
        $dIni   = excelSerialToDate($serIni);
        $dFim   = excelSerialToDate($serFim);

        $totalSprints++;
        if ($dIni !== '' && $dFim !== '') {
            $totalComData++;
            // sanity: fim deve ser >= início, e intervalo plausível (<= 30 dias)
            $gap = (strtotime($dFim) - strtotime($dIni)) / 86400;
            if ($gap < 0 || $gap > 30) {
                $suspeitas[] = "$sprint ($ano): intervalo de $gap dias ($dIni → $dFim)";
            }
        }

        printf("  %-14s %6s  %-12s %-12s  %-12s %-12s\n",
            $sprint, (string)$dias, (string)$serIni, $dIni, (string)$serFim, $dFim);
    }
    echo "\n";
}

echo str_repeat('=', 78) . "\n";
echo "  RESUMO\n";
echo str_repeat('=', 78) . "\n";
echo "  Sprints lidas:            $totalSprints\n";
echo "  Com data início e fim:    $totalComData\n";
echo "  Suspeitas (gap <0 ou >30 dias): " . count($suspeitas) . "\n";
foreach ($suspeitas as $s) echo "     ⚠ $s\n";
echo "\n";
echo "  Validação de referência (PI 4/26 — seriais reais da aba Apoio):\n";
echo "     SP 26.4.1 = 46293/46304 → " . excelSerialToDate(46293) . " a " . excelSerialToDate(46304) . " (esperado 2026-09-28 a 2026-10-09)\n";
echo "     SP 26.4.2 = 46307/46318 → " . excelSerialToDate(46307) . " a " . excelSerialToDate(46318) . " (esperado 2026-10-12 a 2026-10-23)\n";
echo "     (Obs.: os seriais 46041/46052 pertencem à SP 26.1.1, não à 26.4.1.)\n";

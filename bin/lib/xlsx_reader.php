<?php
/**
 * bin/lib/xlsx_reader.php — leitor xlsx mínimo compartilhado pelos utilitários CLI
 * (ZipArchive + SimpleXML; lê o VALOR calculado <v> das células, inclusive de fórmulas).
 * Mesma técnica de bin/check_apoio_dates.php e bin/seed_catalogo.php.
 */

/**
 * Lê uma aba e devolve [linha => [coluna => valor]] (strings cruas).
 * Lança RuntimeException se o arquivo/aba não existir.
 */
function xlsxReadSheet(string $file, string $sheetName): array
{
    if (!is_file($file)) {
        throw new RuntimeException("Arquivo não encontrado: $file");
    }
    $z = new ZipArchive();
    if ($z->open($file) !== true) {
        throw new RuntimeException("Não consegui abrir o xlsx: $file");
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
    $nomes  = [];
    foreach ($wb->sheets->sheet as $s) {
        $nomes[] = (string)$s['name'];
        if ((string)$s['name'] === $sheetName) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $relMap[$rid] ?? null;
            break;
        }
    }
    if (!$target) {
        $z->close();
        throw new RuntimeException("Aba '$sheetName' não encontrada. Abas disponíveis: " . implode(', ', $nomes));
    }
    $path = ltrim($target, '/');
    if (!str_starts_with($path, 'xl/')) $path = 'xl/' . $path;
    $sheet = simplexml_load_string($z->getFromName($path));
    $z->close();

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]+)\d+$/', (string)$c['r'], $m)) continue;
            $type = (string)$c['t'];
            if ($type === 'inlineStr') {
                $val = '';
                if (isset($c->is->t)) $val = (string)$c->is->t;
                foreach ($c->is->r ?? [] as $run) { $val .= (string)$run->t; }
            } else {
                $val = (string)$c->v;
                if ($type === 's') $val = $shared[(int)$val] ?? $val;
            }
            $rows[$r][$m[1]] = $val;
        }
    }
    return $rows;
}

/**
 * Serial Excel (epoch 1899-12-30) -> 'Y-m-d H:i:s' em hora de parede (sem fuso).
 * Parte fracionária = hora. Retorna null se vazio/não numérico.
 */
function xlsxSerialToDateTime(?string $serial): ?string
{
    if ($serial === null || $serial === '' || !is_numeric($serial)) return null;
    $secs = (int)round(((float)$serial) * 86400);
    return gmdate('Y-m-d H:i:s', $secs - 2209161600); // 2209161600 = 1970-01-01 - 1899-12-30
}

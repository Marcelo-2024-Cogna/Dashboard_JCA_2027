<?php
/**
 * bin/teste_todos.php - executor unico dos testes bin/teste_*.php
 * ---------------------------------------------------------------------------
 * Roda cada teste num processo PHP separado (o mesmo binario que executa este
 * script), na ordem: legendas, efficiency, fator_carga, debt_load,
 * cache_visao_geral. teste_job_noturno so roda com --job-noturno (exige um
 * mysqld descartavel; veja o cabecalho do proprio teste).
 *
 * Uso: php bin/teste_todos.php [--sem-banco] [--rapido] [--lista] [--job-noturno]
 *        [--host=H] [--porta=P] [--usuario=U]   (estes tres so vao ao job noturno)
 *   --sem-banco    repassa --sem-banco aos testes que aceitam (efficiency,
 *                  fator_carga, debt_load, cache_visao_geral). legendas nao usa banco.
 *   --rapido       = --sem-banco, e so roda os testes que nao usam banco nem
 *                  gravam dentro do projeto. Exclui o job noturno.
 *   --lista        mostra os testes, o que cada um usa/grava, e sai (exit 0).
 *   --job-noturno  inclui teste_job_noturno (mysqld descartavel).
 *
 * Saida: uma linha por teste (PASSOU/FALHOU e tempo) e o total. O texto dos
 * testes que falharam e mostrado logo abaixo da linha. Exit 0 so se todos passarem.
 * Compativel com PHP 7.4 e 8.x.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via CLI.\n");
}

/**
 * arquivo => [aceita --sem-banco, usa banco sem a opcao, grava no projeto, roda no --rapido, descricao]
 * Nenhum dos testes abaixo grava dentro do projeto com --sem-banco e sem --base-url:
 * os arquivos temporarios vao para sys_get_temp_dir(). Sem --sem-banco, os que
 * usam banco fazem so SELECT no banco local. Com --base-url (nao repassado
 * por este executor), teste_cache_visao_geral limparia data/cache.
 */
$testes = [
    'teste_legendas'          => ['sem_banco' => false, 'banco' => false, 'rapido' => true,  'obs' => 'estrutural, sem banco, sem gravacao'],
    'teste_efficiency'        => ['sem_banco' => true,  'banco' => true,  'rapido' => true,  'obs' => 'banco local (SELECT); temp em sys_get_temp_dir'],
    'teste_fator_carga'       => ['sem_banco' => true,  'banco' => true,  'rapido' => true,  'obs' => 'banco local (SELECT); temp em sys_get_temp_dir'],
    'teste_debt_load'         => ['sem_banco' => true,  'banco' => true,  'rapido' => true,  'obs' => 'banco local (SELECT); temp em sys_get_temp_dir'],
    'teste_cache_visao_geral' => ['sem_banco' => true,  'banco' => true,  'rapido' => true,  'obs' => 'banco local (SELECT); temp em sys_get_temp_dir; --base-url gravaria em data/cache (nao usado aqui)'],
];
$jobNoturno = ['sem_banco' => false, 'banco' => true, 'rapido' => false, 'obs' => 'exige mysqld descartavel; so com --job-noturno'];

$opts = getopt('', ['sem-banco', 'rapido', 'lista', 'job-noturno', 'host::', 'porta::', 'usuario::', 'help']);
if (isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    if (preg_match('/Uso:.*?(?=\n \* Saida)/s', $doc, $m)) {
        echo preg_replace('/^ \* ?/m', '', $m[0]) . "\n";
    }
    exit(0);
}
$rapido = array_key_exists('rapido', $opts);
$semBanco = $rapido || array_key_exists('sem-banco', $opts);
$comJob = array_key_exists('job-noturno', $opts);
$lista = array_key_exists('lista', $opts);

if ($rapido && $comJob) {
    fwrite(STDERR, "--rapido e --job-noturno sao incompativeis.\n");
    exit(2);
}

$plano = [];
foreach ($testes as $nome => $c) {
    if ($rapido && !$c['rapido']) continue;
    $plano[$nome] = $c;
}
if ($comJob) {
    $plano['teste_job_noturno'] = $jobNoturno;
}

if ($lista) {
    foreach ($plano as $nome => $c) {
        printf("%-26s %s\n", $nome, $c['obs']);
    }
    if (!$comJob && !$rapido) {
        printf("%-26s %s\n", 'teste_job_noturno', '(nao incluido: use --job-noturno) ' . $jobNoturno['obs']);
    }
    exit(0);
}

echo 'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ")\n";
if (!function_exists('proc_open')) {
    fwrite(STDERR, "proc_open desabilitado neste PHP.\n");
    exit(2);
}

$falhas = 0;
$total = 0;
$inicio = microtime(true);
foreach ($plano as $nome => $c) {
    $args = [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . $nome . '.php'];
    if ($semBanco && $c['sem_banco']) {
        $args[] = '--sem-banco';
    }
    if ($nome === 'teste_job_noturno') {
        foreach (['host', 'porta', 'usuario'] as $k) {
            if (isset($opts[$k]) && is_string($opts[$k]) && $opts[$k] !== '') {
                $args[] = '--' . $k . '=' . $opts[$k];
            }
        }
    }
    $saida = tempnam(sys_get_temp_dir(), 'jca_tt_');
    $t0 = microtime(true);
    $codigo = -1;
    $proc = @proc_open(
        $args,
        [0 => ['file', (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'r'], 1 => ['file', $saida, 'w'], 2 => ['file', $saida, 'a']],
        $pipes,
        dirname(__DIR__)
    );
    if (is_resource($proc)) {
        $codigo = proc_close($proc);
    } else {
        file_put_contents($saida, "Nao foi possivel iniciar o processo PHP.\n");
    }
    $seg = microtime(true) - $t0;
    $total++;
    $ok = $codigo === 0;
    printf("%-7s %-26s %6.1f s\n", $ok ? 'PASSOU' : 'FALHOU', $nome, $seg);
    if (!$ok) {
        $falhas++;
        $txt = (string) file_get_contents($saida);
        echo '        exit code ' . $codigo . "\n";
        foreach (explode("\n", rtrim($txt)) as $linha) {
            echo '        | ' . rtrim($linha) . "\n";
        }
    }
    @unlink($saida);
}

printf("\nTotal: %d teste(s), %d passou, %d falhou, %.1f s\n", $total, $total - $falhas, $falhas, microtime(true) - $inicio);
exit($falhas === 0 ? 0 : 1);

<?php

namespace App\Repositories;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * VelocidadeRepository
 * ────────────────────
 * VELOCIDADE no formato do widget "Team Velocity" do Jira (SOMENTE LEITURA).
 *
 * Mesmos itens e regras do Planejado x Realizado (vw_planejado_realizado_item +
 * tipos de sustentação de config/baseline.php → plan_real_sustentacao_types; itens
 * sem categoria ficam fora). Por sprint s de valor da PI de referência:
 *
 *   escopo_inicial(s) = SPE da baseline dos itens com sprint_ref da baseline = s      (= barra PI Planning)
 *   escopo_final(s)   = SPE da extração, sprint_ref atual = s, status <> 'Cancelado'  (= barra Sprint Planning)
 *   concluido(s)      = SP atuais dos itens ENTREGUES com sprint_ref atual = s        (= barra Entregues)
 *   B = itens do escopo inicial, F = itens do escopo final (casamento por chave + PI):
 *   adicionado(s)     = Σ SPE_extração de F \ B
 *   removido(s)       = Σ SPE_baseline de B \ F
 *   mudanca_escopo(s) = Σ (SPE_extração − SPE_baseline) de B ∩ F
 *   IDENTIDADE: final = inicial + adicionado − removido + mudança (reconciliacao.residuo = 0)
 *
 * velocidade_media = média móvel do concluído nas até 4 últimas sprints FECHADAS terminando
 * em cada sprint; a sprint em andamento repete o último valor fechado (e não entra na média).
 *
 * SPRINTS EXIBIDAS: tipo 'valor' da PI com data_ini <= hoje (fechada: data_fim < hoje); se o
 * filtro sprint existe, trunca nela (inclusive). Filtros/PI/times: PrevisibilidadeRepository::resolverContexto().
 */
class VelocidadeRepository
{
    public const JANELA_MEDIA = 4;
    private const CAMPOS = ['ini', 'fin', 'conc', 'add', 'rem', 'mud'];

    private PDO $pdo;
    private PrevisibilidadeRepository $base;
    private array $tiposSustentacao;

    public function __construct(?PrevisibilidadeRepository $base = null, ?PDO $pdo = null, ?array $tiposSustentacao = null)
    {
        $this->pdo  = $pdo ?? Database::pdo();
        $this->base = $base ?? new PrevisibilidadeRepository($this->pdo);
        $this->tiposSustentacao = $tiposSustentacao
            ?? ((require dirname(__DIR__, 2) . '/config/baseline.php')['plan_real_sustentacao_types'] ?? ['Sustentação']);
    }

    /**
     * @param array{jornada?:string,time?:string,versao?:string,sprint?:string} $filtros
     * @param ?array $ctx contexto já resolvido (resolverContexto); quando null, resolve internamente
     * @throws InvalidArgumentException filtro inexistente/inconsistente
     */
    public function consultar(array $filtros, ?string $hoje = null, ?array $ctx = null): array
    {
        $hoje = $hoje ?? date('Y-m-d');
        $ctx  = $ctx ?? $this->base->resolverContexto($filtros, $hoje);
        $pi   = $ctx['pi'];
        $times = $ctx['times'];
        $sprintFiltro = $ctx['filtros']['sprint'];

        $avisos = [];
        $exibidas = [];
        if ($pi === null) {
            $avisos[] = 'Nenhuma PI cadastrada no catálogo.';
        } else {
            $exibidas = $this->sprintsExibidas((int)$pi['id'], $sprintFiltro, $hoje, $avisos);
            if ($exibidas === []) {
                $avisos[] = 'Nenhuma sprint de valor iniciada nesta PI ainda.';
            }
            if ($times === []) {
                $avisos[] = 'Nenhum time da seleção possui itens extraídos do Jira para ' . $pi['desc_pi'] . '.';
            }
            if ($ctx['times_sem_extracao']) {
                $nomes = array_column($ctx['times_sem_extracao'], 'time');
                $resto = count($nomes) - 8;
                $avisos[] = count($nomes) . ' time(s) sem extração na ' . $pi['desc_pi'] . ' ficaram fora do gráfico: '
                    . implode(', ', array_slice($nomes, 0, 8)) . ($resto > 0 ? " e mais {$resto}" : '') . '.';
            }
        }

        $nomes = array_column($exibidas, 'nome');
        $keys  = array_column($times, 'projeto_key');
        $calcula = $pi !== null && $nomes !== [] && $keys !== [];
        $dados = ['por' => [], 'conc' => [], 'diag' => ['sem_categoria' => [], 'categoria_divergente' => 0]];
        if ($calcula) {
            $dados = $this->agregar($pi['desc_pi'], $nomes, $keys);
        }

        // ── Série por sprint (soma dos times) ───────────────────────────
        $serie = [];
        $linhas = []; // acumuladores por sprint
        foreach ($exibidas as $s) {
            $acc = array_fill_keys(self::CAMPOS, 0.0);
            foreach ($keys as $k) {
                $c = $dados['por'][$k][$s['nome']] ?? null;
                if ($c) foreach (self::CAMPOS as $f) $acc[$f] += $c[$f];
            }
            $linhas[$s['nome']] = $acc;
        }
        // média móvel sobre as fechadas
        $fechadas = [];
        $ultimaMedia = null;
        foreach ($exibidas as $s) {
            $acc = $linhas[$s['nome']];
            if ($s['status'] === 'fechada') {
                $fechadas[] = $acc['conc'];
                $ult = array_slice($fechadas, -self::JANELA_MEDIA);
                $ultimaMedia = round(array_sum($ult) / count($ult), 2);
            }
            $serie[] = ['sprint' => $s['nome'], 'status' => $s['status']] + $this->formatar($acc)
                + ['velocidade_media' => $ultimaMedia];
        }

        $ultimas = [];
        foreach ($exibidas as $s) {
            if ($s['status'] === 'fechada') $ultimas[] = $linhas[$s['nome']]['conc'];
        }
        $ultimas = array_slice($ultimas, -self::JANELA_MEDIA);
        $resumo = [
            'velocidade_ultimas_n' => $ultimas ? round(array_sum($ultimas) / count($ultimas), 2) : null,
            'n_sprints_na_media'   => count($ultimas),
            'soma_concluido_na_media' => round(array_sum($ultimas), 2),
        ];

        // ── Por time: sprints fechadas exibidas (ou a sprint filtrada) ──
        if ($sprintFiltro !== null) {
            $somar = array_values(array_filter($nomes, fn($n) => $n === $sprintFiltro));
        } else {
            $somar = array_column(array_filter($exibidas, fn($s) => $s['status'] === 'fechada'), 'nome');
        }
        $porTime = [];
        $soma = array_fill_keys(self::CAMPOS, 0.0);
        foreach ($times as $t) {
            $acc = array_fill_keys(self::CAMPOS, 0.0);
            foreach ($somar as $n) {
                $c = $dados['por'][$t['projeto_key']][$n] ?? null;
                if ($c) foreach (self::CAMPOS as $f) $acc[$f] += $c[$f];
            }
            foreach (self::CAMPOS as $f) $soma[$f] += $acc[$f];
            $porTime[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                          'tem_baseline' => $t['tem_baseline']] + $this->formatar($acc);
        }
        $total = $this->formatar($soma);

        if ($calcula) {
            $semBase = array_filter($times, fn($t) => !$t['tem_baseline']);
            if ($semBase) {
                $avisos[] = 'Sem baseline importada para: ' . implode(', ', array_column($semBase, 'time')) . '.';
            }
            $totIni = array_sum(array_column($serie, 'escopo_inicial'));
            if ($totIni == 0) {
                $avisos[] = 'Sem itens na baseline para as sprints exibidas e a seleção; o Escopo Inicial ficou zerado '
                    . '(todo o escopo aparece como Adicionado).';
            }
            if ($resumo['n_sprints_na_media'] === 0) {
                $avisos[] = 'Nenhuma sprint fechada na janela: a Velocidade Média não pode ser calculada.';
            }
        }

        $residuoMax = 0.0;
        foreach (array_merge($serie, $porTime) as $x) $residuoMax = max($residuoMax, abs($x['reconciliacao']['residuo']));

        return [
            'janela' => [
                'pi' => $pi['desc_pi'] ?? null,
                'sprints_exibidas' => $exibidas,
                'ate_sprint' => $sprintFiltro,
            ],
            'filtros'  => $ctx['filtros'],
            'serie'    => $serie,
            'resumo'   => $resumo,
            'por_time' => $porTime,
            'total'    => $total,
            'avisos'   => $avisos,
            'times_sem_extracao' => $ctx['times_sem_extracao'],
            'diagnosticos' => [
                'sprints_somadas_por_time' => array_values($somar),
                'residuo_maximo' => round($residuoMax, 2),
                'itens_categoria_divergente' => $dados['diag']['categoria_divergente'],
                'itens_sem_categoria_fora' => $dados['diag']['sem_categoria'],
                'tipos_sustentacao' => array_values($this->tiposSustentacao),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════

    /** Sprints de valor iniciadas da PI (truncadas na sprint do filtro, inclusive). */
    private function sprintsExibidas(int $piId, ?string $ate, string $hoje, array &$avisos): array
    {
        $st = $this->pdo->prepare(
            "SELECT nome, tipo, data_ini, data_fim FROM sprints
              WHERE configuracao_pi_id = :pi AND ativo = 'S' ORDER BY data_ini, nome"
        );
        $st->execute([':pi' => $piId]);
        $todas = $st->fetchAll();

        $limite = null;
        if ($ate !== null) {
            foreach ($todas as $s) {
                if ($s['nome'] === $ate) { $limite = $s; break; }
            }
            if ($limite !== null && $limite['tipo'] !== 'valor') {
                $avisos[] = "A sprint '{$ate}' é do tipo '{$limite['tipo']}'; o gráfico mostra as sprints de valor até a data dela.";
            }
            if ($limite !== null && $limite['data_ini'] !== null && $limite['data_ini'] > $hoje) {
                $avisos[] = "A sprint '{$ate}' ainda não iniciou; o gráfico mostra as sprints de valor já iniciadas até ela.";
            }
        }

        $out = [];
        foreach ($todas as $s) {
            if ($s['tipo'] !== 'valor' || $s['data_ini'] === null || $s['data_ini'] > $hoje) continue;
            if ($limite !== null && ($s['data_ini'] > $limite['data_ini']
                || ($s['data_ini'] === $limite['data_ini'] && strcmp($s['nome'], $limite['nome']) > 0))) continue;
            $out[] = [
                'nome' => $s['nome'],
                'status' => ($s['data_fim'] !== null && $s['data_fim'] < $hoje) ? 'fechada' : 'em_andamento',
                'data_ini' => $s['data_ini'],
                'data_fim' => $s['data_fim'],
            ];
        }
        return $out;
    }

    private function in(string $p, array $vals, array &$par): string
    {
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $ph[] = ":{$p}{$i}"; $par[":{$p}{$i}"] = $v; }
        return implode(',', $ph);
    }

    /**
     * Agrega por projeto_key e sprint os campos brutos (ini, fin, conc, add, rem, mud).
     * Mesma fonte/categorias do PlanejadoRealizadoRepository.
     */
    private function agregar(string $descPi, array $sprints, array $keys): array
    {
        $par = [':pi' => $descPi];
        $tipos = $this->in('tp', $this->tiposSustentacao ?: [''], $par);
        $s = $this->in('sp', $sprints, $par);
        $k = $this->in('pk', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT origem, chave, projeto_key, sprint_ref, status, spe, sp, entregue, categoria, tipo_item
               FROM (SELECT v.origem, v.chave, v.projeto_key, v.pi, v.tipo_item, v.status, v.sprint_ref, v.spe, v.sp, v.entregue,
                            CASE WHEN v.tipo_item IN ($tipos) THEN 'sustentacao' ELSE v.categoria END AS categoria
                       FROM vw_planejado_realizado_item v) x
              WHERE pi = :pi AND sprint_ref IN ($s) AND projeto_key IN ($k)"
        );
        $st->execute($par);

        $B = []; $F = []; $conc = []; $semCat = [];
        foreach ($st->fetchAll() as $r) {
            $key = $r['projeto_key']; $sp = $r['sprint_ref']; $ch = $r['chave'];
            if ($r['categoria'] === null) {
                $id = $r['origem'] . '|' . ($r['tipo_item'] ?? '(sem tipo)');
                $semCat[$id] = ($semCat[$id] ?? 0) + 1;
                continue;
            }
            $spe = (float)($r['spe'] ?? 0);
            if ($r['origem'] === 'baseline') {
                $B[$key][$sp][$ch]['v'] = ($B[$key][$sp][$ch]['v'] ?? 0.0) + $spe;
                $B[$key][$sp][$ch]['c'] = $r['categoria'];
            } else {
                if (($r['status'] ?? '') !== 'Cancelado') {
                    $F[$key][$sp][$ch]['v'] = ($F[$key][$sp][$ch]['v'] ?? 0.0) + $spe;
                    $F[$key][$sp][$ch]['c'] = $r['categoria'];
                }
                if ((int)$r['entregue'] === 1) {
                    $conc[$key][$sp] = ($conc[$key][$sp] ?? 0.0) + (float)($r['sp'] ?? 0);
                }
            }
        }

        $por = []; $diverg = 0;
        foreach ($keys as $key) {
            foreach ($sprints as $sp) {
                $b = $B[$key][$sp] ?? []; $f = $F[$key][$sp] ?? [];
                $c = array_fill_keys(self::CAMPOS, 0.0);
                foreach ($b as $ch => $x) {
                    $c['ini'] += $x['v'];
                    if (isset($f[$ch])) {
                        $c['mud'] += $f[$ch]['v'] - $x['v'];
                        if ($f[$ch]['c'] !== $x['c']) $diverg++;
                    } else {
                        $c['rem'] += $x['v'];
                    }
                }
                foreach ($f as $ch => $x) {
                    $c['fin'] += $x['v'];
                    if (!isset($b[$ch])) $c['add'] += $x['v'];
                }
                $c['conc'] = $conc[$key][$sp] ?? 0.0;
                $por[$key][$sp] = $c;
            }
        }
        $diagSem = [];
        foreach ($semCat as $id => $n) {
            [$origem, $tipo] = explode('|', $id, 2);
            $diagSem[] = ['origem' => $origem, 'tipo_item' => $tipo, 'itens' => $n];
        }
        return ['por' => $por, 'diag' => ['sem_categoria' => $diagSem, 'categoria_divergente' => $diverg]];
    }

    /** Acumulador bruto → campos da resposta (+ resíduo da identidade). */
    private function formatar(array $a): array
    {
        $r = fn($v) => round((float)$v, 2);
        $ini = $r($a['ini']); $fin = $r($a['fin']); $add = $r($a['add']); $rem = $r($a['rem']); $mud = $r($a['mud']);
        return [
            'escopo_inicial' => $ini,
            'escopo_final'   => $fin,
            'concluido'      => $r($a['conc']),
            'adicionado'     => $add,
            'removido'       => $rem,
            'mudanca_escopo' => $mud,
            'reconciliacao'  => ['residuo' => $r($a['fin'] - ($a['ini'] + $a['add'] - $a['rem'] + $a['mud']))],
        ];
    }
}

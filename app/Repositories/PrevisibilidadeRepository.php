<?php

namespace App\Repositories;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * PrevisibilidadeRepository
 * ─────────────────────────
 * Previsibilidade por time (SOMENTE LEITURA), sobre vw_previsibilidade
 * (grão projeto_key, jornada, time, pi, sprint_ref).
 *
 * Recebe os filtros do header (jornada, time, versao = desc_pi, sprint),
 * valida contra o catálogo do BANCO (InvalidArgumentException → HTTP 400),
 * resolve a JANELA de sprints e agrega por time + total:
 *
 *   committed           = SUM(baseline.story_point_estimate) dos itens de valor planejados p/ a janela
 *   delivered_planned   = SUM(extração.story_points ATUAIS) desses itens hoje entregues
 *   previsibilidade_pct = delivered_planned / NULLIF(committed, 0) * 100
 *   variacao_estimativa = delivered_planned − SPE da baseline dos mesmos itens entregues
 *   baldes              = destino do committed (transbordo), ver destino()
 *
 * JANELA
 *   - sprint informada → exatamente essa sprint (mesmo aberta), origem 'filtro';
 *   - senão → sprints de tipo 'valor' da PI com data_fim < hoje (fechadas),
 *     origem 'fechadas'.
 *   Item sem sprint ou de sprint fora da janela não entra (sprint_ref NULL / futura).
 *
 * PI: sem 'versao' usa a PI corrente (a que contém hoje; senão a de maior
 * data_fim) e marca filtros.versao_padrao = true.
 *
 * TIMES: entram apenas times ATIVOS com itens na EXTRAÇÃO da PI (sem extração
 * não há "realizado" — evitaria um 0% falso). Os demais da seleção são
 * listados em times_sem_extracao.
 *
 * Reutilizável pelos próximos gráficos (Planejado x Realizado, Lead Time,
 * Produtividade): resolverContexto() devolve PI/janela/times já validados.
 */
class PrevisibilidadeRepository
{
    public const META = 80;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * @param array{jornada?:string,time?:string,versao?:string,sprint?:string} $filtros
     * @param string|null $hoje 'Y-m-d' (injetável p/ testes; padrão = hoje)
     * @throws InvalidArgumentException filtro inexistente/inconsistente
     */
    public function consultar(array $filtros, ?string $hoje = null): array
    {
        $ctx = $this->resolverContexto($filtros, $hoje);

        $avisos = [];
        $porTime = [];
        $total = $this->vazio();

        if ($ctx['pi'] === null) {
            $avisos[] = 'Nenhuma PI cadastrada no catálogo.';
        } elseif ($ctx['sprints'] === []) {
            $avisos[] = 'Nenhuma sprint de valor fechada nesta PI ainda. Escolha uma sprint no filtro ou aguarde o fechamento.';
        }

        $times = $ctx['times'];          // com extração na PI
        if ($ctx['pi'] !== null && $times === []) {
            $avisos[] = 'Nenhum time da seleção possui itens extraídos do Jira para ' . $ctx['pi']['desc_pi'] . '.';
        }

        if ($ctx['pi'] !== null && $ctx['sprints'] !== [] && $times !== []) {
            $keys = array_column($times, 'projeto_key');
            $agg  = $this->agregar($ctx['pi']['desc_pi'], $ctx['sprints'], $keys, true);
            $dest = $this->destino($ctx['pi']['desc_pi'], $ctx['sprints'], $keys);
            foreach ($times as $t) {
                $r = $agg[$t['projeto_key']] ?? null;
                $porTime[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                              'tem_baseline' => $t['tem_baseline']]
                           + $this->linha($r, $dest[$t['projeto_key']] ?? $this->destinoVazio());
            }
            $destTotal = $this->destinoVazio();
            foreach ($dest as $d) $destTotal = $this->somarDestino($destTotal, $d);
            $total = $this->linha($this->agregar($ctx['pi']['desc_pi'], $ctx['sprints'], $keys, false)['*'] ?? null, $destTotal);

            $semBaseline = array_filter($porTime, fn($x) => !$x['tem_baseline']);
            if ($semBaseline) {
                $avisos[] = 'Sem baseline importada para: ' . implode(', ', array_column($semBaseline, 'time')) . '.';
            }
            $ajustados = $this->itensAjustados((int)$ctx['pi']['id'], $ctx['pi']['desc_pi'], $ctx['sprints'], $keys);
            if ($ajustados > 0) {
                $avisos[] = $ajustados . ' item(ns) da baseline desta seleção foram ajustados após a carga (ver trilha de auditoria).';
            }
            if ($total['committed'] == 0) {
                $avisos[] = 'Sem itens comprometidos (baseline) na janela para a seleção; a previsibilidade não pode ser calculada.';
            }
        } else {
            foreach ($times as $t) {
                $porTime[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                              'tem_baseline' => $t['tem_baseline']] + $this->linha(null);
            }
        }

        return [
            'janela' => [
                'origem'  => $ctx['origem'],
                'pi'      => $ctx['pi']['desc_pi'] ?? null,
                'sprints' => $ctx['sprints'],
                'detalhe' => $ctx['sprints_detalhe'],
            ],
            'filtros'   => $ctx['filtros'],
            'por_time'  => $porTime,
            'total'     => $total,
            'meta'      => self::META,
            'avisos'    => $avisos,
            'times_sem_extracao' => $ctx['times_sem_extracao'],
        ];
    }

    /**
     * Valida os filtros e resolve PI, janela e times (base comum dos gráficos).
     *
     * @return array{pi:?array,origem:string,sprints:string[],sprints_detalhe:array,
     *               times:array,times_sem_extracao:array,filtros:array}
     */
    public function resolverContexto(array $filtros, ?string $hoje = null): array
    {
        $hoje    = $hoje ?? date('Y-m-d');
        $jornada = trim((string)($filtros['jornada'] ?? ''));
        $time    = trim((string)($filtros['time'] ?? ''));
        $versao  = trim((string)($filtros['versao'] ?? ''));
        $sprint  = trim((string)($filtros['sprint'] ?? ''));

        // ── PI ───────────────────────────────────────────────────────────
        $pis = $this->pdo->query(
            "SELECT id_versao_corrigida AS id, desc_pi, data_ini, data_fim
               FROM configuracao_pi WHERE ativo = 'S' ORDER BY data_ini, desc_pi"
        )->fetchAll();

        $pi = null;
        $versaoPadrao = false;
        if ($versao !== '') {
            foreach ($pis as $p) {
                if ($p['desc_pi'] === $versao) { $pi = $p; break; }
            }
            if ($pi === null) {
                throw new InvalidArgumentException("Versão corrigida (PI) inexistente: '{$versao}'.");
            }
        } elseif ($pis) {
            $versaoPadrao = true;
            foreach ($pis as $p) {
                if ($p['data_ini'] && $p['data_fim'] && $p['data_ini'] <= $hoje && $hoje <= $p['data_fim']) { $pi = $p; break; }
            }
            if ($pi === null) {
                foreach ($pis as $p) {
                    if ($pi === null || (string)$p['data_fim'] > (string)$pi['data_fim']) $pi = $p;
                }
            }
        }

        // ── Jornada / Time ───────────────────────────────────────────────
        if ($jornada !== '') {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM jornada WHERE ativo = 'S' AND nome = :n");
            $st->execute([':n' => $jornada]);
            if ((int)$st->fetchColumn() === 0) {
                throw new InvalidArgumentException("Jornada inexistente ou inativa: '{$jornada}'.");
            }
        }
        if ($time !== '') {
            $sql = "SELECT COUNT(*) FROM times t JOIN jornada j ON j.id_jornada = t.jornada_id
                     WHERE t.ativo = 'S' AND j.ativo = 'S' AND t.nome = :t";
            $par = [':t' => $time];
            if ($jornada !== '') { $sql .= ' AND j.nome = :j'; $par[':j'] = $jornada; }
            $st = $this->pdo->prepare($sql);
            $st->execute($par);
            if ((int)$st->fetchColumn() === 0) {
                throw new InvalidArgumentException($jornada !== ''
                    ? "Time '{$time}' inexistente, inativo ou não pertence à jornada '{$jornada}'."
                    : "Time inexistente ou inativo: '{$time}'.");
            }
        }

        // ── Sprints da PI + janela ───────────────────────────────────────
        $detalhe = [];
        $janela = [];
        $origem = 'fechadas';
        if ($pi !== null) {
            $st = $this->pdo->prepare(
                "SELECT nome, tipo, data_ini, data_fim FROM sprints
                  WHERE configuracao_pi_id = :pi AND ativo = 'S' ORDER BY data_ini, nome"
            );
            $st->execute([':pi' => $pi['id']]);
            $sprintsPi = $st->fetchAll();
            foreach ($sprintsPi as &$s) {
                $s['fechada'] = $s['data_fim'] !== null && $s['data_fim'] < $hoje;
            }
            unset($s);

            if ($sprint !== '') {
                $achada = null;
                foreach ($sprintsPi as $s) if ($s['nome'] === $sprint) { $achada = $s; break; }
                if ($achada === null) {
                    throw new InvalidArgumentException("Sprint '{$sprint}' inexistente na {$pi['desc_pi']}.");
                }
                $origem = 'filtro';
                $detalhe = [$achada];
            } else {
                $detalhe = array_values(array_filter($sprintsPi, fn($s) => $s['tipo'] === 'valor' && $s['fechada']));
            }
            $janela = array_column($detalhe, 'nome');
        } elseif ($sprint !== '') {
            throw new InvalidArgumentException("Sprint '{$sprint}' inexistente (nenhuma PI cadastrada).");
        }

        // ── Times da seleção (+ presença de extração/baseline na PI) ─────
        $times = []; $semExtracao = [];
        if ($pi !== null) {
            $sql = "SELECT j.nome AS jornada, t.nome AS time, t.projeto_key,
                           EXISTS(SELECT 1 FROM extracao_jira e
                                   WHERE e.ativo = 'S' AND e.configuracao_pi_id = :pi1
                                     AND SUBSTRING_INDEX(e.chave, '-', 1) = t.projeto_key) AS tem_extracao,
                           EXISTS(SELECT 1 FROM baseline b
                                   WHERE b.ativo = 'S' AND b.configuracao_pi_id = :pi2
                                     AND SUBSTRING_INDEX(b.chave, '-', 1) = t.projeto_key) AS tem_baseline
                      FROM times t JOIN jornada j ON j.id_jornada = t.jornada_id
                     WHERE t.ativo = 'S' AND j.ativo = 'S'
                       AND t.projeto_key IS NOT NULL AND t.projeto_key <> ''";
            $par = [':pi1' => $pi['id'], ':pi2' => $pi['id']];
            if ($jornada !== '') { $sql .= ' AND j.nome = :j'; $par[':j'] = $jornada; }
            if ($time !== '')    { $sql .= ' AND t.nome = :t'; $par[':t'] = $time; }
            $sql .= ' ORDER BY j.nome, t.nome';
            $st = $this->pdo->prepare($sql);
            $st->execute($par);
            foreach ($st->fetchAll() as $r) {
                $item = ['jornada' => $r['jornada'], 'time' => $r['time'], 'projeto_key' => $r['projeto_key'],
                         'tem_baseline' => (bool)$r['tem_baseline']];
                if ($r['tem_extracao']) $times[] = $item; else $semExtracao[] = $item;
            }
        }

        return [
            'pi'      => $pi,
            'origem'  => $origem,
            'sprints' => $janela,
            'sprints_detalhe' => array_map(fn($s) => [
                'nome' => $s['nome'], 'tipo' => $s['tipo'], 'data_ini' => $s['data_ini'],
                'data_fim' => $s['data_fim'], 'fechada' => (bool)$s['fechada'],
            ], $detalhe),
            'times'   => $times,
            'times_sem_extracao' => $semExtracao,
            'filtros' => [
                'jornada' => $jornada !== '' ? $jornada : null,
                'time'    => $time    !== '' ? $time    : null,
                'versao'  => $pi['desc_pi'] ?? null,
                'versao_padrao' => $versaoPadrao,
                'sprint'  => $sprint  !== '' ? $sprint  : null,
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Soma a view na janela. porTime=true → indexado por projeto_key;
     * false → uma única chave '*' (total da seleção).
     */
    private function agregar(string $descPi, array $sprints, array $projetoKeys, bool $porTime): array
    {
        // Com prepares nativos o mesmo placeholder nomeado não pode repetir: um conjunto por uso.
        $phS = []; $phB1 = []; $phB2 = []; $phK = []; $par = [':pi' => $descPi];
        foreach (array_values($sprints) as $i => $s) {
            $phS[] = ":s$i";   $par[":s$i"]  = $s;
            $phB1[] = ":ba$i"; $par[":ba$i"] = $s;
            $phB2[] = ":bb$i"; $par[":bb$i"] = $s;
        }
        foreach (array_values($projetoKeys) as $i => $k)  { $phK[] = ":k$i"; $par[":k$i"] = $k; }
        $fora = fn(string $in) => '(sprint_ref_baseline IS NULL OR sprint_ref_baseline NOT IN (' . $in . '))';

        // ESCOPO ADICIONADO (semântica v1.0): item da extração na janela que não está
        // na baseline JANELADA = fora da baseline (scope_add_sp) + presente na baseline
        // mas com sprint de baseline fora da janela (scope_realocado_sp).
        $sql = 'SELECT ' . ($porTime ? 'projeto_key' : "'*' AS projeto_key") . ',
                       SUM(committed_spe)         AS committed,
                       SUM(delivered_planned_sp)  AS delivered_planned,
                       SUM(variacao_estimativa)   AS variacao_estimativa,
                       SUM(scope_add_sp)          AS scope_sem_baseline,
                       SUM(CASE WHEN ' . $fora(implode(',', $phB1)) . ' THEN scope_realocado_sp ELSE 0 END) AS scope_outra_sprint,
                       SUM(delivered_total_sp)    AS delivered_total,
                       SUM(committed_itens)         AS i_committed,
                       SUM(delivered_planned_itens) AS i_delivered_planned,
                       SUM(scope_add_itens)         AS i_scope_sem_baseline,
                       SUM(CASE WHEN ' . $fora(implode(',', $phB2)) . ' THEN scope_realocado_itens ELSE 0 END) AS i_scope_outra_sprint,
                       SUM(delivered_total_itens)   AS i_delivered_total,
                       ROUND(SUM(delivered_planned_sp) / NULLIF(SUM(committed_spe), 0) * 100, 1) AS previsibilidade_pct
                  FROM vw_previsibilidade
                 WHERE pi = :pi
                   AND sprint_ref IN (' . implode(',', $phS) . ')
                   AND projeto_key IN (' . implode(',', $phK) . ')'
             . ($porTime ? ' GROUP BY projeto_key' : '');
        $st = $this->pdo->prepare($sql);
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if ($r['projeto_key'] !== null) $out[$r['projeto_key']] = $r;
        }
        return $out;
    }

    /**
     * Transparência: quantos itens de VALOR da baseline planejados para a janela
     * (mesmo universo do committed) têm registro na trilha baseline_ajuste
     * (qualquer origem: formal, sql_direto ou regularizado). Casamento por PI + chave.
     * Não altera nenhum número da previsibilidade; só alimenta o campo 'avisos'.
     */
    public function itensAjustados(int $piId, string $descPi, array $sprints, array $projetoKeys): int
    {
        $phS = []; $phK = []; $par = [':pi' => $descPi, ':pid' => $piId];
        foreach (array_values($sprints) as $i => $s) { $phS[] = ":s$i"; $par[":s$i"] = $s; }
        foreach (array_values($projetoKeys) as $i => $k) { $phK[] = ":k$i"; $par[":k$i"] = $k; }
        $st = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT v.chave)
               FROM vw_previsibilidade_item v
              WHERE v.pi = :pi
                AND v.sprint_ref_baseline IN (' . implode(',', $phS) . ')
                AND v.projeto_key IN (' . implode(',', $phK) . ')
                AND EXISTS (SELECT 1 FROM baseline_ajuste a WHERE a.configuracao_pi_id = :pid AND a.chave = v.chave)'
        );
        $st->execute($par);
        return (int)$st->fetchColumn();
    }

    public const BALDES = [
        'entregue_na_janela', 'entregue_apos_janela', 'transbordo_aberto',
        'nao_concluido_na_janela', 'devolvido_backlog', 'cancelado', 'fora_da_extracao',
    ];

    /** 'SP 26.4.2' → [26,4,2] (comparação por TUPLA, não por texto); NULL se fora do formato. */
    private static function tupla(?string $ref): ?array
    {
        if ($ref === null || !preg_match('/(\d+)\.(\d+)\.(\d+)/', $ref, $m)) return null;
        return [(int)$m[1], (int)$m[2], (int)$m[3]];
    }

    /**
     * DESTINO DO COMMITTED por time (projeto_key): classifica cada item planejado
     * (baseline de valor, sprint_ref_baseline na janela) pela situação ATUAL na
     * extração, em baldes mutuamente excludentes (soma dos SPE = committed):
     *   fora_da_extracao → cancelado → (entregue: na janela | após a janela)
     *   → (não entregue: backlog | transbordo aberto | não concluído na janela).
     * "Após a janela" = tupla ano.pi.seq do sprint_ref atual > maior sprint da janela;
     * sprint atual igual/anterior à maior da janela conta como "na janela" e item
     * entregue sem sprint cai em entregue_na_janela. Também devolve o diagnóstico
     * dos entregues com story_points NULL/0 e SPE preenchido na extração (sem fallback).
     */
    private function destino(string $descPi, array $sprints, array $projetoKeys): array
    {
        $maxJanela = null;
        foreach ($sprints as $s) {
            $t = self::tupla($s);
            if ($t !== null && ($maxJanela === null || $t > $maxJanela)) $maxJanela = $t;
        }
        $phS = []; $phK = []; $par = [':pi' => $descPi];
        foreach (array_values($sprints) as $i => $s) { $phS[] = ":s$i"; $par[":s$i"] = $s; }
        foreach (array_values($projetoKeys) as $i => $k) { $phK[] = ":k$i"; $par[":k$i"] = $k; }
        $st = $this->pdo->prepare(
            'SELECT projeto_key, chave, spe_baseline, na_extracao, status_atual, sprint_ref_atual,
                    sp_atual, spe_atual, entregue_atual, cancelado_atual
               FROM vw_previsibilidade_item
              WHERE pi = :pi AND sprint_ref_baseline IN (' . implode(',', $phS) . ')
                AND projeto_key IN (' . implode(',', $phK) . ')'
        );
        $st->execute($par);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $d = &$out[$r['projeto_key']];
            $d ??= $this->destinoVazio();
            $spe = (float)$r['spe_baseline'];
            $atual = self::tupla($r['sprint_ref_atual']);
            $posterior = $atual !== null && $maxJanela !== null && $atual > $maxJanela;

            if (!(int)$r['na_extracao'])        $b = 'fora_da_extracao';
            elseif ((int)$r['cancelado_atual']) $b = 'cancelado';
            elseif ((int)$r['entregue_atual'])  $b = $posterior ? 'entregue_apos_janela' : 'entregue_na_janela';
            elseif ($r['sprint_ref_atual'] === null) $b = 'devolvido_backlog';
            elseif ($posterior)                 $b = 'transbordo_aberto';
            else                                $b = 'nao_concluido_na_janela';

            $d['baldes'][$b]['sp_baseline'] += $spe;
            $d['baldes'][$b]['itens']++;
            if ((int)$r['entregue_atual']) {
                $d['baldes'][$b]['sp_atual'] += (float)$r['sp_atual'];
                if (((float)$r['sp_atual']) == 0.0 && (float)$r['spe_atual'] > 0) {
                    $d['diagnostico_sp_nulo']['itens']++;
                    $d['diagnostico_sp_nulo']['spe_extracao'] += (float)$r['spe_atual'];
                    $d['diagnostico_sp_nulo']['spe_baseline'] += $spe;
                }
            }
            unset($d);
        }
        return $out;
    }

    private function destinoVazio(): array
    {
        $baldes = [];
        foreach (self::BALDES as $b) $baldes[$b] = ['sp_baseline' => 0.0, 'itens' => 0, 'sp_atual' => 0.0];
        return ['baldes' => $baldes, 'diagnostico_sp_nulo' => ['itens' => 0, 'spe_extracao' => 0.0, 'spe_baseline' => 0.0]];
    }

    private function somarDestino(array $a, array $b): array
    {
        foreach (self::BALDES as $k) {
            foreach (['sp_baseline', 'itens', 'sp_atual'] as $f) $a['baldes'][$k][$f] += $b['baldes'][$k][$f];
        }
        foreach (['itens', 'spe_extracao', 'spe_baseline'] as $f) $a['diagnostico_sp_nulo'][$f] += $b['diagnostico_sp_nulo'][$f];
        return $a;
    }

    /** Formata uma linha agregada (ou zeros quando não há linhas). */
    private function linha(?array $r, ?array $dest = null): array
    {
        $dest ??= $this->destinoVazio();
        $r2 = fn($v) => round((float)$v, 2);

        $baldes = [];
        foreach (self::BALDES as $k) {
            $x = $dest['baldes'][$k];
            $baldes[$k] = ['sp_baseline' => $r2($x['sp_baseline']), 'itens' => (int)$x['itens']];
            if ($k === 'entregue_na_janela' || $k === 'entregue_apos_janela') $baldes[$k]['sp_atual'] = $r2($x['sp_atual']);
        }
        $committed = $r === null ? 0.0 : $r2($r['committed']);
        $tSp    = $baldes['entregue_apos_janela']['sp_baseline'] + $baldes['transbordo_aberto']['sp_baseline'];
        $tItens = $baldes['entregue_apos_janela']['itens'] + $baldes['transbordo_aberto']['itens'];
        $dg = $dest['diagnostico_sp_nulo'];

        return [
            'committed'           => $committed,
            'delivered_planned'   => $r === null ? 0.0 : $r2($r['delivered_planned']),
            'previsibilidade_pct' => $r === null || $r['previsibilidade_pct'] === null ? null : (float)$r['previsibilidade_pct'],
            'variacao_estimativa' => $r === null ? 0.0 : $r2($r['variacao_estimativa']),
            'delivered_total'     => $r === null ? 0.0 : $r2($r['delivered_total']),
            'itens' => [
                'committed'         => $r === null ? 0 : (int)$r['i_committed'],
                'delivered_planned' => $r === null ? 0 : (int)$r['i_delivered_planned'],
                'delivered_total'   => $r === null ? 0 : (int)$r['i_delivered_total'],
            ],
            'baldes' => $baldes,
            'transbordo' => [
                'sp_baseline' => round($tSp, 2), 'itens' => $tItens,
                'pct' => $committed > 0 ? round($tSp / $committed * 100, 1) : null,
            ],
            'escopo_adicionado' => $r === null
                ? ['sp' => 0.0, 'itens' => 0, 'sem_baseline' => ['sp' => 0.0, 'itens' => 0], 'baseline_outra_sprint' => ['sp' => 0.0, 'itens' => 0]]
                : [
                    'sp'    => $r2($r['scope_sem_baseline'] + $r['scope_outra_sprint']),
                    'itens' => (int)$r['i_scope_sem_baseline'] + (int)$r['i_scope_outra_sprint'],
                    'sem_baseline'          => ['sp' => $r2($r['scope_sem_baseline']), 'itens' => (int)$r['i_scope_sem_baseline']],
                    'baseline_outra_sprint' => ['sp' => $r2($r['scope_outra_sprint']), 'itens' => (int)$r['i_scope_outra_sprint']],
                ],
            // PONTO EM ABERTO (sem fallback aplicado): entregues do planejado com SP NULL/0 e SPE > 0 na extração.
            'diagnostico_sp_nulo' => [
                'itens' => (int)$dg['itens'], 'spe_extracao' => $r2($dg['spe_extracao']), 'spe_baseline' => $r2($dg['spe_baseline']),
            ],
        ];
    }

    private function vazio(): array
    {
        return $this->linha(null);
    }
}

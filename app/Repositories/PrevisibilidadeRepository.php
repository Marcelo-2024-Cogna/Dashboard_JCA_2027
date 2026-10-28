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
 *   previsibilidade_pct = delivered_planned / NULLIF(committed, 0) * 100
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
            $agg = $this->agregar($ctx['pi']['desc_pi'], $ctx['sprints'], array_column($times, 'projeto_key'), true);
            foreach ($times as $t) {
                $r = $agg[$t['projeto_key']] ?? null;
                $porTime[] = ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                              'tem_baseline' => $t['tem_baseline']] + $this->linha($r);
            }
            $total = $this->linha($this->agregar($ctx['pi']['desc_pi'], $ctx['sprints'], array_column($times, 'projeto_key'), false)['*'] ?? null);

            $semBaseline = array_filter($porTime, fn($x) => !$x['tem_baseline']);
            if ($semBaseline) {
                $avisos[] = 'Sem baseline importada para: ' . implode(', ', array_column($semBaseline, 'time')) . '.';
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
        $inB1 = implode(',', $phB1);
        $inB2 = implode(',', $phB2);

        // ESCOPO ADICIONADO (semântica v1.0): item da extração na janela que não está
        // na baseline JANELADA = fora da baseline (scope_add_sp) + presente na baseline
        // mas com sprint de baseline fora da janela (scope_realocado_sp).
        $sql = 'SELECT ' . ($porTime ? 'projeto_key' : "'*' AS projeto_key") . ',
                       SUM(committed_sp)          AS committed,
                       SUM(committed_spe)         AS committed_spe,
                       SUM(delivered_planned_sp)  AS delivered_planned,
                       SUM(scope_add_sp)          AS scope_add_fora_baseline,
                       SUM(scope_add_sp)
                         + SUM(CASE WHEN sprint_ref_baseline IS NULL OR sprint_ref_baseline NOT IN (' . $inB1 . ')
                                    THEN scope_realocado_sp ELSE 0 END) AS scope_add,
                       SUM(delivered_total_sp)    AS delivered_total,
                       SUM(committed_itens)         AS i_committed,
                       SUM(delivered_planned_itens) AS i_delivered_planned,
                       SUM(scope_add_itens)
                         + SUM(CASE WHEN sprint_ref_baseline IS NULL OR sprint_ref_baseline NOT IN (' . $inB2 . ')
                                    THEN scope_realocado_itens ELSE 0 END) AS i_scope_add,
                       SUM(delivered_total_itens)   AS i_delivered_total,
                       ROUND(SUM(delivered_planned_sp) / NULLIF(SUM(committed_sp), 0) * 100, 1) AS previsibilidade_pct
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

    /** Formata uma linha agregada (ou zeros quando não há linhas). */
    private function linha(?array $r): array
    {
        if ($r === null) return $this->vazio();
        return [
            'committed'           => round((float)$r['committed'], 2),
            'committed_spe'       => round((float)$r['committed_spe'], 2),
            'delivered_planned'   => round((float)$r['delivered_planned'], 2),
            'previsibilidade_pct' => $r['previsibilidade_pct'] === null ? null : (float)$r['previsibilidade_pct'],
            'scope_add'           => round((float)$r['scope_add'], 2),
            'scope_add_fora_baseline' => round((float)$r['scope_add_fora_baseline'], 2),
            'delivered_total'     => round((float)$r['delivered_total'], 2),
            'itens' => [
                'committed'         => (int)$r['i_committed'],
                'delivered_planned' => (int)$r['i_delivered_planned'],
                'scope_add'         => (int)$r['i_scope_add'],
                'delivered_total'   => (int)$r['i_delivered_total'],
            ],
        ];
    }

    private function vazio(): array
    {
        return [
            'committed' => 0.0, 'committed_spe' => 0.0, 'delivered_planned' => 0.0,
            'previsibilidade_pct' => null, 'scope_add' => 0.0, 'scope_add_fora_baseline' => 0.0, 'delivered_total' => 0.0,
            'itens' => ['committed' => 0, 'delivered_planned' => 0, 'scope_add' => 0, 'delivered_total' => 0],
        ];
    }
}

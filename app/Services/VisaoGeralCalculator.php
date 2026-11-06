<?php

namespace App\Services;

use App\Repositories\LeadTimeRepository;

/**
 * VisaoGeralCalculator
 * ────────────────────
 * Núcleo PURO da Visão Geral (sem PDO, sem relógio). Recebe as linhas da Q1
 * (itens de `vw_item`), o catálogo de sprints (Q0), o config e o `$hoje`, e
 * devolve os blocos calculados de cada gadget. Toda a decisão de negócio dos
 * gadgets vive aqui; o VisaoGeralRepository faz apenas a E/S e a composição.
 *
 * Nenhuma função abaixo consulta o banco: quando precisa de um número que já
 * é regra de outra tela (Aceitos da Velocidade, Produtividade por sprint, Cycle
 * time), recebe o resultado pronto do repositório como parâmetro.
 *
 * Convenções das linhas da Q1 (array associativo por item):
 *   chave, projeto_key, tipo_item, status, categoria_status, sprint (texto),
 *   sprint_ref, pai, story_points (?float), tempo_gasto_horas (?float),
 *   is_entrega_valor (0|1), entregue (0|1),
 *   criado, inicio_fluxo, fim_fluxo, inicio_homologacao, fim_homologacao,
 *   inicio_implantacao, fim_implantacao,
 *   dias_desenvolvimento, dias_homologacao, dias_implantacao (?int),
 *   categoria ('valor'|'sustentacao'|null) — derivada pelo repositório
 *             (tipo_item em plan_real_sustentacao_types → sustentacao;
 *              senão is_entrega_valor=1 → valor; senão null).
 *
 * Datas chegam como 'Y-m-d' ou 'Y-m-d H:i:s' (só a parte Y-m-d conta nos cálculos
 * de dias úteis, via App\Services\DiasUteis) ou null.
 *
 * @see design.md §D.4, §D.5, §D.6, §D.9
 */
class VisaoGeralCalculator
{
    // ════════════════════════════════════════════════════════════════════
    // D.4 — Sprint em foco, anterior, seguinte e janela do modo PI
    // ════════════════════════════════════════════════════════════════════

    /**
     * Resolve foco/anterior/seguinte/janela a partir do catálogo de sprints da PI.
     *
     * @param list<array{nome:string,tipo:string,data_ini:string,data_fim:string}> $sprintsPi
     *        sprints da PI (Q0), de QUALQUER tipo; serão ordenadas por data_ini.
     * @param ?string $sprintFiltro nome de sprint escolhido no header (vence o modo); null = nenhum.
     * @param string  $hoje 'Y-m-d'.
     * @return array{
     *   sprint_foco: ?array{nome:string,tipo:string,data_ini:string,data_fim:string,status:string,origem:string},
     *   sprint_anterior: ?array{nome:string,data_ini:string,data_fim:string,origem:string,mesma_do_foco:bool},
     *   sprint_seguinte: ?array{nome:string,tipo:string},
     *   sprints_pi: list<array{nome:string,tipo:string,data_ini:string,data_fim:string,status:string}>,
     *   sprints_modo_pi: list<string>,
     *   periodo_sprint: ?array{ini:string,fim:string},
     *   periodo_pi: ?array{ini:string,fim:string},
     *   avisos: list<string>
     * }
     */
    public static function resolverFoco(array $sprintsPi, ?string $sprintFiltro, string $hoje): array
    {
        $sprintFiltro = ($sprintFiltro !== null && trim($sprintFiltro) !== '') ? $sprintFiltro : null;

        // Ordena por (data_ini, nome) para desempate estável.
        usort($sprintsPi, static function (array $a, array $b): int {
            return [$a['data_ini'], $a['nome']] <=> [$b['data_ini'], $b['nome']];
        });

        $avisos = [];

        // Vocabulário de status da janela.
        $statusDe = static function (array $s) use ($hoje): string {
            if ($s['data_ini'] > $hoje) {
                return 'futura';
            }
            if ($s['data_fim'] < $hoje) {
                return 'fechada';
            }
            return 'ativa';
        };

        $sprintsPiOut = [];
        foreach ($sprintsPi as $s) {
            $sprintsPiOut[] = [
                'nome'     => $s['nome'],
                'tipo'     => $s['tipo'],
                'data_ini' => $s['data_ini'],
                'data_fim' => $s['data_fim'],
                'status'   => $statusDe($s),
            ];
        }

        $valor = array_values(array_filter($sprintsPi, static fn(array $s): bool => $s['tipo'] === 'valor'));

        // ── 2. Sprint em foco ────────────────────────────────────────────
        $foco = null;
        if ($sprintFiltro !== null) {
            foreach ($sprintsPi as $s) {
                if ($s['nome'] === $sprintFiltro) {
                    $foco = $s + ['status' => $statusDe($s), 'origem' => 'filtro'];
                    break;
                }
            }
        }
        if ($foco === null && $sprintFiltro === null) {
            // 2.ii ativa: sprint de valor com data_ini <= hoje <= data_fim
            foreach ($valor as $s) {
                if ($s['data_ini'] <= $hoje && $hoje <= $s['data_fim']) {
                    $foco = $s + ['status' => 'ativa', 'origem' => 'ativa'];
                    break;
                }
            }
            // 2.iii última iniciada: maior data_ini <= hoje
            if ($foco === null) {
                $cand = null;
                foreach ($valor as $s) {
                    if ($s['data_ini'] <= $hoje && ($cand === null || $s['data_ini'] > $cand['data_ini'])) {
                        $cand = $s;
                    }
                }
                if ($cand !== null) {
                    $foco = $cand + ['status' => $statusDe($cand), 'origem' => 'ultima_iniciada'];
                    $avisos[] = 'Nenhuma sprint em andamento hoje; mostrando a última iniciada (' . $cand['nome'] . ').';
                }
            }
            // 2.iv null: PI sem sprint iniciada (gadgets de sprint ficam vazios)
        }

        // ── 3. Sprint anterior ───────────────────────────────────────────
        $anterior = null;
        if ($foco !== null && ($foco['origem'] ?? null) === 'filtro') {
            // predecessora do foco: maior data_ini < data_ini do foco (valor)
            $cand = null;
            foreach ($valor as $s) {
                if ($s['data_ini'] < $foco['data_ini'] && ($cand === null || $s['data_ini'] > $cand['data_ini'])) {
                    $cand = $s;
                }
            }
            if ($cand !== null) {
                $anterior = [
                    'nome'          => $cand['nome'],
                    'data_ini'      => $cand['data_ini'],
                    'data_fim'      => $cand['data_fim'],
                    'origem'        => 'predecessora_do_foco',
                    'mesma_do_foco' => false,
                ];
            }
        } else {
            // sem filtro: a sprint de valor com MAIOR data_fim < hoje (última fechada)
            $cand = null;
            foreach ($valor as $s) {
                if ($s['data_fim'] < $hoje && ($cand === null || $s['data_fim'] > $cand['data_fim'])) {
                    $cand = $s;
                }
            }
            if ($cand !== null) {
                $mesma = $foco !== null && $cand['nome'] === $foco['nome'];
                $anterior = [
                    'nome'          => $cand['nome'],
                    'data_ini'      => $cand['data_ini'],
                    'data_fim'      => $cand['data_fim'],
                    'origem'        => 'ultima_fechada',
                    'mesma_do_foco' => $mesma,
                ];
                if ($mesma) {
                    $avisos[] = 'A sprint em foco e a anterior são a mesma (' . $cand['nome'] . '), a última fechada.';
                }
            }
        }

        // ── 4. Sprint seguinte (da anterior) ─────────────────────────────
        $seguinte = null;
        if ($anterior !== null) {
            $cand = null;
            foreach ($sprintsPi as $s) {
                if ($s['data_ini'] > $anterior['data_ini'] && ($cand === null || $s['data_ini'] < $cand['data_ini'])) {
                    $cand = $s;
                }
            }
            if ($cand !== null) {
                $seguinte = ['nome' => $cand['nome'], 'tipo' => $cand['tipo']];
            }
        }

        // ── 5. sprints_modo_pi = sprints de valor com data_ini <= hoje ───
        $modoPi = [];
        $inis = [];
        $fins = [];
        foreach ($valor as $s) {
            if ($s['data_ini'] <= $hoje) {
                $modoPi[]  = $s['nome'];
                $inis[]    = $s['data_ini'];
                $fins[]    = $s['data_fim'];
            }
        }
        $periodoPi = $modoPi !== [] ? ['ini' => min($inis), 'fim' => max($fins)] : null;

        $periodoSprint = $foco !== null ? ['ini' => $foco['data_ini'], 'fim' => $foco['data_fim']] : null;

        $focoOut = null;
        if ($foco !== null) {
            $focoOut = [
                'nome'     => $foco['nome'],
                'tipo'     => $foco['tipo'],
                'data_ini' => $foco['data_ini'],
                'data_fim' => $foco['data_fim'],
                'status'   => $foco['status'],
                'origem'   => $foco['origem'],
            ];
        }

        return [
            'sprint_foco'     => $focoOut,
            'sprint_anterior' => $anterior,
            'sprint_seguinte' => $seguinte,
            'sprints_pi'      => $sprintsPiOut,
            'sprints_modo_pi' => $modoPi,
            'periodo_sprint'  => $periodoSprint,
            'periodo_pi'      => $periodoPi,
            'avisos'          => $avisos,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G1 — Andamento da Sprint Atual (baldes)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Classifica os itens em baldes por status do config.
     *
     * População: ref = foco (modo sprint) ou ref ∈ sprints_modo_pi (modo PI),
     * universo valor + sustentação. Os cancelados ficam FORA de total_sp.
     *
     * @param list<array> $rows linhas da Q1 (universo valor + sustentação; o repositório já filtrou categoria != null).
     * @param list<string> $refsFoco sprint_ref que entram nos baldes (foco, ou sprints_modo_pi).
     * @param array{cancelado:list<string>,bloqueado:list<string>,backlog:list<string>,em_andamento:list<string>} $statusCfg
     * @return array{
     *   backlog_sp:float, em_andamento_sp:float, bloqueado_sp:float, aceitos_sp:float,
     *   aceitos_valor_sp:float, aceitos_sustentacao_sp:float, total_sp:float,
     *   itens: array{backlog:int,em_andamento:int,bloqueado:int,aceitos:int},
     *   cancelados_foco: array{sp:float,itens:int},
     *   em_andamento_por_status: array<string,float>,
     *   status_nao_mapeados: array<string,int>
     * }
     */
    public static function baldes(array $rows, array $refsFoco, array $statusCfg): array
    {
        $refs = array_flip($refsFoco);

        $sp     = ['backlog' => 0.0, 'em_andamento' => 0.0, 'bloqueado' => 0.0, 'aceitos' => 0.0];
        $itens  = ['backlog' => 0, 'em_andamento' => 0, 'bloqueado' => 0, 'aceitos' => 0];
        $aceitosValor = 0.0;
        $aceitosSust  = 0.0;
        $cancSp = 0.0;
        $cancIt = 0;
        $emAndamentoPorStatus = [];
        $naoMapeados = [];

        $mapa = static function (string $status, array $statusCfg): string {
            if (in_array($status, $statusCfg['cancelado'], true)) return 'cancelado';
            if (in_array($status, $statusCfg['bloqueado'], true)) return 'bloqueado';
            if (in_array($status, $statusCfg['backlog'], true))   return 'backlog';
            if (in_array($status, $statusCfg['em_andamento'], true)) return 'em_andamento';
            return '__nao_mapeado__';
        };

        foreach ($rows as $r) {
            if (!isset($refs[$r['sprint_ref']])) {
                continue;
            }
            $pts    = (float) ($r['story_points'] ?? 0);
            $status = (string) ($r['status'] ?? '');

            // 1º cancelado, 2º aceito (entregue), depois as demais listas.
            if (in_array($status, $statusCfg['cancelado'], true)) {
                $cancSp += $pts;
                $cancIt++;
                continue;
            }
            if ((int) ($r['entregue'] ?? 0) === 1) {
                $sp['aceitos']    += $pts;
                $itens['aceitos']++;
                if (($r['categoria'] ?? null) === 'sustentacao') {
                    $aceitosSust += $pts;
                } else {
                    $aceitosValor += $pts;
                }
                continue;
            }

            $balde = $mapa($status, $statusCfg);
            if ($balde === '__nao_mapeado__') {
                // cai em em_andamento, mas fica visível no diagnóstico.
                $naoMapeados[$status] = ($naoMapeados[$status] ?? 0) + 1;
                $balde = 'em_andamento';
            }
            $sp[$balde]    += $pts;
            $itens[$balde]++;
            if ($balde === 'em_andamento') {
                $emAndamentoPorStatus[$status] = ($emAndamentoPorStatus[$status] ?? 0) + $pts;
            }
        }

        $total = $sp['backlog'] + $sp['em_andamento'] + $sp['bloqueado'] + $sp['aceitos'];

        return [
            'backlog_sp'             => round($sp['backlog'], 2),
            'em_andamento_sp'        => round($sp['em_andamento'], 2),
            'bloqueado_sp'           => round($sp['bloqueado'], 2),
            'aceitos_sp'             => round($sp['aceitos'], 2),
            'aceitos_valor_sp'       => round($aceitosValor, 2),
            'aceitos_sustentacao_sp' => round($aceitosSust, 2),
            'total_sp'               => round($total, 2),
            'itens'                  => $itens,
            'cancelados_foco'        => ['sp' => round($cancSp, 2), 'itens' => $cancIt],
            'em_andamento_por_status' => self::arredondarMapa($emAndamentoPorStatus),
            'status_nao_mapeados'    => $naoMapeados,
        ];
    }

    /**
     * Cancelados da PI inteira (qualquer sprint ou sem sprint), universo valor + sustentação.
     * @param list<array> $rows
     * @param list<string> $cancelado
     * @return array{sp:float,itens:int}
     */
    public static function canceladosPi(array $rows, array $cancelado): array
    {
        $sp = 0.0;
        $it = 0;
        foreach ($rows as $r) {
            if (in_array((string) ($r['status'] ?? ''), $cancelado, true)) {
                $sp += (float) ($r['story_points'] ?? 0);
                $it++;
            }
        }
        return ['sp' => round($sp, 2), 'itens' => $it];
    }

    /**
     * Itens do universo (não cancelados) fora da janela do modo PI:
     *   - sem_sprint: sprint_ref vazio/null;
     *   - sprints_futuras: ref de sprint de valor com data_ini > hoje;
     *   - sprint_de_outra_pi: ref que não pertence a nenhuma sprint do catálogo da PI.
     *
     * @param list<array> $rows universo (valor + sustentação).
     * @param list<string> $cancelado
     * @param list<string> $modoPi sprints de valor já iniciadas (entram nos baldes).
     * @param array<string,array{tipo:string,data_ini:string}> $catalogo nome => {tipo,data_ini} de TODAS as sprints da PI.
     * @param string $hoje
     * @return array{sem_sprint_sp:float,sem_sprint_itens:int,sprints_futuras_sp:float,sprints_futuras_itens:int,sprint_de_outra_pi_sp:float,sprint_de_outra_pi_itens:int}
     */
    public static function foraDaJanela(array $rows, array $cancelado, array $modoPi, array $catalogo, string $hoje): array
    {
        $modo = array_flip($modoPi);

        $semSp = 0.0; $semIt = 0;
        $futSp = 0.0; $futIt = 0;
        $outSp = 0.0; $outIt = 0;

        foreach ($rows as $r) {
            if (in_array((string) ($r['status'] ?? ''), $cancelado, true)) {
                continue;
            }
            $ref = $r['sprint_ref'] ?? null;
            $pts = (float) ($r['story_points'] ?? 0);

            if ($ref === null || $ref === '') {
                $semSp += $pts; $semIt++;
                continue;
            }
            if (isset($modo[$ref])) {
                continue; // está nos baldes
            }
            if (!isset($catalogo[$ref])) {
                // ref não é sprint desta PI
                $outSp += $pts; $outIt++;
                continue;
            }
            $cat = $catalogo[$ref];
            if ($cat['tipo'] === 'valor' && $cat['data_ini'] > $hoje) {
                $futSp += $pts; $futIt++;
            }
            // demais (sprint de sustentação da PI, etc.) ficam fora destes contadores.
        }

        return [
            'sem_sprint_sp'            => round($semSp, 2),
            'sem_sprint_itens'         => $semIt,
            'sprints_futuras_sp'       => round($futSp, 2),
            'sprints_futuras_itens'    => $futIt,
            'sprint_de_outra_pi_sp'    => round($outSp, 2),
            'sprint_de_outra_pi_itens' => $outIt,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G3 — Cycle time por status (reuso de LeadTimeRepository)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Monta as linhas de lead time da Q1 (valor + entregue com ref na janela) e
     * devolve as estatísticas do LeadTimeRepository para o bloco e para a janela padrão.
     *
     * @param list<array> $rows Q1 completa.
     * @param list<string> $refsJanela sprint_ref do bloco (foco ou sprints_modo_pi).
     * @param list<string> $refsPadrao sprint_ref da janela padrão (ctx['sprints'] = fechadas).
     * @return array{itens:int,bloco:array,janela_padrao:array}
     *         bloco/janela_padrao = retorno de LeadTimeRepository::estatisticas().
     */
    public static function cycleTime(array $rows, array $refsJanela, array $refsPadrao): array
    {
        $linhasPara = static function (array $refs) use ($rows): array {
            $flip = array_flip($refs);
            $out  = [];
            foreach ($rows as $r) {
                if ((int) ($r['is_entrega_valor'] ?? 0) !== 1 || (int) ($r['entregue'] ?? 0) !== 1) {
                    continue;
                }
                $ref = $r['sprint_ref'] ?? null;
                if ($ref === null || !isset($flip[$ref])) {
                    continue;
                }
                $out[] = LeadTimeRepository::linha(
                    self::intOrNull($r['dias_desenvolvimento'] ?? null),
                    self::intOrNull($r['dias_homologacao'] ?? null),
                    self::intOrNull($r['dias_implantacao'] ?? null),
                    $r['status'] ?? null,
                    $r['fim_implantacao'] ?? null
                );
            }
            return $out;
        };

        $linhasBloco  = $linhasPara($refsJanela);
        $linhasPadrao = $linhasPara($refsPadrao);

        return [
            'itens'         => count($linhasBloco),
            'bloco'         => LeadTimeRepository::estatisticas($linhasBloco),
            'janela_padrao' => LeadTimeRepository::estatisticas($linhasPadrao),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G4 — Produtividade por sprint (consome porSprint pronto)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Monta o bloco G4 a partir do resultado de ProdutividadeRepository::porSprint
     * (recebido pronto; o Calculator NÃO consulta).
     *
     * @param array<string,array{horas:float,pontos:float,itens:int,sp_nulo:int}> $porSprint chave = sprint_ref.
     * @param list<array{nome:string,tipo:string,status:string}> $sprintsPi catálogo da PI (D.4 sprints_pi).
     * @param ?string $focoNome nome da sprint em foco (para destacar).
     * @param list<string> $modoPi sprints de valor iniciadas (ponderada da PI).
     * @param list<string> $sprintsFechadas ctx['sprints'] (ponderada das fechadas = tela Produtividade).
     * @param bool $piCorrente quando false, pi_ponderada e pi_ponderada_fechadas ficam null.
     * @return array{
     *   sprints:list<array{nome:string,status:string,foco:bool,horas:?float,pontos:?float,h_por_sp:?float,itens:int,itens_sp_nulo:int}>,
     *   pi_ponderada: ?array{horas:float,pontos:float,h_por_sp:?float},
     *   pi_ponderada_fechadas: ?array{horas:float,pontos:float,h_por_sp:?float,sprints:list<string>},
     *   nota: ?string
     * }
     */
    public static function produtividadeSprints(
        array $porSprint,
        array $sprintsPi,
        ?string $focoNome,
        array $modoPi,
        array $sprintsFechadas,
        bool $piCorrente
    ): array {
        $sprints = [];
        foreach ($sprintsPi as $s) {
            if ($s['tipo'] !== 'valor') {
                continue;
            }
            $d = $porSprint[$s['nome']] ?? null;
            if ($d === null) {
                $horas = null; $pontos = null; $hps = null; $itens = 0; $spNulo = 0;
            } else {
                $horas  = round((float) $d['horas'], 2);
                $pontos = round((float) $d['pontos'], 2);
                $hps    = $d['pontos'] > 0 ? round($d['horas'] / $d['pontos'], 2) : null;
                $itens  = (int) $d['itens'];
                $spNulo = (int) $d['sp_nulo'];
            }
            $sprints[] = [
                'nome'          => $s['nome'],
                'status'        => $s['status'],
                'foco'          => $focoNome !== null && $s['nome'] === $focoNome,
                'horas'         => $horas,
                'pontos'        => $pontos,
                'h_por_sp'      => $hps,
                'itens'         => $itens,
                'itens_sp_nulo' => $spNulo,
            ];
        }

        $somar = static function (array $nomes) use ($porSprint): array {
            $h = 0.0; $p = 0.0;
            foreach ($nomes as $n) {
                if (isset($porSprint[$n])) {
                    $h += (float) $porSprint[$n]['horas'];
                    $p += (float) $porSprint[$n]['pontos'];
                }
            }
            return ['horas' => round($h, 2), 'pontos' => round($p, 2), 'h_por_sp' => $p > 0 ? round($h / $p, 2) : null];
        };

        if (!$piCorrente) {
            return [
                'sprints'               => $sprints,
                'pi_ponderada'          => null,
                'pi_ponderada_fechadas' => null,
                'nota'                  => 'total consolidado da PI está na tela Produtividade (produtividade_historico); os valores por sprint vêm de vw_item.',
            ];
        }

        $ponderada = $somar($modoPi);
        $fechadas  = $somar($sprintsFechadas) + ['sprints' => array_values($sprintsFechadas)];

        return [
            'sprints'               => $sprints,
            'pi_ponderada'          => $ponderada,
            'pi_ponderada_fechadas' => $fechadas,
            'nota'                  => null,
        ];
    }

    /**
     * Produtividade "até o momento" (G1): Σhoras ÷ Σpontos sobre um conjunto de sprints.
     * @param array<string,array{horas:float,pontos:float,itens:int,sp_nulo:int}> $porSprint
     * @param list<string> $sprints
     * @return array{horas:float,pontos:float,h_por_sp:?float,itens_sp_nulo:int}
     */
    public static function produtividadeAteAgora(array $porSprint, array $sprints): array
    {
        $h = 0.0; $p = 0.0; $spNulo = 0;
        foreach ($sprints as $n) {
            if (isset($porSprint[$n])) {
                $h      += (float) $porSprint[$n]['horas'];
                $p      += (float) $porSprint[$n]['pontos'];
                $spNulo += (int) $porSprint[$n]['sp_nulo'];
            }
        }
        return [
            'horas'         => round($h, 2),
            'pontos'        => round($p, 2),
            'h_por_sp'      => $p > 0 ? round($h / $p, 2) : null,
            'itens_sp_nulo' => $spNulo,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G7 — Burndown (só entrega de valor)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param list<array> $rows Q1 completa (universo valor + sustentação).
     * @param list<string> $refsFoco sprint_ref ou tokens para pertinência (foco/modo PI).
     * @param array{ini:string,fim:string} $periodo período da janela.
     * @param string $hoje
     * @param bool $porToken true = pertinência por token (SprintRef::todas); false = por sprint_ref.
     * @return array burndown (estrutura D.2) ou ['estado'=>'vazio','motivo'=>...].
     */
    public static function burndown(array $rows, array $refsFoco, array $periodo, string $hoje, bool $porToken): array
    {
        if ($hoje < $periodo['ini']) {
            return ['estado' => 'vazio', 'motivo' => 'Sprint ainda não iniciada.'];
        }

        [$valor, $sustSp, $sustIt] = self::populacaoFluxo($rows, $refsFoco, $porToken);

        $dias = self::diasUteisPeriodo($periodo['ini'], $periodo['fim']);
        $n    = count($dias);

        // Escopo: descarta itens concluídos antes do período.
        $escopoItens = [];
        $jaAntes = 0; $jaAntesSp = 0.0;
        $semData = 0; $spNulo = 0;
        foreach ($valor as $r) {
            $pts = (float) ($r['story_points'] ?? 0);
            if (($r['story_points'] ?? null) === null) { $spNulo++; }
            $dc = self::dataConclusao($r);
            if ($dc !== null && $dc < $periodo['ini']) {
                $jaAntes++; $jaAntesSp += $pts;
                continue;
            }
            if ((int) ($r['entregue'] ?? 0) === 1 && $dc === null) {
                $semData++;
            }
            $escopoItens[] = ['sp' => $pts, 'dc' => $dc];
        }
        $escopoSp = 0.0;
        foreach ($escopoItens as $it) { $escopoSp += $it['sp']; }
        $escopoSp = round($escopoSp, 2);

        if ($escopoSp == 0.0 && $escopoItens === []) {
            return ['estado' => 'vazio', 'motivo' => 'Sem itens de valor no escopo da sprint.'];
        }

        // ultimo_dia = maior dia útil <= min(hoje, fim)
        $limite    = min($hoje, $periodo['fim']);
        $ultimoDia = null;
        foreach ($dias as $d) {
            if ($d <= $limite) { $ultimoDia = $d; }
        }

        $labels = ['inicio'];
        foreach ($dias as $d) { $labels[] = $d; }

        $ideal = [];
        for ($k = 0; $k <= $n; $k++) {
            $ideal[] = round($escopoSp * (1 - $k / max($n, 1)), 2);
        }

        $real = [round($escopoSp, 2)];
        for ($k = 1; $k <= $n; $k++) {
            $diaK = $dias[$k - 1];
            if ($ultimoDia === null || $diaK > $ultimoDia) {
                $real[] = null;
                continue;
            }
            $concl = 0.0;
            foreach ($escopoItens as $it) {
                if ($it['dc'] !== null && $it['dc'] <= $diaK) {
                    $concl += $it['sp'];
                }
            }
            $real[] = round($escopoSp - $concl, 2);
        }

        return [
            'estado'      => 'ok',
            'periodo'     => $periodo,
            'escopo_sp'   => $escopoSp,
            'ultimo_dia'  => $ultimoDia,
            'dias'        => $labels,
            'ideal'       => $ideal,
            'real'        => $real,
            'diagnosticos' => [
                'itens_escopo'           => count($escopoItens),
                'ja_concluidos_antes'    => $jaAntes,
                'ja_concluidos_antes_sp' => round($jaAntesSp, 2),
                'entregues_sem_data'     => $semData,
                'sp_nulo'                => $spNulo,
                'feriados_ignorados'     => true,
                'sustentacao_fora_sp'    => round($sustSp, 2),
                'sustentacao_fora_itens' => $sustIt,
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G8 — CFD (só valor; inclui os já concluídos antes)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param list<array> $rows Q1 completa.
     * @param list<string> $refsFoco
     * @param array{ini:string,fim:string} $periodo
     * @param string $hoje
     * @param bool $porToken
     * @return array cfd (estrutura D.2) ou vazio.
     */
    public static function cfd(array $rows, array $refsFoco, array $periodo, string $hoje, bool $porToken): array
    {
        if ($hoje < $periodo['ini']) {
            return ['estado' => 'vazio', 'motivo' => 'Sprint ainda não iniciada.'];
        }

        [$valor, $sustSp, $sustIt] = self::populacaoFluxo($rows, $refsFoco, $porToken);

        $itens = [];
        $escopoSp = 0.0;
        $spNulo = 0;
        foreach ($valor as $r) {
            $pts = (float) ($r['story_points'] ?? 0);
            if (($r['story_points'] ?? null) === null) { $spNulo++; }
            $escopoSp += $pts;
            $itens[] = [
                'sp'     => $pts,
                'dc'     => self::dataConclusao($r),
                'inicio' => isset($r['inicio_fluxo']) && $r['inicio_fluxo'] !== null ? substr((string) $r['inicio_fluxo'], 0, 10) : null,
                'entregue' => (int) ($r['entregue'] ?? 0) === 1,
            ];
        }
        $escopoSp = round($escopoSp, 2);

        if ($itens === []) {
            return ['estado' => 'vazio', 'motivo' => 'Sem itens de valor no escopo da sprint.'];
        }

        $dias = self::diasUteisPeriodo($periodo['ini'], $periodo['fim']);
        $limite = min($hoje, $periodo['fim']);
        $ultimoDia = null;
        foreach ($dias as $d) {
            if ($d <= $limite) { $ultimoDia = $d; }
        }

        $labels = [];
        $pendente = [];
        $emAndamento = [];
        $concluido = [];
        foreach ($dias as $d) {
            $labels[] = $d;
            if ($ultimoDia === null || $d > $ultimoDia) {
                $pendente[]    = null;
                $emAndamento[] = null;
                $concluido[]   = null;
                continue;
            }
            $c = 0.0; $a = 0.0;
            foreach ($itens as $it) {
                $concl = $it['dc'] !== null && $it['dc'] <= $d;
                if ($concl) {
                    $c += $it['sp'];
                } elseif ($it['inicio'] !== null && $it['inicio'] <= $d) {
                    $a += $it['sp'];
                }
            }
            $concluido[]   = round($c, 2);
            $emAndamento[] = round($a, 2);
            $pendente[]    = round($escopoSp - $a - $c, 2);
        }

        // Invariante: no ultimo_dia, concluido + apos_periodo + sem_data = Σ SP entregues.
        $aposPeriodoSp = 0.0; $semDataSp = 0.0;
        foreach ($itens as $it) {
            if (!$it['entregue']) { continue; }
            if ($it['dc'] === null) {
                $semDataSp += $it['sp'];
            } elseif ($ultimoDia !== null && $it['dc'] > $ultimoDia) {
                $aposPeriodoSp += $it['sp'];
            }
        }

        return [
            'estado'     => 'ok',
            'escopo_sp'  => $escopoSp,
            'ultimo_dia' => $ultimoDia,
            'dias'       => $labels,
            'pendente'   => $pendente,
            'em_andamento' => $emAndamento,
            'concluido'  => $concluido,
            'diagnosticos' => [
                'concluidos_apos_periodo_sp' => round($aposPeriodoSp, 2),
                'entregues_sem_data_sp'      => round($semDataSp, 2),
                'sp_nulo'                    => $spNulo,
                'sustentacao_fora_sp'        => round($sustSp, 2),
                'sustentacao_fora_itens'     => $sustIt,
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G9 — Tempo do item em cada status (fases entre marcos)
    // ════════════════════════════════════════════════════════════════════

    public const FASES_TEMPO = [
        'Desenvolvimento',
        'Aguardando homologação',
        'Homologação',
        'Aguardando implantação',
        'Implantação',
    ];

    /**
     * @param list<array> $rows Q1 completa.
     * @param list<string> $refsFoco
     * @param string $hoje
     * @param bool $porToken pertinência por token (modo sprint) ou ref (modo PI).
     * @param bool $modoPi   true = lista restrita a não entregues; medias sobre toda a população.
     * @param int  $maxLinhas teto da lista (config max_linhas_tempo_status).
     * @param list<string> $cancelado
     * @return array tempo_status (estrutura D.2).
     */
    public static function tempoStatus(array $rows, array $refsFoco, string $hoje, bool $porToken, bool $modoPi, int $maxLinhas, array $cancelado): array
    {
        [$valor, $sustSp, $sustIt] = self::populacaoFluxo($rows, $refsFoco, $porToken, $cancelado);

        $somaFase = array_fill_keys(self::FASES_TEMPO, 0.0);
        $nFase    = array_fill_keys(self::FASES_TEMPO, 0);
        $invertidas = 0;
        $semDiaUtil = 0;
        $lista = [];
        $populacao = 0;

        foreach ($valor as $r) {
            $populacao++;
            $entregue = (int) ($r['entregue'] ?? 0) === 1;
            [$dias, $faseAtual, $totalDias, $inv, $semDu] = self::fasesItem($r, $hoje, $entregue);
            $invertidas += $inv;
            $semDiaUtil += $semDu;

            foreach (self::FASES_TEMPO as $f) {
                if ($dias[$f] !== null) {
                    $somaFase[$f] += $dias[$f];
                    $nFase[$f]++;
                }
            }

            $lista[] = [
                'chave'      => $r['chave'] ?? null,
                'tipo'       => $r['tipo_item'] ?? null,
                'status'     => $r['status'] ?? null,
                'sp'         => ($r['story_points'] ?? null) === null ? null : (float) $r['story_points'],
                'fase_atual' => $faseAtual,
                'dias'       => $dias,
                'total_dias' => $totalDias,
                '_entregue'  => $entregue,
                '_ordfase'   => $dias[$faseAtual] ?? null,
            ];
        }

        $medias = [];
        foreach (self::FASES_TEMPO as $f) {
            $medias[] = [
                'fase' => $f,
                'dias' => $nFase[$f] > 0 ? round($somaFase[$f] / $nFase[$f], 2) : null,
                'n'    => $nFase[$f],
            ];
        }

        // Lista: no modo PI só não entregues.
        $itensLista = $modoPi
            ? array_values(array_filter($lista, static fn(array $x): bool => !$x['_entregue']))
            : $lista;

        // Ordenação: não entregues primeiro (por maior tempo na fase atual desc),
        // depois entregues por total_dias desc.
        // O índice original ($i) é o último desempate para emular a ordenação
        // estável do PHP 8 (o sort do 7.4 não é estável): a saída é byte-idêntica
        // nas duas versões mesmo entre elementos empatados.
        $idx = 0;
        foreach ($itensLista as &$__it) { $__it['_i'] = $idx++; }
        unset($__it);
        usort($itensLista, static function (array $a, array $b): int {
            if ($a['_entregue'] !== $b['_entregue']) {
                return $a['_entregue'] <=> $b['_entregue']; // false (0) antes de true (1)
            }
            if (!$a['_entregue']) {
                $c = ($b['_ordfase'] ?? -1) <=> ($a['_ordfase'] ?? -1);
            } else {
                $c = ($b['total_dias'] ?? -1) <=> ($a['total_dias'] ?? -1);
            }
            return $c !== 0 ? $c : ($a['_i'] <=> $b['_i']);
        });

        $truncado = count($itensLista) > $maxLinhas;
        $itensLista = array_slice($itensLista, 0, $maxLinhas);
        $itensLista = array_map(static function (array $x): array {
            unset($x['_entregue'], $x['_ordfase'], $x['_i']);
            return $x;
        }, $itensLista);

        return [
            'estado'          => 'ok',
            'escopo'          => $modoPi ? 'pi' : 'sprint',
            'fases'           => self::FASES_TEMPO,
            'medias'          => $medias,
            'itens'           => $itensLista,
            'truncado'        => $truncado,
            'populacao_medias' => $populacao,
            'diagnosticos'    => [
                'fases_invertidas'       => $invertidas,
                'fases_sem_dia_util'     => $semDiaUtil,
                'sustentacao_fora_sp'    => round($sustSp, 2),
                'sustentacao_fora_itens' => $sustIt,
            ],
        ];
    }

    /**
     * Calcula as 5 fases de um item.
     * @return array{0:array<string,?int>,1:string,2:int,3:int,4:int} [dias por fase, fase_atual, total_dias, n_invertidas, n_sem_dia_util]
     */
    private static function fasesItem(array $r, string $hoje, bool $entregue): array
    {
        $d10 = static fn($v): ?string => ($v === null || $v === '') ? null : substr((string) $v, 0, 10);
        $iniF = $d10($r['inicio_fluxo'] ?? null);
        $fimF = $d10($r['fim_fluxo'] ?? null);
        $iniH = $d10($r['inicio_homologacao'] ?? null);
        $fimH = $d10($r['fim_homologacao'] ?? null);
        $iniI = $d10($r['inicio_implantacao'] ?? null);
        $fimI = $d10($r['fim_implantacao'] ?? null);

        $dias = array_fill_keys(self::FASES_TEMPO, null);
        $invertidas = 0;
        $semDiaUtil = 0;

        // Marcos de abertura de cada fase (fase aberta corre até hoje para não entregues).
        $marcos = [
            'Desenvolvimento'          => ['ini' => $iniF, 'fim' => $fimF, 'tipo' => 'trabalho'],
            'Aguardando homologação'   => ['ini' => $fimF, 'fim' => $iniH, 'tipo' => 'espera'],
            'Homologação'              => ['ini' => $iniH, 'fim' => $fimH, 'tipo' => 'trabalho'],
            'Aguardando implantação'   => ['ini' => $fimH, 'fim' => $iniI, 'tipo' => 'espera'],
            'Implantação'              => ['ini' => $iniI, 'fim' => $fimI, 'tipo' => 'implantacao'],
        ];

        $abertaFase = null;
        foreach ($marcos as $fase => $m) {
            if ($m['ini'] === null) {
                continue;
            }
            if ($m['fim'] !== null) {
                // fase fechada
                if (DiasUteis::invertida($m['ini'], $m['fim'])) {
                    $invertidas++;
                    continue; // null, contado em invertidas
                }
                $val = self::calcFase($m['tipo'], $m['ini'], $m['fim']);
                if ($val === null) {
                    $semDiaUtil++;
                    continue;
                }
                $dias[$fase] = $val;
            } else {
                // fase aberta (início sem fim): corre até hoje só para não entregues
                $abertaFase = $fase;
                if (!$entregue && !DiasUteis::invertida($m['ini'], $hoje)) {
                    $val = self::calcFase($m['tipo'], $m['ini'], $hoje);
                    if ($val !== null) {
                        $dias[$fase] = $val;
                    }
                }
                break; // fase aberta é a última iniciada
            }
        }

        // fase_atual
        if ($entregue) {
            $faseAtual = 'Concluído';
        } elseif ($iniF === null) {
            $faseAtual = 'Backlog (não iniciado)';
        } else {
            $faseAtual = $abertaFase ?? self::ultimaFaseComDado($dias);
        }

        $total = 0;
        foreach ($dias as $v) {
            if ($v !== null) { $total += $v; }
        }

        return [$dias, $faseAtual, $total, $invertidas, $semDiaUtil];
    }

    private static function calcFase(string $tipo, string $a, string $b): ?int
    {
        switch ($tipo) {
            case 'trabalho':
                return DiasUteis::trabalho($a, $b);
            case 'espera':
                return DiasUteis::espera($a, $b);
            case 'implantacao':
                return DiasUteis::implantacao($a, $b);
            default:
                return null;
        }
    }

    private static function ultimaFaseComDado(array $dias): string
    {
        $ultima = 'Desenvolvimento';
        foreach (self::FASES_TEMPO as $f) {
            if ($dias[$f] !== null) {
                $ultima = $f;
            }
        }
        return $ultima;
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G10 — Chamados (Incidente/Bug resolvidos)
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param list<array> $rows Q1 completa.
     * @param string $hoje
     * @param int $janelaDias janela (config janela_chamados_dias).
     * @param int $limiteDias limite de resolução (config limite_chamado_dias).
     * @param bool $piCorrente quando false, gadget 'vazio'.
     * @param list<string> $cancelado
     * @return array chamados (estrutura D.2) ou vazio.
     */
    public static function chamados(array $rows, string $hoje, int $janelaDias, int $limiteDias, bool $piCorrente, array $cancelado): array
    {
        if (!$piCorrente) {
            return ['estado' => 'vazio', 'motivo' => 'janela de 30 dias só se aplica à PI corrente.'];
        }

        $inicioJanela = date('Y-m-d', strtotime($hoje . ' -' . ($janelaDias - 1) . ' days'));

        $tipos = ['Incidente', 'Bug'];
        $acc = [];
        foreach ($tipos as $t) {
            $acc[$t] = ['dias' => [], 'desde_criacao' => []];
        }

        $resolvidosPi = 0;
        $comFimFluxo = 0;
        $semInicioFluxo = 0;
        $naJanela = 0;
        $dataInvertida = 0;

        foreach ($rows as $r) {
            $tipo = (string) ($r['tipo_item'] ?? '');
            if (!in_array($tipo, $tipos, true)) {
                continue;
            }
            $catStatus = (string) ($r['categoria_status'] ?? '');
            $status    = (string) ($r['status'] ?? '');
            $resolvido = $catStatus === 'Itens concluídos' && !in_array($status, $cancelado, true);
            if (!$resolvido) {
                continue;
            }
            $resolvidosPi++;

            $fimF = ($r['fim_fluxo'] ?? null);
            $iniF = ($r['inicio_fluxo'] ?? null);
            $fimF = ($fimF === null || $fimF === '') ? null : substr((string) $fimF, 0, 10);
            $iniF = ($iniF === null || $iniF === '') ? null : substr((string) $iniF, 0, 10);

            if ($fimF === null) {
                continue; // fora: sem fim_fluxo (contado pela diferença resolvidos_pi − com_fim_fluxo)
            }
            $comFimFluxo++;

            // janela DATE(fim_fluxo) entre hoje−(janela-1) e hoje
            if ($fimF < $inicioJanela || $fimF > $hoje) {
                continue;
            }

            if ($iniF === null) {
                $semInicioFluxo++;
                continue;
            }
            if (DiasUteis::invertida($iniF, $fimF)) {
                $dataInvertida++;
                continue;
            }

            $naJanela++;
            $acc[$tipo]['dias'][] = max(0, DiasUteis::bruto($iniF, $fimF));

            $criado = ($r['criado'] ?? null);
            $criado = ($criado === null || $criado === '') ? null : substr((string) $criado, 0, 10);
            if ($criado !== null) {
                $acc[$tipo]['desde_criacao'][] = max(0, DiasUteis::bruto($criado, $fimF));
            }
        }

        $porTipo = [];
        foreach ($tipos as $t) {
            $d = $acc[$t]['dias'];
            if ($d === []) {
                continue;
            }
            $dc = $acc[$t]['desde_criacao'];
            $dentro = count(array_filter($d, static fn(int $x): bool => $x <= $limiteDias));
            $porTipo[] = [
                'tipo'                   => $t,
                'n'                      => count($d),
                'media_dias'             => round(array_sum($d) / count($d), 2),
                'mediana_dias'           => self::mediana($d),
                'pct_dentro_limite'      => round($dentro / count($d) * 100, 2),
                'media_dias_desde_criacao' => $dc === [] ? null : round(array_sum($dc) / count($dc), 2),
            ];
        }

        if ($naJanela === 0) {
            return [
                'estado'  => 'vazio',
                'motivo'  => 'Nenhum chamado (Incidente/Bug) resolvido com data de fluxo na janela de ' . $janelaDias . ' dias.',
            ];
        }

        return [
            'estado'              => 'ok',
            'independe_do_seletor' => true,
            'janela_dias'         => $janelaDias,
            'limite_dias'         => $limiteDias,
            'por_tipo'            => $porTipo,
            'cobertura'           => [
                'resolvidos_pi'  => $resolvidosPi,
                'com_fim_fluxo'  => $comFimFluxo,
                'sem_inicio_fluxo' => $semInicioFluxo,
                'na_janela'      => $naJanela,
                'pct_com_data'   => $resolvidosPi > 0 ? round($comFimFluxo / $resolvidosPi * 100, 2) : null,
            ],
            'diagnosticos'        => ['data_invertida' => $dataInvertida],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.6 G2 — Sprint anterior + transbordo
    // ════════════════════════════════════════════════════════════════════

    /**
     * Métricas da sprint anterior (N) por token, e o transbordo N → N+1.
     *
     * @param list<array> $rows Q1 completa.
     * @param string $nomeN sprint anterior.
     * @param ?string $nomeN1 sprint seguinte (N+1); null = N é a última da PI.
     * @param list<string> $cancelado
     * @return array{
     *   alocados_sp:float, nao_aceitos_sem_continuidade_sp:float,
     *   transbordo: array{disponivel:bool,sp:float,itens:int,nao_entregue_sp:float,para_sprint:?string,motivo:?string}
     * }
     */
    public static function sprintAnterior(array $rows, string $nomeN, ?string $nomeN1, array $cancelado): array
    {
        $alocadosSp = 0.0;
        $semContinuidadeSp = 0.0;
        $transSp = 0.0; $transIt = 0; $transNaoEntregueSp = 0.0;

        foreach ($rows as $r) {
            $status = (string) ($r['status'] ?? '');
            if (in_array($status, $cancelado, true)) {
                continue;
            }
            $tokens = SprintRef::todas($r['sprint'] ?? null);
            $flip   = array_flip($tokens);
            $temN   = isset($flip[$nomeN]);
            if (!$temN) {
                continue;
            }
            $pts = (float) ($r['story_points'] ?? 0);
            $alocadosSp += $pts;

            $temN1    = $nomeN1 !== null && isset($flip[$nomeN1]);
            $entregue = (int) ($r['entregue'] ?? 0) === 1;

            // Transbordo N → N+1: item em N e em N+1.
            if ($temN1) {
                $transSp += $pts; $transIt++;
                if (!$entregue) { $transNaoEntregueSp += $pts; }
            }

            // Não aceitos sem continuidade: N nos tokens, N+1 fora, não entregue.
            if (!$temN1 && !$entregue) {
                $semContinuidadeSp += $pts;
            }
        }

        if ($nomeN1 === null) {
            $transbordo = [
                'disponivel'      => false,
                'sp'              => 0.0,
                'itens'           => 0,
                'nao_entregue_sp' => 0.0,
                'para_sprint'     => null,
                'motivo'          => 'Transbordo para a próxima PI não é extraído (extração só por fixVersion).',
            ];
        } else {
            $transbordo = [
                'disponivel'      => true,
                'sp'              => round($transSp, 2),
                'itens'           => $transIt,
                'nao_entregue_sp' => round($transNaoEntregueSp, 2),
                'para_sprint'     => $nomeN1,
                'motivo'          => null,
            ];
        }

        return [
            'alocados_sp'                     => round($alocadosSp, 2),
            'nao_aceitos_sem_continuidade_sp' => round($semContinuidadeSp, 2),
            'transbordo'                      => $transbordo,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // D.5 G6 — Lista de itens da sprint anterior
    // ════════════════════════════════════════════════════════════════════

    /**
     * Lista de itens com N nos tokens (universo de SP, não cancelados). Ordem:
     * aceitos → em andamento → bloqueado → backlog → chave. Totais sobre o
     * conjunto completo antes do corte de max_linhas_lista.
     *
     * @param list<array> $rows Q1 completa.
     * @param string $nomeN sprint anterior.
     * @param ?string $nomeN1 sprint seguinte (para a flag de transbordo).
     * @param array<string,string> $resumos chave => resumo (Q2 baseline); ausência = null.
     * @param int $maxLinhas
     * @param array{cancelado:list<string>,bloqueado:list<string>,backlog:list<string>,em_andamento:list<string>} $statusCfg
     * @return array lista_sprint_anterior (estrutura D.2).
     */
    public static function listaSprintAnterior(array $rows, string $nomeN, ?string $nomeN1, array $resumos, int $maxLinhas, array $statusCfg): array
    {
        $ordemBalde = ['aceitos' => 0, 'em_andamento' => 1, 'bloqueado' => 2, 'backlog' => 3];

        $classificar = static function (array $r) use ($statusCfg): string {
            if ((int) ($r['entregue'] ?? 0) === 1) return 'aceitos';
            $status = (string) ($r['status'] ?? '');
            if (in_array($status, $statusCfg['bloqueado'], true)) return 'bloqueado';
            if (in_array($status, $statusCfg['backlog'], true))   return 'backlog';
            return 'em_andamento';
        };

        $linhas = [];
        $totalSp = 0.0; $totalItens = 0;
        $comResumo = 0;

        foreach ($rows as $r) {
            $status = (string) ($r['status'] ?? '');
            if (in_array($status, $statusCfg['cancelado'], true)) {
                continue;
            }
            $tokens = SprintRef::todas($r['sprint'] ?? null);
            $flip   = array_flip($tokens);
            if (!isset($flip[$nomeN])) {
                continue;
            }
            $totalItens++;
            $totalSp += (float) ($r['story_points'] ?? 0);

            $chave  = (string) ($r['chave'] ?? '');
            $resumo = $resumos[$chave] ?? null;
            if ($resumo !== null) { $comResumo++; }

            $balde = $classificar($r);
            $linhas[] = [
                '_ord'        => $ordemBalde[$balde] ?? 9,
                '_chave'      => $chave,
                'tipo'        => $r['tipo_item'] ?? null,
                'chave'       => $chave,
                'pai'         => ($r['pai'] ?? null) === '' ? null : ($r['pai'] ?? null),
                'resumo'      => $resumo,
                'status'      => $status,
                'sprint_maior' => $r['sprint_ref'] ?? null,
                'sprints'     => $tokens,
                'sp'          => ($r['story_points'] ?? null) === null ? null : (float) $r['story_points'],
                'transbordo'  => $nomeN1 !== null && isset($flip[$nomeN1]),
            ];
        }

        usort($linhas, static function (array $a, array $b): int {
            return [$a['_ord'], $a['_chave']] <=> [$b['_ord'], $b['_chave']];
        });

        $truncado = count($linhas) > $maxLinhas;
        $linhas = array_slice($linhas, 0, $maxLinhas);
        $linhas = array_map(static function (array $x): array {
            unset($x['_ord'], $x['_chave']);
            return $x;
        }, $linhas);

        return [
            'estado'              => 'ok',
            'sprint'              => $nomeN,
            'itens'               => $linhas,
            'total_itens'         => $totalItens,
            'total_sp'            => round($totalSp, 2),
            'truncado'            => $truncado,
            'resumo_disponivel_pct' => $totalItens > 0 ? round($comResumo / $totalItens * 100, 2) : null,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Auxiliares puros
    // ════════════════════════════════════════════════════════════════════

    /**
     * População dos gadgets de fluxo (G7, G8, G9): só entrega de valor, não
     * cancelado, com pertinência por token (modo sprint) ou por sprint_ref
     * (modo PI). Devolve [itens de valor, sustentacao_fora_sp, sustentacao_fora_itens].
     *
     * @param list<array> $rows
     * @param list<string> $refs foco (tokens) ou sprints_modo_pi (refs).
     * @param bool $porToken
     * @param list<string> $cancelado
     * @return array{0:list<array>,1:float,2:int}
     */
    private static function populacaoFluxo(array $rows, array $refs, bool $porToken, array $cancelado = ['Cancelado']): array
    {
        $flip = array_flip($refs);
        $valor = [];
        $sustSp = 0.0; $sustIt = 0;

        foreach ($rows as $r) {
            if (in_array((string) ($r['status'] ?? ''), $cancelado, true)) {
                continue;
            }
            $pertence = $porToken
                ? (bool) array_intersect_key($flip, array_flip(SprintRef::todas($r['sprint'] ?? null)))
                : isset($flip[$r['sprint_ref'] ?? '']);
            if (!$pertence) {
                continue;
            }
            if (($r['categoria'] ?? null) === 'sustentacao') {
                $sustSp += (float) ($r['story_points'] ?? 0);
                $sustIt++;
                continue;
            }
            if ((int) ($r['is_entrega_valor'] ?? 0) !== 1) {
                continue;
            }
            $valor[] = $r;
        }
        return [$valor, $sustSp, $sustIt];
    }

    /** data_conclusao = COALESCE(fim_homologacao, fim_fluxo) se entregue; só a data. */
    private static function dataConclusao(array $r): ?string
    {
        if ((int) ($r['entregue'] ?? 0) !== 1) {
            return null;
        }
        foreach (['fim_homologacao', 'fim_fluxo'] as $c) {
            $v = $r[$c] ?? null;
            if ($v !== null && $v !== '') {
                return substr((string) $v, 0, 10);
            }
        }
        return null;
    }

    /** Dias de semana (seg–sex) de $ini a $fim, inclusivo, como 'Y-m-d'. */
    private static function diasUteisPeriodo(string $ini, string $fim): array
    {
        $dias = [];
        $t   = strtotime(substr($ini, 0, 10) . ' 00:00:00');
        $tf  = strtotime(substr($fim, 0, 10) . ' 00:00:00');
        while ($t <= $tf) {
            $n = (int) date('N', $t); // 1..7
            if ($n <= 5) {
                $dias[] = date('Y-m-d', $t);
            }
            $t += 86400;
        }
        return $dias;
    }

    private static function mediana(array $vals): ?float
    {
        if ($vals === []) {
            return null;
        }
        sort($vals);
        $n = count($vals);
        $m = intdiv($n, 2);
        return $n % 2 ? (float) $vals[$m] : round(($vals[$m - 1] + $vals[$m]) / 2, 2);
    }

    private static function arredondarMapa(array $mapa): array
    {
        $out = [];
        foreach ($mapa as $k => $v) {
            $out[$k] = round((float) $v, 2);
        }
        return $out;
    }

    private static function intOrNull($v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }
}

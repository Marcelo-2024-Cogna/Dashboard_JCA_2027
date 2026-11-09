<?php

namespace App\Repositories;

use App\Database;
use App\Services\VisaoGeralCalculator;
use InvalidArgumentException;
use PDO;

/**
 * VisaoGeralRepository
 * ────────────────────
 * Visão Geral por jornada (SOMENTE LEITURA). Faz a E/S (seção D.3), delega todo
 * o cálculo ao VisaoGeralCalculator e monta o JSON exatamente na forma da §D.2.
 *
 * Uma leitura de itens (Q1), a produtividade por sprint (reuso de
 * ProdutividadeRepository::porSprint), a série de Velocidade (reuso de
 * VelocidadeRepository, com o contexto injetado) e o resto é cálculo em memória.
 *
 * Contexto único: resolverContexto() roda UMA vez por requisição e é injetado
 * em VelocidadeRepository::consultar($filtros, $hoje, $ctx).
 *
 * Modos (§D.2): 'sprint' (padrão) ou 'pi'. O filtro de sprint vence o modo.
 */
class VisaoGeralRepository
{
    private PDO $pdo;
    private PrevisibilidadeRepository $base;
    private VelocidadeRepository $velocidade;
    private ProdutividadeRepository $produtividade;
    private array $config;
    private array $tiposSustentacao;
    private array $statusCfg;

    public function __construct(
        ?PrevisibilidadeRepository $base = null,
        ?VelocidadeRepository $velocidade = null,
        ?ProdutividadeRepository $produtividade = null,
        ?PDO $pdo = null,
        ?array $config = null
    ) {
        $this->pdo           = $pdo ?? Database::pdo();
        $this->base          = $base ?? new PrevisibilidadeRepository($this->pdo);
        $this->velocidade    = $velocidade ?? new VelocidadeRepository($this->base, $this->pdo);
        $this->produtividade = $produtividade ?? new ProdutividadeRepository($this->base, $this->pdo);
        $this->config        = $config ?? (require dirname(__DIR__, 2) . '/config/visao_geral.php');
        $this->tiposSustentacao = (require dirname(__DIR__, 2) . '/config/baseline.php')['plan_real_sustentacao_types']
            ?? ['Sustentação'];
        $this->statusCfg = $this->config['status'] ?? [];
    }

    /**
     * @param array{jornada?:string,time?:string,versao?:string,sprint?:string,modo?:string} $filtros
     * @throws InvalidArgumentException filtro inexistente/inconsistente ou modo inválido
     */
    public function consultar(array $filtros, ?string $hoje = null): array
    {
        $hoje = $hoje ?? date('Y-m-d');

        // ── Normaliza o modo ────────────────────────────────────────────
        $modoSolicitado = strtolower(trim((string) ($filtros['modo'] ?? '')));
        if ($modoSolicitado === '') {
            $modoSolicitado = 'sprint';
        }
        if (!in_array($modoSolicitado, ['sprint', 'pi'], true)) {
            throw new InvalidArgumentException("Parâmetro 'modo' inválido: use 'sprint' ou 'pi'.");
        }

        // ── Contexto único (valida filtros, resolve PI/janela/times) ─────
        $ctx   = $this->base->resolverContexto($filtros, $hoje);
        $pi    = $ctx['pi'];
        $times = $ctx['times'];
        $keys  = array_column($times, 'projeto_key');

        $jornadaFiltro = $ctx['filtros']['jornada'];
        $timeFiltro    = $ctx['filtros']['time'];
        $sprintFiltro  = $ctx['filtros']['sprint'];

        $avisos = [];

        if ($pi === null) {
            // Sem PI não há janela nem universo; devolve estrutura mínima.
            return $this->montar(
                $ctx, $hoje, $modoSolicitado, $modoSolicitado,
                $this->janelaVazia($hoje, null),
                ['ultima_carga' => null, 'defasagem_dias' => null, 'prefixos_sem_time' => []],
                [], 0,
                $this->gadgetsVazios('Nenhuma PI cadastrada no catálogo.'),
                ['Nenhuma PI cadastrada no catálogo.']
            );
        }

        // ── Q0: sprints da PI (todos os tipos) ───────────────────────────
        $sprintsPi = $this->sprintsPi((int) $pi['id']);

        // ── Foco/anterior/seguinte/janela (puro) ─────────────────────────
        $foco = VisaoGeralCalculator::resolverFoco($sprintsPi, $sprintFiltro, $hoje);
        foreach ($foco['avisos'] as $a) {
            $avisos[] = $a;
        }

        // Modo efetivo: o filtro de sprint não altera o modo solicitado, mas a
        // janela é sempre a do foco quando há filtro de sprint (resolverFoco já trata).
        $modoEfetivo = $modoSolicitado;

        $pisCorrente = $this->piCorrente($pi, $hoje);

        // ── Catálogo de sprints da PI (nome => {tipo,data_ini}) ──────────
        $catalogo = [];
        foreach ($sprintsPi as $s) {
            $catalogo[$s['nome']] = ['tipo' => $s['tipo'], 'data_ini' => $s['data_ini']];
        }

        // ── Guarda: seleção sem times → gadgets vazios ───────────────────
        if ($keys === []) {
            if ($ctx['times_sem_extracao'] !== []) {
                $avisos[] = 'Nenhum time da seleção possui itens extraídos do Jira para ' . $pi['desc_pi'] . '.';
            } else {
                $avisos[] = 'Nenhum time da seleção possui itens nesta PI.';
            }
            $janela = $this->montarJanela($pi, $pisCorrente, $hoje, $foco);
            return $this->montar(
                $ctx, $hoje, $modoSolicitado, $modoEfetivo, $janela,
                ['ultima_carga' => null, 'defasagem_dias' => null, 'prefixos_sem_time' => []],
                [], 0,
                $this->gadgetsVazios('Nenhum time da seleção tem itens extraídos nesta PI.'),
                $avisos
            );
        }

        // ── Q1: itens da PI de referência (times com extração) ───────────
        $rows = $this->itens((int) $pi['id'], $keys);
        // Universo dos gadgets de SP = valor + sustentação (categoria != null).
        // Chamados (G10) usa a Q1 completa (Incidente/Bug costumam ter categoria null).
        $universeRows = array_values(array_filter($rows, static fn(array $r): bool => $r['categoria'] !== null));

        // ── Série de Velocidade (reuso, contexto injetado) ───────────────
        $velocidadeResp = $this->velocidade->consultar($filtros, $hoje, $ctx);

        // ── Produtividade por sprint (reuso SQL) ─────────────────────────
        $sprintsValorPi = array_values(array_filter(
            array_map(fn($s) => $s['nome'], array_filter($sprintsPi, fn($s) => $s['tipo'] === 'valor'))
        ));
        $porSprint = $sprintsValorPi !== []
            ? $this->produtividade->porSprint((int) $pi['id'], $sprintsValorPi, $keys)
            : [];

        // ── Q3: defasagem + prefixos sem time ────────────────────────────
        $extracao = $this->extracao((int) $pi['id'], $keys, $hoje, $jornadaFiltro, $timeFiltro, $avisos);

        // ── Universo ─────────────────────────────────────────────────────
        $universo = [
            'times'              => array_map(fn($t) => [
                'jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
            ], $times),
            'times_sem_extracao' => $ctx['times_sem_extracao'],
            'itens_pi'           => count($rows),
        ];

        // ── Referências para os gadgets ──────────────────────────────────
        $isPi      = $modoEfetivo === 'pi';
        $focoNome  = $foco['sprint_foco']['nome'] ?? null;
        $modoPi    = $foco['sprints_modo_pi'];
        $refsFoco  = $isPi ? $modoPi : ($focoNome !== null ? [$focoNome] : []);
        $periodo   = $isPi ? $foco['periodo_pi'] : $foco['periodo_sprint'];
        $sprintsFechadas = $ctx['sprints'];

        $janela = $this->montarJanela($pi, $pisCorrente, $hoje, $foco);

        $gadgets = $this->montarGadgets(
            $universeRows, $rows, $foco, $isPi, $refsFoco, $modoPi, $catalogo, $hoje,
            $sprintsFechadas, $porSprint, $pisCorrente, $periodo,
            $velocidadeResp, $keys, (int) $pi['id'], $avisos
        );

        return $this->montar($ctx, $hoje, $modoSolicitado, $modoEfetivo, $janela, $extracao, $universo['times'], $universo['itens_pi'], $gadgets, $avisos, $ctx['times_sem_extracao']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Montagem do JSON
    // ═══════════════════════════════════════════════════════════════════════

    private function montar(
        array $ctx, string $hoje, string $modoSolicitado, string $modoEfetivo, array $janela,
        array $extracao, array $times, int $itensPi, array $gadgets, array $avisos, ?array $semExtracao = null
    ): array {
        return [
            'filtros' => [
                'jornada'         => $ctx['filtros']['jornada'],
                'time'            => $ctx['filtros']['time'],
                'versao'          => $ctx['filtros']['versao'],
                'versao_padrao'   => $ctx['filtros']['versao_padrao'],
                'sprint'          => $ctx['filtros']['sprint'],
                'modo_solicitado' => $modoSolicitado,
                'modo_efetivo'    => $modoEfetivo,
            ],
            'janela'   => $janela,
            'extracao' => $extracao,
            'universo' => [
                'times'              => $times,
                'times_sem_extracao' => $semExtracao ?? $ctx['times_sem_extracao'],
                'itens_pi'           => $itensPi,
            ],
            'gadgets'  => $gadgets,
            'avisos'   => $avisos,
        ];
    }

    private function montarJanela(array $pi, bool $piCorrente, string $hoje, array $foco): array
    {
        return [
            'pi'              => $pi['desc_pi'],
            'pi_corrente'     => $piCorrente,
            'hoje'            => $hoje,
            'sprint_foco'     => $foco['sprint_foco'],
            'sprint_anterior' => $foco['sprint_anterior'],
            'sprint_seguinte' => $foco['sprint_seguinte'],
            'sprints_pi'      => $foco['sprints_pi'],
            'sprints_modo_pi' => $foco['sprints_modo_pi'],
            'periodo'         => $foco['periodo_sprint'] ?? $foco['periodo_pi'],
        ];
    }

    private function janelaVazia(string $hoje, ?string $descPi): array
    {
        return [
            'pi'              => $descPi,
            'pi_corrente'     => false,
            'hoje'            => $hoje,
            'sprint_foco'     => null,
            'sprint_anterior' => null,
            'sprint_seguinte' => null,
            'sprints_pi'      => [],
            'sprints_modo_pi' => [],
            'periodo'         => null,
        ];
    }

    /**
     * Monta os blocos de todos os gadgets.
     * @param list<array> $rows universo de SP (valor + sustentação).
     * @param list<array> $todasRows Q1 completa (inclui categoria null), só para G10 (chamados).
     */
    private function montarGadgets(
        array $rows, array $todasRows, array $foco, bool $isPi, array $refsFoco, array $modoPi, array $catalogo, string $hoje,
        array $sprintsFechadas, array $porSprint, bool $piCorrente, ?array $periodo,
        array $velocidadeResp, array $keys, int $piId, array &$avisos
    ): array {
        $cancelado = $this->statusCfg['cancelado'] ?? ['Cancelado'];
        $maxLista  = (int) ($this->config['max_linhas_lista'] ?? 500);
        $maxTempo  = (int) ($this->config['max_linhas_tempo_status'] ?? 300);
        $janelaCh  = (int) ($this->config['janela_chamados_dias'] ?? 30);
        $limiteCh  = (int) ($this->config['limite_chamado_dias'] ?? 2);

        $focoNome = $foco['sprint_foco']['nome'] ?? null;
        $rotuloEscopo = $isPi ? ('PI (' . count($modoPi) . ' sprints)') : $focoNome;
        $escopoLabel  = $isPi ? 'pi' : 'sprint';

        // Mapa sprint => serie (Velocidade) para aceitos_sp.
        $serie = $velocidadeResp['serie'] ?? [];
        $serieByNome = [];
        foreach ($serie as $row) {
            $serieByNome[$row['sprint']] = $row;
        }

        // ── G1 Andamento ─────────────────────────────────────────────────
        $andamento = $this->gadgetAndamento(
            $rows, $refsFoco, $isPi, $modoPi, $catalogo, $hoje, $cancelado,
            $focoNome, $escopoLabel, $rotuloEscopo, $serieByNome, $porSprint, $sprintsFechadas, $piCorrente
        );

        // ── G2 Sprint anterior ─────────────────────────────────────────
        $sprintAnterior = $this->gadgetSprintAnterior($rows, $foco, $cancelado, $serieByNome, $porSprint);

        // ── G3 Cycle time por status ───────────────────────────────────
        $cycle = VisaoGeralCalculator::cycleTime($rows, $refsFoco, $sprintsFechadas);
        $cycleTime = $this->formatarCycle($cycle, $refsFoco, $sprintsFechadas);

        // ── G4 Produtividade por sprint ─────────────────────────────────
        $prodSprints = ['estado' => 'ok', 'classe' => 'exato'] + VisaoGeralCalculator::produtividadeSprints(
            $porSprint, $foco['sprints_pi'], $focoNome, $modoPi, $sprintsFechadas, $piCorrente
        );

        // ── G5 Team Velocity (reuso) ────────────────────────────────────
        $teamVelocity = [
            'estado' => 'ok',
            'classe' => 'exato',
            'serie'  => $serie,
            'resumo' => $velocidadeResp['resumo'] ?? [],
            'link'   => '/metricas/velocidade',
        ];

        // ── G7 Burndown ─────────────────────────────────────────────────
        $burndown = $this->gadgetFluxo(
            VisaoGeralCalculator::burndown($rows, $refsFoco, $periodo ?? ['ini' => $hoje, 'fim' => $hoje], $hoje, !$isPi),
            $periodo,
            'Escopo constante; conclusão pela data de aceite; feriados ignorados.'
        );

        // ── G8 CFD ──────────────────────────────────────────────────────
        $cfd = $this->gadgetFluxo(
            VisaoGeralCalculator::cfd($rows, $refsFoco, $periodo ?? ['ini' => $hoje, 'fim' => $hoje], $hoje, !$isPi),
            null,
            'Escopo constante; três séries empilhadas por data de fluxo.'
        );

        // ── G9 Tempo por status ─────────────────────────────────────────
        $tempoStatus = $this->gadgetTempoStatus(
            VisaoGeralCalculator::tempoStatus($rows, $refsFoco, $hoje, !$isPi, $isPi, $maxTempo, $cancelado)
        );

        // ── G10 Chamados (Q1 completa: Incidente/Bug têm categoria null) ──
        $chamados = $this->gadgetChamados(
            VisaoGeralCalculator::chamados($todasRows, $hoje, $janelaCh, $limiteCh, $piCorrente, $cancelado)
        );

        // ── G6 Lista da sprint anterior ─────────────────────────────────
        $listaSprintAnterior = $this->gadgetListaSprintAnterior($rows, $foco, $piId, $keys);

        // ── Avisos derivados ──────────────────────────────────────────────
        $naoMapeados = $andamento['diagnosticos']['status_nao_mapeados'] ?? [];
        if (is_array($naoMapeados) && $naoMapeados !== []) {
            $avisos[] = 'Status fora das listas do config caíram em "Em andamento": '
                . implode(', ', array_keys($naoMapeados)) . '.';
        }
        if ($isPi) {
            $fdj = $andamento['fora_da_janela'] ?? [];
            if (($fdj['sem_sprint_itens'] ?? 0) > 0) {
                $avisos[] = $fdj['sem_sprint_itens'] . ' item(ns) (' . $fdj['sem_sprint_sp'] . ' SP) da PI ainda sem sprint.';
            }
            if (($fdj['sprint_de_outra_pi_itens'] ?? 0) > 0) {
                $avisos[] = $fdj['sprint_de_outra_pi_itens'] . ' item(ns) (' . $fdj['sprint_de_outra_pi_sp'] . ' SP) apontam para sprint de outra PI.';
            }
        }

        return [
            'andamento'             => $andamento,
            'sprint_anterior'       => $sprintAnterior,
            'cycle_time_status'     => $cycleTime,
            'produtividade_sprints' => $prodSprints,
            'team_velocity'         => $teamVelocity,
            'burndown'              => $burndown,
            'cfd'                   => $cfd,
            'tempo_status'          => $tempoStatus,
            'chamados'              => $chamados,
            'lista_sprint_anterior' => $listaSprintAnterior,
            'sustentacao'           => [
                'estado' => 'aguardando_modulo',
                'classe' => 'aguardando_modulo',
                'gadgets' => [
                    'Visão da Sustentação na Sprint atual',
                    'Tempo gastos em tarefas de Sustentação',
                    'Incidentes/Solicitações da sprint anterior',
                ],
            ],
        ];
    }

    // ── G1 ──────────────────────────────────────────────────────────────
    private function gadgetAndamento(
        array $rows, array $refsFoco, bool $isPi, array $modoPi, array $catalogo, string $hoje, array $cancelado,
        ?string $focoNome, string $escopoLabel, ?string $rotuloEscopo, array $serieByNome, array $porSprint,
        array $sprintsFechadas, bool $piCorrente
    ): array {
        if ($refsFoco === []) {
            return ['estado' => 'vazio', 'classe' => 'exato', 'motivo' => 'Nenhuma sprint em foco nesta PI.'];
        }

        $baldes = VisaoGeralCalculator::baldes($rows, $refsFoco, $this->statusCfg);

        // aceitos_sp pela Velocidade quando as refs estão na série.
        $aceitosVel = 0.0;
        $todasNaSerie = true;
        foreach ($refsFoco as $ref) {
            if (isset($serieByNome[$ref])) {
                $aceitosVel += (float) $serieByNome[$ref]['concluido'];
            } else {
                $todasNaSerie = false;
            }
        }
        if ($todasNaSerie) {
            $aceitosSp = round($aceitosVel, 2);
            $origem = 'velocidade';
            $aceitosVelocidadeSp = round($aceitosVel, 2);
        } else {
            $aceitosSp = $baldes['aceitos_sp'];
            $origem = 'local';
            $aceitosVelocidadeSp = null;
        }

        // Produtividade até agora.
        if ($isPi) {
            $prodAgora = VisaoGeralCalculator::produtividadeAteAgora($porSprint, $modoPi);
            $janelaPadrao = $piCorrente
                ? VisaoGeralCalculator::produtividadeAteAgora($porSprint, $sprintsFechadas)
                : null;
            if ($janelaPadrao !== null) {
                $janelaPadrao = ['horas' => $janelaPadrao['horas'], 'pontos' => $janelaPadrao['pontos'], 'h_por_sp' => $janelaPadrao['h_por_sp']];
            }
            $prodAgora['janela_padrao'] = $janelaPadrao;
        } else {
            $prodAgora = VisaoGeralCalculator::produtividadeAteAgora($porSprint, $focoNome !== null ? [$focoNome] : []);
            $prodAgora['janela_padrao'] = null;
        }

        $canceladosPi = VisaoGeralCalculator::canceladosPi($rows, $cancelado);

        $out = [
            'estado'                 => 'ok',
            'classe'                 => 'exato',
            'escopo'                 => $escopoLabel,
            'rotulo_escopo'          => $rotuloEscopo,
            'backlog_sp'             => $baldes['backlog_sp'],
            'em_andamento_sp'        => $baldes['em_andamento_sp'],
            'bloqueado_sp'           => $baldes['bloqueado_sp'],
            'aceitos_sp'             => $aceitosSp,
            'aceitos_sp_origem'      => $origem,
            'aceitos_valor_sp'       => $baldes['aceitos_valor_sp'],
            'aceitos_sustentacao_sp' => $baldes['aceitos_sustentacao_sp'],
            'total_sp'               => round($baldes['backlog_sp'] + $baldes['em_andamento_sp'] + $baldes['bloqueado_sp'] + $aceitosSp, 2),
            'cancelados_foco'        => $baldes['cancelados_foco'],
            'itens'                  => $baldes['itens'],
            'produtividade_ate_agora' => $prodAgora,
            'cancelados_pi'          => $canceladosPi,
            'em_andamento_por_status' => $baldes['em_andamento_por_status'],
            'reconciliacao'          => [
                'aceitos_local_sp'     => $baldes['aceitos_sp'],
                'aceitos_velocidade_sp' => $aceitosVelocidadeSp,
                'residuo_aceitos'      => $aceitosVelocidadeSp === null ? null : round($baldes['aceitos_sp'] - $aceitosVelocidadeSp, 2),
            ],
            'diagnosticos'           => ['status_nao_mapeados' => $baldes['status_nao_mapeados'] === [] ? new \stdClass() : $baldes['status_nao_mapeados']],
        ];

        if ($isPi) {
            $out['fora_da_janela'] = VisaoGeralCalculator::foraDaJanela($rows, $cancelado, $modoPi, $catalogo, $hoje);
        }

        return $out;
    }

    // ── G2 ──────────────────────────────────────────────────────────────
    private function gadgetSprintAnterior(array $rows, array $foco, array $cancelado, array $serieByNome, array $porSprint): array
    {
        $ant = $foco['sprint_anterior'];
        if ($ant === null) {
            return ['estado' => 'vazio', 'classe' => 'exato', 'motivo' => 'Nenhuma sprint anterior fechada nesta PI.'];
        }
        $nomeN  = $ant['nome'];
        $nomeN1 = $foco['sprint_seguinte']['nome'] ?? null;

        $calc = VisaoGeralCalculator::sprintAnterior($rows, $nomeN, $nomeN1, $cancelado);

        // Aceitos: serie[N].concluido (ref = N).
        $serieN = $serieByNome[$nomeN] ?? null;
        $aceitosSp = $serieN !== null ? round((float) $serieN['concluido'], 2) : 0.0;

        // Quebra valor/sustentação dos aceitos da sprint N (ref = N, entregue).
        $aceitosValor = 0.0; $aceitosSust = 0.0;
        foreach ($rows as $r) {
            if (($r['sprint_ref'] ?? null) !== $nomeN) {
                continue;
            }
            if ((int) ($r['entregue'] ?? 0) !== 1) {
                continue;
            }
            $pts = (float) ($r['story_points'] ?? 0);
            if (($r['categoria'] ?? null) === 'sustentacao') {
                $aceitosSust += $pts;
            } else {
                $aceitosValor += $pts;
            }
        }

        $prod = $porSprint[$nomeN] ?? null;
        $prodHSp = ($prod !== null && (float) $prod['pontos'] > 0)
            ? round((float) $prod['horas'] / (float) $prod['pontos'], 2)
            : null;

        return [
            'estado'      => 'ok',
            'classe'      => 'exato',
            'sprint'      => $nomeN,
            'proxima'     => $nomeN1,
            'alocados_sp' => $calc['alocados_sp'],
            'aceitos_sp'  => $aceitosSp,
            'aceitos_valor_sp'      => round($aceitosValor, 2),
            'aceitos_sustentacao_sp' => round($aceitosSust, 2),
            'nao_aceitos_sem_continuidade_sp' => $calc['nao_aceitos_sem_continuidade_sp'],
            'transbordo'  => $calc['transbordo'],
            'produtividade_h_sp' => $prodHSp,
            'sustentacao' => ['estado' => 'aguardando_modulo'],
        ];
    }

    // ── G3 ──────────────────────────────────────────────────────────────
    private function formatarCycle(array $cycle, array $refsFoco, array $sprintsFechadas): array
    {
        $etapa = static fn(array $e): array => ['dias' => $e['media_nos_itens'] === null ? null : round((float) $e['media_nos_itens'], 2), 'n' => $e['n']];
        $bloco = $cycle['bloco'];
        $padrao = $cycle['janela_padrao'];

        if ($cycle['itens'] === 0 && $padrao['itens'] === 0) {
            return ['estado' => 'vazio', 'classe' => 'exato', 'motivo' => 'Nenhum item de valor entregue na janela.'];
        }

        return [
            'estado'          => 'ok',
            'classe'          => 'exato',
            'sprints'         => array_values($refsFoco),
            'itens'           => $cycle['itens'],
            'desenvolvimento' => $etapa($bloco['desenvolvimento']),
            'homologacao'     => $etapa($bloco['homologacao']),
            'implantacao'     => $etapa($bloco['implantacao']),
            'lead_time_medio' => $bloco['lead_time_medio'] === null ? null : round((float) $bloco['lead_time_medio'], 2),
            'janela_padrao'   => [
                'sprints'         => array_values($sprintsFechadas),
                'itens'           => $padrao['itens'],
                'desenvolvimento' => $etapa($padrao['desenvolvimento']),
                'homologacao'     => $etapa($padrao['homologacao']),
                'implantacao'     => $etapa($padrao['implantacao']),
                'lead_time_medio' => $padrao['lead_time_medio'] === null ? null : round((float) $padrao['lead_time_medio'], 2),
            ],
        ];
    }

    // ── G7/G8 ─────────────────────────────────────────────────────────────
    private function gadgetFluxo(array $calc, ?array $periodo, string $regra): array
    {
        if (($calc['estado'] ?? '') === 'vazio') {
            return ['estado' => 'vazio', 'classe' => 'aproximado', 'motivo' => $calc['motivo'] ?? 'Sem dados.'];
        }
        return ['classe' => 'aproximado', 'regra' => $regra] + $calc;
    }

    // ── G9 ──────────────────────────────────────────────────────────────
    private function gadgetTempoStatus(array $calc): array
    {
        return ['classe' => 'aproximado', 'regra' => 'Fases entre marcos de fluxo, em dias úteis.'] + $calc;
    }

    // ── G10 ─────────────────────────────────────────────────────────────
    private function gadgetChamados(array $calc): array
    {
        if (($calc['estado'] ?? '') === 'vazio') {
            return ['estado' => 'vazio', 'classe' => 'aproximado', 'motivo' => $calc['motivo'] ?? 'Sem chamados.'];
        }
        return ['classe' => 'aproximado', 'regra' => 'Dias úteis de inicio_fluxo a fim_fluxo; janela fixa de 30 dias.'] + $calc;
    }

    // ── G6 ──────────────────────────────────────────────────────────────
    private function gadgetListaSprintAnterior(array $rows, array $foco, int $piId, array $keys): array
    {
        $ant = $foco['sprint_anterior'];
        if ($ant === null) {
            return ['estado' => 'vazio', 'classe' => 'exato', 'motivo' => 'Nenhuma sprint anterior fechada nesta PI.'];
        }
        $nomeN  = $ant['nome'];
        $nomeN1 = $foco['sprint_seguinte']['nome'] ?? null;

        // Q2: resumos da baseline só para as chaves da sprint N (universo não cancelado).
        $cancelado = $this->statusCfg['cancelado'] ?? ['Cancelado'];
        $chaves = [];
        foreach ($rows as $r) {
            if (in_array((string) ($r['status'] ?? ''), $cancelado, true)) {
                continue;
            }
            $tokens = \App\Services\SprintRef::todas($r['sprint'] ?? null);
            if (in_array($nomeN, $tokens, true)) {
                $chaves[(string) ($r['chave'] ?? '')] = true;
            }
        }
        $resumos = $this->resumosBaseline($piId, array_keys($chaves));

        return VisaoGeralCalculator::listaSprintAnterior($rows, $nomeN, $nomeN1, $resumos, (int) ($this->config['max_linhas_lista'] ?? 500), $this->statusCfg);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // E/S (seção D.3)
    // ═══════════════════════════════════════════════════════════════════════

    private function piCorrente(array $pi, string $hoje): bool
    {
        return $pi['data_ini'] !== null && $pi['data_fim'] !== null
            && $pi['data_ini'] <= $hoje && $hoje <= $pi['data_fim'];
    }

    /** Placeholders únicos (prepares nativos não repetem nomeados). */
    private function in(string $p, array $vals, array &$par): string
    {
        $ph = [];
        foreach (array_values($vals) as $i => $v) {
            $ph[] = ":{$p}{$i}";
            $par[":{$p}{$i}"] = $v;
        }
        return implode(',', $ph);
    }

    /** Q0: sprints da PI (todos os tipos). */
    private function sprintsPi(int $piId): array
    {
        $st = $this->pdo->prepare(
            "SELECT nome, tipo, data_ini, data_fim FROM sprints
              WHERE configuracao_pi_id = :pi AND ativo = 'S' ORDER BY data_ini, nome"
        );
        $st->execute([':pi' => $piId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'nome'     => $r['nome'],
                'tipo'     => $r['tipo'],
                'data_ini' => $r['data_ini'],
                'data_fim' => $r['data_fim'],
            ];
        }
        return $out;
    }

    /**
     * Q1: itens da PI de referência, times da seleção com extração.
     * Categoria derivada aqui: sustentacao / valor / null.
     * @return list<array>
     */
    private function itens(int $piId, array $keys): array
    {
        $par = [':pi' => $piId];
        $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT i.chave, i.projeto_key, i.tipo_item, i.status, i.categoria_status, i.sprint, i.sprint_ref, i.pai,
                    i.story_points, i.tempo_gasto_horas, i.is_entrega_valor, i.entregue,
                    i.criado, i.inicio_fluxo, i.fim_fluxo, i.inicio_homologacao, i.fim_homologacao,
                    i.inicio_implantacao, i.fim_implantacao,
                    i.dias_desenvolvimento, i.dias_homologacao, i.dias_implantacao
               FROM vw_item i
              WHERE i.configuracao_pi_id = :pi AND i.projeto_key IN ($k)"
        );
        $st->execute($par);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $tipo = (string) ($r['tipo_item'] ?? '');
            if (in_array($tipo, $this->tiposSustentacao, true)) {
                $categoria = 'sustentacao';
            } elseif ((int) ($r['is_entrega_valor'] ?? 0) === 1) {
                $categoria = 'valor';
            } else {
                $categoria = null;
            }
            $out[] = [
                'chave'              => $r['chave'],
                'projeto_key'        => $r['projeto_key'],
                'tipo_item'          => $r['tipo_item'],
                'status'             => $r['status'],
                'categoria_status'   => $r['categoria_status'],
                'sprint'             => $r['sprint'],
                'sprint_ref'         => $r['sprint_ref'],
                'pai'                => $r['pai'],
                'story_points'       => $r['story_points'] === null ? null : (float) $r['story_points'],
                'tempo_gasto_horas'  => $r['tempo_gasto_horas'] === null ? null : (float) $r['tempo_gasto_horas'],
                'is_entrega_valor'   => (int) ($r['is_entrega_valor'] ?? 0),
                'entregue'           => (int) ($r['entregue'] ?? 0),
                'criado'             => $r['criado'],
                'inicio_fluxo'       => $r['inicio_fluxo'],
                'fim_fluxo'          => $r['fim_fluxo'],
                'inicio_homologacao' => $r['inicio_homologacao'],
                'fim_homologacao'    => $r['fim_homologacao'],
                'inicio_implantacao' => $r['inicio_implantacao'],
                'fim_implantacao'    => $r['fim_implantacao'],
                'dias_desenvolvimento' => $r['dias_desenvolvimento'],
                'dias_homologacao'   => $r['dias_homologacao'],
                'dias_implantacao'   => $r['dias_implantacao'],
                'categoria'          => $categoria,
            ];
        }
        return $out;
    }

    /** Q2: resumos da baseline, em blocos de até 500 chaves. 1ª ocorrência vence. */
    private function resumosBaseline(int $piId, array $chaves): array
    {
        $chaves = array_values(array_filter($chaves, fn($c) => $c !== '' && $c !== null));
        if ($chaves === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($chaves, 500) as $bloco) {
            $par = [':pi' => $piId];
            $in = $this->in('c', $bloco, $par);
            $st = $this->pdo->prepare(
                "SELECT b.chave, b.resumo FROM baseline b
                  WHERE b.configuracao_pi_id = :pi AND b.ativo = 'S' AND b.chave IN ($in)
                  ORDER BY b.id_baseline"
            );
            $st->execute($par);
            foreach ($st->fetchAll() as $r) {
                if (!isset($out[$r['chave']])) {
                    $out[$r['chave']] = $r['resumo'];
                }
            }
        }
        return $out;
    }

    /**
     * Q3: defasagem (MAX data_carga) e, quando jornada e time estão vazios,
     * prefixos de chave sem time ativo no catálogo.
     */
    private function extracao(int $piId, array $keys, string $hoje, ?string $jornada, ?string $time, array &$avisos): array
    {
        $par = [':pi' => $piId];
        $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT MAX(data_carga) AS m, MIN(data_carga) AS n FROM extracao_jira
              WHERE ativo = 'S' AND configuracao_pi_id = :pi AND SUBSTRING_INDEX(chave, '-', 1) IN ($k)"
        );
        $st->execute($par);
        $linhaCarga  = $st->fetch();
        $ultimaCarga = $linhaCarga && $linhaCarga['m'] !== null ? $linhaCarga['m'] : null;
        $maisAntiga  = $linhaCarga && $linhaCarga['n'] !== null ? $linhaCarga['n'] : null;

        $defasagem = null;
        $defasagemHoras = null;
        $limiteHoras = (int) ($this->config['aviso_defasagem_horas'] ?? 25);
        if ($ultimaCarga !== null) {
            $defasagem = (int) floor((strtotime($hoje . ' 00:00:00') - strtotime(substr((string) $ultimaCarga, 0, 10) . ' 00:00:00')) / 86400);
        }
        // Aviso em HORAS sobre a carga MAIS ANTIGA da seleção (job noturno às 23:00:
        // 25 h cobre a janela entre duas execuções sem alarme falso).
        if ($maisAntiga !== null) {
            $defasagemHoras = round((time() - strtotime((string) $maisAntiga)) / 3600, 1);
            if ($defasagemHoras > $limiteHoras) {
                $avisos[] = 'A carga mais antiga do Jira tem ' . $defasagemHoras . ' h de defasagem.';
            }
        }

        $prefixos = [];
        if ($jornada === null && $time === null) {
            $st = $this->pdo->prepare(
                "SELECT SUBSTRING_INDEX(e.chave, '-', 1) AS prefixo, COUNT(*) AS itens FROM extracao_jira e
                  WHERE e.ativo = 'S' AND e.configuracao_pi_id = :pi
                    AND NOT EXISTS (SELECT 1 FROM times t WHERE t.ativo = 'S' AND t.projeto_key = SUBSTRING_INDEX(e.chave, '-', 1))
                  GROUP BY prefixo ORDER BY itens DESC, prefixo"
            );
            $st->execute([':pi' => $piId]);
            foreach ($st->fetchAll() as $r) {
                $prefixos[] = ['prefixo' => $r['prefixo'], 'itens' => (int) $r['itens']];
            }
            if ($prefixos !== []) {
                $avisos[] = count($prefixos) . ' prefixo(s) de chave sem time ativo no catálogo: '
                    . implode(', ', array_column($prefixos, 'prefixo')) . '.';
            }
        }

        return [
            'ultima_carga'      => $ultimaCarga,
            'defasagem_dias'    => $defasagem,
            'defasagem_horas'   => $defasagemHoras,
            'aviso_defasagem_horas' => $limiteHoras,
            'prefixos_sem_time' => $prefixos,
        ];
    }

    /** Gadgets todos vazios (seleção sem times / sem PI). */
    private function gadgetsVazios(string $motivo): array
    {
        $vazioExato = ['estado' => 'vazio', 'classe' => 'exato', 'motivo' => $motivo];
        $vazioAprox = ['estado' => 'vazio', 'classe' => 'aproximado', 'motivo' => $motivo];
        return [
            'andamento'             => $vazioExato,
            'sprint_anterior'       => $vazioExato,
            'cycle_time_status'     => $vazioExato,
            'produtividade_sprints' => $vazioExato,
            'team_velocity'         => $vazioExato,
            'burndown'              => $vazioAprox,
            'cfd'                   => $vazioAprox,
            'tempo_status'          => $vazioAprox,
            'chamados'              => $vazioAprox,
            'lista_sprint_anterior' => $vazioExato,
            'sustentacao'           => [
                'estado' => 'aguardando_modulo',
                'classe' => 'aguardando_modulo',
                'gadgets' => [
                    'Visão da Sustentação na Sprint atual',
                    'Tempo gastos em tarefas de Sustentação',
                    'Incidentes/Solicitações da sprint anterior',
                ],
            ],
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Textos "Como é calculado" das telas de métricas (só apresentação; não calcula nada).
 *
 * paraTela() devolve o contrato lido pelo App.Legenda (app.js):
 *   done_statuses, tipos_valor, tipos_sustentacao e blocos[chave] com
 *   titulo, fonte, unidade, formula, entra, nao_entra, done, janela, limitacoes[],
 *   onde_no_codigo e, opcionalmente, diferencas_jira[].
 * Listas vindas de config (Done, tipos) nunca são digitadas nos textos.
 * Compatível com PHP 7.4.
 */
final class MetodologiaService
{
    /** Campos de texto obrigatórios de um bloco completo. */
    public const CAMPOS_TEXTO = ['titulo', 'fonte', 'unidade', 'formula', 'entra', 'nao_entra', 'done', 'janela', 'onde_no_codigo'];

    /**
     * Tela => chaves de bloco. Visão Geral: só as chaves novas (as 12 atuais
     * continuam em LEGENDAS no JS da view).
     */
    public const INVENTARIO = [
        'overview' => ['cancelados', 'lista_sprint_anterior', 'sustentacao_placeholder'],
        'velocidade' => [
            'velocidade_grafico', 'velocidade_media', 'velocidade_tabela_sprints',
            'velocidade_por_time', 'velocidade_tabela_times',
        ],
        'previsibilidade' => [
            'prev_previsibilidade', 'prev_committed', 'prev_entregue', 'prev_transbordo',
            'prev_variacao', 'prev_cancelado', 'prev_escopo_adicionado',
            'prev_grafico_time', 'prev_grafico_transbordo', 'prev_tabela',
        ],
        'plan-real' => [
            'pr_velocidade', 'pr_pi_planning', 'pr_sprint_planning', 'pr_entregue',
            'pr_grafico', 'pr_tabela_pontos', 'pr_tabela_times', 'pr_diagnosticos',
        ],
        'lead-time' => [
            'lt_medio', 'lt_itens', 'lt_etapa', 'lt_p50', 'lt_sem_implantacao',
            'lt_grafico', 'lt_tabela', 'lt_tabela_secundaria', 'lt_diagnosticos',
        ],
        'produtividade' => [
            'pd_jornada', 'pd_melhor', 'pd_pior', 'pd_variacao', 'pd_sugestao',
            'pd_grafico_jornada', 'pd_grafico_times', 'pd_tabela', 'pd_diagnosticos',
        ],
        'epicos' => [
            'ep_total', 'ep_concluidos', 'ep_risco', 'ep_nao_iniciados',
            'ep_grafico_sp', 'ep_grafico_progresso', 'ep_tabela',
        ],
        'sustentacao' => [
            'su_itens', 'su_horas_sust', 'su_pct', 'su_horas_valor',
            'su_grafico_horas', 'su_grafico_tipos', 'su_grafico_pct',
        ],
    ];

    /**
     * Textos por chave, preenchidos por tela nas FEATs seguintes (chave => bloco).
     * @var array<string,array>
     */
    private const TEXTOS = self::TEXTOS_VELOCIDADE + self::TEXTOS_PREVISIBILIDADE + self::TEXTOS_PLAN_REAL
        + self::TEXTOS_LEAD_TIME + self::TEXTOS_PRODUTIVIDADE + self::TEXTOS_OVERVIEW
        + self::TEXTOS_EPICOS + self::TEXTOS_SUSTENTACAO;

    /**
     * Marcadores aceitos nos textos (trocados em paraTela() por valores de config/baseline.php,
     * para a lista nunca ser digitada à mão): {done} = done_statuses,
     * {valor} = value_delivery_types, {sust} = plan_real_sustentacao_types,
     * {sust_extract} = sustentacao_types (lista da tela Pontos de Sustentação),
     * {cancelado} = status.cancelado de config/visao_geral.php.
     */
    private const DONE_ENTREGUE = 'Conta como entregue o item com status {done} (done_statuses de config/baseline.php; a mesma lista está repetida em db/views.sql, coluna entregue). Vale o status de hoje.';
    private const ITENS_VELOCIDADE = 'Itens de entrega de valor ({valor}) e itens de Sustentação ({sust}), dos times da seleção que têm extração na PI. O tipo define a categoria; os tipos de sustentação vêm de plan_real_sustentacao_types.';
    private const SEM_CATEGORIA = 'Itens de outros tipos (por exemplo Incidente e Bug), que não são de valor nem de sustentação. Times sem extração na PI.';
    private const ITENS_VALOR = 'Itens de entrega de valor ({valor}) da baseline e da extração, dos times da seleção que têm extração na PI.';
    private const SO_VALOR = 'Itens de Sustentação e Suporte e de outros tipos fora de entrega de valor (por exemplo Incidente e Bug). Times sem extração na PI.';
    private const JANELA_PREV = 'Sprints de tipo valor da PI já fechadas (data final anterior a hoje). Se há sprint no filtro, apenas ela, mesmo aberta ou futura. A PI é a do filtro ou, sem filtro, a PI corrente.';
    private const ONDE_PREV = 'app/Repositories/PrevisibilidadeRepository.php (consultar, agregar, destino, linha); db/views.sql (vw_previsibilidade, vw_previsibilidade_item); config/baseline.php (done_statuses).';
    private const ONDE_PR = 'app/Repositories/PlanejadoRealizadoRepository.php (agregar, formatar, diagnosticos); db/views.sql (vw_planejado_realizado_item, vw_velocidade_sprint); config/baseline.php (done_statuses, plan_real_sustentacao_types).';
    private const JANELA_PR = 'Mesma janela da Previsibilidade: sprints de tipo valor da PI já fechadas (data final anterior a hoje) ou, se há sprint no filtro, apenas ela. A PI é a do filtro ou a PI corrente.';
    private const DONE_PR = 'Só a barra Entregues usa Done: entram os itens com status {done} (done_statuses de config/baseline.php; a mesma lista está repetida em db/views.sql, coluna entregue), pelo status de hoje.';
    private const ONDE_VEL = 'app/Repositories/VelocidadeRepository.php (consultar, agregar, sprintsExibidas); db/views.sql (vw_planejado_realizado_item, vw_item, vw_baseline_item); config/baseline.php (done_statuses, plan_real_sustentacao_types).';
    private const JANELA_VEL = 'Sprints de tipo valor da PI que já começaram (data inicial até hoje); é fechada a que tem data final anterior a hoje. Se há sprint no filtro, o gráfico termina nela. A PI é a do filtro ou a PI corrente.';

    private const TEXTOS_VELOCIDADE = [
        'velocidade_grafico' => [
            'titulo' => 'Velocidade: barras por sprint (Team Velocity)',
            'fonte' => 'Baseline (snapshot do PI Planning) e extração do Jira, pela view vw_planejado_realizado_item, para a PI e os times do filtro.',
            'unidade' => 'Story Points. O escopo usa Story Point Estimate (SPE); o Concluído usa Story Points (SP) atuais.',
            'formula' => 'Escopo Inicial = SPE da baseline dos itens cuja sprint na baseline é a sprint. Escopo Final = SPE da extração dos itens cuja sprint atual é a sprint, sem os de status Cancelado. Concluído = SP atuais dos itens entregues cuja sprint atual é a sprint. Adicionado = SPE da extração dos itens do Escopo Final que não estão no Inicial. Removido = SPE da baseline dos itens do Inicial que não estão no Final. Mudança de Escopo = SPE da extração menos SPE da baseline nos itens que estão nos dois. O mesmo item é reconhecido pela chave, na mesma sprint. Vale: Final = Inicial + Adicionado - Removido + Mudança.',
            'entra' => self::ITENS_VELOCIDADE,
            'nao_entra' => self::SEM_CATEGORIA . ' Itens Cancelados no Escopo Final. Sprints futuras e sprints de outro tipo que não valor.',
            'done' => self::DONE_ENTREGUE . ' Só o Concluído usa Done.',
            'janela' => self::JANELA_VEL,
            'onde_no_codigo' => self::ONDE_VEL,
            'limitacoes' => [
                'Mostra o estado de hoje, não o do fim da sprint. Não há histórico (changelog) do Jira.',
                'Item sem SP conta 0 no Concluído; item sem SPE conta 0 no escopo.',
                'Cada item conta na maior sprint do seu campo de sprint (sprint de referência). Por isso um item que seguiu para a sprint seguinte aparece como Removido na sprint em que foi planejado.',
                'O escopo usa SPE e o Concluído usa SP, então o Concluído pode ser maior que o Escopo Final.',
                'Time sem baseline importada fica com Escopo Inicial 0, e o escopo dele aparece como Adicionado.',
                'A sprint em andamento aparece hachurada, com Concluído parcial, e fica fora da Velocidade Média.',
            ],
            'diferencas_jira' => [
                'O Escopo Inicial vem da baseline do PI Planning, e não do estado da sprint no dia em que ela começou.',
                'Os pontos são os SP e SPE de hoje. O valor que o item tinha em cada data não é reconstituído, porque não há changelog.',
                'O Concluído inclui itens de Sustentação e Suporte e usa o status de hoje, e não a coluna do board no fim da sprint.',
                'Item que passou para uma sprint posterior (carry-over) conta como Removido na sprint de origem.',
                'A Mudança de Escopo aqui é só a reestimativa de itens presentes no Inicial e no Final. A definição usada pelo gadget do Jira não foi confirmada.',
                'A Velocidade Média usa até 4 sprints fechadas. A janela do gadget não foi confirmada.',
                'Esta legenda descreve o cálculo do JCA e não garante os mesmos números do gadget.',
            ],
        ],
        'velocidade_media' => [
            'titulo' => 'Velocidade Média (linha vermelha e destaque)',
            'fonte' => 'Série de Concluído por sprint do próprio gráfico de Velocidade (VelocidadeRepository).',
            'unidade' => 'Story Points por sprint.',
            'formula' => 'Em cada sprint fechada, média simples do Concluído das até 4 últimas sprints fechadas que terminam nela (JANELA_MEDIA = 4), com 2 casas decimais. A sprint em andamento repete o último valor fechado. O destaque em vermelho é a média do Concluído das até 4 últimas sprints fechadas exibidas; abaixo dele aparecem a quantidade de sprints e a soma do Concluído usadas.',
            'entra' => 'O Concluído das sprints fechadas exibidas, com as mesmas regras do gráfico (valor e sustentação).',
            'nao_entra' => 'A sprint em andamento e as sprints fechadas além das 4 mais recentes. Sem sprint fechada, a média não é calculada e aparece um traço.',
            'done' => self::DONE_ENTREGUE . ' A média parte do Concluído.',
            'janela' => 'Até 4 sprints fechadas entre as sprints exibidas (até a sprint do filtro, se houver). No início da PI usa as fechadas disponíveis.',
            'onde_no_codigo' => self::ONDE_VEL . ' Constante JANELA_MEDIA.',
            'limitacoes' => [
                'Depende do Concluído de hoje de cada sprint: se itens mudarem de status, a média muda.',
                'Com menos de 4 sprints fechadas, a média usa as que existem.',
                'O Concluído inclui Sustentação e Suporte.',
            ],
            'diferencas_jira' => [
                'A janela é fixa em até 4 sprints fechadas. A janela do gadget do Jira não foi confirmada.',
                'O Concluído usa SP atuais e inclui Sustentação e Suporte.',
                'A sprint em andamento fica fora da média.',
                'O Escopo vem da baseline do PI Planning e não há changelog (ver a legenda do gráfico).',
            ],
        ],
        'velocidade_tabela_sprints' => [
            'titulo' => 'Pontos por sprint (tabela)',
            'fonte' => 'Mesma série do gráfico de Velocidade (VelocidadeRepository).',
            'unidade' => 'Story Points; a coluna % concluído é percentual.',
            'formula' => 'Cada linha repete os campos do gráfico para a sprint. % concluído do Escopo Final = Concluído / Escopo Final x 100, com traço se o Escopo Final é 0. A linha Total das fechadas soma só as sprints fechadas; nela, a coluna Velocidade Média mostra o destaque do gráfico (média das últimas fechadas) e não uma soma.',
            'entra' => self::ITENS_VELOCIDADE,
            'nao_entra' => self::SEM_CATEGORIA . ' A sprint em andamento (em itálico) não entra no total.',
            'done' => self::DONE_ENTREGUE . ' Só a coluna Concluído usa Done.',
            'janela' => self::JANELA_VEL,
            'onde_no_codigo' => self::ONDE_VEL . ' Tela: public/views/vw_velocity.php (tabelaSprints).',
            'limitacoes' => [
                'Estado de hoje, não o do fim da sprint; não há changelog.',
                'Mudança aparece com sinal; Concluído usa SP e o escopo usa SPE.',
            ],
        ],
        'velocidade_por_time' => [
            'titulo' => 'Velocidade por time (gráfico)',
            'fonte' => 'Mesma agregação do gráfico de Velocidade, somada por time (VelocidadeRepository).',
            'unidade' => 'Story Points. Story Points Estimate é o Escopo Inicial (SPE da baseline); Story Points entregues é o Concluído (SP atuais).',
            'formula' => 'Por time, soma nas sprints consideradas do Escopo Inicial (barra Story Points Estimate) e do Concluído (barra Story Points entregues). As sprints somadas aparecem acima do gráfico.',
            'entra' => self::ITENS_VELOCIDADE,
            'nao_entra' => self::SEM_CATEGORIA . ' A sprint em andamento, exceto quando ela é a sprint escolhida no filtro.',
            'done' => self::DONE_ENTREGUE . ' Só Story Points entregues usa Done.',
            'janela' => 'Soma das sprints fechadas exibidas ou, se há sprint no filtro, apenas essa sprint (mesmo em andamento). ' . self::JANELA_VEL,
            'onde_no_codigo' => self::ONDE_VEL . ' Tela: public/views/vw_velocity.php (graficoTimes).',
            'limitacoes' => [
                'Time sem baseline importada tem Story Points Estimate 0 (a tabela marca o time).',
                'Times sem extração na PI ficam fora do gráfico e são citados nos avisos.',
                'Story Points Estimate é SPE e Story Points entregues é SP, então as barras não são da mesma medida.',
            ],
        ],
        'velocidade_tabela_times' => [
            'titulo' => 'Pontos por time (tabela)',
            'fonte' => 'Mesma agregação por time do gráfico Velocidade por time (VelocidadeRepository).',
            'unidade' => 'Story Points.',
            'formula' => 'Por time, soma nas sprints consideradas de Story Points Estimate (Escopo Inicial), Escopo Final e Story Points entregues (Concluído). Δ entregues - Estimate = entregues menos Estimate, com 2 casas decimais. A linha Total da seleção soma os times.',
            'entra' => self::ITENS_VELOCIDADE,
            'nao_entra' => self::SEM_CATEGORIA . ' A sprint em andamento, exceto quando ela é a sprint escolhida no filtro.',
            'done' => self::DONE_ENTREGUE . ' Só Story Points entregues usa Done.',
            'janela' => 'Soma das sprints fechadas exibidas ou, se há sprint no filtro, apenas essa sprint (mesmo em andamento). ' . self::JANELA_VEL,
            'onde_no_codigo' => self::ONDE_VEL . ' Tela: public/views/vw_velocity.php (tabelaTimes).',
            'limitacoes' => [
                'Time sem baseline importada tem Estimate 0 (o nome do time ganha uma dica).',
                'Estimate é SPE e entregues é SP, então o Δ mistura as duas medidas.',
            ],
        ],
    ];

    private const TEXTOS_PREVISIBILIDADE = [
        'prev_previsibilidade' => [
            'titulo' => 'Previsibilidade (%)',
            'fonte' => 'Baseline (PI Planning) e extração do Jira, pelas views vw_previsibilidade e vw_previsibilidade_item.',
            'unidade' => 'Percentual (1 casa decimal), calculado sobre Story Points.',
            'formula' => 'Previsibilidade = Entregue do planejado / Committed x 100. Sem Committed o resultado é traço. A meta é 80% (constante META do repositório). Cores do gráfico e da tabela: verde a partir da meta, âmbar a partir de 60%, vermelho abaixo de 60%.',
            'entra' => self::ITENS_VALOR,
            'nao_entra' => self::SO_VALOR,
            'done' => self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV . ' Tela: public/views/vw_previsibilidade.php (faixa).',
            'limitacoes' => [
                'Estado de hoje: itens entregues depois da janela também contam como entregues. Não há changelog.',
                'Item da baseline ausente da extração não conta como entregue.',
                'O percentual do total usa a soma dos times, não a média dos percentuais.',
                'Mistura SPE (Committed) e SP atuais (Entregue).',
            ],
        ],
        'prev_committed' => [
            'titulo' => 'Committed (SPE da baseline)',
            'fonte' => 'Baseline (snapshot do PI Planning) pela view vw_previsibilidade.',
            'unidade' => 'Story Point Estimate (SPE); o cartão mostra também a quantidade de itens.',
            'formula' => 'Soma do SPE da baseline dos itens de valor cuja sprint na baseline está na janela. Item sem SPE conta 0.',
            'entra' => 'Itens de entrega de valor ({valor}) da baseline, de times com extração na PI, planejados para uma sprint da janela.',
            'nao_entra' => self::SO_VALOR . ' Itens da baseline sem sprint ou com sprint fora da janela.',
            'done' => 'Não usa Done: é o planejado, medido antes da entrega.',
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'Inclui itens que depois foram cancelados ou saíram da sprint, pois o Committed é a baseline.',
                'Ajustes na baseline após a carga aparecem nos avisos da tela (trilha de auditoria).',
                'Time sem baseline importada tem Committed 0.',
            ],
        ],
        'prev_entregue' => [
            'titulo' => 'Entregue do planejado (SP atuais)',
            'fonte' => 'Baseline e extração do Jira, ligadas pela chave do item na mesma PI (vw_previsibilidade).',
            'unidade' => 'Story Points (SP) atuais; o cartão mostra também a quantidade de itens.',
            'formula' => 'Soma dos SP atuais da extração dos itens do Committed que hoje têm status de Done. Item sem SP conta 0.',
            'entra' => 'Itens do Committed (baseline de valor na janela) que estão na extração com status de Done, mesmo que tenham sido entregues depois da janela ou em outra sprint.',
            'nao_entra' => 'Itens do Committed ausentes da extração, não entregues ou cancelados. Itens entregues que não estavam no Committed (eles contam em Entregue total).',
            'done' => self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV . ' A janela vale para a sprint na baseline; a entrega pode ter ocorrido depois.',
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'Usa o status de hoje, sem data de entrega.',
                'Item entregue com SP nulo ou 0 e SPE preenchido conta 0 (sem fallback para o SPE); a contagem está nos diagnósticos do endpoint.',
            ],
        ],
        'prev_transbordo' => [
            'titulo' => 'Transbordo',
            'fonte' => 'Baseline e extração do Jira, por item (vw_previsibilidade_item), classificados em PrevisibilidadeRepository::destino.',
            'unidade' => 'SPE da baseline, mais a quantidade de itens e o percentual do Committed.',
            'formula' => 'Transbordo = SPE da baseline dos itens do Committed cuja sprint atual é posterior à janela, estejam entregues ou abertos (balde Entregue após a janela + balde Transbordo aberto). % = Transbordo / Committed x 100. Sprint posterior é a que tem ano.pi.seq maior que o da maior sprint da janela (comparação numérica, não de texto).',
            'entra' => 'Itens do Committed que estão na extração, não cancelados, com sprint atual posterior à maior sprint da janela.',
            'nao_entra' => 'Itens cuja sprint atual é a da janela ou anterior, itens sem sprint (devolvidos ao backlog), cancelados e ausentes da extração.',
            'done' => 'Done só separa o transbordo em entregue após a janela e aberto. ' . self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'Item cuja sprint atual não segue o padrão ano.pi.seq não é tratado como posterior.',
                'Estado de hoje: o item pode ter sido replanejado várias vezes e só a sprint atual é vista.',
            ],
        ],
        'prev_variacao' => [
            'titulo' => 'Variação de estimativa',
            'fonte' => 'Baseline e extração do Jira, pela view vw_previsibilidade.',
            'unidade' => 'Story Points (diferença com sinal).',
            'formula' => 'Variação = SP atuais dos itens planejados que hoje estão entregues menos o SPE da baseline desses mesmos itens. Positivo quer dizer que hoje valem mais pontos que no PI Planning; negativo, menos.',
            'entra' => 'Itens do Committed com status de Done na extração.',
            'nao_entra' => 'Itens do Committed não entregues, cancelados ou ausentes da extração.',
            'done' => self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'Compara SP de hoje com SPE da baseline, duas medidas diferentes. Item entregue sem SP conta 0 e puxa a variação para baixo.',
                'Não separa reestimativa de erro de preenchimento.',
            ],
        ],
        'prev_cancelado' => [
            'titulo' => 'Cancelado',
            'fonte' => 'Baseline e extração do Jira, por item (vw_previsibilidade_item).',
            'unidade' => 'SPE da baseline, mais a quantidade de itens.',
            'formula' => 'Soma do SPE da baseline dos itens do Committed que estão na extração com status Cancelado.',
            'entra' => 'Itens do Committed (valor, sprint na baseline dentro da janela) cujo status atual na extração é Cancelado.',
            'nao_entra' => 'Itens do Committed ausentes da extração (ficam em Fora da extração) e itens cancelados fora do Committed.',
            'done' => 'Não usa Done. O item cancelado não está entre os status de Done.',
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'O SPE cancelado continua dentro do Committed e, por isso, baixa a Previsibilidade.',
                'Usa o status de hoje.',
            ],
        ],
        'prev_escopo_adicionado' => [
            'titulo' => 'Escopo adicionado',
            'fonte' => 'Extração do Jira comparada com a baseline, pela view vw_previsibilidade.',
            'unidade' => 'Story Points (SP) atuais; o cartão mostra também a quantidade de itens.',
            'formula' => 'Soma dos SP atuais dos itens de valor da extração cuja sprint atual está na janela e que não estão na baseline da janela. Isso reúne os itens que não existem na baseline e os que existem na baseline com sprint fora da janela (ou sem sprint).',
            'entra' => 'Itens de entrega de valor da extração, com sprint atual na janela, fora da baseline da janela.',
            'nao_entra' => 'Itens que estão na baseline com sprint dentro da janela, e itens sem sprint ou com sprint fora da janela. ' . self::SO_VALOR,
            'done' => 'Não usa Done: conta itens em qualquer status.',
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV,
            'limitacoes' => [
                'Não filtra por status: itens cancelados com sprint na janela entram na soma.',
                'Usa SP atuais; item sem SP conta 0.',
                'A resposta separa os itens sem baseline dos que estão na baseline em outra sprint, mas o cartão mostra só o total.',
            ],
        ],
        'prev_grafico_time' => [
            'titulo' => 'Previsibilidade por time (gráfico)',
            'fonte' => 'Mesma agregação por time dos cartões (PrevisibilidadeRepository).',
            'unidade' => 'Percentual (1 casa decimal).',
            'formula' => 'Uma barra por time com Previsibilidade = Entregue do planejado / Committed x 100. A linha tracejada marca a meta de 80%. Time sem Committed mostra "sem committed". O tooltip traz o entregue e o Committed em SP.',
            'entra' => self::ITENS_VALOR,
            'nao_entra' => self::SO_VALOR,
            'done' => self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV . ' Tela: public/views/vw_previsibilidade.php (grafico).',
            'limitacoes' => [
                'Times sem extração na PI ficam fora do gráfico.',
                'Cores: verde a partir da meta, âmbar a partir de 60%, vermelho abaixo de 60%.',
            ],
        ],
        'prev_grafico_transbordo' => [
            'titulo' => 'Destino do committed (transbordo)',
            'fonte' => 'Baseline e extração do Jira, por item (vw_previsibilidade_item), classificados em PrevisibilidadeRepository::destino.',
            'unidade' => 'SPE da baseline. A soma das barras de um time é o Committed dele.',
            'formula' => 'Cada item do Committed cai em um só balde, testados nesta ordem: Fora da extração (ausente da extração); Cancelado; entre os entregues, Entregue após a janela (sprint atual posterior à janela) ou Entregue na janela (demais, inclusive sem sprint); entre os não entregues, Devolvido ao backlog (sem sprint), Transbordo aberto (sprint atual posterior à janela) ou Não concluído na janela (demais). A barra de cada balde soma o SPE da baseline dos itens. O tooltip mostra também a quantidade de itens e, nos entregues, os SP atuais.',
            'entra' => 'Itens do Committed: valor, na baseline, com sprint na baseline dentro da janela.',
            'nao_entra' => self::SO_VALOR . ' Itens fora do Committed (Escopo adicionado).',
            'done' => self::DONE_ENTREGUE . ' Done separa os baldes entregues dos demais.',
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV . ' Tela: public/views/vw_previsibilidade.php (graficoTransbordo, BALDES).',
            'limitacoes' => [
                'Sprint posterior é decidida pela tupla ano.pi.seq; sprint fora desse padrão não conta como posterior.',
                'Estado de hoje: só a sprint atual do item é vista.',
            ],
        ],
        'prev_tabela' => [
            'titulo' => 'Números por time (tabela)',
            'fonte' => 'Baseline e extração do Jira (vw_previsibilidade e vw_previsibilidade_item).',
            'unidade' => 'Committed, transbordo e cancelado em SPE da baseline; entregue, variação, escopo adicionado e entregue total em SP atuais; previsibilidade em percentual.',
            'formula' => 'As colunas repetem os cartões, por time: Committed, Entregue do planejado, Previsibilidade, Variação de estimativa, Transbordo (com % do Committed), Cancelado e Escopo adicionado. Entregue total = SP atuais dos itens de valor entregues com sprint atual na janela, estejam ou não no Committed. A linha Total da seleção usa a soma de todos os times.',
            'entra' => self::ITENS_VALOR,
            'nao_entra' => self::SO_VALOR,
            'done' => self::DONE_ENTREGUE,
            'janela' => self::JANELA_PREV,
            'onde_no_codigo' => self::ONDE_PREV . ' Tela: public/views/vw_previsibilidade.php (tabela).',
            'limitacoes' => [
                'Time sem baseline importada tem o nome com uma dica e Committed 0.',
                'Entregue total não é o numerador da Previsibilidade: ele inclui itens que não estavam no Committed.',
                'Mistura SPE e SP atuais.',
            ],
        ],
    ];

    private const TEXTOS_PLAN_REAL = [
        'pr_velocidade' => [
            'titulo' => 'Velocidade (barra e cartão)',
            'fonte' => 'View vw_velocidade_sprint: composição ativa dos times, calendário das sprints e histórico de sustentação (sustentacao_historico).',
            'unidade' => 'Story Points de capacidade planejada. Não são itens entregues.',
            'formula' => 'Por time e sprint da janela: Velocity = ROUND(qtde_dev x horas_dev x dias_uteis da sprint / média de produtividade da PI). Sustentação = pontos registrados em sustentacao_historico para o time, a PI e a sprint (0 se não houver). Capacidade = Velocity - Sustentação. A barra soma Sustentação e Capacidade de todos os times e sprints da janela, e o total é essa soma.',
            'entra' => 'Times ativos (de jornada ativa) com composição ativa, desde que tenham extração na PI, nas sprints da janela.',
            'nao_entra' => 'Times sem extração na PI. Time sem média de produtividade positiva não tem Velocity (a Sustentação registrada dele ainda entra).',
            'done' => 'Não usa Done: é capacidade calculada, não item entregue.',
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR . ' Visão: vw_velocidade_sprint.',
            'limitacoes' => [
                'Não é a Velocidade da tela Velocidade, que mede o Concluído.',
                'Os valores vêm de cadastros (composição do time, calendário e sustentação histórica), não de itens do Jira.',
                'O Velocity é arredondado para inteiro em cada sprint antes da soma.',
            ],
        ],
        'pr_pi_planning' => [
            'titulo' => 'PI Planning (barra e cartão Comprometido)',
            'fonte' => 'Baseline (snapshot do PI Planning) pela view vw_planejado_realizado_item.',
            'unidade' => 'Story Point Estimate (SPE).',
            'formula' => 'Soma do SPE da baseline dos itens cuja sprint na baseline está na janela, separada em Sustentação, Entrega de Valor sem épico e Entrega de Valor com épico (campo pai preenchido). No cartão, o percentual é PI Planning / Velocidade x 100, com traço se a Velocidade é 0.',
            'entra' => 'Itens de entrega de valor ({valor}) e de Sustentação ({sust}) da baseline, dos times com extração na PI.',
            'nao_entra' => 'Itens de outros tipos (por exemplo Incidente e Bug), listados nos diagnósticos. Itens sem SPE contam 0.',
            'done' => 'Não usa Done: é o planejado.',
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR,
            'limitacoes' => [
                'A baseline não é filtrada por status: itens que depois foram cancelados continuam na barra.',
                'Time sem baseline importada contribui com 0.',
            ],
        ],
        'pr_sprint_planning' => [
            'titulo' => 'Sprint Planning (barra e cartão)',
            'fonte' => 'Extração do Jira pela view vw_planejado_realizado_item.',
            'unidade' => 'Story Point Estimate (SPE) atual.',
            'formula' => 'Soma do SPE da extração dos itens cuja sprint atual está na janela, sem os de status Cancelado, separada nas mesmas categorias. O Δ do cartão é Sprint Planning menos PI Planning.',
            'entra' => 'Itens de entrega de valor ({valor}) e de Sustentação ({sust}) da extração, dos times com extração na PI.',
            'nao_entra' => 'Itens com status Cancelado. Itens de outros tipos (por exemplo Incidente e Bug). Itens sem sprint ou de sprint fora da janela.',
            'done' => 'Não usa Done: conta itens em qualquer status, exceto Cancelado.',
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR,
            'limitacoes' => [
                'É o estado de hoje, que pode ter mudado depois do início da sprint. Não há changelog.',
                'O Δ junta novas histórias, cancelamentos e remanejamentos, sem separá-los.',
            ],
        ],
        'pr_entregue' => [
            'titulo' => 'Entregues (barra e cartão Entregue)',
            'fonte' => 'Extração do Jira pela view vw_planejado_realizado_item.',
            'unidade' => 'Story Points (SP) atuais.',
            'formula' => 'Soma dos SP atuais dos itens com status de Done cuja sprint atual está na janela, separada nas mesmas categorias. O Δ do cartão é Entregues menos Sprint Planning; o percentual é Entregues / Sprint Planning x 100, com traço se o Sprint Planning é 0.',
            'entra' => 'Itens de entrega de valor ({valor}) e de Sustentação ({sust}) da extração com status de Done, dos times com extração na PI.',
            'nao_entra' => 'Itens não entregues e itens de outros tipos (por exemplo Incidente e Bug). Item sem SP conta 0.',
            'done' => self::DONE_PR,
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR,
            'limitacoes' => [
                'Usa o status de hoje, sem data de entrega.',
                'Mistura SP (Entregues) com SPE (Sprint Planning) no Δ e no percentual.',
                'Entregue com SP nulo e SPE preenchido conta 0; os diagnósticos contam esses casos.',
            ],
        ],
        'pr_grafico' => [
            'titulo' => 'Planejado x Realizado (gráfico)',
            'fonte' => 'Quatro barras: Velocidade (vw_velocidade_sprint), PI Planning (baseline), Sprint Planning (extração) e Entregues (extração).',
            'unidade' => 'Story Points. Velocidade e Capacidade são pontos de capacidade, PI Planning e Sprint Planning usam SPE, e Entregues usa SP atuais.',
            'formula' => 'Cada barra é empilhada por categoria: Sustentação, Entrega de Valor (sem épico), Entrega de Valor (com épico) e, só na Velocidade, Capacidade. O rótulo à direita é o total da barra. Ao lado do total de Sprint Planning e de Entregues aparece o Δ (diferente de zero): Sprint Planning menos PI Planning, e Entregues menos Sprint Planning. As barras Velocidade, PI Planning e Sprint Planning formam o grupo Planejado; Entregues forma o grupo Entregue.',
            'entra' => 'Ver as legendas de cada barra: Velocidade, PI Planning, Sprint Planning e Entregues.',
            'nao_entra' => 'Itens de outros tipos (por exemplo Incidente e Bug) e times sem extração na PI.',
            'done' => self::DONE_PR,
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR . ' Tela: public/views/vw_plan_real.php (grafico).',
            'limitacoes' => [
                'As barras não têm a mesma unidade, então comparar os totais é aproximado.',
                'O tooltip mostra cada categoria com o percentual do total da barra.',
            ],
        ],
        'pr_tabela_pontos' => [
            'titulo' => 'Pontos por barra (tabela)',
            'fonte' => 'Mesmas quatro barras do gráfico Planejado x Realizado.',
            'unidade' => 'Story Points.',
            'formula' => 'Cada linha traz os pontos da barra por categoria e o total. Na Velocidade aparecem Sustentação e Capacidade; nas demais, Sustentação, Valor sem épico e Valor com épico. As duas últimas linhas trazem o Δ por categoria: PI Planning para Sprint Planning, e Sprint Planning para Entregues. Abaixo da tabela, a nota lista os tipos de item tratados como Sustentação (plan_real_sustentacao_types).',
            'entra' => 'Ver as legendas de cada barra.',
            'nao_entra' => 'Itens de outros tipos (por exemplo Incidente e Bug) e times sem extração na PI.',
            'done' => self::DONE_PR,
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR . ' Tela: public/views/vw_plan_real.php (tabelaPontos).',
            'limitacoes' => [
                'Os tipos de sustentação vêm de config/baseline.php; mudar a lista muda as categorias.',
                'Traço significa categoria que a barra não tem.',
            ],
        ],
        'pr_tabela_times' => [
            'titulo' => 'Pontos por time (tabela)',
            'fonte' => 'Mesmas quatro barras, calculadas por time (PlanejadoRealizadoRepository).',
            'unidade' => 'Story Points.',
            'formula' => 'Por time, o total de cada barra e, abaixo dele, o detalhe: S = Sustentação, SE = Valor sem épico, CE = Valor com épico, C = Capacidade. As duas últimas colunas são os Δ totais (PI Planning para Sprint Planning, Sprint Planning para Entregues). A linha Total da seleção usa as barras somadas.',
            'entra' => 'Times ativos da seleção com extração na PI. Ver as legendas de cada barra.',
            'nao_entra' => 'Times sem extração na PI ficam fora de todas as barras e são citados nos avisos.',
            'done' => self::DONE_PR,
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR . ' Tela: public/views/vw_plan_real.php (tabelaTimes).',
            'limitacoes' => [
                'Time sem baseline importada tem uma dica no nome e PI Planning 0.',
            ],
        ],
        'pr_diagnosticos' => [
            'titulo' => 'Diagnósticos dos dados',
            'fonte' => 'Extração do Jira e baseline pela view vw_planejado_realizado_item, na janela e nos times da seleção.',
            'unidade' => 'Quantidade de itens e pontos (SPE ou SP, conforme a barra).',
            'formula' => 'Lista (1) os tipos de item sem categoria, que ficam fora das barras, com itens e pontos que cada barra usaria (SPE em PI Planning e Sprint Planning, SP em Entregues); (2) os status dos itens de Sustentação na extração e se cada status conta como entregue; (3) itens de Sustentação com SP nulo e SPE maior que 0, no total e entre os entregues; (4) itens de valor entregues com SP nulo e SPE maior que 0. Nenhum fallback é aplicado: os diagnósticos só informam.',
            'entra' => 'Itens da janela e dos times da seleção, de qualquer tipo.',
            'nao_entra' => 'Itens fora da janela e de times sem extração na PI.',
            'done' => 'O item "considerado entregue" na lista de status segue a regra de Done. ' . self::DONE_ENTREGUE,
            'janela' => self::JANELA_PR,
            'onde_no_codigo' => self::ONDE_PR . ' Método diagnosticos.',
            'limitacoes' => [
                'Os diagnósticos não alteram nenhum número das barras.',
                'Itens sem tipo aparecem como (sem tipo).',
            ],
        ],
    ];

    private const ONDE_LT = 'app/Repositories/LeadTimeRepository.php (consultar, itens, estatisticas, implantacaoResumo, diagnosticos); db/views.sql (vw_lead_time_item, vw_item, vw_extracao_calculada: dias_desenvolvimento, dias_homologacao, dias_implantacao); config/baseline.php (done_statuses).';
    private const JANELA_LT = 'Itens cuja sprint de referência atual está na janela: as sprints de tipo valor da PI já fechadas (data final anterior a hoje) ou, se há sprint no filtro, apenas ela, mesmo aberta ou futura. A PI é a do filtro ou, sem filtro, a PI corrente.';
    private const ENTRA_LT = 'Itens de entrega de valor ({valor}) já entregues, de times com extração na PI, cuja sprint de referência está na janela. Cada item entra pelo menos com uma das etapas Desenvolvimento, Homologação ou Implantação.';
    private const FORA_LT = 'Itens de outros tipos (por exemplo Sustentação, Incidente e Bug), itens de valor ainda não entregues e itens sem nenhuma das três etapas (estes só aparecem na contagem de itens sem etapa). Ideação não entra. Times sem extração na PI.';
    private const DONE_LT = 'Só entram itens com status {done} (done_statuses de config/baseline.php; a mesma lista está repetida em db/views.sql, coluna entregue). Pendente de Produção conta como entregue mesmo sem implantação concluída. Vale o status de hoje.';
    private const DIAS_UTEIS_LT = 'Cada etapa é medida em dias úteis entre o início e o fim dela na extração; a fórmula SQL desconta sábados e domingos. Desenvolvimento e Homologação valem no mínimo 1 dia. Implantação com resultado 0 vale 1, e com resultado negativo fica sem valor. Etapa sem data de início ou de fim fica sem valor.';
    private const LIM_ETAPAS_LT = 'O tempo de bloqueio não é descontado (o comentário na view SQL registra que fica ignorado).';

    private const TEXTOS_LEAD_TIME = [
        'lt_medio' => [
            'titulo' => 'Lead time médio (cartão)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item (que lê vw_item), resumida em LeadTimeRepository::estatisticas.',
            'unidade' => 'Dias úteis, com 1 casa decimal na tela.',
            'formula' => 'Lead time do item = dias de Desenvolvimento + Homologação + Implantação, somando só as etapas que o item tem. O cartão mostra a média simples do lead time de todos os itens da seleção que têm pelo menos uma etapa. ' . self::DIAS_UTEIS_LT,
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (kpis).',
            'limitacoes' => [
                'Item que não tem uma das etapas soma menos, então o lead time dele fica subestimado em relação ao dos itens completos.',
                self::LIM_ETAPAS_LT,
                'Não é o lead time da planilha (vw_extracao_calculada.lead_time, que parte da data de criação e inclui a ideação). Aquele cálculo existe na view, mas esta tela usa a soma das três etapas.',
                'Usa o status e a sprint de hoje, sem histórico (changelog) do Jira.',
            ],
        ],
        'lt_itens' => [
            'titulo' => 'Itens considerados (cartão)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item, contados em LeadTimeRepository::estatisticas.',
            'unidade' => 'Quantidade de itens.',
            'formula' => 'Contagem dos itens da seleção que têm pelo menos uma etapa. Itens entregues sem nenhuma etapa não entram nessa contagem nem na média; a quantidade deles aparece no texto do cartão.',
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (kpis).',
            'limitacoes' => [
                'É a base de todas as médias da tela. Com poucos itens, uma média pode oscilar bastante.',
                'Usa o status e a sprint de hoje.',
            ],
        ],
        'lt_etapa' => [
            'titulo' => 'Etapa que mais pesa (cartão)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item, resumida em LeadTimeRepository::estatisticas e escolhida na view da tela.',
            'unidade' => 'Dias úteis e percentual do lead time médio.',
            'formula' => 'Contribuição da etapa = soma dos dias da etapa dividida pelo número de itens da seleção (item sem a etapa conta 0). O cartão mostra a etapa de maior contribuição, os dias dela, o lead time médio e a razão entre os dois em percentual (sem casas decimais). A soma das contribuições das três etapas é o lead time médio.',
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (kpis).',
            'limitacoes' => [
                'A contribuição divide pelos itens da seleção, e não pelos itens que têm a etapa. Etapa presente em poucos itens pesa pouco mesmo quando é longa; a média só nos itens que a têm está na tabela de detalhes.',
                self::LIM_ETAPAS_LT,
            ],
        ],
        'lt_p50' => [
            'titulo' => 'Mediana (P50) e máximo (cartão)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item, resumida em LeadTimeRepository::estatisticas.',
            'unidade' => 'Dias úteis.',
            'formula' => 'P50 = mediana do lead time dos itens da seleção: com quantidade ímpar de itens, o valor central; com quantidade par, a média dos dois valores centrais. O texto do cartão traz o máximo, que é o maior lead time de um item.',
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (kpis).',
            'limitacoes' => [
                'A mediana é menos sensível a itens muito longos que a média; uma diferença grande entre as duas indica itens fora do padrão.',
                'O máximo vem de um único item e não é um percentil.',
            ],
        ],
        'lt_sem_implantacao' => [
            'titulo' => 'Entregues sem implantação concluída (cartão)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item (colunas status e fim_implantacao), contada em LeadTimeRepository::implantacaoResumo.',
            'unidade' => 'Quantidade de itens e percentual dos entregues.',
            'formula' => 'Entre todos os itens de valor entregues da seleção (inclusive os sem etapa), concluída é a que tem data de fim de implantação. O cartão mostra entregues menos concluídas, de quantos entregues, e o percentual correspondente. O texto separa os itens com status Pendente de Produção sem fim de implantação dos que estão em outro status sem fim. O cartão é um alerta e não altera nenhuma média de lead time.',
            'entra' => self::ENTRA_LT . ' Aqui entram também os itens sem nenhuma etapa.',
            'nao_entra' => 'Itens de outros tipos, itens de valor ainda não entregues e times sem extração na PI.',
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (kpis).',
            'limitacoes' => [
                'Pendente de Produção conta como entregue, mas ainda não tem fim de implantação. Times com muitos itens assim ficam com a Implantação subestimada e não são diretamente comparáveis.',
                'Na cor do cartão, âmbar quer dizer que há ao menos um item sem implantação concluída.',
            ],
        ],
        'lt_grafico' => [
            'titulo' => 'Cycle time médio por etapas (gráfico)',
            'fonte' => 'Extração do Jira pela view vw_lead_time_item, resumida por time e por jornada em LeadTimeRepository.',
            'unidade' => 'Dias úteis.',
            'formula' => 'Cada coluna é um time, mais a coluna destacada Média da jornada. A coluna é empilhada em Desenvolvimento, Homologação e Implantação, e cada segmento é a contribuição da etapa: soma dos dias da etapa dividida pelo número de itens do time (item sem a etapa conta 0). A soma dos segmentos é o lead time médio, escrito acima da coluna. A média da jornada é calculada sobre todos os itens da jornada, e não é a média das médias dos times. Com mais de uma jornada, há uma coluna de média por jornada. Time sem item mostra "sem itens". ' . self::DIAS_UTEIS_LT,
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (grafico).',
            'limitacoes' => [
                'O tooltip mostra também a média nos itens que têm a etapa, com o n, o lead time médio, o P50 e o máximo. Na Implantação mostra ainda em quantos itens ela foi concluída.',
                'O símbolo ⚠ no eixo marca o time ou a média com menos de 50% dos itens com implantação concluída. Nesses casos a Implantação está subestimada.',
                'Segmentos muito baixos ficam sem número; o valor está no tooltip.',
                self::LIM_ETAPAS_LT,
            ],
        ],
        'lt_tabela' => [
            'titulo' => 'Cycle time médio por etapas (tabela)',
            'fonte' => 'Mesmos dados do gráfico (LeadTimeRepository).',
            'unidade' => 'Dias úteis; a coluna Itens é uma quantidade.',
            'formula' => 'Por time: contribuição de Desenvolvimento, Homologação e Implantação, lead time médio, itens e quantos itens têm a implantação concluída (com o aviso de quantos estão pendentes de produção). A linha Média da jornada usa todos os itens da jornada, e não a média dos times. Com mais de uma jornada aparecem um título por jornada e a linha Total da seleção, com todos os itens.',
            'entra' => self::ENTRA_LT,
            'nao_entra' => self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (tabelaPrincipal).',
            'limitacoes' => [
                'A Implantação soma só as etapas que o item tem. Times com muitos itens pendentes de produção aparecem com implantação subestimada.',
                'Traço indica que não há itens para calcular.',
                self::LIM_ETAPAS_LT,
            ],
        ],
        'lt_tabela_secundaria' => [
            'titulo' => 'Detalhes: média nos itens que têm a etapa, P50 e máximo',
            'fonte' => 'Mesmos dados do gráfico (LeadTimeRepository::estatisticas).',
            'unidade' => 'Dias úteis; n e Sem etapa são quantidades de itens.',
            'formula' => 'Em cada etapa, média = soma dos dias da etapa dividida só pelos itens que têm a etapa (o n aparece entre parênteses). P50 é a mediana do lead time dos itens e Máx. é o maior. Sem etapa é a quantidade de itens entregues sem nenhuma das três etapas, que ficam fora das médias.',
            'entra' => self::ENTRA_LT,
            'nao_entra' => 'Itens sem nenhuma etapa ficam fora das médias e do P50, e são contados na coluna Sem etapa. ' . self::FORA_LT,
            'done' => self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Tela: public/views/vw_cycle_time.php (tabelaSecundaria).',
            'limitacoes' => [
                'A média nos itens que têm a etapa é maior ou igual à contribuição da etapa mostrada na tabela principal.',
                'Média com n pequeno é pouco confiável.',
            ],
        ],
        'lt_diagnosticos' => [
            'titulo' => 'Diagnósticos dos dados (Lead Time)',
            'fonte' => 'Extração do Jira pela view vw_item e pela view vw_lead_time_item, na janela e nos times da seleção (LeadTimeRepository::diagnosticos).',
            'unidade' => 'Quantidade de itens.',
            'formula' => 'Três contagens: itens da janela fora do lead time por não serem de entrega de valor, por tipo; itens de entrega de valor ainda não entregues, por status; itens entregues sem nenhuma etapa, por time. São só informação e não alteram nenhum número da tela.',
            'entra' => 'Itens de qualquer tipo e status da janela e dos times da seleção.',
            'nao_entra' => 'Itens fora da janela e de times sem extração na PI.',
            'done' => 'A lista de não entregues separa os itens de valor que ainda não têm status de Done. ' . self::DONE_LT,
            'janela' => self::JANELA_LT,
            'onde_no_codigo' => self::ONDE_LT . ' Método diagnosticos. Tela: public/views/vw_cycle_time.php (diagnosticos).',
            'limitacoes' => [
                'Item sem tipo ou sem status aparece como (vazio).',
                'Itens sem etapa são consequência de datas de início ou de fim vazias no Jira.',
            ],
        ],
    ];

    private const ONDE_PD = 'app/Repositories/ProdutividadeRepository.php (consultar, aoVivo, somarEntregues, historico, agregarJornada, referenciaPlanejamento); db/views.sql (vw_produtividade_pi, vw_item); bin/consolidar_produtividade.php (tabela produtividade_historico); config/baseline.php (done_statuses).';
    private const ENTRA_PD = 'Itens de entrega de valor ({valor}) já entregues, dos times da seleção. Nas PIs fechadas, os itens da PI inteira; na PI de referência ao vivo, os itens cuja sprint de referência está na janela.';
    private const FORA_PD = 'Itens de outros tipos (por exemplo Sustentação, Incidente e Bug) e itens de valor ainda não entregues. Os pontos de itens sem Story Points não entram (sem uso do estimate como substituto). Times sem dados na PI.';
    private const DONE_PD = 'Só entram itens com status {done} (done_statuses de config/baseline.php; a mesma lista está repetida em db/views.sql, coluna entregue). Vale o status de hoje.';
    private const JANELA_PD = 'PI fechada (data final anterior a hoje): consolidado da PI inteira em produtividade_historico, sem janela de sprints. PI de referência em andamento: ao vivo, só com os itens cuja sprint de referência está nas sprints de valor fechadas da PI. Com sprint no filtro, a PI de referência é recalculada ao vivo só para essa sprint e as demais PIs não mudam. A PI de referência é a do filtro Versão ou, sem filtro, a PI corrente; as colunas são as PIs ativas do catálogo, em ordem cronológica, até ela.';
    private const LIM_HORAS_PD = 'As horas são o tempo gasto total do item no Jira (aggregatetimespent), acumulado durante a vida do item. Não há divisão por sprint: no cálculo ao vivo, todas as horas do item entram na última sprint dele (sprint de referência).';

    private const TEXTOS_PRODUTIVIDADE = [
        'pd_jornada' => [
            'titulo' => 'Produtividade da jornada (cartão)',
            'fonte' => 'Extração do Jira (vw_item e vw_produtividade_pi) e, para PI fechada, produtividade_historico, resumidos em ProdutividadeRepository::agregarJornada.',
            'unidade' => 'Horas por Story Point (h/pt), com 2 casas decimais.',
            'formula' => 'Produtividade = soma das horas gastas dividida pela soma dos Story Points dos itens entregues (horas = tempo gasto em segundos dividido por 3600). A média da jornada é ponderada: soma das horas de todos os times da seleção dividida pela soma dos pontos deles na PI de referência, e traço se os pontos somam 0. O texto do cartão traz as horas, os pontos e, só como dado, a média simples das produtividades dos times. Quanto menos horas por ponto, melhor.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD,
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (kpis).',
            'limitacoes' => [
                self::LIM_HORAS_PD,
                'Item entregue sem Story Points entra nas horas e não entra nos pontos, o que eleva a produtividade (mais horas por ponto). Não há uso do estimate como substituto; a contagem está nos diagnósticos.',
                'A média ponderada dá mais peso a times com mais pontos; ela difere da média simples dos times.',
                'Usa o status de hoje.',
            ],
        ],
        'pd_melhor' => [
            'titulo' => 'Melhor time (cartão)',
            'fonte' => 'Produtividade por time na PI de referência (ProdutividadeRepository), ordenada na view da tela.',
            'unidade' => 'Horas por Story Point (h/pt).',
            'formula' => 'Entre os times que têm produtividade calculada e horas maiores que 0 na PI de referência, o cartão mostra o de menor h/pt. Produtividade do time = horas dividido por pontos.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD . ' Times sem produtividade na PI de referência (por exemplo, pontos 0) ficam fora da comparação.',
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (kpis).',
            'limitacoes' => [
                'Compara times de tamanhos e tipos de trabalho diferentes, e não mede qualidade nem esforço de cada um.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_pior' => [
            'titulo' => 'Pior time (cartão)',
            'fonte' => 'Produtividade por time na PI de referência (ProdutividadeRepository), ordenada na view da tela.',
            'unidade' => 'Horas por Story Point (h/pt).',
            'formula' => 'Entre os times que têm produtividade calculada e horas maiores que 0 na PI de referência, o cartão mostra o de maior h/pt. O cartão só mostra um time se há mais de um time comparável; com um só, aparece traço.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD . ' Times sem produtividade na PI de referência (por exemplo, pontos 0) ficam fora da comparação.',
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (kpis).',
            'limitacoes' => [
                'Mais horas por ponto pode vir de pontos pouco estimados, ou de itens sem Story Points, que contam horas sem pontos.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_variacao' => [
            'titulo' => 'Variação vs PI anterior (cartão)',
            'fonte' => 'Produtividade ponderada da jornada em duas PIs (ProdutividadeRepository::agregarJornada), comparadas na view da tela.',
            'unidade' => 'Horas por Story Point (diferença com sinal) e percentual com 1 casa decimal.',
            'formula' => 'Variação = produtividade ponderada da PI de referência menos a da PI anterior que aparece nas colunas, isto é, a PI imediatamente anterior no catálogo com dados para a seleção. O percentual é a variação dividida pela produtividade da PI anterior. Negativo (▼) quer dizer menos horas por ponto, ou seja, melhorou; positivo (▲), piorou. Sem PI anterior com dados ou sem produtividade em uma das duas, aparece traço.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD,
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (kpis).',
            'limitacoes' => [
                'A PI de referência pode estar em andamento (ao vivo, só com as sprints fechadas) e a anterior ser o consolidado da PI inteira; os dois períodos não têm o mesmo tamanho.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_sugestao' => [
            'titulo' => 'Sugestão para a próxima PI (cartão)',
            'fonte' => 'Histórico consolidado (produtividade_historico) das PIs fechadas, agregado em ProdutividadeRepository::consultar.',
            'unidade' => 'Horas por Story Point (h/pt).',
            'formula' => 'É a produtividade ponderada da jornada na PI fechada mais recente (até a PI de referência) que tenha produtividade no histórico consolidado. O texto do cartão indica a PI de origem. Sem PI fechada com dados, aparece traço. A sugestão é somente leitura: a tela não grava nada em times_composicao.',
            'entra' => 'Linhas do histórico consolidado dos times da seleção, em PIs fechadas até a PI de referência.',
            'nao_entra' => 'PI em andamento e PI fechada ainda sem consolidado em produtividade_historico. ' . self::FORA_PD,
            'done' => self::DONE_PD . ' O consolidado foi calculado quando a PI foi gravada em bin/consolidar_produtividade.php.',
            'janela' => 'PIs fechadas (data final anterior a hoje) até a PI de referência, da mais recente para a mais antiga; vale a primeira com produtividade.',
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (kpis).',
            'limitacoes' => [
                'O valor consolidado não muda quando o Jira muda depois da consolidação; só uma nova execução do script de consolidação o atualiza.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_grafico_jornada' => [
            'titulo' => 'Total em horas para entregar 1 ponto (barra da jornada)',
            'fonte' => 'Produtividade ponderada da jornada na PI de referência (ProdutividadeRepository::agregarJornada).',
            'unidade' => 'Horas por Story Point (h/pt).',
            'formula' => 'Uma barra com a produtividade ponderada da PI de referência: soma das horas dividida pela soma dos pontos dos times. O rótulo da barra é SELEÇÃO quando há mais de uma jornada e JORNADA quando há uma. O selo ao lado do título indica se a PI é ao vivo ou histórico. O tooltip mostra a conta (horas dividido por pontos).',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD,
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (graficoJornada).',
            'limitacoes' => [
                'Sem dados, a barra tem tamanho 0 e o rótulo diz "sem dados"; 0 não é um valor de produtividade.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_grafico_times' => [
            'titulo' => 'Total em horas para entregar 1 ponto (por time e PI)',
            'fonte' => 'Produtividade por time e PI: histórico consolidado nas PIs fechadas e extração ao vivo na PI de referência em andamento (ProdutividadeRepository).',
            'unidade' => 'Horas por Story Point (h/pt).',
            'formula' => 'Colunas agrupadas por time, uma série por PI, com PIs em ordem cronológica até a PI de referência e só as que têm dados para a seleção. Cada barra é horas dividido por pontos do time naquela PI, com o rótulo do valor. Time sem dado na PI mostra um traço, e não zero. Menos horas por ponto é melhor. O tooltip mostra a conta e se o valor é ao vivo ou histórico.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD . ' Times sem dados em nenhuma PI ficam fora do gráfico e são citados nos avisos.',
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (graficoTimes).',
            'limitacoes' => [
                'Ao vivo e histórico usam janelas diferentes (sprints fechadas contra PI inteira), então uma PI em andamento não é totalmente comparável com as fechadas.',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_tabela' => [
            'titulo' => 'Produtividade (h/ponto) por time e PI (tabela)',
            'fonte' => 'Mesmos dados do gráfico por time, mais a referência de planejamento em times_composicao.media_produtividade_pi (ProdutividadeRepository::referenciaPlanejamento).',
            'unidade' => 'Horas por Story Point (h/pt); o Δ em h/pt e em percentual.',
            'formula' => 'Uma coluna por PI com a produtividade do time (horas dividido por pontos) e, na linha final, a da jornada (ou da seleção), ponderada, com a média simples dos times entre parênteses. Referência (planejamento) é a média de produtividade da PI cadastrada na composição ativa do time (Velocity). Δ = produtividade da PI de referência menos a referência, e o percentual é o Δ dividido pela referência, omitido se a referência é 0. Sem referência ou sem produtividade na PI de referência, o Δ aparece como traço. A coluna Sugestão traz a produtividade da PI fechada mais recente do histórico do time e a PI de origem. Na linha da jornada, Referência e Δ ficam em traço.',
            'entra' => self::ENTRA_PD,
            'nao_entra' => self::FORA_PD,
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Tela: public/views/vw_produtividade.php (tabela).',
            'limitacoes' => [
                'Traço é time sem dado naquela PI, e não zero. O símbolo ⚠ marca itens entregues sem Story Points, que não entram nos pontos.',
                'A quantidade de itens e de itens sem SP mostrada nas PIs fechadas é lida ao vivo da view vw_produtividade_pi; horas e pontos vêm do consolidado. Por isso podem divergir se o Jira mudou depois da consolidação.',
                'Se o time tem mais de uma composição ativa, vale a última lida (maior id).',
                self::LIM_HORAS_PD,
            ],
        ],
        'pd_diagnosticos' => [
            'titulo' => 'Diagnósticos dos dados (Produtividade)',
            'fonte' => 'Resposta do ProdutividadeRepository::consultar (itens sem Story Points, times sem dados e PIs sem consolidado).',
            'unidade' => 'Quantidade de itens e lista de times e PIs.',
            'formula' => 'Lista (1) por PI e time, os itens entregues sem Story Points, que ficam fora dos pontos; (2) por PI, os times sem dados; (3) as PIs fechadas sem consolidado em produtividade_historico, com os times afetados (a linha só aparece se houver). São só informação e não alteram nenhum número.',
            'entra' => 'Times da seleção e PIs até a PI de referência.',
            'nao_entra' => 'Times fora da seleção e PIs posteriores à PI de referência.',
            'done' => self::DONE_PD,
            'janela' => self::JANELA_PD,
            'onde_no_codigo' => self::ONDE_PD . ' Método consultar. Tela: public/views/vw_produtividade.php (diagnosticos).',
            'limitacoes' => [
                'PI fechada sem consolidado fica sem valor na tabela e no gráfico até rodar bin/consolidar_produtividade.php.',
                'A contagem de itens sem Story Points nas PIs fechadas é lida ao vivo da view, e não do consolidado.',
            ],
        ],
    ];

    private const ONDE_VG = 'app/Repositories/VisaoGeralRepository.php (itens, montarGadgets); app/Services/VisaoGeralCalculator.php; config/visao_geral.php (status, max_linhas_lista); config/baseline.php (done_statuses, plan_real_sustentacao_types).';

    private const TEXTOS_OVERVIEW = [
        'cancelados' => [
            'titulo' => 'Cancelados na PI',
            'fonte' => 'Extração do Jira (view vw_item): itens da PI de referência dos times da seleção que têm extração (VisaoGeralRepository::itens).',
            'unidade' => 'Story Points (SP) atuais e quantidade de itens.',
            'formula' => 'Soma dos SP atuais e contagem dos itens cujo status está na lista de cancelado ({cancelado}; status.cancelado de config/visao_geral.php), em qualquer sprint ou sem sprint. A parte "no foco" do cartão é a mesma soma restrita aos itens cuja sprint de referência (a maior sprint do item) é a sprint em foco ou, no modo PI inteira, uma das sprints de valor já iniciadas da PI.',
            'entra' => 'Itens de entrega de valor ({valor}) e de Sustentação ({sust}) com status cancelado. Item sem SP conta 0 SP e conta como 1 item.',
            'nao_entra' => 'Itens de outros tipos (por exemplo Incidente e Bug), que ficam sem categoria na Visão Geral. Itens de times sem extração na PI. Os itens cancelados ficam fora do Total do escopo e dos baldes Backlog, Em andamento, Bloqueados e Aceitos.',
            'done' => 'Não usa Done: conta pelo status de cancelado. No cálculo dos baldes da sprint o teste de cancelado vem antes do teste de Done.',
            'janela' => 'A PI de referência inteira, mostrada na faixa de contexto, sem recorte por sprint e também no modo Sprint ativa. Só a parte "no foco" segue a sprint em foco (modo Sprint ativa) ou as sprints de valor já iniciadas (modo PI inteira).',
            'onde_no_codigo' => self::ONDE_VG . ' Métodos itens, gadgetAndamento, VisaoGeralCalculator::canceladosPi e baldes. Tela: public/views/vw_overview.php (cardAndamento).',
            'limitacoes' => [
                'Usa o status de hoje, sem data de cancelamento.',
                'O número principal é da PI inteira mesmo no modo Sprint ativa; só a parte "no foco" é recortada.',
                'Item cancelado sem Story Points soma 0 SP, e o contador de itens inclui esse item.',
            ],
        ],
        'lista_sprint_anterior' => [
            'titulo' => 'Itens da sprint anterior (lista)',
            'fonte' => 'Extração do Jira (view vw_item), pela mesma leitura da Visão Geral; o resumo de cada item vem da tabela baseline (PI Planning), pela chave.',
            'unidade' => 'Story Points (SP) atuais por item e quantidade de itens.',
            'formula' => 'Cada linha é um item não cancelado cujo campo Sprint contém a sprint anterior (qualquer sprint do campo, não só a maior). O total é a soma dos SP e a contagem desses itens, feita antes do corte de linhas. Transbordo = Sim quando o item também está na sprint seguinte à anterior. A ordem é aceitos, em andamento, bloqueados e backlog, e dentro de cada grupo pela chave. Os filtros do modal (Alocados, Aceitos, Não aceitos sem continuidade, Transbordo) agem no navegador sobre as linhas recebidas: Aceitos = status na lista de Done; Não aceitos = fora de Done e sem transbordo.',
            'entra' => 'Itens de entrega de valor ({valor}) e de Sustentação ({sust}) dos times da seleção com extração na PI que passaram pela sprint anterior.',
            'nao_entra' => 'Itens com status {cancelado}. Itens de outros tipos (por exemplo Incidente e Bug). As linhas além do limite max_linhas_lista de config/visao_geral.php ficam fora da tabela, mas dentro do total.',
            'done' => 'A lista de Done ({done}; done_statuses de config/baseline.php) separa Aceitos de Não aceitos no filtro do modal. A ordem da lista usa o indicador de entregue da view vw_item (coluna entregue). Vale o status de hoje.',
            'janela' => 'Sprint anterior: sem sprint no filtro, a sprint de valor com a maior data final anterior a hoje (a última fechada); com sprint no filtro, a sprint de valor imediatamente anterior à do filtro. Sprint seguinte: a sprint da PI, de qualquer tipo, com a menor data inicial depois da data inicial da anterior.',
            'onde_no_codigo' => self::ONDE_VG . ' Métodos gadgetListaSprintAnterior, resumosBaseline e VisaoGeralCalculator::listaSprintAnterior, resolverFoco. Tela: public/views/vw_overview.php (cardLista, abrirItensSprint).',
            'limitacoes' => [
                'Usa o status de hoje, e não o do fim da sprint.',
                'Sem sprint seguinte na PI, nenhum item é marcado como transbordo.',
                'Item que não está na baseline fica sem resumo (traço); a nota da lista mostra o percentual de itens com resumo.',
                'Se a lista passa do limite de linhas, o filtro do modal vale só para as linhas recebidas, e a nota avisa o corte.',
            ],
        ],
        'sustentacao_placeholder' => [
            'titulo' => 'Sustentação (aguardando módulo)',
            'fonte' => 'Não há dados. O VisaoGeralRepository devolve gadgets.sustentacao com estado aguardando_modulo e a lista com os nomes dos gadgets previstos.',
            'unidade' => 'Não se aplica: o cartão não mostra número.',
            'formula' => 'Não há cálculo. O cartão lista os gadgets previstos (Visão da Sustentação na Sprint atual; Tempo gastos em tarefas de Sustentação; Incidentes/Solicitações da sprint anterior), que dependem do módulo de Sustentação, com regras ainda não definidas.',
            'entra' => 'Nada: nenhum item do Jira é lido para este cartão.',
            'nao_entra' => 'Os números de sustentação que existem em outros blocos (Aceitos, que separa valor e sustentação, e Chamados) e a tela Pontos de Sustentação são calculados à parte e não alimentam este cartão.',
            'done' => 'Não usa Done.',
            'janela' => 'Não se aplica.',
            'onde_no_codigo' => 'app/Repositories/VisaoGeralRepository.php (montarGadgets, gadgetsVazios: chave sustentacao); public/views/vw_overview.php (cardSustentacao).',
            'limitacoes' => [
                'O texto do cartão afirma que a divisão por 5 do Jira corresponde à Produtividade (horas por ponto). É uma afirmação da tela, e não um cálculo feito pelo código.',
                'A linha Incidentes/Solicitações do cartão Dados da sprint anterior também só informa que aguarda o módulo.',
            ],
        ],
    ];

    private const FONTE_EXT = 'Extração direta do Jira, lida do cache data/items.json pelo endpoint GET /extract (ExtractorController); não vem do banco de dados nem das views. A tela só lê o cache, mesmo velho; ele é refeito pelo botão Atualizar Jira. O que o cache abrange é definido pela JQL padrão (JqlBuilder, config/baseline.php).';
    private const FILTRO_EXT = 'Os filtros do cabeçalho são aplicados no navegador: jornada (texto entre colchetes no início do nome do projeto), versão (primeira versão corrigida do item) e sprint (primeiro nome do campo sprint, sem o prefixo entre colchetes). O filtro de time não é lido por esta tela.';
    private const JANELA_EXT = 'Sem janela de datas nem de sprint: vale o conteúdo do cache e os filtros do cabeçalho. A data da extração e a idade do cache aparecem em Carga dos dados.';
    private const LIM_EXT_CACHE = 'Mostra o cache de data/items.json, que pode estar velho: ele só muda quando alguém usa Atualizar Jira.';
    private const LIM_EXT_TIME = 'O filtro Time do cabeçalho não é aplicado: os números cobrem todos os times que passam pelos outros filtros.';
    private const LIM_EXT_CANC = 'Itens cancelados não são excluídos: entram nas contagens e nas somas.';

    private const ONDE_EP = 'public/views/vw_epicos.php (filterItems, render, renderKpis, renderSp, renderProg, renderTable); app/Controllers/ExtractorController.php (handle, cache em data/items.json); app/Models/JiraModel.php (campos story_points, pai, status); config/baseline.php (done_statuses).';
    private const ITENS_EP = 'Itens com o campo pai (parent do Jira) preenchido, de qualquer tipo, status e sprint, que passam pelos filtros do cabeçalho.';
    private const FORA_EP = 'Itens sem pai. Épicos sem nenhum item na seleção. Os itens não são filtrados por tipo nem por status.';
    private const DONE_EP = 'Conta como entregue o item com status {done}. A tela traz essa lista digitada no próprio código (const DONE), hoje igual a done_statuses de config/baseline.php; mudar o config não muda a tela. Vale o status de hoje.';
    private const ARRED_EP = 'A completude de cada épico é arredondada para inteiro antes de classificar: de 99,5% a 99,99% vira 100% e menos de 0,5% vira 0%.';

    private const TEXTOS_EPICOS = [
        'ep_total' => [
            'titulo' => 'Épicos (com itens)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de épicos.',
            'formula' => 'Número de valores distintos do campo pai entre os itens da seleção. Cada item entra no épico indicado pelo pai dele; a tela não confere o tipo do pai.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP,
            'done' => 'Não usa Done: conta épicos em qualquer situação.',
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP,
            'limitacoes' => [self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC],
        ],
        'ep_concluidos' => [
            'titulo' => 'Épicos concluídos',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de épicos.',
            'formula' => 'Completude do épico = round(SP entregue / SP total x 100), com SP total e SP entregue somados sobre os itens do épico na seleção. Concluído = completude de 100 ou mais.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP . ' Épico com SP total 0, que tem completude 0.',
            'done' => self::DONE_EP,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP,
            'limitacoes' => [
                self::ARRED_EP,
                'Usa Story Points (SP); item sem SP conta 0 SP.',
                'O épico mostra só os itens que passam pelos filtros; itens do mesmo épico fora do filtro não entram na conta.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
        'ep_risco' => [
            'titulo' => 'Épicos em risco',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de épicos.',
            'formula' => 'Conta os épicos com completude maior que 0 e menor que 50, em que completude = round(SP entregue / SP total x 100). O cartão mostra a legenda "< 50% completo".',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP . ' Épicos com completude 0 (ficam em Não iniciados) e de 50 em diante.',
            'done' => self::DONE_EP,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP,
            'limitacoes' => [
                'Risco é só a faixa de completude: a tela não olha prazo, sprint nem status de bloqueio.',
                self::ARRED_EP,
                'Usa Story Points (SP); item sem SP conta 0 SP.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
        'ep_nao_iniciados' => [
            'titulo' => 'Épicos não iniciados',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de épicos.',
            'formula' => 'Conta os épicos com completude 0, em que completude = round(SP entregue / SP total x 100). Entra também o épico cujo SP total é 0.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP . ' Épicos com completude acima de 0.',
            'done' => self::DONE_EP,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP,
            'limitacoes' => [
                'Não iniciado quer dizer sem SP entregue: o épico pode ter itens em andamento.',
                self::ARRED_EP,
                'Épico em que nenhum item tem SP também aparece aqui, por ter SP total 0.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
        'ep_grafico_sp' => [
            'titulo' => 'Top Épicos por Story Points',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Story Points (SP).',
            'formula' => 'Os 12 épicos com maior SP total, em barras horizontais: SP Total = soma do story_points dos itens do épico; SP Entregue = a mesma soma só dos itens com status de Done. O rótulo de cada barra é a chave do épico.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP . ' Do 13º épico em diante, na ordem de SP total.',
            'done' => self::DONE_EP . ' Só a barra SP Entregue usa Done.',
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP . ' Função renderSp.',
            'limitacoes' => [
                'O gráfico mostra a chave do épico, e não o título.',
                'Usa Story Points (SP); item sem SP conta 0 SP.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
        'ep_grafico_progresso' => [
            'titulo' => 'Progresso dos Épicos (faixa de completude)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de épicos por faixa.',
            'formula' => 'Cada épico cai em uma só faixa pela completude = round(SP entregue / SP total x 100): Concluído (100 ou mais), Avançado (50 a 99), Em risco (1 a 49) e Não iniciado (0). O gráfico mostra a quantidade de épicos em cada faixa.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP,
            'done' => self::DONE_EP,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP . ' Função renderProg.',
            'limitacoes' => [
                self::ARRED_EP,
                'Usa Story Points (SP); item sem SP conta 0 SP.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
        'ep_tabela' => [
            'titulo' => 'Detalhamento por Épico (tabela)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Itens (quantidade), Story Points (SP) e completude em percentual.',
            'formula' => 'Uma linha por épico, em ordem decrescente de SP total, sem limite de linhas: Itens = quantidade de itens com aquele pai; SP Total = soma do story_points deles; SP Entregue = soma dos itens com status de Done; Completude = round(SP entregue / SP total x 100), com a barra de progresso limitada a 100% de largura.',
            'entra' => self::ITENS_EP . ' ' . self::FILTRO_EXT,
            'nao_entra' => self::FORA_EP,
            'done' => self::DONE_EP . ' Só SP Entregue e Completude usam Done.',
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_EP . ' Função renderTable.',
            'limitacoes' => [
                self::ARRED_EP,
                'A tabela mostra a chave do épico, e não o título.',
                'Usa Story Points (SP); item sem SP conta 0 SP, mas conta em Itens.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_EXT_CANC,
            ],
        ],
    ];

    private const ONDE_SU = 'public/views/vw_sustain.php (filterItems, teamOf, isSust, renderKpis, renderHoras, renderTipos, renderPct); app/Controllers/ExtractorController.php (handle, cache em data/items.json); app/Models/JiraModel.php (campos tempo_gasto_horas, tipo_item, projeto); config/baseline.php (sustentacao_types).';
    private const TIPOS_SU = 'Itens cujo tipo_item é um dos tipos de sustentação desta tela ({sust_extract}). A lista está digitada em const SUST no código da tela e hoje é igual a sustentacao_types de config/baseline.php. As telas Velocidade, Planejado x Realizado e Visão Geral usam outra lista, plan_real_sustentacao_types ({sust}).';
    private const HORAS_SU = 'Horas de um item = tempo_gasto_horas: o aggregatetimespent do Jira dividido por 3600 e arredondado a 2 casas; vazio ou zero vira nulo e conta 0. É o total acumulado na vida do item, sem divisão por sprint ou período.';
    private const TODOS_SU = 'Todos os itens do cache que passam pelos filtros do cabeçalho, de qualquer tipo e status. ';
    private const TIME_SU = 'O time de cada item é o nome do projeto do Jira sem o prefixo entre colchetes; item sem projeto vai para (outros).';
    private const LIM_SU_STATUS = 'Não filtra por status nem por período: itens em qualquer status, inclusive Backlog e Cancelado, entram com as horas já apontadas.';
    private const LIM_SU_VALOR = 'O que a tela chama de Valor é o resto: todo item cujo tipo não está na lista de sustentação, incluindo tipos que config/baseline.php deixa fora de entrega de valor (como Bug, Design e Liderança).';
    private const DONE_SU = 'Não usa Done: as horas são somadas em qualquer status.';

    private const TEXTOS_SUSTENTACAO = [
        'su_itens' => [
            'titulo' => 'Itens de Sustentação',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de itens.',
            'formula' => 'Contagem dos itens da seleção cujo tipo_item é de sustentação.',
            'entra' => self::TIPOS_SU . ' ' . self::FILTRO_EXT,
            'nao_entra' => 'Itens de outros tipos. Nada é excluído por status: itens cancelados contam.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderKpis.',
            'limitacoes' => [self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS],
        ],
        'su_horas_sust' => [
            'titulo' => 'Horas em Sustentação',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Horas, arredondadas para inteiro no cartão.',
            'formula' => 'Soma do tempo_gasto_horas dos itens de sustentação da seleção. ' . self::HORAS_SU,
            'entra' => self::TIPOS_SU . ' ' . self::FILTRO_EXT,
            'nao_entra' => 'Itens de outros tipos. Horas não apontadas no Jira.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderKpis.',
            'limitacoes' => [self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS, 'As horas são o acumulado da vida do item, e não só as do período filtrado.'],
        ],
        'su_pct' => [
            'titulo' => '% do Esforço Total (sustentação / total)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Percentual (1 casa decimal), calculado sobre horas.',
            'formula' => 'Horas em Sustentação / horas de todos os itens da seleção x 100; é 0 quando o total de horas é 0. A cor do cartão muda quando o valor passa de 30%.',
            'entra' => self::TODOS_SU . self::HORAS_SU,
            'nao_entra' => 'Itens fora dos filtros do cabeçalho.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderKpis.',
            'limitacoes' => [
                'O denominador soma as horas de todos os tipos, e não só as de valor.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS,
            ],
        ],
        'su_horas_valor' => [
            'titulo' => 'Horas em Valor',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Horas, arredondadas para inteiro no cartão.',
            'formula' => 'Horas de todos os itens da seleção menos as horas em Sustentação. ' . self::HORAS_SU,
            'entra' => self::TODOS_SU . 'Só ficam de fora os itens de sustentação. ' . self::TIPOS_SU,
            'nao_entra' => 'Itens de sustentação e itens fora dos filtros do cabeçalho.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderKpis.',
            'limitacoes' => [self::LIM_SU_VALOR, self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS],
        ],
        'su_grafico_horas' => [
            'titulo' => 'Horas: Valor vs Sustentação (por time)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Horas, arredondadas para inteiro em cada barra.',
            'formula' => 'Barras empilhadas por time: Sustentação = horas dos itens de tipo de sustentação; Valor = horas dos demais itens. Só aparecem times com mais de 0 hora, do maior para o menor total. ' . self::TIME_SU,
            'entra' => self::TODOS_SU . self::HORAS_SU,
            'nao_entra' => 'Times sem hora apontada na seleção.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderHoras.',
            'limitacoes' => [
                self::LIM_SU_VALOR,
                'O time é o projeto do Jira, que pode diferir do cadastro de times do dashboard.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS,
            ],
        ],
        'su_grafico_tipos' => [
            'titulo' => 'Tipos de item de Sustentação (quantidade)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Quantidade de itens por tipo.',
            'formula' => 'Rosca com a contagem de itens de sustentação por tipo_item, do tipo mais frequente para o menos frequente.',
            'entra' => self::TIPOS_SU . ' ' . self::FILTRO_EXT,
            'nao_entra' => 'Itens de outros tipos. Tipos de sustentação sem nenhum item na seleção não aparecem.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderTipos.',
            'limitacoes' => [
                'Conta itens, com ou sem horas apontadas.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS,
            ],
        ],
        'su_grafico_pct' => [
            'titulo' => '% de horas em sustentação (por time)',
            'fonte' => self::FONTE_EXT,
            'unidade' => 'Percentual (1 casa decimal), calculado sobre horas.',
            'formula' => 'Por time: horas dos itens de sustentação / horas de todos os itens do time x 100. Só aparecem times com mais de 0 hora, do maior para o menor percentual. A escala vai de 0 a 100 e a cor da barra muda acima de 15% e acima de 30%. ' . self::TIME_SU,
            'entra' => self::TODOS_SU . self::HORAS_SU,
            'nao_entra' => 'Times sem hora apontada na seleção.',
            'done' => self::DONE_SU,
            'janela' => self::JANELA_EXT,
            'onde_no_codigo' => self::ONDE_SU . ' Função renderPct.',
            'limitacoes' => [
                'O denominador soma as horas de todos os tipos, e não só as de valor.',
                'O time é o projeto do Jira, que pode diferir do cadastro de times do dashboard.',
                self::LIM_EXT_CACHE, self::LIM_EXT_TIME, self::LIM_SU_STATUS,
            ],
        ],
    ];

    /** @return array<string,mixed> */
    public static function paraTela(string $tela): array
    {
        $base = dirname(__DIR__, 2);
        $baseline = is_file($base . '/config/baseline.php') ? (require $base . '/config/baseline.php') : [];
        $vg = is_file($base . '/config/visao_geral.php') ? (require $base . '/config/visao_geral.php') : [];
        if (!is_array($baseline)) { $baseline = []; }
        if (!is_array($vg)) { $vg = []; }

        $blocos = [];
        foreach (self::INVENTARIO[$tela] ?? [] as $chave) {
            $blocos[$chave] = self::bloco($chave, [
                '{done}'  => implode(', ', $baseline['done_statuses'] ?? []),
                '{valor}' => implode(', ', $baseline['value_delivery_types'] ?? []),
                '{sust}'  => implode(', ', $baseline['plan_real_sustentacao_types'] ?? []),
                '{sust_extract}' => implode(', ', $baseline['sustentacao_types'] ?? []),
                '{cancelado}'    => implode(', ', $vg['status']['cancelado'] ?? []),
            ]);
        }

        return [
            'done_statuses'     => array_values($baseline['done_statuses'] ?? []),
            'tipos_valor'       => array_values($baseline['value_delivery_types'] ?? []),
            'tipos_sustentacao' => array_values($baseline['plan_real_sustentacao_types'] ?? []),
            'blocos'            => $blocos,
        ];
    }

    /**
     * Campos de texto vazios ou ausentes (e limitacoes vazia).
     * @return string[]
     */
    public static function validarBloco(array $bloco): array
    {
        $faltam = [];
        foreach (self::CAMPOS_TEXTO as $c) {
            if (!isset($bloco[$c]) || !is_string($bloco[$c]) || trim($bloco[$c]) === '') {
                $faltam[] = $c;
            }
        }
        if (empty($bloco['limitacoes']) || !is_array($bloco['limitacoes'])) {
            $faltam[] = 'limitacoes';
        }
        return $faltam;
    }

    /** Bloco da chave; sem texto cadastrado devolve o esqueleto vazio (stub). */
    private static function bloco(string $chave, array $subs = []): array
    {
        $b = self::TEXTOS[$chave] ?? [];
        $out = [];
        foreach (self::CAMPOS_TEXTO as $c) {
            $out[$c] = isset($b[$c]) ? strtr((string) $b[$c], $subs) : '';
        }
        $out['limitacoes'] = isset($b['limitacoes']) && is_array($b['limitacoes'])
            ? array_map(static function ($t) use ($subs) { return strtr((string) $t, $subs); }, array_values($b['limitacoes']))
            : [];
        if (!empty($b['diferencas_jira']) && is_array($b['diferencas_jira'])) {
            $out['diferencas_jira'] = array_map(static function ($t) use ($subs) { return strtr((string) $t, $subs); }, array_values($b['diferencas_jira']));
        }
        return $out;
    }
}

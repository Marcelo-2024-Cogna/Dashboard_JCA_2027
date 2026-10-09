<?php
/**
 * config/jira.php
 * Credenciais e constantes da API Jira.
 * Variáveis de ambiente têm precedência sobre os valores padrão.
 */

return [
    'host'  => getenv('JIRA_HOST')       ?: 'https://cogna.atlassian.net',
    'email' => getenv('JIRA_EMAIL')      ?: 'marcelo.doliveira@cogna.com.br',
    'token' => getenv('JIRA_API_TOKEN')  ?: 'ATATT3xFfGF0rk7econkkvKg1PpqrxudnjzGXn1R9lZ-7rc4zE8mKXIBtaYeCWbVz2BwOH2X1yYhclVH4gt5jwdj5hIry1ExsRo4Qv_-Tn0liiq_8cH0hxpltI5eeZsEe51Vl31gErP0HZOsjbQ_a-8U2FLWXmKCVKsmjgwnDrF3zFz69sv0Kt8=E36B9456',

    // Tempo de cache do arquivo data/items.json em segundos (padrão: 30 min)
    'cache_ttl' => (int)(getenv('JIRA_CACHE_TTL') ?: 1800),

    // JQL padrão usada pela extração (sprints PI 3/26)
    'default_jql' =>
        'project in (ADKB,ADMD,ADPL,ADR6,ADTH,ADTP,ADGP,ADSE,ASEC,ATEN,ASPL,SGGU,SGTB,SDIA)'
        . ' AND issuetype in ("Débito Técnico", Enabler, Story, Spike, Teste, Incidente,'
        . ' "Solicitação de Serviço", bug, Sustentação, Suporte)'
        . ' AND sprint in ("26.3.1","26.3.2","26.3.3","26.3.4","26.3.5")'
        . ' ORDER BY project ASC, created DESC',

    // IDs dos campos customizados da instância Cogna (validados em 09/10/2026)
    'custom_fields' => [
        'sprint'               => 'customfield_10020',
        'story_points'         => 'customfield_10026',
        'story_point_estimate' => 'customfield_10016',
        'tipo'                 => 'customfield_10041',
        'alteracao_escopo'     => 'customfield_10315',
        'ponto_compromisso'    => 'customfield_10338',
        'inicio_fluxo'         => 'customfield_10141',
        'fim_fluxo'            => 'customfield_10282',
        'inicio_homologacao'   => 'customfield_10339',
        'fim_homologacao'      => 'customfield_10342',
        'inicio_implantacao'   => 'customfield_10377',
        'fim_implantacao'      => 'customfield_10344',
        'ciclo_ideacao'        => 'customfield_10340',
        'ciclo_desenvolvimento'=> 'customfield_10281',
        'ciclo_homologacao'    => 'customfield_10341',
        'ciclo_implantacao'    => 'customfield_10345',
        'lead_time'            => 'customfield_10286',
        'customer_lead_time'   => 'customfield_10284',
        'tipo_sustentacao'     => 'customfield_10337',
        'antecipacao'          => 'customfield_10290',
        'ambiente'             => 'customfield_10049',
        'tempo_total_bloqueado'=> 'customfield_10700',
    ],

    // Campos padrão + customizados solicitados na busca
    'field_list' => [
        'issuetype', 'summary', 'project', 'status', 'fixVersions',
        'aggregatetimespent', 'parent', 'resolution', 'created',
        'customfield_10020', 'customfield_10026', 'customfield_10016',
        'customfield_10041', 'customfield_10315', 'customfield_10338',
        'customfield_10141', 'customfield_10282', 'customfield_10339',
        'customfield_10342', 'customfield_10377', 'customfield_10344',
        'customfield_10340', 'customfield_10281', 'customfield_10341',
        'customfield_10345', 'customfield_10286', 'customfield_10284',
        'customfield_10337', 'customfield_10290', 'customfield_10049',
        'customfield_10700',
    ],
];

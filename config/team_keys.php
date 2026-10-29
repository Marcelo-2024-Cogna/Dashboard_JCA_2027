<?php
/**
 * config/team_keys.php — De-para TIME (planilha Velocity) → projeto_key (Jira)
 * ───────────────────────────────────────────────────────────────────────────
 * Mapeia o nome do time como aparece na aba "2026_Produtividade" da planilha
 * Velocity para a sigla do projeto no Jira (projeto_key), derivada do prefixo
 * da coluna "Chave" da planilha Baseline (ex: SGGU-6546 → SGGU).
 *
 * A CHAVE do array é o nome do time EXATAMENTE como está na Velocity.
 * O VALOR é o projeto_key do Jira (ou null quando ainda não há correspondência
 * — times novos/sem itens na Baseline; preenchidos depois pelo Painel).
 *
 * Validado com o usuário (de-para de conferência). Times sem match ficam null.
 * Atualizar a cada PI conforme a formação dos times muda.
 *
 * Jornada Ingresso: de-para confirmado pelo DM do Ingresso (prefixo IN*).
 * No banco, os times que se dividiram (Backoffice, Ecomm) herdaram a
 * velocidade do time original até a planilha Velocity ser atualizada.
 */
return [
    // ── Bloco 1: match direto confirmado ──────────────────────────────────
    'kbots'                         => 'ASKB',
    'Papa-léguas'                   => 'ASPL',
    'Gestor de unidades'            => 'SGGU',
    'Aprendizagem'                  => 'APAP',
    'Avaliação'                     => 'APAV',
    'Comunicação'                   => 'APCO',
    'Interação'                     => 'APIN',
    'Plataforma'                    => 'APPL',
    'Secretaria e Financeiro'       => 'APSF',
    'Eagles'                        => 'JFEA',
    'House of Money'                => 'JFHM',
    'Captar'                        => 'INCA',   // mantém IN* (confirmado), mesmo com a jornada migrando p/ JI*
    'Colaborar'                     => 'JACO',
    'Moodle'                        => 'JAMO',
    'Docencia'                      => 'JADO',
    'COGNA IA'                      => 'IACI',
    'Sapience'                      => 'IAPI',
    'DeVengers'                     => 'SADV',
    'Joker'                         => 'SAJO',
    'Team Tregamos'                 => 'SATT',
    'Admin'                         => 'VADM',
    'Play'                          => 'VPLY',
    'Meios de Pagamento'            => 'VMPG',
    'Pós'                           => 'PGPO',
    'Backoffice ERP'                => 'VBEP',
    'Gateway'                       => 'VGAT',
    'Thunderbolts'                  => 'SGTB',

    // ── Bloco 2/3: confirmados pelo usuário ───────────────────────────────
    'Athenas Atendimento'           => 'ASAT',
    'Route66'                       => 'ASR6',
    'Athenas Sec. Enturmação'       => 'SGSE',
    'Athenas Sec. Gestão Acad.'     => 'SGGS',
    'Alex IA'                       => 'IAAL',
    'Cmd - Learning Experience'     => 'PGCM',
    'Opm Externo'                   => 'PGEX',

    // Fusão Sparta+Triforce → JFTS (a partir da PI 3/26).
    // Historicamente: Triforce=JFTR, Sparta=JFSP (PIs anteriores).
    'Triforce_Sparta'               => 'JFTS',
    'Sparta'                        => 'JFSP',   // histórico (pré-fusão)
    'Triforce'                      => 'JFTR',   // histórico (pré-fusão)

    // ── Jornada Pós Graduação (confirmado) ────────────────────────────────
    // (PGCM e PGEX já mapeados acima no bloco 2)

    // ── Jornada Ingresso — versão CONFIRMADA PELO DM do Ingresso ───────────
    // Reestruturação: Backoffice e Ecomm foram divididos em 2 times cada;
    // Agents e Customer Success deixaram de existir; Consultoria Educação
    // virou Aplicativos de Captação. Prefixo IN*.
    // Nomes NOVOS (para quando a planilha Velocity for atualizada):
    'Aplicativos de Captação'       => 'INAP',
    'Backoffice de Inscrição'       => 'INBI',
    'Backoffice de Ofertas'         => 'INBO',
    'Funis e Área do Candidato'     => 'INFU',
    'Portais e Vitrines'            => 'INPV',
    'Cursos Técnicos'               => 'INCT',   // novo; sem dados na Velocity ainda
    // Nomes ANTIGOS ainda presentes na Velocity (mapeados p/ o time sucessor;
    // Agents e Customer Sucess não existem mais → null):
    'Consultoria Educação'          => 'INAP',
    'Backoffice de Ingresso'        => 'INBI',
    'Ecomm'                         => 'INFU',
    'Agents'                        => null,
    'Customer Sucess'               => null,

    // Alexandria e Maia eram times separados até a PI 3/26 (JAAL/JAMA);
    // na PI 4/26 viraram um time único Alexandria/Maia = JAAM.
    'Alexandria/Maia'               => 'JAAM',

    // Times novos/históricos sem itens na Baseline da PI 4/26 (null por ora):
    'Thanos'                        => null,     // SGTH (só PIs anteriores)
    'ThreadPool'                    => null,     // SGTP (só PIs anteriores)
    'APP'                           => null,     // Voomp (confirmar)
];

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
 * ⚠️ PENDENTE (jornada Ingresso): o DM do negócio Ingresso estava
 * reestruturando a planilha Velocity (projetos IN* vs JI*). O de-para da
 * Ingresso abaixo (Captar=INCA; Consultoria Educação=JICE; Customer Sucess=
 * JICS; Ecomm=JIEC; Backoffice de Ingresso=JIBK) está SUJEITO A CONFIRMAÇÃO
 * com esse DM. Ajustar via UPDATE em times.projeto_key quando confirmado.
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

    // ── PENDENTE DE CONFIRMAÇÃO (deixar null até o usuário informar) ───────
    // Jornada Ingresso — só 'Captar'=INCA é óbvio; os demais nomes comerciais
    // da Velocity não casam 1:1 com os keys técnicos da Baseline:
    //   keys livres: INAP(Aplicativos de Captação), INBI(Backoffice de
    //   Inscrição), INBO(Backoffice de Ofertas), INFU(Funis e Área do
    //   Candidato), INPV(Portais e Vitrines).
    // Jornada Ingresso migrou de prefixo IN*→JI* na PI 4/26 (nomes novos):
    'Agents'                        => 'JIAG',
    'Backoffice de Ingresso'        => 'JIBK',
    'Consultoria Educação'          => 'JICE',
    'Customer Sucess'               => 'JICS',
    'Ecomm'                         => 'JIEC',
    // (keys sem time na Velocity 4/26: INBO 'Backoffice de Ofertas',
    //  JIAC 'Área do Candidato' — não usados por ora.)

    // Alexandria e Maia eram times separados até a PI 3/26 (JAAL/JAMA);
    // na PI 4/26 viraram um time único Alexandria/Maia = JAAM.
    'Alexandria/Maia'               => 'JAAM',

    // Times novos/históricos sem itens na Baseline da PI 4/26 (null por ora):
    'Thanos'                        => null,     // SGTH (só PIs anteriores)
    'ThreadPool'                    => null,     // SGTP (só PIs anteriores)
    'APP'                           => null,     // Voomp (confirmar)
];

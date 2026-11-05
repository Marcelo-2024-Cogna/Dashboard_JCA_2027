<?php
/**
 * config/jira.php
 * Credenciais e constantes da API Jira.
 * Variáveis de ambiente têm precedência sobre os valores padrão.
 */

// Credenciais SEMPRE via ambiente — nunca hardcode segredos aqui (este
// arquivo é versionado). Sem fallback com segredo: se faltar o token, a
// extração falha de forma clara.
//
// FONTE DAS CREDENCIAIS (precedência):
//   1) Variáveis de ambiente reais (ex.: definidas no systemd/Apache do
//      servidor de produção) — SEMPRE vencem.
//   2) config/secrets.local.php (NÃO versionado, no .gitignore) — para o
//      ambiente de desenvolvimento local. Copie config/secrets.local.php.example
//      e preencha. Esse arquivo só preenche o que ainda NÃO estiver no ambiente.
$__secrets = __DIR__ . '/secrets.local.php';
if (is_file($__secrets)) {
    $__s = require $__secrets;
    if (is_array($__s)) {
        foreach ($__s as $__k => $__v) {
            // não sobrescreve variável de ambiente já definida (produção vence)
            if ($__v !== null && $__v !== '' && getenv($__k) === false) {
                putenv("$__k=$__v");
            }
        }
    }
    unset($__s, $__k, $__v);
}
unset($__secrets);

return [
    'host'  => getenv('JIRA_HOST')      ?: 'https://cogna.atlassian.net',
    'email' => getenv('JIRA_EMAIL')     ?: '',
    'token' => getenv('JIRA_API_TOKEN') ?: '',

    // Tempo de cache do arquivo data/items.json em segundos (padrão: 30 min)
    'cache_ttl' => (int)(getenv('JIRA_CACHE_TTL') ?: 1800),

    // Verificação de certificado SSL. Em redes com proxy corporativo que
    // injeta certificado próprio (ex: "self-signed certificate in chain"),
    // defina JIRA_SSL_VERIFY=0 para desabilitar, OU aponte JIRA_CA_BUNDLE
    // para o .pem do certificado corporativo (mais seguro).
    'ssl_verify' => getenv('JIRA_SSL_VERIFY') !== '0',
    'ca_bundle'  => getenv('JIRA_CA_BUNDLE') ?: null,

    // NOTA: a JQL de extração NÃO fica mais aqui. Ela é montada pelo
    // App\Services\JqlBuilder a partir de config/baseline.php (fonte única
    // de projetos, sprints e tipos), garantindo que /extract e a baseline
    // consultem exatamente o mesmo universo de itens.

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

<?php
/**
 * RASCUNHO DO USUÁRIO — 06/11/2026 (referência, NÃO é código de produção)
 * ───────────────────────────────────────────────────────────────────────
 * Esboço da estratégia de extração INCREMENTAL e CUMULATIVA por PI:
 * a cada sprint que fecha, rodar o JQL acrescentando essa sprint às demais
 * já fechadas da PI ("macro do Excel"). Guardado aqui só para consulta; a
 * análise de como encaixar isso no projeto está em
 * .agents/tasks/extracao-incremental-analise.md.
 *
 * NÃO carregar/autoloadar este arquivo: é um exemplo, com placeholders.
 */

class srvJiraIncrementalExtraction
{
    private $jiraApiClient;
    private $storageRepository;

    public function __construct($jiraApiClient, $storageRepository)
    {
        // NOTA (bug no rascunho): as atribuições estão invertidas — deveria ser
        // $this->jiraApiClient = $jiraApiClient; $this->storageRepository = $storageRepository;
        $jiraApiClient = $this->jiraApiClient;
        $storageRepository = $this->storageRepository;
    }

    /**
     * Executa a extração incremental das sprints fechadas de uma PI específica.
     * @param string $piName        Nome da Program Increment (ex: 'PI 2026.Q4')
     * @param array  $allPiSprints  Sprints da PI ordenadas cronologicamente
     * @return array Dados consolidados e incrementais
     */
    public function extractIncrementalByPI(string $piName, array $allPiSprints): array
    {
        $processedSprints = $this->storageRepository->getProcessedSprints($piName);

        $closedSprintsInPI = array_filter($allPiSprints, function ($sprint) {
            return $sprint['state'] === 'closed';
        });

        $newlyClosedSprints = array_udiff(
            $closedSprintsInPI,
            $processedSprints,
            function ($a, $b) { return $a['id'] <=> $b['id']; }
        );

        if (empty($newlyClosedSprints)) {
            return ['status' => 'up_to_date', 'message' => 'Nenhuma nova sprint fechada para extrair nesta PI.'];
        }

        $targetSprintsToExtract = array_merge($processedSprints, $newlyClosedSprints);
        $sprintIds = array_column($targetSprintsToExtract, 'id');

        $jql = sprintf('sprint IN (%s) ORDER BY closedDate ASC', implode(', ', $sprintIds));

        $rawJiraData = $this->jiraApiClient->searchIssuesByJql($jql);

        $consolidatedData = $this->transformAndConsolidate($rawJiraData, $targetSprintsToExtract);
        $this->storageRepository->savePiSnapshot($piName, $consolidatedData, $newlyClosedSprints);

        return [
            'status' => 'success',
            'sprints_added' => count($newlyClosedSprints),
            'total_sprints_accumulated' => count($targetSprintsToExtract),
            'data' => $consolidatedData,
        ];
    }

    private function transformAndConsolidate(array $rawIssues, array $sprints): array
    {
        return $rawIssues;
    }
}

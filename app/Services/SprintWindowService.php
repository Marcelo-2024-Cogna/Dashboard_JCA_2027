<?php

namespace App\Services;

/**
 * SprintWindowService
 * ───────────────────
 * Determina a "janela de sprints fechadas" a considerar nas métricas de
 * Planejado x Realizado — a comparação só cruza sprints já encerradas,
 * crescendo gradativamente conforme a PI avança.
 *
 * Prioridade para definir a SPRINT DE FECHAMENTO (até onde considerar):
 *   1. Filtro da tela      (parâmetro explícito do usuário)
 *   2. closing_sprint      (override manual em config/pi.php / painel)
 *   3. Automático por data (última sprint cujo fim < hoje)
 *
 * A janela resultante = todas as sprints da PI da 1ª até a de fechamento.
 */
class SprintWindowService
{
    /** @var string[] sprints da PI, em ordem (ex: ['26.4.1', ...]) */
    private array $allSprints;
    /** @var array<string,array{start:string,end:string}> datas por sprint */
    private array $dates;
    private ?string $configClosing;

    public function __construct(array $allSprints, array $sprintDates, ?string $configClosing = null)
    {
        $this->allSprints    = array_values($allSprints);
        $this->dates         = $sprintDates;
        $this->configClosing = $configClosing ?: null;
    }

    /**
     * Resolve a sprint de fechamento aplicando a prioridade.
     * @param string|null $screenSprint sprint escolhida no filtro da tela
     */
    public function resolveClosingSprint(?string $screenSprint = null): ?string
    {
        // 1. Filtro da tela (se válido)
        if ($screenSprint && in_array($screenSprint, $this->allSprints, true)) {
            return $screenSprint;
        }
        // 2. Override manual da config
        if ($this->configClosing && in_array($this->configClosing, $this->allSprints, true)) {
            return $this->configClosing;
        }
        // 3. Automático por data (última com fim < hoje)
        return $this->lastClosedByDate();
    }

    /**
     * Última sprint cujo fim já passou (fim < hoje). Null se nenhuma fechou.
     */
    public function lastClosedByDate(?\DateTimeInterface $now = null): ?string
    {
        $now = $now ?? new \DateTimeImmutable('today');
        $closed = null;
        foreach ($this->allSprints as $sp) {
            $end = $this->dates[$sp]['end'] ?? null;
            if ($end === null) {
                continue;
            }
            try {
                $endDate = new \DateTimeImmutable($end);
            } catch (\Exception $e) {
                continue;
            }
            // Fechada se o fim é estritamente anterior a hoje.
            if ($endDate < $now) {
                $closed = $sp;
            }
        }
        return $closed;
    }

    /**
     * Lista de sprints da janela: da primeira até a sprint de fechamento
     * (inclusive). Vazia se nenhuma sprint fechou ainda.
     *
     * @return string[]
     */
    public function windowSprints(?string $screenSprint = null): array
    {
        $closing = $this->resolveClosingSprint($screenSprint);
        if ($closing === null) {
            return [];
        }
        $idx = array_search($closing, $this->allSprints, true);
        if ($idx === false) {
            return [];
        }
        return array_slice($this->allSprints, 0, $idx + 1);
    }

    /**
     * Extrai o número da sprint (ex: "26.4.1") de um texto de sprint do
     * Jira como "[SGGU] SP 26.4.1" ou "26.4.1; 26.4.2".
     */
    public static function parseSprintNumber(?string $sprintText): ?string
    {
        if (!$sprintText) {
            return null;
        }
        // Pega o PRIMEIRO número no formato XX.X.X
        if (preg_match('/(\d+\.\d+\.\d+)/', $sprintText, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Verifica se um item (pelo seu campo sprint) pertence à janela.
     * Item de sprint FORA da janela (futura) é excluído — mesmo entregue.
     *
     * @param string[] $window sprints da janela
     */
    public static function itemInWindow(?string $itemSprint, array $window): bool
    {
        if (empty($window)) {
            return false;
        }
        $num = self::parseSprintNumber($itemSprint);
        if ($num === null) {
            return false; // sem sprint identificável → fora
        }
        return in_array($num, $window, true);
    }
}

<?php

namespace App\Srv;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * srvResolvedorPi
 * ───────────────
 * Resolve quais PIs o job noturno deve extrair numa data:
 *   - PI CORRENTE: configuracao_pi (ativo='S') que contém a data; se nenhuma
 *     contiver, a PI já iniciada de maior data_fim (período de graça);
 *   - PI ANTERIOR: continua sendo extraída por até $diasGraca dias depois do
 *     data_fim dela (virada de PI);
 *   - FALHA (RuntimeException, code 2) se a data não cai em nenhuma PI e a
 *     última já passou do período de graça, ou se não há PI iniciada.
 */
class srvResolvedorPi
{
    private PDO $pdo;
    private int $diasGraca;

    public function __construct(PDO $pdo, int $diasGraca = 14)
    {
        $this->pdo       = $pdo;
        $this->diasGraca = max(0, $diasGraca);
    }

    /**
     * @param string      $hojeYmd   data de referência (Y-m-d)
     * @param string|null $forcarPi  desc_pi forçada (--pi); deve existir e estar ativa
     * @return array{pis:string[],corrente:string,motivo:string}
     * @throws RuntimeException code 2 (configuração/PI inválida)
     */
    public function resolver(string $hojeYmd, ?string $forcarPi = null): array
    {
        $pis = $this->pdo->query(
            "SELECT desc_pi, data_ini, data_fim FROM configuracao_pi
              WHERE ativo = 'S' AND data_ini IS NOT NULL AND data_fim IS NOT NULL
           ORDER BY data_fim, data_ini"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($forcarPi !== null && $forcarPi !== '') {
            foreach ($pis as $p) {
                if ($p['desc_pi'] === $forcarPi) {
                    return ['pis' => [$forcarPi], 'corrente' => $forcarPi, 'motivo' => 'PI forçada por --pi'];
                }
            }
            throw new RuntimeException("PI '{$forcarPi}' não encontrada ou inativa em configuracao_pi (com datas).", 2);
        }

        // 1) PI que contém a data (se várias, a de início mais recente).
        $corrente = null;
        foreach ($pis as $p) {
            if ($p['data_ini'] <= $hojeYmd && $hojeYmd <= $p['data_fim']) {
                if ($corrente === null || $p['data_ini'] >= $corrente['data_ini']) $corrente = $p;
            }
        }
        $motivo = 'a data cai dentro da PI';

        // 2) Entre PIs: a já iniciada de maior data_fim, desde que dentro da graça.
        if ($corrente === null) {
            foreach ($pis as $p) {
                if ($p['data_ini'] <= $hojeYmd && ($corrente === null || $p['data_fim'] > $corrente['data_fim'])) {
                    $corrente = $p;
                }
            }
            if ($corrente === null) {
                throw new RuntimeException("Nenhuma PI cadastrada já iniciada em {$hojeYmd}.", 2);
            }
            $limite = (new DateTimeImmutable($corrente['data_fim']))->modify("+{$this->diasGraca} days")->format('Y-m-d');
            if ($hojeYmd > $limite) {
                throw new RuntimeException(
                    "A data {$hojeYmd} não cai em nenhuma PI cadastrada e a última ({$corrente['desc_pi']}, fim {$corrente['data_fim']}) "
                    . "passou do período de graça de {$this->diasGraca} dias. Cadastre a próxima PI em configuracao_pi.",
                    2
                );
            }
            $motivo = "entre PIs: usando a última ({$corrente['desc_pi']}) dentro do período de graça";
        }

        $lista = [$corrente['desc_pi']];

        // 3) PIs anteriores ainda dentro da graça (virada de PI).
        foreach ($pis as $p) {
            if ($p['desc_pi'] === $corrente['desc_pi'] || $p['data_fim'] >= $corrente['data_ini']) continue;
            $limite = (new DateTimeImmutable($p['data_fim']))->modify("+{$this->diasGraca} days")->format('Y-m-d');
            if ($hojeYmd > $p['data_fim'] && $hojeYmd <= $limite) {
                $lista[] = $p['desc_pi'];
                $motivo .= "; {$p['desc_pi']} ainda no período de graça";
            }
        }

        return ['pis' => $lista, 'corrente' => $corrente['desc_pi'], 'motivo' => $motivo];
    }
}

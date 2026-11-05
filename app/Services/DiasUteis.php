<?php

namespace App\Services;

/**
 * DiasUteis
 * ─────────
 * Dias úteis (seg–sex, sem feriados) no MESMO padrão das views SQL — usa a
 * fórmula de `WEEKDAY` do MySQL (seg = 0 … dom = 6) para garantir paridade
 * com `vw_item.dias_desenvolvimento|dias_homologacao|dias_implantacao`.
 *
 * Todos os métodos são estáticos e puros (sem PDO, sem relógio). As datas
 * podem vir como 'Y-m-d' ou 'Y-m-d H:i:s'; só a parte da data (Y-m-d) conta.
 */
class DiasUteis
{
    /**
     * networkdays(a, b) − 1. Pode ser negativo (datas iguais/ordenadas em
     * fim de semana). Fórmula idêntica à das views:
     *   (DATEDIFF(b,a)+1)
     *   − FLOOR((DATEDIFF(b,a)+WEEKDAY(a)+1)/7)*2
     *   − (WEEKDAY(a)=6 ? 1 : 0)
     *   − (WEEKDAY(b)=5 ? 1 : 0)
     *   − 1
     */
    public static function bruto(string $a, string $b): int
    {
        $da = substr($a, 0, 10);
        $db = substr($b, 0, 10);

        $diff = self::datediff($da, $db); // DATEDIFF(b, a) = b − a
        $wa   = self::weekday($da);        // seg=0 … dom=6
        $wb   = self::weekday($db);

        return ($diff + 1)
            - (int) floor(($diff + $wa + 1) / 7) * 2
            - ($wa === 6 ? 1 : 0)
            - ($wb === 5 ? 1 : 0)
            - 1;
    }

    /** Desenvolvimento e Homologação: mínimo 1 dia útil. */
    public static function trabalho(string $a, string $b): int
    {
        return max(1, self::bruto($a, $b));
    }

    /**
     * Implantação: paridade com vw_item.dias_implantacao.
     * bruto < 0 → null; bruto = 0 → 1; senão bruto.
     */
    public static function implantacao(string $a, string $b): ?int
    {
        $bruto = self::bruto($a, $b);
        if ($bruto < 0) {
            return null;
        }
        if ($bruto === 0) {
            return 1;
        }
        return $bruto;
    }

    /** True só se a data de fim (b) é anterior à data de início (a). */
    public static function invertida(string $a, string $b): bool
    {
        return substr($b, 0, 10) < substr($a, 0, 10);
    }

    /**
     * Fase de espera (aguardando): mínimo 0 dias úteis.
     * Não usa o sinal de `bruto` para detectar inversão (bruto dá negativo
     * para datas iguais/ordenadas em fim de semana); usa a comparação de data.
     */
    public static function espera(string $a, string $b): ?int
    {
        if (substr($b, 0, 10) < substr($a, 0, 10)) {
            return null;
        }
        return max(0, self::bruto($a, $b));
    }

    /** DATEDIFF(b, a): nº de dias de `a` até `b` (b − a), com sinal. */
    private static function datediff(string $a, string $b): int
    {
        $ta = strtotime($a . ' 00:00:00');
        $tb = strtotime($b . ' 00:00:00');
        return (int) round(($tb - $ta) / 86400);
    }

    /** WEEKDAY do MySQL: seg = 0, ter = 1 … sáb = 5, dom = 6. */
    private static function weekday(string $d): int
    {
        // date('N'): seg = 1 … dom = 7  →  WEEKDAY = N − 1
        return (int) date('N', strtotime($d . ' 00:00:00')) - 1;
    }
}

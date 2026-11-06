<?php
/**
 * app/compat.php — Polyfills para PHP 7.4 (funções nativas do PHP 8.x)
 * ─────────────────────────────────────────────────────────────────────
 * Cada função é definida SOMENTE se ainda não existir (`function_exists`),
 * portanto este arquivo é inerte no PHP 8.x. Carregar com require_once no
 * topo de public/index.php e de todo ponto de entrada CLI (bin/*.php),
 * antes de qualquer uso (funções não são autoloadáveis).
 */

if (!function_exists('arsort_estavel')) {
    /**
     * arsort() com desempate pela ordem de inserção: reproduz no PHP 7.4 o
     * resultado do arsort() estável do PHP 8 (empates mantêm a ordem original).
     * Não é polyfill de função nativa; é um auxiliar do projeto.
     */
    function arsort_estavel(array &$array): void
    {
        $ord = array_flip(array_keys($array));
        $val = $array;
        uksort($array, static function ($a, $b) use ($val, $ord): int {
            return ($val[$b] <=> $val[$a]) ?: ($ord[$a] <=> $ord[$b]);
        });
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || ($haystack !== '' && substr_compare($haystack, $needle, -strlen($needle)) === 0);
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('get_debug_type')) {
    /** @param mixed $value */
    function get_debug_type($value): string
    {
        switch (true) {
            case $value === null:
                return 'null';
            case is_bool($value):
                return 'bool';
            case is_int($value):
                return 'int';
            case is_float($value):
                return 'float';
            case is_string($value):
                return 'string';
            case is_array($value):
                return 'array';
            case is_object($value):
                $ref = new ReflectionClass($value);
                if ($ref->isAnonymous()) {
                    $parent = $ref->getParentClass();
                    if ($parent !== false) {
                        return $parent->getName() . '@anonymous';
                    }
                    $ifaces = $ref->getInterfaceNames();
                    return ($ifaces ? $ifaces[0] : 'class') . '@anonymous';
                }
                return $ref->getName();
            default:
                $type = @get_resource_type($value);
                if ($type === 'Unknown') {
                    return 'resource (closed)';
                }
                return $type !== null && $type !== false ? "resource ($type)" : 'mixed';
        }
    }
}

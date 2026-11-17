<?php

namespace App\Srv;

/**
 * srvDisco
 * ────────
 * Mede o espaço livre dos caminhos configurados (config/extracao.php, chave
 * 'disco'). Usado pelo pré-voo do job noturno e pelo verificador.
 *
 * O provedor é injetável (testes): function (string $caminho): ?array
 * devolve ['livre' => bytes, 'total' => bytes] ou null se indisponível.
 * Caminho indisponível (inexistente no Windows/dev) é ignorado com aviso.
 */
final class srvDisco
{
    private const MB = 1048576;

    private array $caminhos;
    private float $minMb;
    private float $minPct;
    /** @var callable */
    private $provedor;
    /** @var string[] */
    private array $avisos = [];
    /** @var array|null */
    private $cache = null;

    public function __construct(array $cfgDisco, ?callable $provedor = null)
    {
        $this->caminhos = [];
        foreach ((array)($cfgDisco['caminhos'] ?? []) as $c) {
            $c = trim((string)$c);
            if ($c !== '') $this->caminhos[] = $c;
        }
        $this->minMb    = isset($cfgDisco['min_livre_mb']) && is_numeric($cfgDisco['min_livre_mb']) ? (float)$cfgDisco['min_livre_mb'] : 500.0;
        $this->minPct   = isset($cfgDisco['min_livre_pct']) && is_numeric($cfgDisco['min_livre_pct']) ? (float)$cfgDisco['min_livre_pct'] : 10.0;
        $this->provedor = $provedor ?? self::provedorPadrao();
    }

    public static function provedorPadrao(): callable
    {
        return function (string $caminho): ?array {
            $livre = @disk_free_space($caminho);
            $total = @disk_total_space($caminho);
            if ($livre === false || $total === false || $total <= 0) return null;
            return ['livre' => (float)$livre, 'total' => (float)$total];
        };
    }

    public function minLivreMb(): float
    {
        return $this->minMb;
    }

    public function minLivrePct(): float
    {
        return $this->minPct;
    }

    /** @return array<int,array{caminho:string,disponivel:bool,livre_mb:float,total_mb:float,livre_pct:float}> */
    public function medir(): array
    {
        if ($this->cache !== null) return $this->cache;
        $out = [];
        foreach ($this->caminhos as $c) {
            $r = null;
            try {
                $r = call_user_func($this->provedor, $c);
            } catch (\Throwable $e) {
                $r = null;
            }
            if (!is_array($r) || !isset($r['livre'], $r['total']) || (float)$r['total'] <= 0) {
                $this->avisos[] = "disco: caminho '{$c}' indisponível; ignorado";
                $out[] = ['caminho' => $c, 'disponivel' => false, 'livre_mb' => 0.0, 'total_mb' => 0.0, 'livre_pct' => 0.0];
                continue;
            }
            $livre = (float)$r['livre'];
            $total = (float)$r['total'];
            $out[] = [
                'caminho'    => $c,
                'disponivel' => true,
                'livre_mb'   => round($livre / self::MB, 1),
                'total_mb'   => round($total / self::MB, 1),
                'livre_pct'  => round($livre * 100 / $total, 1),
            ];
        }
        $this->cache = $out;
        return $out;
    }

    /** Pré-voo: livre < min_livre_mb. */
    public function abaixoDoMinimoMb(): array
    {
        $r = [];
        foreach ($this->medir() as $m) {
            if ($m['disponivel'] && $m['livre_mb'] < $this->minMb) $r[] = $m;
        }
        return $r;
    }

    /** Verificador: livre < min_livre_mb OU livre% < min_livre_pct. */
    public function abaixoDeQualquerLimite(): array
    {
        $r = [];
        foreach ($this->medir() as $m) {
            if ($m['disponivel'] && ($m['livre_mb'] < $this->minMb || $m['livre_pct'] < $this->minPct)) $r[] = $m;
        }
        return $r;
    }

    /** @return string[] */
    public function avisos(): array
    {
        $this->medir();
        return $this->avisos;
    }

    public function descrever(array $m): string
    {
        return sprintf('%s: livre %.1F MB (%.1F%% de %.1F MB)', $m['caminho'], $m['livre_mb'], $m['livre_pct'], $m['total_mb']);
    }
}

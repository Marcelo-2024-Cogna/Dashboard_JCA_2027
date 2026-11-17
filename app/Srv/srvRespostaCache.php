<?php

namespace App\Srv;

use Throwable;

/**
 * srvRespostaCache
 * ────────────────
 * Cache de resposta em ARQUIVO (um arquivo por chave), com validade por
 * "token" informado pelo chamador (marcadores de banco, data, versão do código).
 * Sem dependência do controller: diretório, relógio, logger, provedor de
 * marcadores e gerador são injetados.
 *
 *   $c = new srvRespostaCache('/app/data/cache');
 *   $r = $c->obter($chave, fn() => $token, fn() => ['status' => 200, 'corpo' => $json]);
 *   // $r['estado'] = HIT | MISS | STALE | BYPASS
 *
 * Regras: só respostas 200 não vazias e ≤ max_bytes são gravadas; gravação
 * atômica (temporário na mesma pasta + rename); trava por chave (flock) contra
 * stampede; nome do arquivo = hash da chave; qualquer falha de E/S cai para o
 * cálculo normal e é registrada, nunca derruba a requisição. Exceção do
 * gerador propaga (nada é gravado, a trava é liberada).
 */
class srvRespostaCache
{
    public const ESTADO_HIT    = 'HIT';
    public const ESTADO_MISS   = 'MISS';
    public const ESTADO_STALE  = 'STALE';
    public const ESTADO_BYPASS = 'BYPASS';

    private const FORMATO = 1;
    private const PREFIXO = 'vg_';

    private string $dir;
    /** @var callable */
    private $relogio;
    /** @var callable|null */
    private $logger;
    /** @var array<string,mixed> */
    private array $op;

    /**
     * @param callable|null $relogio fn(): int (segundos); padrão time()
     * @param callable|null $logger  fn(string $nivel, string $msg, array $ctx): void
     * @param array<string,mixed> $opcoes ttl_s, max_bytes, espera_trava_s, servir_stale,
     *        limpeza_intervalo_s, limpeza_idade_s, tmp_idade_s
     */
    public function __construct(string $dir, ?callable $relogio = null, ?callable $logger = null, array $opcoes = [])
    {
        $this->dir     = rtrim($dir, '/\\');
        $this->relogio = $relogio ?? static function (): int { return time(); };
        $this->logger  = $logger;
        $this->op      = $opcoes + [
            'ttl_s'               => 900,
            'max_bytes'           => 4194304,
            'espera_trava_s'      => 5,
            'servir_stale'        => true,
            'limpeza_intervalo_s' => 3600,
            'limpeza_idade_s'     => 86400,
            'tmp_idade_s'         => 600,
        ];
    }

    /**
     * @param callable      $provedorMarcadores fn(): ?string  (null/exceção = BYPASS)
     * @param callable      $gerador            fn(): array{status:int,corpo:string,meta?:array,gravar?:bool}
     *                                          (gravar=false: devolve sem gravar)
     * @param callable|null $validadorExtra     fn(array $meta, int $agora): bool (false = não é HIT)
     * @return array{estado:string,corpo:string,meta:array,validade:string,idade_s:int,gravado:bool,motivo:?string}
     */
    public function obter(string $chave, callable $provedorMarcadores, callable $gerador, ?callable $validadorExtra = null): array
    {
        $validade = null;
        try {
            $validade = $provedorMarcadores();
        } catch (Throwable $e) {
            $this->log('warning', 'cache: provedor de marcadores falhou', ['erro' => get_class($e)]);
        }
        if (!is_string($validade) || $validade === '') {
            $r = $gerador();
            return $this->resultado(self::ESTADO_BYPASS, (string) ($r['corpo'] ?? ''), $r['meta'] ?? [], '', 0, false, 'sem marcadores', $r);
        }

        $nome = self::PREFIXO . hash('sha256', $chave);
        $slot = $this->dir . '/' . $nome . '.vgc';
        $agora = $this->agora();

        $atual = $this->lerSlot($slot);
        if ($atual !== null && $this->ehHit($atual, $validade, $agora, $validadorExtra)) {
            return $this->resultado(self::ESTADO_HIT, $atual['corpo'], $atual['meta']['extra'], $validade, $agora - $atual['meta']['criado_em'], false, null);
        }
        $candidata = $atual;

        if (!$this->garantirDir()) {
            $r = $gerador();
            return $this->resultado(self::ESTADO_MISS, (string) ($r['corpo'] ?? ''), $r['meta'] ?? [], $validade, 0, false, 'pasta indisponível', $r);
        }

        $trava = @fopen($this->dir . '/' . $nome . '.lock', 'c');
        if ($trava === false) {
            $this->log('warning', 'cache: não abriu a trava', ['arquivo' => $nome]);
            $r = $gerador();
            return $this->resultado(self::ESTADO_MISS, (string) ($r['corpo'] ?? ''), $r['meta'] ?? [], $validade, 0, false, 'sem trava', $r);
        }

        $inicio = microtime(true);
        $limite = $inicio + max(0, (float) $this->op['espera_trava_s']);
        try {
            while (true) {
                if (flock($trava, LOCK_EX | LOCK_NB)) {
                    // Com a trava: outro processo pode ter gravado enquanto esperávamos.
                    $agora = $this->agora();
                    $relido = $this->lerSlot($slot);
                    if ($relido !== null && $this->ehHit($relido, $validade, $agora, $validadorExtra)) {
                        return $this->resultado(self::ESTADO_HIT, $relido['corpo'], $relido['meta']['extra'], $validade, $agora - $relido['meta']['criado_em'], false, null);
                    }
                    $r = $gerador();
                    $gravado = $this->gravarSeValido($slot, $nome, $validade, $r);
                    if ($gravado) {
                        $this->limpezaOportunista();
                    }
                    return $this->resultado(self::ESTADO_MISS, (string) ($r['corpo'] ?? ''), $r['meta'] ?? [], $validade, 0, $gravado, null, $r);
                }
                // Outro processo está regenerando esta chave.
                if ($candidata !== null && !empty($this->op['servir_stale'])) {
                    return $this->resultado(self::ESTADO_STALE, $candidata['corpo'], $candidata['meta']['extra'], (string) $candidata['meta']['validade'], max(0, $agora - $candidata['meta']['criado_em']), false, 'regeneração em andamento');
                }
                $agora = $this->agora();
                $novo = $this->lerSlot($slot);
                if ($novo !== null && $this->ehHit($novo, $validade, $agora, $validadorExtra)) {
                    return $this->resultado(self::ESTADO_HIT, $novo['corpo'], $novo['meta']['extra'], $validade, $agora - $novo['meta']['criado_em'], false, null);
                }
                if (microtime(true) >= $limite) {
                    break;
                }
                usleep(100000);
            }
            // Esgotou a espera: gera sem trava e tenta gravar.
            $r = $gerador();
            $gravado = $this->gravarSeValido($slot, $nome, $validade, $r);
            return $this->resultado(self::ESTADO_MISS, (string) ($r['corpo'] ?? ''), $r['meta'] ?? [], $validade, 0, $gravado, 'espera da trava esgotada', $r);
        } finally {
            @flock($trava, LOCK_UN);
            @fclose($trava);
        }
    }

    /**
     * Estado da entrada sem gerar nem gravar.
     * @return array{estado:string,idade_s:?int}
     */
    public function inspecionar(string $chave, string $validade, ?callable $validadorExtra = null): array
    {
        $slot = $this->dir . '/' . self::PREFIXO . hash('sha256', $chave) . '.vgc';
        $agora = $this->agora();
        $atual = $this->lerSlot($slot);
        if ($atual === null) {
            return ['estado' => self::ESTADO_MISS, 'idade_s' => null];
        }
        $idade = $agora - $atual['meta']['criado_em'];
        return ['estado' => $this->ehHit($atual, $validade, $agora, $validadorExtra) ? self::ESTADO_HIT : self::ESTADO_STALE, 'idade_s' => $idade];
    }

    /** Apaga entradas, temporários, travas e o marcador de limpeza (nunca .gitkeep). */
    public function limpar(): int
    {
        $n = 0;
        if (!is_dir($this->dir)) {
            return 0;
        }
        foreach (['*.vgc', '*.tmp', '*.lock'] as $padrao) {
            foreach (glob($this->dir . '/' . $padrao) ?: [] as $f) {
                if (@unlink($f)) {
                    $n++;
                }
            }
        }
        @unlink($this->dir . '/.limpeza');
        return $n;
    }

    /**
     * No máximo 1 vez por intervalo (mtime de <dir>/.limpeza): remove entradas e
     * temporários antigos e travas órfãs. Quem não obtém o flock do marcador segue.
     */
    public function limpezaOportunista(): void
    {
        try {
            $marca = $this->dir . '/.limpeza';
            $agora = $this->agora();
            $intervalo = (int) $this->op['limpeza_intervalo_s'];
            if (is_file($marca) && ($agora - (int) @filemtime($marca)) < $intervalo) {
                return;
            }
            $fh = @fopen($marca, 'c');
            if ($fh === false) {
                return;
            }
            try {
                if (!flock($fh, LOCK_EX | LOCK_NB)) {
                    return;
                }
                clearstatcache(true, $marca);
                if (($agora - (int) @filemtime($marca)) < $intervalo && filesize($marca) > 0) {
                    return;
                }
                @ftruncate($fh, 0);
                @fwrite($fh, (string) $agora);
                @fflush($fh);
                $idadeVgc = (int) $this->op['limpeza_idade_s'];
                $idadeTmp = (int) $this->op['tmp_idade_s'];
                foreach (glob($this->dir . '/*.vgc') ?: [] as $f) {
                    if ($agora - (int) @filemtime($f) > $idadeVgc) {
                        @unlink($f);
                    }
                }
                foreach (glob($this->dir . '/*.tmp') ?: [] as $f) {
                    if ($agora - (int) @filemtime($f) > $idadeTmp) {
                        @unlink($f);
                    }
                }
                foreach (glob($this->dir . '/*.lock') ?: [] as $f) {
                    if ($agora - (int) @filemtime($f) > $idadeVgc) {
                        $l = @fopen($f, 'c');
                        if ($l !== false) {
                            if (flock($l, LOCK_EX | LOCK_NB)) {
                                @unlink($f);
                            }
                            @fclose($l);
                        }
                    }
                }
                @touch($marca, $agora);
            } finally {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
        } catch (Throwable $e) {
            $this->log('warning', 'cache: limpeza oportunista falhou', ['erro' => get_class($e)]);
        }
    }

    // ── internos ────────────────────────────────────────────────────────

    private function agora(): int
    {
        return (int) ($this->relogio)();
    }

    /** @param array{meta:array,corpo:string} $e */
    private function ehHit(array $e, string $validade, int $agora, ?callable $validadorExtra): bool
    {
        $idade = $agora - $e['meta']['criado_em'];
        if ($e['meta']['validade'] !== $validade || $idade < 0 || $idade >= (int) $this->op['ttl_s']) {
            return false;
        }
        if ($validadorExtra !== null) {
            try {
                if ($validadorExtra($e['meta']['extra'], $agora) === false) {
                    return false;
                }
            } catch (Throwable $ex) {
                $this->log('warning', 'cache: validador extra falhou', ['erro' => get_class($ex)]);
                return false;
            }
        }
        return true;
    }

    private function garantirDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }
        if (!@mkdir($this->dir, 0770 & ~umask(), true) && !is_dir($this->dir)) {
            $this->log('warning', 'cache: não criou a pasta', ['pasta' => basename($this->dir)]);
            return false;
        }
        return true;
    }

    /** @return array{meta:array,corpo:string}|null */
    private function lerSlot(string $arq): ?array
    {
        if (!is_file($arq)) {
            return null;
        }
        $raw = @file_get_contents($arq);
        if ($raw === false) {
            $this->log('warning', 'cache: falha ao ler entrada', ['arquivo' => basename($arq)]);
            return null;
        }
        $pos = strpos($raw, "\n");
        if ($pos === false) {
            $this->log('warning', 'cache: entrada corrompida (sem cabeçalho)', ['arquivo' => basename($arq)]);
            return null;
        }
        $meta = json_decode(substr($raw, 0, $pos), true);
        $corpo = (string) substr($raw, $pos + 1);
        if (!is_array($meta)
            || ($meta['formato'] ?? null) !== self::FORMATO
            || !isset($meta['validade']) || !is_string($meta['validade'])
            || !isset($meta['criado_em']) || !is_int($meta['criado_em'])
            || ($meta['bytes'] ?? null) !== strlen($corpo)
            || ($meta['md5'] ?? null) !== md5($corpo)
        ) {
            $this->log('warning', 'cache: entrada corrompida ou de outro formato', ['arquivo' => basename($arq)]);
            return null;
        }
        if (!isset($meta['extra']) || !is_array($meta['extra'])) {
            $meta['extra'] = [];
        }
        return ['meta' => $meta, 'corpo' => $corpo];
    }

    /** @param array<string,mixed> $r resultado do gerador */
    private function gravarSeValido(string $slot, string $nome, string $validade, array $r): bool
    {
        $corpo = $r['corpo'] ?? null;
        if (($r['gravar'] ?? true) === false || ($r['status'] ?? 0) !== 200 || !is_string($corpo) || $corpo === '' || strlen($corpo) > (int) $this->op['max_bytes']) {
            return false;
        }
        $meta = [
            'formato'   => self::FORMATO,
            'validade'  => $validade,
            'criado_em' => $this->agora(),
            'bytes'     => strlen($corpo),
            'md5'       => md5($corpo),
            'extra'     => is_array($r['meta'] ?? null) ? $r['meta'] : [],
        ];
        $linha = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($linha === false) {
            $this->log('warning', 'cache: meta não serializável', ['arquivo' => $nome]);
            return false;
        }
        $tmp = $this->dir . '/' . $nome . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) {
                $this->log('warning', 'cache: não criou o temporário', ['arquivo' => $nome]);
                return false;
            }
            $dados = $linha . "\n" . $corpo;
            $ok = fwrite($fh, $dados) === strlen($dados) && fflush($fh);
            fclose($fh);
            if (!$ok) {
                @unlink($tmp);
                $this->log('warning', 'cache: escrita incompleta', ['arquivo' => $nome]);
                return false;
            }
            @chmod($tmp, 0660 & ~umask());
            if (!@rename($tmp, $slot)) {
                @unlink($tmp);
                $this->log('warning', 'cache: rename falhou', ['arquivo' => $nome]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            @unlink($tmp);
            $this->log('warning', 'cache: falha ao gravar', ['arquivo' => $nome, 'erro' => get_class($e)]);
            return false;
        }
    }

    /**
     * @param array<string,mixed>      $meta
     * @param array<string,mixed>|null $gerado
     * @return array{estado:string,corpo:string,meta:array,validade:string,idade_s:int,gravado:bool,motivo:?string,status:int}
     */
    private function resultado(string $estado, string $corpo, array $meta, string $validade, int $idade, bool $gravado, ?string $motivo, ?array $gerado = null): array
    {
        return [
            'estado'   => $estado,
            'corpo'    => $corpo,
            'meta'     => $meta,
            'validade' => $validade,
            'idade_s'  => $idade,
            'gravado'  => $gravado,
            'motivo'   => $motivo,
            'status'   => $gerado !== null ? (int) ($gerado['status'] ?? 200) : 200,
        ];
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $nivel, string $msg, array $ctx = []): void
    {
        if ($this->logger === null) {
            return;
        }
        try {
            ($this->logger)($nivel, $msg, $ctx);
        } catch (Throwable $e) {
            // log nunca derruba a requisição
        }
    }
}

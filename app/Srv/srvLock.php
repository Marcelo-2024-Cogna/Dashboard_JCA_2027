<?php

namespace App\Srv;

/**
 * srvLock
 * ───────
 * Trava de concorrência por arquivo (flock) para o job noturno e para a
 * extração por PI. O SO libera o flock sozinho se o processo morrer, então
 * um lock "preso" por queda não existe (o arquivo .lock pode ficar no disco).
 *
 *   $l = new srvLock('data/locks/extract_diaria.lock');
 *   if (!$l->tentar()) { ...já em execução... }
 *   ...
 *   $l->liberar();   // ou deixa o destrutor liberar
 */
class srvLock
{
    private string $arquivo;
    /** @var resource|null */
    private $fh = null;

    public function __construct(string $arquivo)
    {
        $this->arquivo = $arquivo;
    }

    /** Tentativa NÃO bloqueante. true = lock obtido por esta instância. */
    public function tentar(): bool
    {
        if ($this->fh !== null) return true;

        $dir = dirname($this->arquivo);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $fh = @fopen($this->arquivo, 'c');
        if ($fh === false) return false;

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        @ftruncate($fh, 0);
        @fwrite($fh, 'pid=' . getmypid() . ' desde=' . date('Y-m-d H:i:s') . "\n");
        @fflush($fh);
        $this->fh = $fh;
        return true;
    }

    /** Espera até $segundos pelo lock (sondagem a cada 1 s). */
    public function esperar(int $segundos): bool
    {
        $limite = time() + max(0, $segundos);
        do {
            if ($this->tentar()) return true;
            if (time() >= $limite) break;
            sleep(1);
        } while (true);
        return false;
    }

    public function liberar(): void
    {
        if ($this->fh === null) return;
        @flock($this->fh, LOCK_UN);
        @fclose($this->fh);
        $this->fh = null;
    }

    public function __destruct()
    {
        $this->liberar();
    }
}

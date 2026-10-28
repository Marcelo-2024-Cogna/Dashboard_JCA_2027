<?php

namespace App\Services;

use Throwable;

/**
 * Logger
 * ──────
 * Log de texto simples (v1.5, ETAPA 6b-2) para monitorar a extração do Jira
 * e, no futuro, a importação da Velocity e da Baseline.
 *
 * Arquivos em data/logs/:
 *   <canal>.log  (ex.: extracao.log, importacao.log)
 *   erros.log    (consolidado: recebe TODA linha de nível ERROR de qualquer canal)
 *
 * Formato (uma linha por evento, fácil de ler/grep):
 *   [2026-10-28 18:30:05] [ERROR] [extracao] mensagem | {"contexto":"json compacto"}
 *
 * Garantias:
 *   - NUNCA lança exceção: falha de log não derruba a extração;
 *   - segredos (token, senha, Authorization, Basic/Bearer, 'ATATT...') são
 *     mascarados na mensagem e no contexto;
 *   - rotação por tamanho: acima de 5 MB o arquivo vira .1 (mantém até .5).
 *
 * Uso: Logger::info('extracao', 'msg', ['k' => 'v']);
 *      Logger::exception('extracao', 'Falha X', $e, ['k' => 'v']);
 */
class Logger
{
    public const CANAL_EXTRACAO   = 'extracao';
    public const CANAL_IMPORTACAO = 'importacao';
    public const CANAL_DASHBOARD  = 'dashboard';

    private const MAX_BYTES    = 5242880; // 5 MB
    private const MAX_ROTATION = 5;
    private const TRACE_LINES  = 5;
    private const MASK         = '***';

    /** Diretório de logs (sobrescrevível para testes). */
    private static ?string $dir = null;

    public static function info(string $canal, string $msg, array $ctx = []): void
    {
        self::write('INFO', $canal, $msg, $ctx);
    }

    public static function warning(string $canal, string $msg, array $ctx = []): void
    {
        self::write('WARNING', $canal, $msg, $ctx);
    }

    public static function error(string $canal, string $msg, array $ctx = []): void
    {
        self::write('ERROR', $canal, $msg, $ctx);
    }

    /**
     * ERROR a partir de uma exceção: registra classe, mensagem, arquivo:linha
     * e as primeiras linhas do stack trace (sem argumentos).
     */
    public static function exception(string $canal, string $msg, Throwable $e, array $ctx = []): void
    {
        try {
            $root  = self::rootDir();
            $trace = [];
            foreach (array_slice($e->getTrace(), 0, self::TRACE_LINES) as $i => $t) {
                $fn    = ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? '');
                $where = isset($t['file']) ? self::rel($t['file'], $root) . ':' . ($t['line'] ?? '?') : '[internal]';
                $trace[] = "#{$i} {$where} {$fn}()";
            }
            $ctx['excecao'] = [
                'classe' => get_class($e),
                'msg'    => $e->getMessage(),
                'local'  => self::rel($e->getFile(), $root) . ':' . $e->getLine(),
                'trace'  => $trace,
            ];
            if ($e->getCode() !== 0) $ctx['excecao']['codigo'] = $e->getCode();
        } catch (Throwable $ignored) {
            // segue sem detalhes da exceção
        }
        self::write('ERROR', $canal, $msg, $ctx);
    }

    /** Mascara segredos em um texto (público para reuso/testes). */
    public static function sanitize(string $text): string
    {
        // Tokens da Atlassian.
        $text = preg_replace('/ATATT[A-Za-z0-9+\/=_\-.]*/', self::MASK, $text) ?? $text;
        // "Authorization: Basic xxx" / "Bearer xxx" / "Basic xxx".
        $text = preg_replace('/(Authorization\s*[:=]\s*)(?:(?:Basic|Bearer)\s+)?[^\s"\',|]+/i', '$1' . self::MASK, $text) ?? $text;
        $text = preg_replace('/\b(Basic|Bearer)\s+[A-Za-z0-9+\/=._~\-]{8,}/i', '$1 ' . self::MASK, $text) ?? $text;
        // chave=valor / chave: valor / "chave":"valor" para chaves sensíveis.
        $text = preg_replace(
            '/((?:pass(?:word|wd)?|senha|token|secret|api[_-]?key)["\']?\s*[:=]\s*["\']?)[^\s"\',&|}]+/i',
            '$1' . self::MASK,
            $text
        ) ?? $text;
        // credenciais em URL: scheme://user:senha@host
        $text = preg_replace('#(://[^/\s:@]+:)[^@\s/]+@#', '$1' . self::MASK . '@', $text) ?? $text;
        return $text;
    }

    // ── Internos ─────────────────────────────────────────────────────────

    private static function write(string $level, string $canal, string $msg, array $ctx): void
    {
        try {
            $canal = preg_replace('/[^a-z0-9_\-]/', '', strtolower($canal)) ?: 'geral';
            $msg   = self::sanitize(trim((string)preg_replace('/\s+/', ' ', $msg)));

            $line = '[' . date('Y-m-d H:i:s') . "] [{$level}] [{$canal}] {$msg}";
            if ($ctx !== []) {
                $json = json_encode(
                    self::sanitizeValue($ctx),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                );
                if ($json !== false && $json !== '[]') $line .= ' | ' . $json;
            }
            $line .= "\n";

            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;

            self::append($dir . '/' . $canal . '.log', $line);
            if ($level === 'ERROR') {
                self::append($dir . '/erros.log', $line);
            }
        } catch (Throwable $ignored) {
            // log nunca derruba o chamador
        }
    }

    private static function append(string $file, string $line): void
    {
        self::rotate($file);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function rotate(string $file): void
    {
        clearstatcache(true, $file);
        if (!is_file($file) || (int)@filesize($file) <= self::MAX_BYTES) return;

        @unlink($file . '.' . self::MAX_ROTATION);
        for ($i = self::MAX_ROTATION - 1; $i >= 1; $i--) {
            if (is_file($file . '.' . $i)) @rename($file . '.' . $i, $file . '.' . ($i + 1));
        }
        @rename($file, $file . '.1');
    }

    /** Sanitiza recursivamente: chaves sensíveis → máscara; strings → sanitize(). */
    private static function sanitizeValue(mixed $v, string $key = ''): mixed
    {
        if ($key !== '' && preg_match('/pass|senha|token|authoriz|secret|api[_-]?key|credential/i', $key)) {
            return self::MASK;
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = self::sanitizeValue($x, is_string($k) ? $k : '');
            }
            return $out;
        }
        if (is_string($v)) return self::sanitize($v);
        if (is_scalar($v) || $v === null) return $v;
        return '[' . get_debug_type($v) . ']';
    }

    private static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function dir(): string
    {
        return self::$dir ?? (self::rootDir() . '/data/logs');
    }

    private static function rel(string $path, string $root): string
    {
        $p = str_replace('\\', '/', $path);
        $r = rtrim(str_replace('\\', '/', $root), '/') . '/';
        return str_starts_with($p, $r) ? substr($p, strlen($r)) : basename($p);
    }
}

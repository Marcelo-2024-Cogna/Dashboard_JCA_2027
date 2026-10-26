<?php

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database
 * ────────
 * Fábrica de conexão PDO ÚNICA do dashboard (singleton estático, lazy).
 *
 * Lê as credenciais de config/db.php (fora do git) e devolve sempre a
 * MESMA instância PDO durante o ciclo de vida da requisição. Centralizar
 * aqui evita abrir várias conexões e padroniza os atributos do PDO.
 *
 * Atributos aplicados:
 *   - ATTR_ERRMODE            = EXCEPTION   (erros viram exceção)
 *   - ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC (arrays associativos)
 *   - ATTR_EMULATE_PREPARES   = false       (prepared statements reais)
 *   - charset utf8mb4 no DSN
 *
 * PORTABILIDADE: o caminho do config é resolvido via dirname(__DIR__),
 * funcionando igual no Windows/XAMPP (dev) e no Linux/CentOS (produção).
 */
class Database
{
    /** Instância única reaproveitada na requisição. */
    private static ?PDO $instance = null;

    /**
     * Retorna a conexão PDO, criando-a sob demanda na primeira chamada.
     * Lança RuntimeException com mensagem clara se a conexão falhar
     * (sem expor a senha no texto do erro).
     */
    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $configPath = dirname(__DIR__) . '/config/db.php';
        if (!is_file($configPath)) {
            throw new RuntimeException(
                "Configuração de banco não encontrada em config/db.php. " .
                "Copie config/db.php.example e ajuste as credenciais."
            );
        }

        $cfg = require $configPath;

        $host    = $cfg['host']    ?? 'localhost';
        $port    = $cfg['port']    ?? 3306;
        $dbname  = $cfg['dbname']  ?? '';
        $user    = $cfg['user']    ?? '';
        $pass    = $cfg['pass']    ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Não repassa a senha nem a mensagem crua do driver.
            throw new RuntimeException(
                "Falha ao conectar ao banco '{$dbname}' em {$host}:{$port}. " .
                "Verifique host, usuário e senha em config/db.php."
            );
        }

        return self::$instance;
    }
}

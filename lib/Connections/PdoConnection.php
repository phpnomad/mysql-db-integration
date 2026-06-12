<?php

namespace PHPNomad\MySql\Integration\Connections;

use PDO;

/**
 * Lazily-opened PDO connection for the PDO database strategies.
 *
 * Accepts the same configuration shape consumers passed to SafeMySQL
 * (host / user / pass / db / port / charset), so migrating off the
 * abandoned colshrapnel/safemysql backend is a binding swap. An existing
 * PDO instance can also be wrapped directly via fromPdo() — useful for
 * tests and for applications that manage their own connection.
 */
class PdoConnection
{
    protected ?PDO $pdo = null;

    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param array{host?: string, user?: string, pass?: string, db?: string, port?: int, charset?: string} $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public static function fromPdo(PDO $pdo): static
    {
        $connection = new static();
        $connection->pdo = $pdo;

        return $connection;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $host = $this->config['host'] ?? 'localhost';
            $port = (int) ($this->config['port'] ?? 3306);
            $db = $this->config['db'] ?? '';
            $charset = $this->config['charset'] ?? 'utf8mb4';

            $this->pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset={$charset}",
                $this->config['user'] ?? 'root',
                $this->config['pass'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Parity with the mysqli-based SafeMySQL backend this
                    // strategy replaces: all column values arrive as strings.
                    // Consumers were written against that typing; native
                    // int/float fetches would silently change cache-key
                    // hashing and strict comparisons downstream.
                    PDO::ATTR_STRINGIFY_FETCHES => true,
                ]
            );
        }

        return $this->pdo;
    }
}

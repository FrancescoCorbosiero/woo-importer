<?php

declare(strict_types=1);

namespace WooImporter\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connection manager (Singleton pattern)
 *
 * Provides a single PDO connection instance for the application.
 * Supports transactions and connection health checks.
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config;
    private int $transactionLevel = 0;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Get the singleton instance
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                $config = self::loadConfigFromEnv();
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Load database configuration from environment variables
     */
    private static function loadConfigFromEnv(): array
    {
        return [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database' => $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? '',
            'username' => $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        ];
    }

    /**
     * Establish database connection
     */
    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->config['charset']} COLLATE {$this->config['collation']}",
        ];

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Database connection failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the PDO instance
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    /**
     * Execute a query and return the statement
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Execute a query and return all rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Execute a query and return a single row
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Execute a query and return a single column value
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0)
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query and return affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Insert a row and return the last insert ID
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->execute($sql, array_values($data));
        return (int) $this->getPdo()->lastInsertId();
    }

    /**
     * Update rows and return affected count
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );

        return $this->execute($sql, array_merge($params, $whereParams));
    }

    /**
     * Insert or update on duplicate key
     */
    public function upsert(string $table, array $data, array $updateColumns = []): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        if (empty($updateColumns)) {
            $updateColumns = $columns;
        }

        $updateParts = [];
        foreach ($updateColumns as $col) {
            $updateParts[] = "`{$col}` = VALUES(`{$col}`)";
        }

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        return $this->execute($sql, array_values($data));
    }

    /**
     * Begin a transaction (supports nested transactions via savepoints)
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionLevel === 0) {
            $this->getPdo()->beginTransaction();
        } else {
            $this->getPdo()->exec("SAVEPOINT level_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
        return true;
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            return false;
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            return $this->getPdo()->commit();
        }

        return true;
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel === 0) {
            return false;
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            return $this->getPdo()->rollBack();
        }

        $this->getPdo()->exec("ROLLBACK TO SAVEPOINT level_{$this->transactionLevel}");
        return true;
    }

    /**
     * Execute a callback within a transaction
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if connection is alive
     */
    public function ping(): bool
    {
        try {
            $this->getPdo()->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Reconnect if connection was lost
     */
    public function reconnectIfNeeded(): void
    {
        if (!$this->ping()) {
            $this->pdo = null;
            $this->connect();
        }
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Reset the singleton instance (for testing)
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->close();
            self::$instance = null;
        }
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }
}

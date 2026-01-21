<?php

declare(strict_types=1);

namespace WooImporter\Models;

use WooImporter\Database\Database;

/**
 * Base model class with common database operations
 */
abstract class BaseModel
{
    protected Database $db;
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Get table name
     */
    public static function getTable(): string
    {
        return static::$table;
    }

    /**
     * Find a record by primary key
     */
    public function find(int $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1',
            static::$table,
            static::$primaryKey
        );
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Find a record by a specific column
     */
    public function findBy(string $column, $value): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1',
            static::$table,
            $column
        );
        return $this->db->fetchOne($sql, [$value]);
    }

    /**
     * Find all records matching conditions
     */
    public function findAllBy(string $column, $value): array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ?',
            static::$table,
            $column
        );
        return $this->db->fetchAll($sql, [$value]);
    }

    /**
     * Find records with custom WHERE clause
     */
    public function findWhere(string $where, array $params = [], ?int $limit = null): array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE %s', static::$table, $where);
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get all records
     */
    public function all(?int $limit = null, int $offset = 0): array
    {
        $sql = sprintf('SELECT * FROM `%s`', static::$table);
        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        return $this->db->fetchAll($sql);
    }

    /**
     * Count records
     */
    public function count(?string $where = null, array $params = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM `%s`', static::$table);
        if ($where !== null) {
            $sql .= " WHERE {$where}";
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    /**
     * Insert a new record
     */
    public function create(array $data): int
    {
        return $this->db->insert(static::$table, $data);
    }

    /**
     * Update a record by primary key
     */
    public function update(int $id, array $data): int
    {
        return $this->db->update(
            static::$table,
            $data,
            sprintf('`%s` = ?', static::$primaryKey),
            [$id]
        );
    }

    /**
     * Insert or update on duplicate key
     */
    public function upsert(array $data, array $updateColumns = []): int
    {
        return $this->db->upsert(static::$table, $data, $updateColumns);
    }

    /**
     * Delete a record by primary key
     */
    public function delete(int $id): int
    {
        $sql = sprintf(
            'DELETE FROM `%s` WHERE `%s` = ?',
            static::$table,
            static::$primaryKey
        );
        return $this->db->execute($sql, [$id]);
    }

    /**
     * Soft delete (set status to deleted)
     */
    public function softDelete(int $id): int
    {
        return $this->update($id, ['status' => 'deleted']);
    }

    /**
     * Check if a record exists
     */
    public function exists(string $column, $value): bool
    {
        $sql = sprintf(
            'SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1',
            static::$table,
            $column
        );
        return $this->db->fetchColumn($sql, [$value]) !== false;
    }

    /**
     * Get the database instance
     */
    public function getDb(): Database
    {
        return $this->db;
    }
}

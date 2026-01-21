<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * SyncLog model - audit trail for all synchronization operations
 */
class SyncLog extends BaseModel
{
    protected static string $table = 'sync_log';

    // Sync types
    public const TYPE_FEED_TO_DB = 'feed_to_db';
    public const TYPE_DB_TO_WOO = 'db_to_woo';
    public const TYPE_WOO_TO_DB = 'woo_to_db';
    public const TYPE_WEBHOOK = 'webhook';

    // Entity types
    public const ENTITY_PRODUCT = 'product';
    public const ENTITY_VARIATION = 'variation';
    public const ENTITY_BATCH = 'batch';

    // Actions
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_SKIP = 'skip';
    public const ACTION_ERROR = 'error';

    /**
     * Log a sync operation
     */
    public function log(
        string $syncType,
        string $entityType,
        string $action,
        ?int $entityId = null,
        ?int $wcEntityId = null,
        ?string $sku = null,
        ?array $changes = null,
        ?string $source = null,
        ?string $message = null
    ): int {
        return $this->create([
            'sync_type' => $syncType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'wc_entity_id' => $wcEntityId,
            'sku' => $sku,
            'action' => $action,
            'changes' => $changes !== null ? json_encode($changes) : null,
            'source' => $source,
            'message' => $message,
        ]);
    }

    /**
     * Log a feed-to-database sync
     */
    public function logFeedSync(
        string $action,
        ?int $productId = null,
        ?string $sku = null,
        ?array $changes = null,
        ?string $message = null
    ): int {
        return $this->log(
            self::TYPE_FEED_TO_DB,
            self::ENTITY_PRODUCT,
            $action,
            $productId,
            null,
            $sku,
            $changes,
            'golden_sneakers',
            $message
        );
    }

    /**
     * Log a database-to-WooCommerce sync
     */
    public function logWooSync(
        string $action,
        ?int $productId = null,
        ?int $wcProductId = null,
        ?string $sku = null,
        ?array $changes = null,
        ?string $message = null
    ): int {
        return $this->log(
            self::TYPE_DB_TO_WOO,
            self::ENTITY_PRODUCT,
            $action,
            $productId,
            $wcProductId,
            $sku,
            $changes,
            'woocommerce',
            $message
        );
    }

    /**
     * Log a webhook event
     */
    public function logWebhook(
        string $action,
        string $entityType,
        ?int $wcEntityId = null,
        ?int $entityId = null,
        ?string $sku = null,
        ?array $changes = null,
        ?string $message = null
    ): int {
        return $this->log(
            self::TYPE_WEBHOOK,
            $entityType,
            $action,
            $entityId,
            $wcEntityId,
            $sku,
            $changes,
            'webhook',
            $message
        );
    }

    /**
     * Log an error
     */
    public function logError(
        string $syncType,
        string $entityType,
        ?string $sku = null,
        string $message = '',
        ?array $context = null
    ): int {
        return $this->log(
            $syncType,
            $entityType,
            self::ACTION_ERROR,
            null,
            null,
            $sku,
            $context,
            null,
            $message
        );
    }

    /**
     * Get recent logs
     */
    public function getRecent(int $limit = 100, ?string $syncType = null): array
    {
        $sql = 'SELECT * FROM sync_log';
        $params = [];

        if ($syncType !== null) {
            $sql .= ' WHERE sync_type = ?';
            $params[] = $syncType;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get logs for a specific product
     */
    public function getForProduct(int $productId, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM sync_log WHERE entity_id = ? ORDER BY created_at DESC';
        $params = [$productId];

        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get logs for a specific SKU
     */
    public function getForSku(string $sku, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM sync_log WHERE sku = ? ORDER BY created_at DESC';
        $params = [$sku];

        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get sync statistics for a time period
     */
    public function getStats(string $since = '-24 hours'): array
    {
        $date = date('Y-m-d H:i:s', strtotime($since));

        $sql = "
            SELECT
                sync_type,
                action,
                COUNT(*) as count
            FROM sync_log
            WHERE created_at >= ?
            GROUP BY sync_type, action
            ORDER BY sync_type, action
        ";

        $results = $this->db->fetchAll($sql, [$date]);

        $stats = [];
        foreach ($results as $row) {
            $key = $row['sync_type'];
            if (!isset($stats[$key])) {
                $stats[$key] = [];
            }
            $stats[$key][$row['action']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Get error count for a time period
     */
    public function getErrorCount(string $since = '-1 hour'): int
    {
        $date = date('Y-m-d H:i:s', strtotime($since));
        $sql = "SELECT COUNT(*) FROM sync_log WHERE action = 'error' AND created_at >= ?";
        return (int) $this->db->fetchColumn($sql, [$date]);
    }

    /**
     * Clean old logs
     */
    public function cleanOld(int $daysToKeep = 30): int
    {
        $date = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
        $sql = 'DELETE FROM sync_log WHERE created_at < ?';
        return $this->db->execute($sql, [$date]);
    }
}

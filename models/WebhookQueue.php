<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * WebhookQueue model - queue for incoming WooCommerce webhooks
 */
class WebhookQueue extends BaseModel
{
    protected static string $table = 'webhook_queue';

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Add a webhook to the queue
     */
    public function enqueue(
        string $topic,
        string $resource,
        int $resourceId,
        array $payload,
        ?string $webhookId = null
    ): int {
        return $this->create([
            'webhook_id' => $webhookId,
            'topic' => $topic,
            'resource' => $resource,
            'resource_id' => $resourceId,
            'payload' => json_encode($payload),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }

    /**
     * Get pending webhooks for processing
     */
    public function getPending(int $limit = 50): array
    {
        $sql = "
            SELECT * FROM webhook_queue
            WHERE status = 'pending' AND attempts < 3
            ORDER BY received_at ASC
            LIMIT ?
        ";
        return $this->db->fetchAll($sql, [$limit]);
    }

    /**
     * Mark webhook as processing
     */
    public function markProcessing(int $id): int
    {
        return $this->update($id, [
            'status' => self::STATUS_PROCESSING,
            'attempts' => $this->db->fetchColumn(
                'SELECT attempts + 1 FROM webhook_queue WHERE id = ?',
                [$id]
            ),
        ]);
    }

    /**
     * Mark webhook as completed
     */
    public function markCompleted(int $id): int
    {
        return $this->update($id, [
            'status' => self::STATUS_COMPLETED,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark webhook as failed
     */
    public function markFailed(int $id, string $errorMessage): int
    {
        return $this->update($id, [
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Retry a failed webhook
     */
    public function retry(int $id): int
    {
        return $this->update($id, [
            'status' => self::STATUS_PENDING,
            'error_message' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * Get webhooks for a specific resource
     */
    public function getForResource(string $resource, int $resourceId): array
    {
        $sql = '
            SELECT * FROM webhook_queue
            WHERE resource = ? AND resource_id = ?
            ORDER BY received_at DESC
        ';
        return $this->db->fetchAll($sql, [$resource, $resourceId]);
    }

    /**
     * Check if a webhook was already processed (deduplication)
     */
    public function isDuplicate(string $webhookId): bool
    {
        if (empty($webhookId)) {
            return false;
        }
        return $this->exists('webhook_id', $webhookId);
    }

    /**
     * Get queue statistics
     */
    public function getStats(): array
    {
        $sql = "
            SELECT
                status,
                COUNT(*) as count
            FROM webhook_queue
            GROUP BY status
        ";
        $results = $this->db->fetchAll($sql);

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($results as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Clean old completed webhooks
     */
    public function cleanOld(int $daysToKeep = 7): int
    {
        $date = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
        $sql = "DELETE FROM webhook_queue WHERE status = 'completed' AND processed_at < ?";
        return $this->db->execute($sql, [$date]);
    }

    /**
     * Get failed webhooks for review
     */
    public function getFailed(int $limit = 50): array
    {
        $sql = "
            SELECT * FROM webhook_queue
            WHERE status = 'failed'
            ORDER BY received_at DESC
            LIMIT ?
        ";
        return $this->db->fetchAll($sql, [$limit]);
    }
}

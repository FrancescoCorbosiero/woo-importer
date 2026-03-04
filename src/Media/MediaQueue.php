<?php

namespace ResellPiacenza\Media;

use ResellPiacenza\Support\Storage;

/**
 * Async Media Upload Queue
 *
 * Decouples image upload from the import pipeline. Products are created
 * imageless during pipeline runs, and a separate worker (bin/process-media-queue)
 * processes the queue in the background, attaching images on the next sync.
 *
 * Queue entries are stored in SQLite (media_queue table) and processed in
 * FIFO order with configurable concurrency.
 *
 * @package ResellPiacenza\Media
 */
class MediaQueue
{
    private Storage $storage;

    /** @var string[] Valid statuses */
    private const STATUSES = ['pending', 'processing', 'completed', 'failed'];

    /**
     * @param Storage $storage SQLite storage instance
     */
    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Enqueue an image for upload
     *
     * @param string $sku Product SKU
     * @param string $url Image source URL
     * @param string $productName Product name (for SEO metadata)
     * @param string $brandName Brand name (for SEO metadata)
     * @param string $type 'primary' or 'gallery'
     * @param int $galleryIndex Gallery position (0 for primary)
     * @return bool True if newly enqueued, false if already exists
     */
    public function enqueue(
        string $sku,
        string $url,
        string $productName = '',
        string $brandName = '',
        string $type = 'primary',
        int $galleryIndex = 0
    ): bool {
        $pdo = $this->storage->getPdo();

        // Check if already queued or completed
        $stmt = $pdo->prepare(
            'SELECT status FROM media_queue WHERE sku = :sku AND source_url = :url'
        );
        $stmt->execute([':sku' => $sku, ':url' => $url]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            // Re-queue failed items
            if ($existing['status'] === 'failed') {
                $stmt = $pdo->prepare(
                    'UPDATE media_queue SET status = :status, attempts = 0, error = NULL, updated_at = datetime(\'now\')
                     WHERE sku = :sku AND source_url = :url'
                );
                $stmt->execute([':status' => 'pending', ':sku' => $sku, ':url' => $url]);
                return true;
            }
            return false; // Already queued/completed
        }

        $stmt = $pdo->prepare(
            'INSERT INTO media_queue (sku, source_url, product_name, brand_name, type, gallery_index, status)
             VALUES (:sku, :url, :product_name, :brand_name, :type, :gallery_index, :status)'
        );
        $stmt->execute([
            ':sku' => $sku,
            ':url' => $url,
            ':product_name' => $productName,
            ':brand_name' => $brandName,
            ':type' => $type,
            ':gallery_index' => $galleryIndex,
            ':status' => 'pending',
        ]);

        return true;
    }

    /**
     * Enqueue a full product's images (primary + gallery)
     *
     * @param array $imageData Image data from resolveImages(): {sku, url, gallery_urls, product_name, brand_name}
     * @return int Number of items enqueued
     */
    public function enqueueProduct(array $imageData): int
    {
        $count = 0;
        $sku = $imageData['sku'] ?? '';
        $url = $imageData['url'] ?? '';

        if (!$sku || !$url) {
            return 0;
        }

        // Primary image
        if ($this->enqueue(
            $sku,
            $url,
            $imageData['product_name'] ?? '',
            $imageData['brand_name'] ?? '',
            'primary',
            0
        )) {
            $count++;
        }

        // Gallery images
        foreach ($imageData['gallery_urls'] ?? [] as $idx => $galleryUrl) {
            if ($this->enqueue(
                $sku,
                $galleryUrl,
                $imageData['product_name'] ?? '',
                $imageData['brand_name'] ?? '',
                'gallery',
                $idx + 1
            )) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Fetch a batch of pending items for processing
     *
     * Atomically marks items as 'processing' to prevent double-processing.
     *
     * @param int $batchSize Number of items to dequeue
     * @return array Queue entries
     */
    public function dequeue(int $batchSize = 10): array
    {
        $pdo = $this->storage->getPdo();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, sku, source_url, product_name, brand_name, type, gallery_index, attempts
                 FROM media_queue
                 WHERE status = :status
                 ORDER BY created_at ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':status', 'pending', \PDO::PARAM_STR);
            $stmt->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($items)) {
                $ids = array_column($items, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $pdo->prepare(
                    "UPDATE media_queue SET status = 'processing', updated_at = datetime('now') WHERE id IN ({$placeholders})"
                );
                $update->execute($ids);
            }

            $pdo->commit();
            return $items;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a queue item as completed
     *
     * @param int $id Queue item ID
     * @param int $mediaId WordPress media ID
     */
    public function markCompleted(int $id, int $mediaId): void
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare(
            'UPDATE media_queue SET status = :status, wp_media_id = :media_id, updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([':status' => 'completed', ':media_id' => $mediaId, ':id' => $id]);
    }

    /**
     * Mark a queue item as failed
     *
     * @param int $id Queue item ID
     * @param string $error Error message
     * @param int $maxAttempts Max retry attempts before permanent failure
     */
    public function markFailed(int $id, string $error, int $maxAttempts = 3): void
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare(
            'UPDATE media_queue
             SET attempts = attempts + 1,
                 error = :error,
                 status = CASE WHEN attempts + 1 >= :max THEN \'failed\' ELSE \'pending\' END,
                 updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([':error' => $error, ':max' => $maxAttempts, ':id' => $id]);
    }

    /**
     * Get queue statistics
     *
     * @return array{pending: int, processing: int, completed: int, failed: int, total: int}
     */
    public function getStats(): array
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query(
            'SELECT status, COUNT(*) as cnt FROM media_queue GROUP BY status'
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['cnt'];
            $stats['total'] += (int) $row['cnt'];
        }

        return $stats;
    }

    /**
     * Get completed uploads for a SKU (to build image-map entries)
     *
     * @param string $sku Product SKU
     * @return array{primary: int|null, gallery: int[]} Media IDs
     */
    public function getCompletedForSku(string $sku): array
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare(
            'SELECT type, gallery_index, wp_media_id
             FROM media_queue
             WHERE sku = :sku AND status = :status
             ORDER BY gallery_index ASC'
        );
        $stmt->execute([':sku' => $sku, ':status' => 'completed']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = ['primary' => null, 'gallery' => []];
        foreach ($rows as $row) {
            if ($row['type'] === 'primary') {
                $result['primary'] = (int) $row['wp_media_id'];
            } else {
                $result['gallery'][] = (int) $row['wp_media_id'];
            }
        }

        return $result;
    }

    /**
     * Get all SKUs that have completed uploads not yet synced to image-map
     *
     * @return string[] SKUs with completed uploads
     */
    public function getSkusWithCompletedUploads(): array
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->query(
            "SELECT DISTINCT sku FROM media_queue WHERE status = 'completed'"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Purge completed entries (after syncing to image-map)
     *
     * @param string|null $sku Specific SKU to purge, or null for all completed
     * @return int Number of rows deleted
     */
    public function purgeCompleted(?string $sku = null): int
    {
        $pdo = $this->storage->getPdo();
        if ($sku !== null) {
            $stmt = $pdo->prepare(
                'DELETE FROM media_queue WHERE sku = :sku AND status = :status'
            );
            $stmt->execute([':sku' => $sku, ':status' => 'completed']);
        } else {
            $stmt = $pdo->prepare('DELETE FROM media_queue WHERE status = :status');
            $stmt->execute([':status' => 'completed']);
        }
        return $stmt->rowCount();
    }

    /**
     * Reset stuck "processing" items back to pending
     *
     * Useful if a worker crashed mid-batch.
     *
     * @param int $olderThanMinutes Reset items processing for longer than N minutes
     * @return int Number of items reset
     */
    public function resetStuck(int $olderThanMinutes = 10): int
    {
        $pdo = $this->storage->getPdo();
        $stmt = $pdo->prepare(
            "UPDATE media_queue
             SET status = 'pending', updated_at = datetime('now')
             WHERE status = 'processing'
               AND updated_at < datetime('now', :age)"
        );
        $stmt->execute([':age' => "-{$olderThanMinutes} minutes"]);
        return $stmt->rowCount();
    }
}

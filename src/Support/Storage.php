<?php

namespace ResellPiacenza\Support;

/**
 * SQLite Storage Layer
 *
 * Replaces scattered JSON file state with a single SQLite database per
 * environment. Provides repository methods for products, variations,
 * API cache, media map, taxonomy map, and sync log.
 *
 * One database file per environment: data/{envName}.sqlite
 * Uses WAL journal mode for concurrent read safety.
 * All writes happen in transactions.
 *
 * @package ResellPiacenza\Support
 */
class Storage
{
    private \PDO $pdo;
    private string $dbPath;
    private static array $instances = [];

    /**
     * Get or create a Storage instance for the given database path
     *
     * @param string $dbPath Full path to SQLite database file
     * @return self
     */
    public static function getInstance(string $dbPath): self
    {
        if (!isset(self::$instances[$dbPath])) {
            self::$instances[$dbPath] = new self($dbPath);
        }
        return self::$instances[$dbPath];
    }

    /**
     * Reset all instances (for testing)
     */
    public static function resetInstances(): void
    {
        self::$instances = [];
    }

    /**
     * @param string $dbPath Full path to the SQLite database file
     */
    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;

        // Ensure directory exists
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new \PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Enable WAL mode for concurrent read safety
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        // Run pending migrations
        $this->migrate();
    }

    /**
     * Get the PDO connection (for advanced queries)
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Get the database file path
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    // =========================================================================
    // Migration System
    // =========================================================================

    /**
     * Run any unapplied SQL migrations
     */
    private function migrate(): void
    {
        // Create migrations tracking table
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS _migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )
        ');

        // Find migration files
        $migrationsDir = __DIR__ . '/migrations';
        if (!is_dir($migrationsDir)) {
            return;
        }

        $files = glob($migrationsDir . '/*.sql');
        sort($files);

        // Get already-applied migrations
        $applied = [];
        $stmt = $this->pdo->query('SELECT filename FROM _migrations');
        foreach ($stmt as $row) {
            $applied[] = $row['filename'];
        }

        // Run unapplied migrations
        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $applied)) {
                continue;
            }

            $sql = file_get_contents($file);
            $this->pdo->exec($sql);

            $stmt = $this->pdo->prepare('INSERT INTO _migrations (filename) VALUES (?)');
            $stmt->execute([$filename]);
        }
    }

    // =========================================================================
    // Transaction Helpers
    // =========================================================================

    /**
     * Execute a callback within a transaction
     *
     * @param callable $callback Receives $this as argument
     * @return mixed Return value of the callback
     */
    public function transaction(callable $callback)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // Products
    // =========================================================================

    /**
     * Get a product by SKU
     */
    public function getProduct(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insert or update a product
     */
    public function upsertProduct(string $sku, array $normalized, string $source): void
    {
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        $name = $normalized['name'] ?? $sku;
        $brand = $normalized['brand'] ?? null;
        $category = $normalized['category_type'] ?? $normalized['category'] ?? null;

        $stmt = $this->pdo->prepare('
            INSERT INTO products (sku, name, brand, category, source, normalized_json, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))
            ON CONFLICT(sku) DO UPDATE SET
                name = excluded.name,
                brand = excluded.brand,
                category = excluded.category,
                source = excluded.source,
                normalized_json = excluded.normalized_json,
                updated_at = datetime(\'now\')
        ');
        $stmt->execute([$sku, $name, $brand, $category, $source, $json]);
    }

    /**
     * Set the WC payload and signature for a product
     */
    public function setWcPayload(string $sku, array $wcPayload, string $signature): void
    {
        $json = json_encode($wcPayload, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('
            UPDATE products SET wc_payload_json = ?, signature = ?, updated_at = datetime(\'now\')
            WHERE sku = ?
        ');
        $stmt->execute([$json, $signature, $sku]);
    }

    /**
     * Set the WC product ID after successful import
     */
    public function setWcProductId(string $sku, int $wcProductId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products SET wc_product_id = ?, updated_at = datetime(\'now\')
            WHERE sku = ?
        ');
        $stmt->execute([$wcProductId, $sku]);
    }

    /**
     * Mark a product as successfully synced
     */
    public function markSynced(string $sku): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE products SET synced_at = datetime(\'now\')
            WHERE sku = ?
        ');
        $stmt->execute([$sku]);
    }

    /**
     * Get products from a source that haven't been updated recently
     */
    public function getStaleProducts(string $source, int $olderThanSeconds): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM products
            WHERE source = ?
              AND updated_at < datetime(\'now\', ? || \' seconds\')
        ');
        $stmt->execute([$source, -$olderThanSeconds]);
        return $stmt->fetchAll();
    }

    /**
     * Get all SKUs from a source
     */
    public function getAllSkusBySource(string $source): array
    {
        $stmt = $this->pdo->prepare('SELECT sku FROM products WHERE source = ?');
        $stmt->execute([$source]);
        return array_column($stmt->fetchAll(), 'sku');
    }

    /**
     * Get products whose signature has changed since last sync
     */
    public function getProductsNeedingSync(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM products
            WHERE signature IS NOT NULL
              AND (synced_at IS NULL OR updated_at > synced_at)
        ');
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Variations
    // =========================================================================

    /**
     * Insert or update a variation
     */
    public function upsertVariation(string $sku, string $sizeEu, array $data, string $source): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO variations (sku, size_eu, price, regular_price, sale_price,
                                    stock_quantity, stock_status, source, wc_variation_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))
            ON CONFLICT(sku, size_eu, source) DO UPDATE SET
                price = excluded.price,
                regular_price = excluded.regular_price,
                sale_price = excluded.sale_price,
                stock_quantity = excluded.stock_quantity,
                stock_status = excluded.stock_status,
                wc_variation_id = excluded.wc_variation_id,
                updated_at = datetime(\'now\')
        ');
        $stmt->execute([
            $sku,
            $sizeEu,
            $data['price'] ?? null,
            $data['regular_price'] ?? null,
            $data['sale_price'] ?? null,
            $data['stock_quantity'] ?? 0,
            $data['stock_status'] ?? 'outofstock',
            $source,
            $data['wc_variation_id'] ?? null,
        ]);
    }

    /**
     * Get all variations for a product
     */
    public function getVariations(string $sku): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM variations WHERE sku = ? ORDER BY size_eu');
        $stmt->execute([$sku]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // API Cache
    // =========================================================================

    /**
     * Get a cached API response if still fresh
     *
     * @param string $key Cache key (e.g. 'kicksdb:DH9765-100')
     * @param int $ttlSeconds Max age in seconds (0 = any age)
     * @return array|null Decoded response or null if expired/missing
     */
    public function getCached(string $key, int $ttlSeconds = 0): ?array
    {
        if ($ttlSeconds > 0) {
            $stmt = $this->pdo->prepare('
                SELECT response_json FROM api_cache
                WHERE cache_key = ?
                  AND fetched_at > datetime(\'now\', ? || \' seconds\')
            ');
            $stmt->execute([$key, -$ttlSeconds]);
        } else {
            $stmt = $this->pdo->prepare('
                SELECT response_json FROM api_cache WHERE cache_key = ?
            ');
            $stmt->execute([$key]);
        }

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return json_decode($row['response_json'], true);
    }

    /**
     * Store an API response in cache
     */
    public function setCache(string $key, array $data, string $source): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare('
            INSERT INTO api_cache (cache_key, source, response_json, fetched_at)
            VALUES (?, ?, ?, datetime(\'now\'))
            ON CONFLICT(cache_key) DO UPDATE SET
                source = excluded.source,
                response_json = excluded.response_json,
                fetched_at = datetime(\'now\')
        ');
        $stmt->execute([$key, $source, $json]);
    }

    /**
     * Remove expired cache entries
     *
     * @return int Number of entries pruned
     */
    public function pruneCache(string $source, int $olderThanSeconds): int
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM api_cache
            WHERE source = ?
              AND fetched_at < datetime(\'now\', ? || \' seconds\')
        ');
        $stmt->execute([$source, -$olderThanSeconds]);
        return $stmt->rowCount();
    }

    // =========================================================================
    // Media Map
    // =========================================================================

    /**
     * Get WordPress media ID for a source URL
     */
    public function getMediaId(string $sourceUrl): ?int
    {
        $hash = md5($sourceUrl);
        $stmt = $this->pdo->prepare('SELECT wp_media_id FROM media_map WHERE url_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ? (int) $row['wp_media_id'] : null;
    }

    /**
     * Store a media URL → WP media ID mapping
     */
    public function setMediaMapping(string $sourceUrl, int $wpMediaId, string $wpUrl): void
    {
        $hash = md5($sourceUrl);
        $stmt = $this->pdo->prepare('
            INSERT INTO media_map (url_hash, source_url, wp_media_id, wp_url, uploaded_at)
            VALUES (?, ?, ?, ?, datetime(\'now\'))
            ON CONFLICT(url_hash) DO UPDATE SET
                wp_media_id = excluded.wp_media_id,
                wp_url = excluded.wp_url,
                uploaded_at = datetime(\'now\')
        ');
        $stmt->execute([$hash, $sourceUrl, $wpMediaId, $wpUrl]);
    }

    /**
     * Get all media mappings
     */
    public function getAllMedia(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM media_map ORDER BY uploaded_at DESC');
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Taxonomy Map
    // =========================================================================

    /**
     * Get WC term ID for a slug + taxonomy
     */
    public function getTaxonomyId(string $slug, string $taxonomy): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT wc_term_id FROM taxonomy_map WHERE slug = ? AND taxonomy = ?
        ');
        $stmt->execute([$slug, $taxonomy]);
        $row = $stmt->fetch();
        return $row ? (int) $row['wc_term_id'] : null;
    }

    /**
     * Store a taxonomy slug → WC term ID mapping
     */
    public function setTaxonomyMapping(
        string $slug,
        string $taxonomy,
        int $wcTermId,
        string $name,
        ?int $parentId = null
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO taxonomy_map (slug, taxonomy, wc_term_id, name, parent_id, created_at)
            VALUES (?, ?, ?, ?, ?, datetime(\'now\'))
            ON CONFLICT(slug, taxonomy) DO UPDATE SET
                wc_term_id = excluded.wc_term_id,
                name = excluded.name,
                parent_id = excluded.parent_id
        ');
        $stmt->execute([$slug, $taxonomy, $wcTermId, $name, $parentId]);
    }

    /**
     * Get all taxonomy mappings, optionally filtered by taxonomy
     */
    public function getAllTaxonomies(?string $taxonomy = null): array
    {
        if ($taxonomy) {
            $stmt = $this->pdo->prepare('SELECT * FROM taxonomy_map WHERE taxonomy = ? ORDER BY slug');
            $stmt->execute([$taxonomy]);
        } else {
            $stmt = $this->pdo->query('SELECT * FROM taxonomy_map ORDER BY taxonomy, slug');
        }
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Sync Log
    // =========================================================================

    /**
     * Log a sync action
     */
    public function logSync(string $sku, string $action, ?array $details = null): void
    {
        $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $this->pdo->prepare('
            INSERT INTO sync_log (sku, action, details_json, synced_at)
            VALUES (?, ?, ?, datetime(\'now\'))
        ');
        $stmt->execute([$sku, $action, $json]);
    }

    /**
     * Get sync history for a SKU
     */
    public function getSyncHistory(string $sku, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM sync_log WHERE sku = ? ORDER BY synced_at DESC LIMIT ?
        ');
        $stmt->execute([$sku, $limit]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Delta Detection
    // =========================================================================

    /**
     * Get the stored signature for a product
     */
    public function getProductSignature(string $sku): ?string
    {
        $stmt = $this->pdo->prepare('SELECT signature FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        return $row ? $row['signature'] : null;
    }

    /**
     * Compare current products against stored signatures, return new + updated
     *
     * @param array $currentProducts Array of products with 'sku' and computed signature
     * @return array ['new' => [...], 'updated' => [...]]
     */
    public function getChangedProducts(array $currentProducts): array
    {
        $new = [];
        $updated = [];

        foreach ($currentProducts as $product) {
            $sku = $product['sku'] ?? '';
            if (!$sku) {
                continue;
            }

            $currentSig = $product['_signature'] ?? '';
            $storedSig = $this->getProductSignature($sku);

            if ($storedSig === null) {
                $new[] = $product;
            } elseif ($storedSig !== $currentSig) {
                $updated[] = $product;
            }
        }

        return ['new' => $new, 'updated' => $updated];
    }

    /**
     * Find products in DB that are no longer in the current feed
     *
     * @param array $currentSkus SKUs present in the current feed
     * @param string $source Source filter
     * @return array SKUs that exist in DB but not in current feed
     */
    public function getRemovedProducts(array $currentSkus, string $source): array
    {
        $allSkus = $this->getAllSkusBySource($source);
        return array_values(array_diff($allSkus, $currentSkus));
    }

    // =========================================================================
    // Statistics (for bin/db-status)
    // =========================================================================

    /**
     * Get product count grouped by source
     */
    public function getProductCountBySource(): array
    {
        $stmt = $this->pdo->query('
            SELECT source, COUNT(*) as count FROM products GROUP BY source ORDER BY count DESC
        ');
        return $stmt->fetchAll();
    }

    /**
     * Get total product count
     */
    public function getTotalProductCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    /**
     * Get total variation count
     */
    public function getTotalVariationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM variations')->fetchColumn();
    }

    /**
     * Get cache entry count and freshness
     */
    public function getCacheStats(): array
    {
        $stmt = $this->pdo->query('
            SELECT source,
                   COUNT(*) as count,
                   MIN(fetched_at) as oldest,
                   MAX(fetched_at) as newest
            FROM api_cache
            GROUP BY source
        ');
        return $stmt->fetchAll();
    }

    /**
     * Get media map count
     */
    public function getMediaCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM media_map')->fetchColumn();
    }

    /**
     * Get taxonomy map count
     */
    public function getTaxonomyCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM taxonomy_map')->fetchColumn();
    }

    /**
     * Get last sync timestamps
     */
    public function getLastSyncInfo(): array
    {
        $stmt = $this->pdo->query('
            SELECT action,
                   COUNT(*) as count,
                   MAX(synced_at) as last_sync
            FROM sync_log
            GROUP BY action
        ');
        return $stmt->fetchAll();
    }

    /**
     * Get products that haven't been synced
     */
    public function getUnsyncedCount(): int
    {
        return (int) $this->pdo->query('
            SELECT COUNT(*) FROM products WHERE synced_at IS NULL AND wc_payload_json IS NOT NULL
        ')->fetchColumn();
    }

    /**
     * Get database file size in bytes
     */
    public function getDatabaseSize(): int
    {
        return file_exists($this->dbPath) ? filesize($this->dbPath) : 0;
    }
}

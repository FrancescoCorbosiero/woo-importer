<?php

declare(strict_types=1);

namespace WooImporter\Services;

use WooImporter\Database\Database;
use WooImporter\Models\Product;
use WooImporter\Models\ProductVariation;
use WooImporter\Models\SyncLog;
use Monolog\Logger;

/**
 * FeedSyncService - Handles Feed → Database synchronization
 *
 * Fetches products from Golden Sneakers API and syncs to the database.
 * The database is the source of truth.
 */
class FeedSyncService
{
    private Database $db;
    private Product $productModel;
    private ProductVariation $variationModel;
    private SyncLog $syncLog;
    private Logger $logger;
    private array $config;

    // Stats
    private array $stats = [
        'products_new' => 0,
        'products_updated' => 0,
        'products_removed' => 0,
        'products_unchanged' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'variations_deactivated' => 0,
    ];

    public function __construct(array $config, Logger $logger, ?Database $db = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db ?? Database::getInstance();
        $this->productModel = new Product($this->db);
        $this->variationModel = new ProductVariation($this->db);
        $this->syncLog = new SyncLog($this->db);
    }

    /**
     * Run the feed sync process
     */
    public function sync(bool $dryRun = false): array
    {
        $this->logger->info('Starting Feed → Database sync');
        $startTime = microtime(true);

        try {
            // Fetch feed from API
            $this->logger->info('Fetching feed from API...');
            $feedProducts = $this->fetchFeedFromAPI();

            if ($feedProducts === null) {
                throw new \RuntimeException('Failed to fetch feed from API');
            }

            $this->logger->info('   Received ' . count($feedProducts) . ' products from feed');

            // Get existing products from database
            $existingProducts = $this->productModel->getAllIndexedBySku();
            $this->logger->info('   Found ' . count($existingProducts) . ' products in database');

            // Track which SKUs we've seen in the feed
            $feedSkus = [];

            // Process each product from feed
            if (!$dryRun) {
                $this->db->beginTransaction();
            }

            try {
                foreach ($feedProducts as $feedProduct) {
                    $sku = $feedProduct['sku'] ?? null;
                    if (!$sku) {
                        continue;
                    }

                    $feedSkus[] = $sku;
                    $this->processProduct($feedProduct, $existingProducts[$sku] ?? null, $dryRun);
                }

                // Handle removed products (in DB but not in feed)
                $this->handleRemovedProducts($feedSkus, $existingProducts, $dryRun);

                if (!$dryRun) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if (!$dryRun) {
                    $this->db->rollback();
                }
                throw $e;
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->logger->info("Feed sync completed in {$duration}s");
            $this->printStats();

            return [
                'success' => true,
                'stats' => $this->stats,
                'duration' => $duration,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('Feed sync failed: ' . $e->getMessage());
            $this->syncLog->logError(
                SyncLog::TYPE_FEED_TO_DB,
                SyncLog::ENTITY_BATCH,
                null,
                $e->getMessage()
            );
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats,
            ];
        }
    }

    /**
     * Process a single product from the feed
     */
    private function processProduct(array $feedProduct, ?array $existingProduct, bool $dryRun): void
    {
        $sku = $feedProduct['sku'];
        $signature = $this->productModel->calculateSignature($feedProduct);

        if ($existingProduct === null) {
            // New product
            $this->stats['products_new']++;
            $this->logger->debug("   + NEW: {$sku}");

            if (!$dryRun) {
                $productId = $this->createProduct($feedProduct, $signature);
                $this->processVariations($productId, $sku, $feedProduct['sizes'] ?? [], $dryRun);

                $this->syncLog->logFeedSync(
                    SyncLog::ACTION_CREATE,
                    $productId,
                    $sku,
                    ['name' => $feedProduct['name'], 'brand' => $feedProduct['brand_name'] ?? null],
                    'Product created from feed'
                );
            }
        } elseif ($existingProduct['feed_signature'] !== $signature) {
            // Updated product
            $this->stats['products_updated']++;
            $this->logger->debug("   ~ UPDATED: {$sku}");

            if (!$dryRun) {
                $productId = (int) $existingProduct['id'];
                $this->updateProduct($productId, $feedProduct, $signature);
                $this->processVariations($productId, $sku, $feedProduct['sizes'] ?? [], $dryRun);

                $this->syncLog->logFeedSync(
                    SyncLog::ACTION_UPDATE,
                    $productId,
                    $sku,
                    ['signature_changed' => true],
                    'Product updated from feed'
                );
            }
        } else {
            // Unchanged
            $this->stats['products_unchanged']++;
        }
    }

    /**
     * Create a new product in the database
     */
    private function createProduct(array $feedProduct, string $signature): int
    {
        return $this->productModel->create([
            'sku' => $feedProduct['sku'],
            'name' => $feedProduct['name'],
            'brand_name' => $feedProduct['brand_name'] ?? null,
            'image_url' => $feedProduct['image_full_url'] ?? null,
            'size_mapper_name' => $feedProduct['size_mapper_name'] ?? null,
            'feed_id' => $feedProduct['id'] ?? null,
            'feed_signature' => $signature,
            'source' => 'feed',
            'status' => 'active',
            'last_feed_sync' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update an existing product in the database
     */
    private function updateProduct(int $productId, array $feedProduct, string $signature): void
    {
        $this->productModel->update($productId, [
            'name' => $feedProduct['name'],
            'brand_name' => $feedProduct['brand_name'] ?? null,
            'image_url' => $feedProduct['image_full_url'] ?? null,
            'size_mapper_name' => $feedProduct['size_mapper_name'] ?? null,
            'feed_signature' => $signature,
            'status' => 'active',
            'last_feed_sync' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Process variations for a product
     */
    private function processVariations(int $productId, string $parentSku, array $sizes, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $priceCalculator = function (float $offerPrice): float {
            return $this->calculateRetailPrice($offerPrice);
        };

        $stats = $this->variationModel->syncFromFeed($productId, $parentSku, $sizes, $priceCalculator);

        $this->stats['variations_created'] += $stats['created'];
        $this->stats['variations_updated'] += $stats['updated'];
        $this->stats['variations_deactivated'] += $stats['deactivated'];
    }

    /**
     * Handle products that were removed from the feed
     */
    private function handleRemovedProducts(array $feedSkus, array $existingProducts, bool $dryRun): void
    {
        foreach ($existingProducts as $sku => $product) {
            if (!in_array($sku, $feedSkus) && $product['source'] === 'feed') {
                $this->stats['products_removed']++;
                $this->logger->debug("   - REMOVED: {$sku}");

                if (!$dryRun) {
                    $productId = (int) $product['id'];

                    // Set product to inactive and zero out all stock
                    $this->productModel->update($productId, [
                        'status' => 'inactive',
                        'last_feed_sync' => date('Y-m-d H:i:s'),
                    ]);
                    $this->variationModel->markOutOfStock($productId);

                    $this->syncLog->logFeedSync(
                        SyncLog::ACTION_DELETE,
                        $productId,
                        $sku,
                        ['reason' => 'not_in_feed'],
                        'Product removed from feed - marked inactive and out of stock'
                    );
                }
            }
        }
    }

    /**
     * Calculate retail price from offer price
     */
    private function calculateRetailPrice(float $offerPrice): float
    {
        $markup = $this->config['import']['markup_percentage'] ?? 25;
        $vat = $this->config['import']['vat_percentage'] ?? 22;
        $roundingType = $this->config['import']['rounding_type'] ?? 'whole';

        // Base price with markup
        $basePrice = $offerPrice * (1 + $markup / 100);

        // Add VAT
        $priceWithVat = $basePrice * (1 + $vat / 100);

        // Apply rounding
        switch ($roundingType) {
            case 'half':
                return round($priceWithVat * 2) / 2;
            case 'none':
                return round($priceWithVat, 2);
            case 'whole':
            default:
                return round($priceWithVat);
        }
    }

    /**
     * Fetch feed from Golden Sneakers API
     */
    private function fetchFeedFromAPI(): ?array
    {
        $params = http_build_query($this->config['api']['params']);
        $url = $this->config['api']['base_url'] . '?' . $params;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api']['bearer_token'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->logger->error('CURL Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error("API returned HTTP {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Print sync statistics
     */
    private function printStats(): void
    {
        $this->logger->info('');
        $this->logger->info('Feed Sync Summary:');
        $this->logger->info("   Products - New: {$this->stats['products_new']}, " .
            "Updated: {$this->stats['products_updated']}, " .
            "Removed: {$this->stats['products_removed']}, " .
            "Unchanged: {$this->stats['products_unchanged']}");
        $this->logger->info("   Variations - Created: {$this->stats['variations_created']}, " .
            "Updated: {$this->stats['variations_updated']}, " .
            "Deactivated: {$this->stats['variations_deactivated']}");
    }

    /**
     * Get current statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get products that need to be synced to WooCommerce
     */
    public function getProductsPendingWooSync(?int $limit = null): array
    {
        return $this->productModel->getPendingWooSync($limit);
    }
}

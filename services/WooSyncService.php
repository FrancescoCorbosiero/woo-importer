<?php

declare(strict_types=1);

namespace WooImporter\Services;

use Automattic\WooCommerce\Client;
use WooImporter\Database\Database;
use WooImporter\Models\Product;
use WooImporter\Models\ProductVariation;
use WooImporter\Models\WcProductMap;
use WooImporter\Models\WcVariationMap;
use WooImporter\Models\SyncLog;
use Monolog\Logger;

/**
 * WooSyncService - Handles Database → WooCommerce synchronization
 *
 * Reads products from the database (source of truth) and syncs to WooCommerce.
 */
class WooSyncService
{
    private Database $db;
    private Product $productModel;
    private ProductVariation $variationModel;
    private WcProductMap $productMap;
    private WcVariationMap $variationMap;
    private SyncLog $syncLog;
    private Logger $logger;
    private array $config;
    private Client $wcClient;

    // Caches
    private array $categoryCache = [];
    private array $brandCategoryCache = [];
    private array $imageMap = [];

    // Stats
    private array $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'products_skipped' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
    ];

    private int $batchSize = 100;

    public function __construct(array $config, Logger $logger, ?Database $db = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db ?? Database::getInstance();

        $this->productModel = new Product($this->db);
        $this->variationModel = new ProductVariation($this->db);
        $this->productMap = new WcProductMap($this->db);
        $this->variationMap = new WcVariationMap($this->db);
        $this->syncLog = new SyncLog($this->db);

        $this->setupWooCommerceClient();
        $this->loadImageMap();
    }

    /**
     * Setup WooCommerce REST API client
     */
    private function setupWooCommerceClient(): void
    {
        $this->wcClient = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'],
                'timeout' => 120,
            ]
        );
    }

    /**
     * Load image map from JSON file
     */
    private function loadImageMap(): void
    {
        $mapFile = dirname(__DIR__) . '/image-map.json';
        if (file_exists($mapFile)) {
            $this->imageMap = json_decode(file_get_contents($mapFile), true) ?: [];
            $this->logger->debug('Loaded image map with ' . count($this->imageMap) . ' images');
        }
    }

    /**
     * Run the sync process
     */
    public function sync(bool $dryRun = false, ?int $limit = null): array
    {
        $this->logger->info('Starting Database → WooCommerce sync');
        $startTime = microtime(true);

        try {
            // Get products pending sync
            $products = $this->productModel->getPendingWooSync($limit);
            $this->logger->info('   Found ' . count($products) . ' products to sync');

            if (empty($products)) {
                $this->logger->info('   No products need syncing');
                return [
                    'success' => true,
                    'stats' => $this->stats,
                    'duration' => 0,
                ];
            }

            // Process in batches
            $batches = array_chunk($products, $this->batchSize);
            $totalBatches = count($batches);

            foreach ($batches as $batchIndex => $batch) {
                $batchNum = $batchIndex + 1;
                $this->logger->info("   Processing batch {$batchNum}/{$totalBatches}...");

                $this->processBatch($batch, $dryRun);
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->logger->info("WooCommerce sync completed in {$duration}s");
            $this->printStats();

            return [
                'success' => true,
                'stats' => $this->stats,
                'duration' => $duration,
            ];

        } catch (\Throwable $e) {
            $this->logger->error('WooCommerce sync failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats,
            ];
        }
    }

    /**
     * Process a batch of products
     */
    private function processBatch(array $products, bool $dryRun): void
    {
        $toCreate = [];
        $toUpdate = [];

        foreach ($products as $product) {
            $fullProduct = $this->productModel->getFullProduct((int) $product['id']);
            if ($fullProduct === null) {
                continue;
            }

            $payload = $this->buildProductPayload($fullProduct);

            if ($product['sync_action'] === 'new' || $fullProduct['wc_map'] === null) {
                $toCreate[] = ['product' => $fullProduct, 'payload' => $payload];
            } else {
                $payload['id'] = $fullProduct['wc_map']['wc_product_id'];
                $toUpdate[] = ['product' => $fullProduct, 'payload' => $payload];
            }
        }

        if ($dryRun) {
            $this->logger->info("      [DRY RUN] Would create " . count($toCreate) . " products");
            $this->logger->info("      [DRY RUN] Would update " . count($toUpdate) . " products");
            $this->stats['products_created'] += count($toCreate);
            $this->stats['products_updated'] += count($toUpdate);
            return;
        }

        // Execute batch operations
        if (!empty($toCreate) || !empty($toUpdate)) {
            $this->executeBatch($toCreate, $toUpdate);
        }
    }

    /**
     * Execute batch API calls
     */
    private function executeBatch(array $toCreate, array $toUpdate): void
    {
        $batchPayload = [
            'create' => array_column($toCreate, 'payload'),
            'update' => array_column($toUpdate, 'payload'),
        ];

        if (empty($batchPayload['create'])) {
            unset($batchPayload['create']);
        }
        if (empty($batchPayload['update'])) {
            unset($batchPayload['update']);
        }

        if (empty($batchPayload)) {
            return;
        }

        try {
            $response = $this->wcClient->post('products/batch', $batchPayload);
            $this->stats['batch_requests']++;

            // Process created products
            if (!empty($response->create)) {
                foreach ($response->create as $index => $wcProduct) {
                    if (isset($wcProduct->id)) {
                        $localProduct = $toCreate[$index]['product'];
                        $this->handleCreatedProduct($localProduct, $wcProduct);
                        $this->stats['products_created']++;
                    } else {
                        $this->stats['errors']++;
                        $this->logger->error("Failed to create product: " . json_encode($wcProduct));
                    }
                }
            }

            // Process updated products
            if (!empty($response->update)) {
                foreach ($response->update as $index => $wcProduct) {
                    if (isset($wcProduct->id)) {
                        $localProduct = $toUpdate[$index]['product'];
                        $this->handleUpdatedProduct($localProduct, $wcProduct);
                        $this->stats['products_updated']++;
                    } else {
                        $this->stats['errors']++;
                    }
                }
            }

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error('Batch API error: ' . $e->getMessage());
        }
    }

    /**
     * Handle a successfully created product
     */
    private function handleCreatedProduct(array $localProduct, object $wcProduct): void
    {
        // Create mapping
        $this->productMap->createMapping(
            (int) $localProduct['id'],
            (int) $wcProduct->id,
            'variable'
        );

        // Sync variations
        $this->syncVariations($localProduct, (int) $wcProduct->id);

        // Mark as synced
        $this->productModel->markWooSynced((int) $localProduct['id']);

        // Log
        $this->syncLog->logWooSync(
            SyncLog::ACTION_CREATE,
            (int) $localProduct['id'],
            (int) $wcProduct->id,
            $localProduct['sku'],
            null,
            'Product created in WooCommerce'
        );
    }

    /**
     * Handle a successfully updated product
     */
    private function handleUpdatedProduct(array $localProduct, object $wcProduct): void
    {
        // Sync variations
        $this->syncVariations($localProduct, (int) $wcProduct->id);

        // Mark as synced
        $this->productModel->markWooSynced((int) $localProduct['id']);

        // Log
        $this->syncLog->logWooSync(
            SyncLog::ACTION_UPDATE,
            (int) $localProduct['id'],
            (int) $wcProduct->id,
            $localProduct['sku'],
            null,
            'Product updated in WooCommerce'
        );
    }

    /**
     * Sync variations for a product
     */
    private function syncVariations(array $localProduct, int $wcProductId): void
    {
        $variations = $localProduct['variations'] ?? [];
        if (empty($variations)) {
            return;
        }

        // Fetch existing WC variations
        $existingWcVariations = $this->fetchExistingVariations($wcProductId);

        $toCreate = [];
        $toUpdate = [];

        foreach ($variations as $variation) {
            $payload = $this->buildVariationPayload($variation, $localProduct['sku']);

            if (isset($variation['wc_map']) && isset($existingWcVariations[$variation['sku']])) {
                // Update existing
                $payload['id'] = $existingWcVariations[$variation['sku']]['id'];
                $toUpdate[] = ['variation' => $variation, 'payload' => $payload];
            } elseif (isset($existingWcVariations[$variation['sku']])) {
                // Exists in WC but not mapped - update and create mapping
                $payload['id'] = $existingWcVariations[$variation['sku']]['id'];
                $toUpdate[] = ['variation' => $variation, 'payload' => $payload, 'create_mapping' => true];
            } else {
                // Create new
                $toCreate[] = ['variation' => $variation, 'payload' => $payload];
            }
        }

        // Execute variation batch
        if (!empty($toCreate) || !empty($toUpdate)) {
            $this->executeVariationBatch($wcProductId, $toCreate, $toUpdate);
        }
    }

    /**
     * Execute variation batch API calls
     */
    private function executeVariationBatch(int $wcProductId, array $toCreate, array $toUpdate): void
    {
        $batchPayload = [
            'create' => array_column($toCreate, 'payload'),
            'update' => array_column($toUpdate, 'payload'),
        ];

        if (empty($batchPayload['create'])) {
            unset($batchPayload['create']);
        }
        if (empty($batchPayload['update'])) {
            unset($batchPayload['update']);
        }

        if (empty($batchPayload)) {
            return;
        }

        try {
            $response = $this->wcClient->post("products/{$wcProductId}/variations/batch", $batchPayload);

            // Process created variations
            if (!empty($response->create)) {
                foreach ($response->create as $index => $wcVariation) {
                    if (isset($wcVariation->id)) {
                        $localVariation = $toCreate[$index]['variation'];
                        $this->variationMap->createMapping(
                            (int) $localVariation['id'],
                            (int) $wcVariation->id,
                            $wcProductId
                        );
                        $this->stats['variations_created']++;
                    }
                }
            }

            // Process updated variations
            if (!empty($response->update)) {
                foreach ($response->update as $index => $wcVariation) {
                    if (isset($wcVariation->id)) {
                        $localVariation = $toUpdate[$index]['variation'];
                        // Create mapping if needed
                        if (!empty($toUpdate[$index]['create_mapping'])) {
                            $this->variationMap->createMapping(
                                (int) $localVariation['id'],
                                (int) $wcVariation->id,
                                $wcProductId
                            );
                        }
                        $this->stats['variations_updated']++;
                    }
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("Variation batch error for product {$wcProductId}: " . $e->getMessage());
        }
    }

    /**
     * Fetch existing variations from WooCommerce
     */
    private function fetchExistingVariations(int $wcProductId): array
    {
        $existing = [];
        $page = 1;
        $perPage = 100;

        do {
            try {
                $variations = $this->wcClient->get("products/{$wcProductId}/variations", [
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

                foreach ($variations as $variation) {
                    if (!empty($variation->sku)) {
                        $existing[$variation->sku] = ['id' => $variation->id];
                    }
                }
                $page++;
            } catch (\Exception $e) {
                break;
            }
        } while (count($variations) === $perPage);

        return $existing;
    }

    /**
     * Build product payload for WooCommerce API
     */
    private function buildProductPayload(array $product): array
    {
        $sku = $product['sku'];
        $name = $product['name'];
        $brand = $product['brand_name'] ?? null;
        $variations = $product['variations'] ?? [];

        // Get categories
        $categoryId = $this->ensureCategoryExists();
        $brandCategoryId = $this->ensureBrandCategoryExists($brand);

        $categories = [['id' => $categoryId]];
        if ($brandCategoryId) {
            $categories[] = ['id' => $brandCategoryId];
        }

        // Template data for descriptions
        $storeName = $this->config['store']['name'] ?? 'ResellPiacenza';
        $templateData = [
            'product_name' => $name,
            'brand_name' => $brand ?? '',
            'sku' => $sku,
            'store_name' => $storeName,
        ];

        $shortDesc = $this->processTemplate(
            $this->config['locale']['default_short_description'] ?? '',
            $templateData
        );
        $longDesc = $this->processTemplate(
            $this->config['locale']['default_long_description'] ?? '',
            $templateData
        );

        // Build payload
        $payload = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => $product['status'] === 'active' ? 'publish' : 'draft',
            'catalog_visibility' => 'visible',
            'short_description' => $shortDesc,
            'description' => $longDesc,
            'categories' => $categories,
            'manage_stock' => false,
            'attributes' => $this->buildAttributes($variations, $brand),
        ];

        // Add image if available
        $mediaId = $this->imageMap[$sku]['media_id'] ?? null;
        if ($mediaId) {
            $payload['images'] = [['id' => $mediaId]];
        }

        return $payload;
    }

    /**
     * Build variation payload for WooCommerce API
     */
    private function buildVariationPayload(array $variation, string $parentSku): array
    {
        $sizeEu = $variation['size_eu'] ?? $variation['size_us'] ?? 'OS';

        return [
            'sku' => $variation['sku'],
            'regular_price' => (string) $variation['retail_price'],
            'stock_quantity' => (int) $variation['stock_quantity'],
            'stock_status' => $variation['stock_quantity'] > 0 ? 'instock' : 'outofstock',
            'manage_stock' => true,
            'attributes' => [
                [
                    'id' => $this->getSizeAttributeId(),
                    'option' => $sizeEu,
                ],
            ],
        ];
    }

    /**
     * Build attributes array for variable product
     */
    private function buildAttributes(array $variations, ?string $brand): array
    {
        $sizeAttrName = $this->config['locale']['size_attribute_name'] ?? 'Taglia';
        $sizeAttrSlug = 'pa_' . ($this->config['locale']['size_attribute_slug'] ?? 'taglia');

        $sizes = [];
        foreach ($variations as $v) {
            $sizes[] = $v['size_eu'] ?? $v['size_us'] ?? 'OS';
        }
        $sizes = array_unique($sizes);
        sort($sizes, SORT_NATURAL);

        $attributes = [
            [
                'name' => $sizeAttrSlug,
                'position' => 0,
                'visible' => true,
                'variation' => true,
                'options' => $sizes,
            ],
        ];

        // Add brand as taxonomy attribute
        if ($brand) {
            $brandAttrSlug = 'pa_' . ($this->config['locale']['brand_attribute_slug'] ?? 'marca');
            $attributes[] = [
                'name' => $brandAttrSlug,
                'position' => 1,
                'visible' => true,
                'variation' => false,
                'options' => [$brand],
            ];
        }

        return $attributes;
    }

    /**
     * Get size attribute ID (cached)
     */
    private function getSizeAttributeId(): int
    {
        static $sizeAttrId = null;

        if ($sizeAttrId === null) {
            $slug = $this->config['locale']['size_attribute_slug'] ?? 'taglia';
            try {
                $attrs = $this->wcClient->get('products/attributes');
                foreach ($attrs as $attr) {
                    if ($attr->slug === $slug) {
                        $sizeAttrId = $attr->id;
                        break;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning("Could not fetch size attribute ID: " . $e->getMessage());
            }
            $sizeAttrId = $sizeAttrId ?? 0;
        }

        return $sizeAttrId;
    }

    /**
     * Ensure category exists in WooCommerce
     */
    private function ensureCategoryExists(?string $name = null, ?string $slug = null): int
    {
        $categoryName = $name ?? $this->config['import']['category_name'] ?? 'Sneakers';
        $categorySlug = $slug ?? $this->sanitizeTitle($categoryName);

        if (isset($this->categoryCache[$categorySlug])) {
            return $this->categoryCache[$categorySlug];
        }

        try {
            $categories = $this->wcClient->get('products/categories', ['slug' => $categorySlug]);

            if (!empty($categories)) {
                $this->categoryCache[$categorySlug] = $categories[0]->id;
                return $categories[0]->id;
            }

            $result = $this->wcClient->post('products/categories', [
                'name' => $categoryName,
                'slug' => $categorySlug,
            ]);

            $this->categoryCache[$categorySlug] = $result->id;
            return $result->id;

        } catch (\Exception $e) {
            $this->logger->error("Category error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Ensure brand category exists
     */
    private function ensureBrandCategoryExists(?string $brandName): ?int
    {
        if (empty($brandName)) {
            return null;
        }

        $slugSuffix = $this->config['brand_categories']['slug_suffix'] ?? '-originali';
        $brandSlug = $this->sanitizeTitle($brandName) . $slugSuffix;

        if (isset($this->brandCategoryCache[$brandSlug])) {
            return $this->brandCategoryCache[$brandSlug];
        }

        try {
            $categories = $this->wcClient->get('products/categories', ['slug' => $brandSlug]);

            if (!empty($categories)) {
                $this->brandCategoryCache[$brandSlug] = $categories[0]->id;
                return $categories[0]->id;
            }

            $result = $this->wcClient->post('products/categories', [
                'name' => $brandName,
                'slug' => $brandSlug,
            ]);

            $this->brandCategoryCache[$brandSlug] = $result->id;
            return $result->id;

        } catch (\Exception $e) {
            $this->logger->error("Brand category error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Process template string with placeholders
     */
    private function processTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }

    /**
     * Sanitize string for use as slug
     */
    private function sanitizeTitle(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Print sync statistics
     */
    private function printStats(): void
    {
        $this->logger->info('');
        $this->logger->info('WooCommerce Sync Summary:');
        $this->logger->info("   Products - Created: {$this->stats['products_created']}, " .
            "Updated: {$this->stats['products_updated']}, " .
            "Errors: {$this->stats['errors']}");
        $this->logger->info("   Variations - Created: {$this->stats['variations_created']}, " .
            "Updated: {$this->stats['variations_updated']}");
        $this->logger->info("   Batch Requests: {$this->stats['batch_requests']}");
    }

    /**
     * Get current statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}

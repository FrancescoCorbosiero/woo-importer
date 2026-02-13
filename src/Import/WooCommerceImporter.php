<?php

namespace ResellPiacenza\Import;

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * WooCommerce Direct Importer
 *
 * Accepts WooCommerce REST API formatted JSON and pushes directly to WooCommerce.
 * NO transformation - expects data already in WC format.
 *
 * Input format (each product):
 * {
 *   "name": "Product Name",
 *   "sku": "SKU-123",
 *   "type": "variable",
 *   "status": "publish",
 *   "short_description": "...",
 *   "description": "...",
 *   "categories": [{"id": 123}],
 *   "brands": [{"id": 456}],
 *   "images": [{"id": 789}],
 *   "attributes": [...],
 *   "_variations": [
 *     {
 *       "sku": "SKU-123-36",
 *       "regular_price": "99.00",
 *       "stock_quantity": 5,
 *       "stock_status": "instock",
 *       "attributes": [{"id": 1, "option": "36"}]
 *     }
 *   ]
 * }
 *
 * @package ResellPiacenza\Import
 */
class WooCommerceImporter
{
    private $config;
    private $wc_client;
    private $logger;

    // Options
    private $dry_run = false;
    private $limit = null;
    private $batch_size = 100;

    // Category slug → ID cache
    private $category_cache = [];

    // Image map for tracking sideloaded images
    private $image_map = [];
    private $image_map_dirty = false;

    // Stats
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
        'images_tracked' => 0,
        'stale_images_cleaned' => 0,
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->batch_size = min($config['import']['batch_size'] ?? 100, 100);

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadImageMap();
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = LoggerFactory::create('WooImporter', [
            'file' => Config::projectRoot() . '/logs/import-wc.log',
        ]);
    }

    /**
     * Setup WooCommerce client
     *
     * Validates the WC URL before constructing the client to catch
     * common misconfigurations (empty URL, trailing colon, bad port)
     * that would otherwise surface as cryptic cURL errors.
     *
     * @throws \RuntimeException If WC_URL is empty or malformed
     */
    private function setupWooCommerceClient(): void
    {
        $url = trim($this->config['woocommerce']['url'] ?? '');

        if (empty($url)) {
            throw new \RuntimeException(
                'WC_URL is not configured. Set it in your .env file (e.g. WC_URL=https://your-store.com).'
            );
        }

        // Strip trailing colon (common typo: "https://store.com:")
        // which causes cURL "Port number was not a decimal number" error
        $url = rtrim($url, ':');

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \RuntimeException(
                "WC_URL is malformed: '{$url}'. Expected format: https://your-store.com"
            );
        }

        $this->wc_client = new Client(
            $url,
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 120]
        );
    }

    /**
     * Load image map for tracking
     */
    private function loadImageMap(): void
    {
        $map_file = Config::projectRoot() . '/image-map.json';
        if (file_exists($map_file)) {
            $this->image_map = json_decode(file_get_contents($map_file), true) ?: [];
        }
    }

    /**
     * Save image map if modified
     */
    private function saveImageMap(): void
    {
        if (!$this->image_map_dirty) {
            return;
        }

        $map_file = Config::projectRoot() . '/image-map.json';
        file_put_contents(
            $map_file,
            json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->logger->info("  Image map updated: {$this->stats['images_tracked']} tracked, {$this->stats['stale_images_cleaned']} stale cleaned");
    }

    /**
     * Fetch existing products from WooCommerce
     *
     * @return array SKU => product data
     */
    private function fetchExistingProducts(): array
    {
        $existing = [];
        $page = 1;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($products as $product) {
                    if (!empty($product->sku)) {
                        $existing[$product->sku] = ['id' => $product->id];
                    }
                }
                $page++;
            } catch (\Exception $e) {
                $this->logger->error("Error fetching products page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === 100);

        return $existing;
    }

    /**
     * Fetch existing variations for a product
     *
     * @param int $product_id Product ID
     * @return array SKU => variation data
     */
    private function fetchExistingVariations(int $product_id): array
    {
        $existing = [];
        $page = 1;

        do {
            try {
                $variations = $this->wc_client->get("products/{$product_id}/variations", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($variations as $var) {
                    if (!empty($var->sku)) {
                        $existing[$var->sku] = ['id' => $var->id];
                    }
                }
                $page++;
            } catch (\Exception $e) {
                break;
            }
        } while (count($variations) === 100);

        return $existing;
    }

    /**
     * Resolve any slug-based categories to IDs via WC API
     *
     * WooCommerce REST API requires category IDs — slug-based assignment
     * is silently ignored. This resolves slugs to IDs before batch send.
     * If a category doesn't exist, it is auto-created so that clean
     * environments work without requiring a separate prepare-taxonomies step.
     *
     * @param array &$payload Product payload (modified in place)
     */
    private function resolveCategoryIds(array &$payload): void
    {
        if (empty($payload['categories'])) {
            return;
        }

        foreach ($payload['categories'] as $idx => &$cat) {
            if (!empty($cat['id'])) {
                continue;
            }

            $slug = $cat['slug'] ?? null;
            if (!$slug) {
                continue;
            }

            $id = $this->lookupOrCreateCategory($slug);
            if ($id) {
                $cat = ['id' => $id];
            } else {
                $this->logger->warning("  Category '{$slug}' could not be found or created — product will be uncategorized");
                unset($payload['categories'][$idx]);
            }
        }

        $payload['categories'] = array_values($payload['categories']);
    }

    /**
     * Look up a WooCommerce category by slug, creating it if it doesn't exist
     *
     * In a clean WooCommerce environment (no taxonomies pre-created),
     * this ensures categories are created on-the-fly during import
     * rather than silently dropping products into "uncategorized".
     *
     * @param string $slug Category slug
     * @return int|null Category ID or null on failure
     */
    private function lookupOrCreateCategory(string $slug): ?int
    {
        if (array_key_exists($slug, $this->category_cache)) {
            return $this->category_cache[$slug];
        }

        // Try to find existing category
        try {
            $categories = $this->wc_client->get('products/categories', ['slug' => $slug]);

            if (!empty($categories)) {
                $id = $categories[0]->id;
                $this->category_cache[$slug] = $id;
                $this->logger->info("  Resolved category '{$slug}' → ID {$id}");
                return $id;
            }
        } catch (\Exception $e) {
            $this->logger->warning("  Category lookup failed for '{$slug}': " . $e->getMessage());
        }

        // Category doesn't exist — try to create it
        $name = $this->getCategoryNameForSlug($slug);
        try {
            $result = $this->wc_client->post('products/categories', [
                'name' => $name,
                'slug' => $slug,
            ]);

            if (!empty($result->id)) {
                $id = $result->id;
                $this->category_cache[$slug] = $id;
                $this->logger->info("  Auto-created category '{$name}' (slug: {$slug}) → ID {$id}");
                return $id;
            }
        } catch (\Exception $e) {
            // "term_exists" means the category was created between our lookup and create
            if (strpos($e->getMessage(), 'term_exists') !== false) {
                try {
                    $categories = $this->wc_client->get('products/categories', ['slug' => $slug]);
                    if (!empty($categories)) {
                        $id = $categories[0]->id;
                        $this->category_cache[$slug] = $id;
                        $this->logger->info("  Found existing category '{$slug}' → ID {$id}");
                        return $id;
                    }
                } catch (\Exception $e2) {
                    // Fall through to null
                }
            }

            $this->logger->warning("  Category auto-creation failed for '{$slug}': " . $e->getMessage());
        }

        $this->category_cache[$slug] = null;
        return null;
    }

    /**
     * Resolve a human-readable category name for a given slug
     *
     * Checks the config categories map first, then falls back to
     * capitalizing the slug (e.g. 'sneakers' → 'Sneakers').
     *
     * @param string $slug Category slug
     * @return string Category name
     */
    private function getCategoryNameForSlug(string $slug): string
    {
        foreach ($this->config['categories'] ?? [] as $cat_config) {
            if (($cat_config['slug'] ?? '') === $slug) {
                return $cat_config['name'];
            }
        }

        return ucfirst(str_replace('-', ' ', $slug));
    }

    /**
     * Process products in batch
     *
     * @param array $wc_products WooCommerce-formatted products
     * @param array $existing_products Existing products
     * @return array Product map (SKU => [id, variations])
     */
    private function batchProcessProducts(array $wc_products, array $existing_products): array
    {
        $to_create = [];
        $to_update = [];
        $product_map = [];

        foreach ($wc_products as $product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            $variations = $product['_variations'] ?? [];

            // Clean internal keys before API call
            $api_payload = $product;
            unset($api_payload['_variations'], $api_payload['_sync_action'], $api_payload['_product_type']);

            // Sanitize image objects: only send WC-recognized fields
            if (!empty($api_payload['images'])) {
                $allowed_image_keys = ['id', 'src', 'name', 'alt', 'position'];
                $api_payload['images'] = array_map(function ($img) use ($allowed_image_keys) {
                    return array_intersect_key($img, array_flip($allowed_image_keys));
                }, $api_payload['images']);
            }

            // Resolve any slug-based categories to IDs (WC API requires IDs)
            $this->resolveCategoryIds($api_payload);

            if (isset($existing_products[$sku])) {
                $api_payload['id'] = $existing_products[$sku]['id'];
                $to_update[] = $api_payload;
                $product_map[$sku] = [
                    'id' => $existing_products[$sku]['id'],
                    'variations' => $variations,
                ];
            } else {
                $to_create[] = $api_payload;
                $product_map[$sku] = [
                    'id' => null,
                    'variations' => $variations,
                    'pending' => true,
                ];
            }
        }

        $this->logger->info("  To create: " . count($to_create) . ", to update: " . count($to_update));

        if (!empty($to_create)) {
            $this->executeBatch('create', $to_create, $product_map);
        }
        if (!empty($to_update)) {
            $this->executeBatch('update', $to_update, $product_map);
        }

        return $product_map;
    }

    /**
     * Execute batch operation
     *
     * @param string $operation 'create' or 'update'
     * @param array $items Product payloads
     * @param array &$product_map Reference to update with new IDs
     */
    private function executeBatch(string $operation, array $items, array &$product_map): void
    {
        $chunks = array_chunk($items, $this->batch_size);

        foreach ($chunks as $chunk_idx => $chunk) {
            if ($this->dry_run) {
                $this->logger->info("  [DRY RUN] Would {$operation} " . count($chunk) . " products");
                if ($operation === 'create') {
                    foreach ($chunk as $item) {
                        $sku = $item['sku'] ?? null;
                        if ($sku && isset($product_map[$sku])) {
                            $product_map[$sku]['id'] = 99990000 + $this->stats['products_created'];
                            unset($product_map[$sku]['pending']);
                            $this->stats['products_created']++;
                        }
                    }
                } else {
                    $this->stats['products_updated'] += count($chunk);
                }
                continue;
            }

            try {
                $result = $this->wc_client->post('products/batch', [$operation => $chunk]);
                $this->stats['batch_requests']++;

                foreach ($result->$operation ?? [] as $idx => $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                        $sku = $item->sku ?? $chunk[$idx]['sku'] ?? 'unknown';
                        $code = $item->error->code ?? '';
                        $msg = $item->error->message ?? 'Unknown';
                        $this->logger->error("  Error [{$sku}]: {$msg}" . ($code ? " [{$code}]" : ''));

                        // Clean stale image-map entries on invalid image ID errors
                        if ($code === 'woocommerce_product_invalid_image_id'
                            || strpos($msg, 'non valido') !== false
                            || strpos($msg, 'invalid') !== false
                        ) {
                            if ($sku !== 'unknown' && isset($this->image_map[$sku])) {
                                $stale_id = $this->image_map[$sku]['media_id'] ?? '?';
                                unset($this->image_map[$sku]);
                                $this->image_map_dirty = true;
                                $this->stats['stale_images_cleaned']++;
                                $this->logger->warning("  Removed stale image-map entry for {$sku} (media_id {$stale_id})");
                            }
                        }
                    } else {
                        if ($operation === 'create') {
                            $this->stats['products_created']++;
                            if (!empty($item->sku) && isset($product_map[$item->sku])) {
                                $product_map[$item->sku]['id'] = $item->id;
                                unset($product_map[$item->sku]['pending']);
                            }
                        } else {
                            $this->stats['products_updated']++;
                        }

                        // Track image IDs from successful responses
                        $this->trackImagesFromResponse($item);
                    }
                }

                $this->logger->info("  Batch " . ($chunk_idx + 1) . ": {$operation}d " . count($chunk));

            } catch (\Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  Batch failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Track image IDs from a successful WC batch response item
     *
     * Captures sideloaded image IDs so they can be reused on subsequent imports
     * instead of creating duplicates.
     */
    private function trackImagesFromResponse($item): void
    {
        $sku = $item->sku ?? '';
        if (empty($sku) || empty($item->images)) {
            return;
        }

        // Skip if already tracked with a non-sideload source
        if (isset($this->image_map[$sku]) && ($this->image_map[$sku]['source'] ?? '') !== 'wc_sideload') {
            return;
        }

        $images = $item->images;
        $primary = $images[0] ?? null;
        if (!$primary || empty($primary->id)) {
            return;
        }

        $entry = [
            'media_id' => $primary->id,
            'url' => $primary->src ?? '',
            'uploaded_at' => date('Y-m-d H:i:s'),
            'source' => 'wc_sideload',
        ];

        // Gallery IDs (images after the primary)
        $gallery_ids = [];
        for ($i = 1; $i < count($images); $i++) {
            if (!empty($images[$i]->id)) {
                $gallery_ids[] = $images[$i]->id;
            }
        }
        if (!empty($gallery_ids)) {
            $entry['gallery_ids'] = $gallery_ids;
        }

        $this->image_map[$sku] = $entry;
        $this->image_map_dirty = true;
        $this->stats['images_tracked']++;
    }

    /**
     * Process variations for a product
     *
     * @param int $product_id Product ID
     * @param array $variations Variations (WC format)
     */
    private function processVariations(int $product_id, array $variations): void
    {
        $existing = $this->fetchExistingVariations($product_id);

        $to_create = [];
        $to_update = [];

        foreach ($variations as $var) {
            $sku = $var['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            if (isset($existing[$sku])) {
                $var['id'] = $existing[$sku]['id'];
                $to_update[] = $var;
            } else {
                $to_create[] = $var;
            }
        }

        if (!empty($to_create)) {
            $this->executeVariationBatch($product_id, 'create', $to_create);
        }
        if (!empty($to_update)) {
            $this->executeVariationBatch($product_id, 'update', $to_update);
        }
    }

    /**
     * Execute variation batch
     *
     * @param int $product_id Product ID
     * @param string $operation 'create' or 'update'
     * @param array $items Variation payloads
     */
    private function executeVariationBatch(int $product_id, string $operation, array $items): void
    {
        $chunks = array_chunk($items, $this->batch_size);

        foreach ($chunks as $chunk) {
            if ($this->dry_run) {
                if ($operation === 'create') {
                    $this->stats['variations_created'] += count($chunk);
                } else {
                    $this->stats['variations_updated'] += count($chunk);
                }
                continue;
            }

            try {
                $result = $this->wc_client->post(
                    "products/{$product_id}/variations/batch",
                    [$operation => $chunk]
                );
                $this->stats['batch_requests']++;

                foreach ($result->$operation ?? [] as $idx => $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                        $var_sku = $item->sku ?? $chunk[$idx]['sku'] ?? 'unknown';
                        $this->logger->error("  Variation error [product:{$product_id} {$var_sku}]: " . ($item->error->message ?? 'Unknown'));
                    } else {
                        if ($operation === 'create') {
                            $this->stats['variations_created']++;
                        } else {
                            $this->stats['variations_updated']++;
                        }
                    }
                }

            } catch (\Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  Variation batch failed [product:{$product_id}]: " . $e->getMessage());
            }
        }
    }

    /**
     * Import from WooCommerce-formatted array
     *
     * @param array $wc_products Array of WC-formatted products
     * @return bool Success
     */
    public function import(array $wc_products): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  WooCommerce Direct Importer');
        $this->logger->info('========================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }

        try {
            // Apply limit
            if ($this->limit) {
                $wc_products = array_slice($wc_products, 0, $this->limit);
            }

            $this->logger->info("  Products to import: " . count($wc_products));

            if (empty($wc_products)) {
                $this->logger->warning('  No products to import');
                return true;
            }

            // Fetch existing
            $this->logger->info('');
            $this->logger->info('Fetching existing WooCommerce products...');
            $existing = $this->fetchExistingProducts();
            $this->logger->info("  Found " . count($existing) . " existing");

            // Batch products
            $this->logger->info('');
            $this->logger->info('Importing products...');
            $product_map = $this->batchProcessProducts($wc_products, $existing);

            // Variations
            $this->logger->info('');
            $this->logger->info('Processing variations...');

            $total = count($product_map);
            $current = 0;

            foreach ($product_map as $sku => $data) {
                $current++;

                if (empty($data['id']) || empty($data['variations'])) {
                    continue;
                }

                echo "\r  Processing: {$current}/{$total}          ";
                $this->processVariations($data['id'], $data['variations']);
            }
            echo "\n";

            // Save image map (tracked images + stale cleanup)
            $this->saveImageMap();

            $this->printSummary($start_time);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fatal error: ' . $e->getMessage());
            // Still save image map on failure to persist any cleanup
            $this->saveImageMap();
            return false;
        }
    }

    /**
     * Print summary
     */
    private function printSummary(float $start_time): void
    {
        $duration = round(microtime(true) - $start_time, 1);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  IMPORT SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("  Products Created:    {$this->stats['products_created']}");
        $this->logger->info("  Products Updated:    {$this->stats['products_updated']}");
        $this->logger->info("  Variations Created:  {$this->stats['variations_created']}");
        $this->logger->info("  Variations Updated:  {$this->stats['variations_updated']}");
        $this->logger->info("  Batch Requests:      {$this->stats['batch_requests']}");
        $this->logger->info("  Errors:              {$this->stats['errors']}");
        $this->logger->info("  Duration:            {$duration}s");
        $this->logger->info('========================================');
    }
}

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
    private $batch_size = 25;

    // Category slug → ID cache
    private $category_cache = [];

    // Brand slug → ID cache
    private $brand_cache = [];

    // Image map for tracking sideloaded images
    private $image_map = [];
    private $image_map_dirty = false;

    // Products that failed batch-create due to duplicate SKU — queued for retry as update
    private $duplicate_retry_queue = [];

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
        $this->batch_size = min($config['import']['batch_size'] ?? 25, 100);

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

        $timeout = (int) ($this->config['import']['api_timeout'] ?? 120);
        $this->wc_client = new Client(
            $url,
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => $timeout]
        );
    }

    /**
     * Load image map for tracking
     */
    private function loadImageMap(): void
    {
        $map_file = Config::imageMapFile();
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

        $map_file = Config::imageMapFile();
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
        $duplicates = [];
        $page = 1;
        $retries = 0;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($products as $product) {
                    if (!empty($product->sku)) {
                        if (isset($existing[$product->sku])) {
                            // Track duplicate SKUs for logging
                            $duplicates[$product->sku][] = $product->id;
                        } else {
                            $existing[$product->sku] = ['id' => $product->id];
                        }
                    }
                }
                $page++;
                $retries = 0;
            } catch (\Exception $e) {
                $retries++;
                if ($retries >= 3) {
                    $this->logger->error("Error fetching products page {$page} after 3 retries: " . $e->getMessage());
                    $retries = 0;
                    $page++; // Skip the failed page instead of stopping entirely
                } else {
                    $this->logger->warning("Retrying products page {$page} ({$retries}/3)...");
                    usleep(500000);
                    continue;
                }
            }
        } while (count($products ?? []) === 100);

        if (!empty($duplicates)) {
            $this->logger->warning("Found " . count($duplicates) . " duplicate SKUs in WooCommerce:");
            foreach ($duplicates as $sku => $ids) {
                $all_ids = array_merge([$existing[$sku]['id']], $ids);
                $this->logger->warning("  SKU {$sku}: product IDs " . implode(', ', $all_ids));
            }
        }

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
     * Resolve any slug/name-based brands to IDs via WC API
     *
     * Similar to resolveCategoryIds, but for the brands taxonomy.
     * Auto-creates brands that don't exist yet so that clean environments
     * work without requiring a separate prepare-taxonomies step.
     *
     * @param array &$payload Product payload (modified in place)
     */
    private function resolveBrandIds(array &$payload): void
    {
        if (empty($payload['brands'])) {
            return;
        }

        foreach ($payload['brands'] as $idx => &$brand) {
            if (!empty($brand['id'])) {
                continue;
            }

            $slug = $brand['slug'] ?? null;
            $name = $brand['name'] ?? null;
            if (!$slug && !$name) {
                continue;
            }

            $id = $this->lookupOrCreateBrand($slug ?? $this->sanitizeBrandSlug($name), $name);
            if ($id) {
                $brand = ['id' => $id];
            } else {
                $this->logger->warning("  Brand '{$name}' could not be found or created — product will have no brand");
                unset($payload['brands'][$idx]);
            }
        }

        $payload['brands'] = array_values($payload['brands']);
    }

    /**
     * Look up a WooCommerce brand by slug, creating it if it doesn't exist
     *
     * @param string $slug Brand slug
     * @param string|null $name Brand display name (for auto-creation)
     * @return int|null Brand ID or null on failure
     */
    private function lookupOrCreateBrand(string $slug, ?string $name = null): ?int
    {
        if (array_key_exists($slug, $this->brand_cache)) {
            return $this->brand_cache[$slug];
        }

        // Try to find existing brand
        try {
            $brands = $this->wc_client->get('products/brands', ['slug' => $slug]);

            if (!empty($brands)) {
                $id = $brands[0]->id;
                $this->brand_cache[$slug] = $id;
                $this->logger->info("  Resolved brand '{$slug}' → ID {$id}");
                return $id;
            }
        } catch (\Exception $e) {
            $this->logger->warning("  Brand lookup failed for '{$slug}': " . $e->getMessage());
        }

        // Brand doesn't exist — try to create it
        $display_name = $name ?? ucfirst(str_replace('-', ' ', $slug));
        try {
            $result = $this->wc_client->post('products/brands', [
                'name' => $display_name,
                'slug' => $slug,
            ]);

            if (!empty($result->id)) {
                $id = $result->id;
                $this->brand_cache[$slug] = $id;
                $this->logger->info("  Auto-created brand '{$display_name}' (slug: {$slug}) → ID {$id}");
                return $id;
            }
        } catch (\Exception $e) {
            // "term_exists" means the brand was created between our lookup and create
            if (strpos($e->getMessage(), 'term_exists') !== false) {
                try {
                    $brands = $this->wc_client->get('products/brands', ['slug' => $slug]);
                    if (!empty($brands)) {
                        $id = $brands[0]->id;
                        $this->brand_cache[$slug] = $id;
                        $this->logger->info("  Found existing brand '{$slug}' → ID {$id}");
                        return $id;
                    }
                } catch (\Exception $e2) {
                    // Fall through to null
                }
            }

            $this->logger->warning("  Brand auto-creation failed for '{$slug}': " . $e->getMessage());
        }

        $this->brand_cache[$slug] = null;
        return null;
    }

    /**
     * Retry products that failed batch-create due to duplicate SKU
     *
     * When fetchExistingProducts() misses some products (pagination gaps,
     * interrupted previous runs, race conditions), the batch create returns
     * product_invalid_sku errors. This method looks up the existing product
     * IDs by SKU and retries them as updates.
     *
     * @param array &$product_map Reference to update with found IDs
     */
    private function retryDuplicatesAsUpdate(array &$product_map): void
    {
        $items = $this->duplicate_retry_queue;
        $this->duplicate_retry_queue = [];

        $count = count($items);
        $this->logger->info("  Retrying {$count} duplicate-SKU products as updates...");

        $to_update = [];

        foreach ($items as $item) {
            $sku = $item['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            $product_id = $this->lookupProductIdBySku($sku);
            if ($product_id) {
                $item['id'] = $product_id;
                $to_update[] = $item;

                // Update product_map so variations get processed
                if (isset($product_map[$sku])) {
                    $product_map[$sku]['id'] = $product_id;
                    unset($product_map[$sku]['pending']);
                }
            } else {
                $this->stats['errors']++;
                $this->logger->error("  Retry failed: could not find existing product for SKU {$sku}");
            }
        }

        if (!empty($to_update)) {
            $this->logger->info("  Updating " . count($to_update) . " recovered products...");
            $this->executeBatch('update', $to_update, $product_map);
        }
    }

    /**
     * Look up a WooCommerce product ID by SKU
     *
     * @param string $sku Product SKU
     * @return int|null Product ID or null if not found
     */
    private function lookupProductIdBySku(string $sku): ?int
    {
        try {
            $results = $this->wc_client->get('products', [
                'sku' => $sku,
                'status' => 'any',
            ]);

            if (!empty($results) && !empty($results[0]->id)) {
                return (int) $results[0]->id;
            }
        } catch (\Exception $e) {
            $this->logger->warning("  SKU lookup failed for {$sku}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Sanitize a brand name into a URL slug
     *
     * @param string $name Brand name
     * @return string Slug
     */
    private function sanitizeBrandSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
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

            // Resolve any slug/name-based brands to IDs (auto-create if missing)
            $this->resolveBrandIds($api_payload);

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
            $this->duplicate_retry_queue = [];
            $this->executeBatch('create', $to_create, $product_map);

            // Retry duplicate-SKU failures as updates (products missed by fetchExistingProducts)
            if (!empty($this->duplicate_retry_queue)) {
                $this->retryDuplicatesAsUpdate($product_map);
            }
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

            $batch_label = (string) ($chunk_idx + 1);
            $this->executeBatchWithRetry($operation, $chunk, $product_map, $batch_label);
        }
    }

    /**
     * Execute a batch with retry logic — splits failed batches in half on timeout
     *
     * When a batch request times out, the batch is split in half and each
     * sub-batch is retried independently. Sub-batches are labeled hierarchically
     * (e.g., batch 5 becomes 5.1, 5.2, then 5.1.1, 5.1.2, etc.).
     *
     * @param string $operation 'create' or 'update'
     * @param array $chunk Product payloads for this batch
     * @param array &$product_map Reference to update with new IDs
     * @param string $batch_label Label for logging (e.g., "1", "1.1", "1.1.2")
     * @param int $retry_depth Current retry depth (max 3)
     */
    private function executeBatchWithRetry(
        string $operation,
        array $chunk,
        array &$product_map,
        string $batch_label = '1',
        int $retry_depth = 0
    ): void {
        $max_retries = 3;

        try {
            $result = $this->wc_client->post('products/batch', [$operation => $chunk]);
            $this->stats['batch_requests']++;

            foreach ($result->$operation ?? [] as $idx => $item) {
                if (isset($item->error)) {
                    $sku = $item->sku ?? $chunk[$idx]['sku'] ?? 'unknown';
                    $code = $item->error->code ?? '';
                    $msg = $item->error->message ?? 'Unknown';

                    // Duplicate SKU on create → queue for retry as update
                    $is_duplicate_sku = $operation === 'create' && (
                        $code === 'product_invalid_sku'
                        || $code === 'woocommerce_rest_product_not_created'
                    );

                    if ($is_duplicate_sku && isset($chunk[$idx])) {
                        $this->duplicate_retry_queue[] = $chunk[$idx];
                        $this->logger->debug("  Duplicate SKU [{$sku}]: queued for retry as update");
                    } else {
                        $this->stats['errors']++;
                        $this->logger->error("  Error [{$sku}]: {$msg}" . ($code ? " [{$code}]" : ''));
                    }

                    // Clean stale image-map entries ONLY for actual image errors
                    // (not for SKU or other errors that happen to contain "non valido")
                    $is_image_error = $code === 'woocommerce_product_invalid_image_id'
                        || stripos($msg, 'immagine') !== false
                        || ($code === 'rest_invalid_param' && stripos($msg, 'image') !== false);

                    if ($is_image_error && $sku !== 'unknown' && isset($this->image_map[$sku])) {
                        $stale_id = $this->image_map[$sku]['media_id'] ?? '?';
                        unset($this->image_map[$sku]);
                        $this->image_map_dirty = true;
                        $this->stats['stale_images_cleaned']++;
                        $this->logger->warning("  Removed stale image-map entry for {$sku} (media_id {$stale_id})");
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

            $this->logger->info("  Batch {$batch_label}: {$operation}d " . count($chunk));

        } catch (\Exception $e) {
            $is_timeout = strpos($e->getMessage(), 'timed out') !== false
                || strpos($e->getMessage(), 'cURL Error') !== false
                || strpos($e->getMessage(), 'Operation timed out') !== false
                || strpos($e->getMessage(), '504') !== false
                || strpos($e->getMessage(), '408') !== false;

            if ($is_timeout && $retry_depth < $max_retries && count($chunk) > 1) {
                // Split the batch in half and retry each sub-batch
                $half = (int) ceil(count($chunk) / 2);
                $sub1 = array_slice($chunk, 0, $half);
                $sub2 = array_slice($chunk, $half);

                $backoff = pow(2, $retry_depth + 1); // 2s, 4s, 8s
                $this->logger->warning(
                    "  Batch {$batch_label} timed out (" . count($chunk) . " items). "
                    . "Splitting into 2 sub-batches and retrying in {$backoff}s (depth {$retry_depth}/{$max_retries})..."
                );
                sleep($backoff);

                $this->executeBatchWithRetry($operation, $sub1, $product_map, $batch_label . '.1', $retry_depth + 1);
                $this->executeBatchWithRetry($operation, $sub2, $product_map, $batch_label . '.2', $retry_depth + 1);
            } else {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  Batch {$batch_label} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Track image IDs from a successful WC batch response item
     *
     * No longer tracks sideloaded images since sideloading is disabled.
     * Images are handled exclusively by the prepare-media pipeline.
     * Kept for backward compatibility with stale image cleanup logic.
     */
    private function trackImagesFromResponse($item): void
    {
        // Sideloading is disabled — images are managed by prepare-media.
        // Nothing to track from batch responses.
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

                $duplicate_retry = [];
                foreach ($result->$operation ?? [] as $idx => $item) {
                    if (isset($item->error)) {
                        $code = $item->error->code ?? '';
                        $var_sku = $item->sku ?? $chunk[$idx]['sku'] ?? 'unknown';

                        // Duplicate variation SKU: queue for update retry
                        if ($operation === 'create' && (
                            $code === 'product_invalid_sku'
                            || strpos($item->error->message ?? '', 'duplicat') !== false
                        )) {
                            $duplicate_retry[] = $chunk[$idx];
                            $this->logger->debug("  Variation duplicate SKU [{$var_sku}], will retry as update");
                        } else {
                            $this->stats['errors']++;
                            $this->logger->error("  Variation error [product:{$product_id} {$var_sku}]: " . ($item->error->message ?? 'Unknown'));
                        }
                    } else {
                        if ($operation === 'create') {
                            $this->stats['variations_created']++;
                        } else {
                            $this->stats['variations_updated']++;
                        }
                    }
                }

                // Retry duplicate variations as updates: find existing by SKU
                if (!empty($duplicate_retry)) {
                    $existing = $this->fetchExistingVariations($product_id);
                    $to_update = [];
                    foreach ($duplicate_retry as $var) {
                        $sku = $var['sku'] ?? '';
                        if (isset($existing[$sku])) {
                            $var['id'] = $existing[$sku]['id'];
                            $to_update[] = $var;
                        } else {
                            $this->stats['errors']++;
                            $this->logger->error("  Variation duplicate but not found under product:{$product_id} [{$sku}]");
                        }
                    }
                    if (!empty($to_update)) {
                        $this->logger->info("  Retrying " . count($to_update) . " duplicate variations as updates...");
                        $this->executeVariationBatch($product_id, 'update', $to_update);
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

            // Variations (parallel processing)
            $this->logger->info('');
            $this->logger->info('Processing variations...');
            $this->processVariationsParallel($product_map);

            // Save image map (stale cleanup)
            $this->saveImageMap();

            $this->printSummary($start_time);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fatal error: ' . $e->getMessage());
            $this->saveImageMap();
            return false;
        }
    }

    // =========================================================================
    // Parallel Variation Processing (curl_multi)
    // =========================================================================

    /**
     * Process variations for all products with controlled concurrency
     *
     * Uses curl_multi to fetch existing variations and send batch
     * create/update requests for multiple products simultaneously,
     * instead of the default sequential per-product approach.
     *
     * @param array $product_map SKU => [id, variations] from batchProcessProducts
     */
    private function processVariationsParallel(array $product_map): void
    {
        $concurrency = (int) ($this->config['import']['variation_concurrency'] ?? 5);

        // Build list of products that need variation processing
        $queue = [];
        foreach ($product_map as $sku => $data) {
            if (empty($data['id']) || empty($data['variations'])) {
                continue;
            }
            $queue[] = ['sku' => $sku, 'id' => $data['id'], 'variations' => $data['variations']];
        }

        $total = count($queue);
        if ($total === 0) {
            $this->logger->info('  No variations to process');
            return;
        }

        $this->logger->info("  {$total} products with variations (concurrency: {$concurrency})");

        if ($this->dry_run) {
            foreach ($queue as $item) {
                $this->stats['variations_created'] += count($item['variations']);
            }
            $this->logger->info("  [DRY RUN] Would process " . array_sum(array_map(fn($q) => count($q['variations']), $queue)) . " variations");
            return;
        }

        // Process in concurrent groups
        $chunks = array_chunk($queue, $concurrency);
        $processed = 0;

        foreach ($chunks as $chunk) {
            // Step 1: Fetch existing variations for all products in this group concurrently
            $existing_map = $this->fetchExistingVariationsConcurrent($chunk);

            // Step 2: Build and send variation batch requests concurrently
            $batch_requests = [];
            foreach ($chunk as $product) {
                $product_id = $product['id'];
                $existing = $existing_map[$product_id] ?? [];

                $to_create = [];
                $to_update = [];

                foreach ($product['variations'] as $var) {
                    $var_sku = $var['sku'] ?? null;
                    if (!$var_sku) {
                        continue;
                    }

                    if (isset($existing[$var_sku])) {
                        $var['id'] = $existing[$var_sku]['id'];
                        $to_update[] = $var;
                    } else {
                        $to_create[] = $var;
                    }
                }

                if (!empty($to_create)) {
                    $batch_requests[] = [
                        'product_id' => $product_id,
                        'operation' => 'create',
                        'items' => $to_create,
                    ];
                }
                if (!empty($to_update)) {
                    $batch_requests[] = [
                        'product_id' => $product_id,
                        'operation' => 'update',
                        'items' => $to_update,
                    ];
                }
            }

            // Execute all variation batches for this group concurrently
            $this->executeVariationBatchesConcurrent($batch_requests);

            $processed += count($chunk);
            echo "\r  Variations: {$processed}/{$total} products          ";
        }
        echo "\n";
    }

    /**
     * Fetch existing variations for multiple products concurrently
     *
     * @param array $products Array of [id => product_id, ...]
     * @return array product_id => [var_sku => [id => var_id], ...]
     */
    private function fetchExistingVariationsConcurrent(array $products): array
    {
        $results = [];
        $handles = [];
        $mh = curl_multi_init();

        foreach ($products as $idx => $product) {
            $product_id = $product['id'];
            $results[$product_id] = [];

            // Fetch up to 100 variations per product (sufficient for sneakers: ~20-40 sizes).
            // If a product ever has >100 variations, this would need pagination —
            // but that's not realistic for shoe/clothing size ranges.
            $url = $this->buildWcApiUrl("products/{$product_id}/variations", ['per_page' => 100, 'page' => 1]);
            $ch = $this->createWcCurlHandle($url);
            $handles[$idx] = ['ch' => $ch, 'product_id' => $product_id];
            curl_multi_add_handle($mh, $ch);
        }

        // Execute all handles concurrently
        $this->executeCurlMulti($mh);

        // Process responses
        foreach ($handles as $idx => $handle) {
            $ch = $handle['ch'];
            $product_id = $handle['product_id'];
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($http_code === 200 && $response) {
                $variations = json_decode($response, false);
                if (is_array($variations)) {
                    foreach ($variations as $var) {
                        if (!empty($var->sku)) {
                            $results[$product_id][$var->sku] = ['id' => $var->id];
                        }
                    }
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Execute variation batch requests concurrently
     *
     * @param array $batch_requests Array of [product_id, operation, items]
     */
    private function executeVariationBatchesConcurrent(array $batch_requests): void
    {
        if (empty($batch_requests)) {
            return;
        }

        $handles = [];
        $mh = curl_multi_init();

        foreach ($batch_requests as $idx => $req) {
            $product_id = $req['product_id'];
            $operation = $req['operation'];

            // WC batch API: chunk items to respect batch_size limit
            $item_chunks = array_chunk($req['items'], $this->batch_size);
            foreach ($item_chunks as $chunk_idx => $chunk) {
                $url = $this->buildWcApiUrl("products/{$product_id}/variations/batch");
                $body = json_encode([$operation => $chunk]);

                $ch = $this->createWcCurlHandle($url, 'POST', $body);
                $key = "{$idx}_{$chunk_idx}";
                $handles[$key] = [
                    'ch' => $ch,
                    'product_id' => $product_id,
                    'operation' => $operation,
                    'count' => count($chunk),
                ];
                curl_multi_add_handle($mh, $ch);
            }
        }

        // Execute all handles concurrently
        $this->executeCurlMulti($mh);

        // Process responses
        foreach ($handles as $key => $handle) {
            $ch = $handle['ch'];
            $operation = $handle['operation'];
            $product_id = $handle['product_id'];
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $this->stats['batch_requests']++;

            if ($http_code === 200 && $response) {
                $result = json_decode($response, false);
                foreach ($result->$operation ?? [] as $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                        $var_sku = $item->sku ?? 'unknown';
                        $this->logger->error("  Variation error [product:{$product_id} {$var_sku}]: " . ($item->error->message ?? 'Unknown'));
                    } else {
                        if ($operation === 'create') {
                            $this->stats['variations_created']++;
                        } else {
                            $this->stats['variations_updated']++;
                        }
                    }
                }
            } else {
                $this->stats['errors'] += $handle['count'];
                $curl_error = curl_error($ch);
                $this->logger->error("  Variation batch failed [product:{$product_id}]: HTTP {$http_code}" . ($curl_error ? " ({$curl_error})" : ''));
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    /**
     * Build a full WooCommerce REST API URL with authentication
     *
     * Uses query string authentication (consumer_key/consumer_secret)
     * which is supported over HTTPS.
     *
     * @param string $endpoint API endpoint (e.g., "products/123/variations")
     * @param array $params Additional query parameters
     * @return string Full URL with auth
     */
    private function buildWcApiUrl(string $endpoint, array $params = []): string
    {
        $base = rtrim($this->config['woocommerce']['url'] ?? '', '/');
        $base = rtrim($base, ':');
        $version = $this->config['woocommerce']['version'] ?? 'wc/v3';

        $params['consumer_key'] = $this->config['woocommerce']['consumer_key'];
        $params['consumer_secret'] = $this->config['woocommerce']['consumer_secret'];

        return "{$base}/wp-json/{$version}/{$endpoint}?" . http_build_query($params);
    }

    /**
     * Create a cURL handle configured for WooCommerce API requests
     *
     * @param string $url Full URL (from buildWcApiUrl)
     * @param string $method HTTP method (GET, POST)
     * @param string|null $body JSON body for POST requests
     * @return resource cURL handle
     */
    private function createWcCurlHandle(string $url, string $method = 'GET', ?string $body = null)
    {
        $ch = curl_init();
        $headers = ['Accept: application/json'];

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($body);
            }
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        return $ch;
    }

    /**
     * Execute curl_multi handle until all requests complete
     *
     * @param resource $mh curl_multi handle
     */
    private function executeCurlMulti($mh): void
    {
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
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

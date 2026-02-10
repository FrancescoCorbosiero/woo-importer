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

    // Stats
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
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
     */
    private function setupWooCommerceClient(): void
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 120]
        );
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

            $id = $this->lookupCategoryBySlug($slug);
            if ($id) {
                $cat = ['id' => $id];
            } else {
                $this->logger->warning("  Category '{$slug}' not found in WooCommerce — product will be uncategorized");
                unset($payload['categories'][$idx]);
            }
        }

        $payload['categories'] = array_values($payload['categories']);
    }

    /**
     * Look up a WooCommerce category by slug (cached)
     *
     * @param string $slug Category slug
     * @return int|null Category ID or null if not found
     */
    private function lookupCategoryBySlug(string $slug): ?int
    {
        if (array_key_exists($slug, $this->category_cache)) {
            return $this->category_cache[$slug];
        }

        try {
            $categories = $this->wc_client->get('products/categories', ['slug' => $slug]);

            if (!empty($categories)) {
                $id = $categories[0]->id;
                $this->category_cache[$slug] = $id;
                $this->logger->info("  Resolved category '{$slug}' → ID {$id}");
                return $id;
            }
        } catch (\Exception $e) {
            $this->logger->debug("  Category lookup failed for '{$slug}': " . $e->getMessage());
        }

        $this->category_cache[$slug] = null;
        return null;
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
                        $this->logger->error("  Error [{$sku}]: " . ($item->error->message ?? 'Unknown') . ($code ? " [{$code}]" : ''));
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
            $synced_ids = [];

            foreach ($product_map as $sku => $data) {
                $current++;

                if (empty($data['id']) || empty($data['variations'])) {
                    continue;
                }

                echo "\r  Processing: {$current}/{$total}          ";
                $this->processVariations($data['id'], $data['variations']);
                $synced_ids[] = $data['id'];
            }
            echo "\n";

            // Trigger WC_Product_Variable::sync() for all imported products.
            // This is what the WP editor "Update" button does internally —
            // rebuilds parent _price meta, stock status, and attribute lookups.
            // Requires the resellpiacenza-variation-sync mu-plugin on the WP server.
            $this->syncVariations($synced_ids);

            // Flush WooCommerce caches so variations appear on frontend
            $this->flushWooCommerceCache();

            $this->printSummary($start_time);
            return true;

        } catch (\Exception $e) {
            $this->logger->error('Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Trigger WC_Product_Variable::sync() via custom REST endpoint
     *
     * The WC REST API does NOT call sync() after creating variations,
     * leaving _product_attributes meta, price lookups, and transient
     * caches stale. This calls our mu-plugin endpoint which runs:
     *   - WC_Product_Variable::sync($id)
     *   - wc_delete_product_transients($id)
     *
     * @param int[] $product_ids Product IDs to sync
     */
    private function syncVariations(array $product_ids): void
    {
        if (empty($product_ids) || $this->dry_run) {
            return;
        }

        $this->logger->info('');
        $this->logger->info('Syncing variations (WC_Product_Variable::sync)...');

        // Process in chunks to avoid oversized requests
        $chunks = array_chunk($product_ids, $this->batch_size);

        foreach ($chunks as $chunk) {
            $result = $this->wpRestPost('resellpiacenza/v1/sync-variations', [
                'product_ids' => $chunk,
            ]);

            if ($result === null) {
                $this->logger->error('  Variation sync failed — is the resellpiacenza-variation-sync mu-plugin installed?');
                $this->logger->info('  Falling back to PUT re-save...');
                $this->fallbackResave($chunk);
            } else {
                $synced = $result['synced'] ?? 0;
                $total = $result['total'] ?? count($chunk);
                $this->logger->info("  Synced {$synced}/{$total} products");

                foreach ($result['results'] ?? [] as $r) {
                    if (($r['status'] ?? '') === 'error') {
                        $this->logger->warning("  Sync error [product:{$r['id']}]: {$r['reason']}");
                    }
                }
            }
        }
    }

    /**
     * Fallback: PUT re-save products when the mu-plugin is not available
     *
     * @param int[] $product_ids Product IDs
     */
    private function fallbackResave(array $product_ids): void
    {
        foreach ($product_ids as $id) {
            try {
                $this->wc_client->put("products/{$id}", ['status' => 'publish']);
            } catch (\Exception $e) {
                $this->logger->debug("  Re-save failed for product {$id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Make a POST request to the WordPress REST API (Basic Auth)
     *
     * Uses the same WP Application Password credentials as media uploads.
     *
     * @param string $endpoint REST route (e.g. 'resellpiacenza/v1/sync-variations')
     * @param array $body Request body
     * @return array|null Decoded response or null on failure
     */
    private function wpRestPost(string $endpoint, array $body): ?array
    {
        $url = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/' . $endpoint;
        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic {$auth}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("  WP REST request failed: {$error}");
            return null;
        }

        if ($http_code >= 400) {
            $this->logger->error("  WP REST returned HTTP {$http_code}: {$response}");
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Flush WooCommerce caches after import
     *
     * REST API-created products don't trigger WP save_post hooks, so
     * WooCommerce's lookup tables and transient caches can be stale.
     * This replicates what happens when you open a product in the editor.
     */
    private function flushWooCommerceCache(): void
    {
        if ($this->dry_run) {
            return;
        }

        $this->logger->info('');
        $this->logger->info('Flushing WooCommerce caches...');

        $tools = [
            'clear_transients',
            'regenerate_product_lookup_tables',
            'regenerate_product_attributes_lookup_table',
        ];

        foreach ($tools as $tool) {
            try {
                $this->wc_client->put("system_status/tools/{$tool}", ['confirm' => true]);
                $this->logger->info("  Ran: {$tool}");
            } catch (\Exception $e) {
                $this->logger->debug("  Tool '{$tool}' failed: " . $e->getMessage());
            }
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

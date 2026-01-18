<?php
/**
 * Golden Sneakers to WooCommerce Batch Importer
 *
 * High-performance version using WooCommerce Batch API.
 * Provides 10-20x performance improvement over sequential import.php
 *
 * Usage:
 *   php import-batch.php                    # Full batch import from API
 *   php import-batch.php --feed=diff.json   # Import from file (used by sync-check)
 *   php import-batch.php --dry-run          # Test without changes
 *   php import-batch.php --limit=50         # Import first 50 products
 *   php import-batch.php --markup=30        # Override markup percentage
 *   php import-batch.php --vat=22           # Override VAT percentage
 *
 * Prerequisites:
 *   Run import-images.php first to pre-upload images
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class GoldenSneakersBatchImporter
{
    private $config;
    private $wc_client;
    private $logger;
    private $dry_run = false;
    private $limit = null;
    private $feed_file = null;
    private $image_map = [];
    private $batch_size = 100;  // WooCommerce max per batch
    private $failed_products = [];
    private $category_cache = [];  // Cache for category IDs

    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration array from config.php
     * @param array $options CLI options (dry_run, limit, markup, vat)
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->feed_file = $options['feed_file'] ?? null;

        // Override markup/vat if provided
        if (isset($options['markup'])) {
            $this->config['api']['params']['markup_percentage'] = $options['markup'];
        }
        if (isset($options['vat'])) {
            $this->config['api']['params']['vat_percentage'] = $options['vat'];
        }

        // Use config batch_size if set
        if (isset($this->config['import']['batch_size'])) {
            $this->batch_size = min($this->config['import']['batch_size'], 100);
        }

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadImageMap();
    }

    /**
     * Setup Monolog logger with file and console handlers
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('GoldenSneakersBatch');

        if ($this->config['logging']['enabled']) {
            $log_dir = dirname($this->config['logging']['file']);
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }

            // File handler - keeps all logs
            $file_level = $this->config['logging']['level'] === 'debug' ? Logger::DEBUG : Logger::INFO;
            $this->logger->pushHandler(
                new RotatingFileHandler(
                    str_replace('.log', '-batch.log', $this->config['logging']['file']),
                    7,  // Keep 7 days
                    $file_level
                )
            );
        }

        // Console handler
        $console_level = $this->config['logging']['console_level'] === 'debug' ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $console_level));
    }

    /**
     * Setup WooCommerce REST API client
     */
    private function setupWooCommerceClient(): void
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'],
                'timeout' => 120,  // Longer timeout for batch operations
            ]
        );
    }

    /**
     * Load image map from JSON file
     */
    private function loadImageMap(): void
    {
        $map_file = __DIR__ . '/image-map.json';
        if (file_exists($map_file)) {
            $this->image_map = json_decode(file_get_contents($map_file), true) ?: [];
            $this->logger->info("📷 Loaded image map with " . count($this->image_map) . " images");
        } else {
            $this->image_map = [];
            $this->logger->warning("⚠️  No image-map.json found. Run import-images.php first for images.");
        }
    }

    /**
     * Get media ID from image map for a given SKU
     *
     * @param string $sku Product SKU
     * @return int|null Media ID or null if not found
     */
    private function getMediaIdForSKU(string $sku): ?int
    {
        return $this->image_map[$sku]['media_id'] ?? null;
    }

    /**
     * Parse template string with placeholders
     *
     * @param string $template Template string with {placeholders}
     * @param array $data Associative array of placeholder values
     * @return string Parsed string
     */
    private function parseTemplate(string $template, array $data): string
    {
        $replacements = [
            '{product_name}' => $data['product_name'] ?? '',
            '{brand_name}' => $data['brand_name'] ?? '',
            '{sku}' => $data['sku'] ?? '',
            '{store_name}' => $this->config['store']['name'] ?? 'ResellPiacenza',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Sanitize string to create URL-friendly slug
     *
     * @param string $title String to sanitize
     * @return string Sanitized slug
     */
    private function sanitize_title(string $title): string
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9-]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        $title = trim($title, '-');
        return $title;
    }

    /**
     * Detect product type by size format
     *
     * Sneakers: numeric sizes (36, 37.5, 42, 10.5)
     * Clothing: letter sizes (S, M, L, XL, XXL)
     *
     * @param array $sizes Array of size data from feed
     * @return string 'sneakers' or 'clothing'
     */
    private function detectProductType(array $sizes): string
    {
        if (empty($sizes)) {
            return 'sneakers';  // Default
        }

        // Check first size's size_eu value
        $first_size = $sizes[0]['size_eu'] ?? '';

        // Letter sizes pattern: S, M, L, XL, XXL, XS, 2XL, 3XL
        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first_size)) {
            return 'clothing';
        }

        // Numeric sizes (with optional decimal): 36, 37.5, 42, 10.5
        if (preg_match('/^\d+\.?\d*$/', $first_size)) {
            return 'sneakers';
        }

        // Default to sneakers for unknown formats
        return 'sneakers';
    }

    /**
     * Build API URL with query parameters
     *
     * @return string Full API URL
     */
    private function getAPIUrl(): string
    {
        $params = http_build_query($this->config['api']['params']);
        return $this->config['api']['base_url'] . '?' . $params;
    }

    /**
     * Fetch products from Golden Sneakers API (or file if provided)
     *
     * @return array Products data
     * @throws Exception on API error
     */
    private function fetchProductsFromAPI(): array
    {
        // If feed file provided, read from file instead of API
        if ($this->feed_file) {
            if (!file_exists($this->feed_file)) {
                throw new Exception("Feed file not found: {$this->feed_file}");
            }

            $this->logger->info("Loading feed from file: {$this->feed_file}");

            $content = file_get_contents($this->feed_file);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Failed to parse feed file: " . json_last_error_msg());
            }

            return $data;
        }

        $url = $this->getAPIUrl();

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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("CURL Error: {$error}");
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception("API returned HTTP {$http_code}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Fetch all existing products from WooCommerce
     * Uses pagination to get everything, builds SKU => product data map
     *
     * @return array SKU => product data mapping
     */
    private function fetchExistingProducts(): array
    {
        $this->logger->debug("  Fetching existing products from WooCommerce...");

        $existing = [];
        $page = 1;
        $per_page = 100;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => $per_page,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($products as $product) {
                    if (!empty($product->sku)) {
                        $existing[$product->sku] = [
                            'id' => $product->id,
                            'name' => $product->name,
                        ];
                    }
                }

                $page++;
            } catch (Exception $e) {
                $this->logger->error("  Error fetching products page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === $per_page);

        return $existing;
    }

    /**
     * Fetch all variations for a product
     *
     * @param int $product_id WooCommerce product ID
     * @return array SKU => variation data mapping
     */
    private function fetchExistingVariations(int $product_id): array
    {
        $existing = [];
        $page = 1;
        $per_page = 100;

        do {
            try {
                $variations = $this->wc_client->get("products/{$product_id}/variations", [
                    'per_page' => $per_page,
                    'page' => $page,
                ]);

                foreach ($variations as $variation) {
                    if (!empty($variation->sku)) {
                        $existing[$variation->sku] = [
                            'id' => $variation->id,
                        ];
                    }
                }

                $page++;
            } catch (Exception $e) {
                $this->logger->error("  Error fetching variations for product {$product_id}: " . $e->getMessage());
                break;
            }
        } while (count($variations) === $per_page);

        return $existing;
    }

    /**
     * Ensure the import category exists in WooCommerce
     *
     * @param string|null $name Category name (uses config default if null)
     * @param string|null $slug Category slug (uses sanitized name if null)
     * @return int Category ID
     * @throws Exception on error
     */
    private function ensureCategoryExists(?string $name = null, ?string $slug = null): int
    {
        // Use defaults from config if not provided
        $category_name = $name ?? $this->config['import']['category_name'];
        $category_slug = $slug ?? $this->sanitize_title($category_name);

        // Check cache first
        if (isset($this->category_cache[$category_slug])) {
            return $this->category_cache[$category_slug];
        }

        try {
            // Search for existing category by slug
            $categories = $this->wc_client->get('products/categories', [
                'slug' => $category_slug
            ]);

            if (!empty($categories)) {
                $category_id = $categories[0]->id;
                $this->category_cache[$category_slug] = $category_id;
                $this->logger->debug("✅ Using existing category: {$category_name} (ID: {$category_id})");
                return $category_id;
            }

            // Category doesn't exist, create it
            if ($this->dry_run) {
                $this->logger->info("🔍 [DRY RUN] Would create category: {$category_name}");
                return 9999;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => $category_name,
                'slug' => $category_slug,
                'display' => 'default',
                'menu_order' => 0,
            ]);

            $this->category_cache[$category_slug] = $result->id;
            $this->logger->info("✅ Created category: {$category_name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("❌ Category error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract EU size options from sizes array
     *
     * @param array $sizes Array of size data
     * @return array Array of EU size strings
     */
    private function extractSizeOptions(array $sizes): array
    {
        return array_map(function ($size) {
            return $size['size_eu'];
        }, $sizes);
    }

    /**
     * Build product payload for batch operation
     *
     * @param array $product_data Product data from API
     * @param int|null $category_id WooCommerce category ID (auto-detected if null)
     * @return array Product payload for WooCommerce API
     */
    private function buildProductPayload(array $product_data, ?int $category_id = null): array
    {
        $sku = $product_data['sku'];
        $name = $product_data['name'];
        $brand = $product_data['brand_name'];
        $sizes = $product_data['sizes'] ?? [];

        // Detect product type and get appropriate category
        $product_type = $product_data['_product_type'] ?? $this->detectProductType($sizes);

        if ($category_id === null) {
            $category_config = $this->config['categories'][$product_type] ?? null;
            if ($category_config) {
                $category_id = $this->ensureCategoryExists(
                    $category_config['name'],
                    $category_config['slug']
                );
            } else {
                $category_id = $this->ensureCategoryExists();
            }
        }

        $template_data = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
        ];

        $payload = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'short_description' => $this->parseTemplate(
                $this->config['templates']['short_description'],
                $template_data
            ),
            'description' => $this->parseTemplate(
                $this->config['templates']['long_description'],
                $template_data
            ),
            'categories' => [
                ['id' => $category_id]
            ],
            'attributes' => [
                [
                    'name' => $this->config['import']['brand_attribute_name'],
                    'position' => 0,
                    'visible' => true,
                    'variation' => false,
                    'options' => [$brand]
                ],
                [
                    'name' => $this->config['import']['size_attribute_name'],
                    'position' => 1,
                    'visible' => true,
                    'variation' => true,
                    'options' => $this->extractSizeOptions($sizes)
                ]
            ]
        ];

        // Add image if available
        $media_id = $this->getMediaIdForSKU($sku);
        if ($media_id) {
            $payload['images'] = [['id' => $media_id]];
        }

        return $payload;
    }

    /**
     * Build variation payload for batch operation
     *
     * Uses presented_price (customer price with margin + VAT),
     * not offer_price (wholesale cost).
     *
     * @param array $size_data Size data from API
     * @param string $variation_sku Variation SKU
     * @return array Variation payload for WooCommerce API
     */
    private function buildVariationPayload(array $size_data, string $variation_sku): array
    {
        return [
            'sku' => $variation_sku,
            'regular_price' => (string) $size_data['presented_price'],  // Customer price (not wholesale)
            'manage_stock' => true,
            'stock_quantity' => $size_data['available_quantity'],
            'stock_status' => $size_data['available_quantity'] > 0 ? 'instock' : 'outofstock',
            'attributes' => [
                [
                    'name' => $this->config['import']['size_attribute_name'],
                    'option' => $size_data['size_eu']
                ]
            ],
            'meta_data' => [
                ['key' => '_size_us', 'value' => $size_data['size_us'] ?? ''],
                ['key' => '_barcode', 'value' => $size_data['barcode'] ?? '']
            ]
        ];
    }

    /**
     * Process products in batches
     *
     * Category is auto-detected per product based on size format:
     * - Numeric sizes (36, 37.5) → Sneakers
     * - Letter sizes (S, M, L) → Abbigliamento
     *
     * @param array $api_products Products from Golden Sneakers API
     * @param array $existing_products Existing WooCommerce products (SKU => data)
     * @return array Product mappings (SKU => [id, sizes])
     */
    private function batchProcessProducts(array $api_products, array $existing_products): array
    {
        $to_create = [];
        $to_update = [];
        $product_map = [];  // SKU => [id, sizes]

        // Sort products into create/update buckets
        foreach ($api_products as $product) {
            $sku = $product['sku'];

            // Skip if no sizes
            if (empty($product['sizes'])) {
                $this->stats['skipped']++;
                $this->logger->debug("  Skipped {$product['name']} - no sizes available");
                continue;
            }

            // buildProductPayload auto-detects category based on size format
            $payload = $this->buildProductPayload($product);

            if (isset($existing_products[$sku])) {
                // Update existing product
                $payload['id'] = $existing_products[$sku]['id'];
                $to_update[] = $payload;
                $product_map[$sku] = [
                    'id' => $existing_products[$sku]['id'],
                    'sizes' => $product['sizes'],
                    'name' => $product['name'],
                ];
            } else {
                // Create new product
                $to_create[] = $payload;
                $product_map[$sku] = [
                    'id' => null,  // Will be set after batch create
                    'sizes' => $product['sizes'],
                    'name' => $product['name'],
                    'pending' => true,
                ];
            }
        }

        $this->logger->info("  Products to create: " . count($to_create) . ", to update: " . count($to_update));

        // Batch create products
        if (!empty($to_create)) {
            $this->executeBatchProducts('create', $to_create, $product_map);
        }

        // Batch update products
        if (!empty($to_update)) {
            $this->executeBatchProducts('update', $to_update, $product_map);
        }

        return $product_map;
    }

    /**
     * Execute batch product operation (create or update)
     *
     * @param string $operation 'create' or 'update'
     * @param array $items Product payloads
     * @param array &$product_map Reference to product map to update with new IDs
     */
    private function executeBatchProducts(string $operation, array $items, array &$product_map): void
    {
        $chunks = array_chunk($items, $this->batch_size);
        $total_chunks = count($chunks);
        $chunk_num = 0;

        foreach ($chunks as $chunk) {
            $chunk_num++;

            if ($this->dry_run) {
                $this->logger->info("  [DRY RUN] Batch {$chunk_num}/{$total_chunks}: Would {$operation} " . count($chunk) . " products");

                // Simulate IDs for dry run
                if ($operation === 'create') {
                    foreach ($chunk as $item) {
                        if (isset($product_map[$item['sku']])) {
                            $product_map[$item['sku']]['id'] = 99990000 + $this->stats['products_created'];
                            unset($product_map[$item['sku']]['pending']);
                            $this->stats['products_created']++;
                        }
                    }
                } else {
                    $this->stats['products_updated'] += count($chunk);
                }
                continue;
            }

            try {
                $result = $this->wc_client->post('products/batch', [
                    $operation => $chunk
                ]);

                $this->stats['batch_requests']++;

                // Track results
                if ($operation === 'create' && isset($result->create)) {
                    foreach ($result->create as $created) {
                        if (isset($created->error)) {
                            $this->stats['errors']++;
                            $sku = $created->sku ?? 'unknown';
                            $this->logger->error("  ❌ Create error for {$sku}: " . ($created->error->message ?? 'Unknown error'));
                            $this->failed_products[] = [
                                'sku' => $sku,
                                'name' => $product_map[$sku]['name'] ?? 'unknown',
                                'error' => $created->error->message ?? 'Unknown error'
                            ];
                        } else {
                            $this->stats['products_created']++;
                            // Update product_map with new ID
                            if (!empty($created->sku) && isset($product_map[$created->sku])) {
                                $product_map[$created->sku]['id'] = $created->id;
                                unset($product_map[$created->sku]['pending']);
                            }
                        }
                    }
                } elseif ($operation === 'update' && isset($result->update)) {
                    foreach ($result->update as $updated) {
                        if (isset($updated->error)) {
                            $this->stats['errors']++;
                            $sku = $updated->sku ?? 'unknown';
                            $this->logger->error("  ❌ Update error for {$sku}: " . ($updated->error->message ?? 'Unknown error'));
                        } else {
                            $this->stats['products_updated']++;
                        }
                    }
                }

                $created_count = $operation === 'create' ? count($result->create ?? []) : 0;
                $updated_count = $operation === 'update' ? count($result->update ?? []) : 0;

                if ($operation === 'create') {
                    $this->logger->info("  ✅ Batch {$chunk_num}/{$total_chunks}: Created {$created_count} products");
                } else {
                    $this->logger->info("  ✅ Batch {$chunk_num}/{$total_chunks}: Updated {$updated_count} products");
                }

            } catch (Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  ❌ Batch {$chunk_num}/{$total_chunks} failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Process variations for a product in batches
     *
     * @param int $product_id WooCommerce product ID
     * @param string $parent_sku Parent product SKU
     * @param array $sizes Array of size data
     */
    private function batchProcessVariations(int $product_id, string $parent_sku, array $sizes): void
    {
        // Fetch existing variations
        $existing_variations = $this->fetchExistingVariations($product_id);

        $to_create = [];
        $to_update = [];

        foreach ($sizes as $size_data) {
            $variation_sku = $parent_sku . '-' . str_replace([' ', '/'], '', $size_data['size_eu']);
            $payload = $this->buildVariationPayload($size_data, $variation_sku);

            if (isset($existing_variations[$variation_sku])) {
                $payload['id'] = $existing_variations[$variation_sku]['id'];
                $to_update[] = $payload;
            } else {
                $to_create[] = $payload;
            }
        }

        // Execute batch operations
        if (!empty($to_create)) {
            $this->executeBatchVariations($product_id, 'create', $to_create);
        }

        if (!empty($to_update)) {
            $this->executeBatchVariations($product_id, 'update', $to_update);
        }
    }

    /**
     * Execute batch variation operation
     *
     * @param int $product_id WooCommerce product ID
     * @param string $operation 'create' or 'update'
     * @param array $items Variation payloads
     */
    private function executeBatchVariations(int $product_id, string $operation, array $items): void
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
                $result = $this->wc_client->post("products/{$product_id}/variations/batch", [
                    $operation => $chunk
                ]);

                $this->stats['batch_requests']++;

                if ($operation === 'create' && isset($result->create)) {
                    foreach ($result->create as $created) {
                        if (isset($created->error)) {
                            $this->stats['errors']++;
                            $this->logger->debug("    ❌ Variation error: " . ($created->error->message ?? 'Unknown'));
                        } else {
                            $this->stats['variations_created']++;
                        }
                    }
                } elseif ($operation === 'update' && isset($result->update)) {
                    foreach ($result->update as $updated) {
                        if (isset($updated->error)) {
                            $this->stats['errors']++;
                        } else {
                            $this->stats['variations_updated']++;
                        }
                    }
                }

            } catch (Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("    ❌ Variation batch failed for product {$product_id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Main import runner
     *
     * @return bool True on success, false on failure
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('========================================');
        $this->logger->info('  Golden Sneakers BATCH Import');
        $this->logger->info('  ⚡ High-Performance Mode');
        $this->logger->info('========================================');

        if ($this->dry_run) {
            $this->logger->warning('⚠️  DRY RUN MODE - No changes will be made');
        }

        $this->logger->info("Markup: {$this->config['api']['params']['markup_percentage']}% | VAT: {$this->config['api']['params']['vat_percentage']}%");
        $this->logger->info("Batch size: {$this->batch_size} items per request");
        $this->logger->info('');

        try {
            // Phase 1: Fetch from API or file
            $source = $this->feed_file ? 'file' : 'Golden Sneakers API';
            $this->logger->info("Phase 1: Fetching from {$source}...");
            $api_products = $this->fetchProductsFromAPI();

            if (empty($api_products)) {
                $this->logger->error('❌ No products fetched from API');
                return false;
            }

            $total_api_products = count($api_products);

            if ($this->limit) {
                $api_products = array_slice($api_products, 0, $this->limit);
                $this->logger->info("✅ Fetched {$total_api_products} products, processing first {$this->limit}");
            } else {
                $this->logger->info("✅ Fetched {$total_api_products} products from API");
            }

            // Phase 2: Fetch existing from WooCommerce
            $this->logger->info('');
            $this->logger->info('Phase 2: Fetching existing WooCommerce products...');
            $existing_products = $this->fetchExistingProducts();
            $this->logger->info("Found " . count($existing_products) . " existing products in WooCommerce");

            // Phase 3: Batch process products (category auto-detected per product)
            $this->logger->info('');
            $this->logger->info('Phase 3: Batch processing products...');
            $this->logger->info('  (Categories auto-detected: Sneakers/Abbigliamento)');
            $product_map = $this->batchProcessProducts($api_products, $existing_products);

            // Phase 4: Batch process variations
            $this->logger->info('');
            $this->logger->info('Phase 4: Batch processing variations...');

            $total = count($product_map);
            $current = 0;
            $products_with_variations = 0;

            foreach ($product_map as $sku => $data) {
                $current++;

                if (empty($data['id'])) {
                    $this->logger->warning("  Skipping variations for {$sku} - no product ID");
                    continue;
                }

                if (empty($data['sizes'])) {
                    continue;
                }

                // Progress indicator (inline update)
                $name_short = mb_substr($data['name'] ?? $sku, 0, 40);
                echo "\r  Processing variations: {$current}/{$total} ({$name_short})                    ";

                $this->batchProcessVariations($data['id'], $sku, $data['sizes']);
                $products_with_variations++;
            }

            echo "\n";
            $this->logger->info("  Processed variations for {$products_with_variations} products");

            // Summary
            $this->logger->info('');
            $this->printSummary($start_time);

            return true;

        } catch (Exception $e) {
            $this->logger->error('❌ Fatal error: ' . $e->getMessage());
            $this->logger->debug('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Print import summary statistics
     *
     * @param float $start_time Microtime when import started
     */
    private function printSummary(float $start_time): void
    {
        $duration = round(microtime(true) - $start_time, 1);

        $this->logger->info('========================================');
        $this->logger->info('  IMPORT SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("✅ Products Created:     {$this->stats['products_created']}");
        $this->logger->info("🔄 Products Updated:     {$this->stats['products_updated']}");
        $this->logger->info("➕ Variations Created:   {$this->stats['variations_created']}");
        $this->logger->info("♻️  Variations Updated:   {$this->stats['variations_updated']}");
        $this->logger->info("⏭️  Products Skipped:     {$this->stats['skipped']}");
        $this->logger->info("📦 Batch Requests:       {$this->stats['batch_requests']}");
        $this->logger->info("❌ Errors:               {$this->stats['errors']}");
        $this->logger->info("⏱️  Duration:             {$duration}s");
        $this->logger->info('========================================');

        // Performance comparison estimate
        $sequential_estimate = ($this->stats['products_created'] + $this->stats['products_updated'] +
                              $this->stats['variations_created'] + $this->stats['variations_updated']) * 1.3;  // ~1.3s per API call
        if ($sequential_estimate > 0 && $duration > 0) {
            $speedup = round($sequential_estimate / $duration, 1);
            $this->logger->info("⚡ Estimated speedup:    ~{$speedup}x vs sequential");
        }

        // Show failed products if any
        if (!empty($this->failed_products)) {
            $this->logger->warning('');
            $this->logger->warning('Failed Products:');
            foreach ($this->failed_products as $failed) {
                $this->logger->warning("  • {$failed['name']} ({$failed['sku']}): {$failed['error']}");
            }
        }
    }
}

// ============================================================================
// CLI Runner
// ============================================================================

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'limit' => null,
    'markup' => null,
    'vat' => null,
    'feed_file' => null,
];

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) str_replace('--limit=', '', $arg);
    }
    if (strpos($arg, '--markup=') === 0) {
        $options['markup'] = (int) str_replace('--markup=', '', $arg);
    }
    if (strpos($arg, '--vat=') === 0) {
        $options['vat'] = (int) str_replace('--vat=', '', $arg);
    }
    if (strpos($arg, '--feed=') === 0) {
        $options['feed_file'] = str_replace('--feed=', '', $arg);
    }
}

// Show help
if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
Golden Sneakers to WooCommerce Batch Importer
High-Performance Mode using WooCommerce Batch API

Usage:
  php import-batch.php [options]

Options:
  --dry-run         Test without making changes
  --limit=N         Process only first N products
  --markup=N        Override markup percentage
  --vat=N           Override VAT percentage
  --feed=FILE       Read products from JSON file instead of API
  --help, -h        Show this help message

Examples:
  php import-batch.php --dry-run --limit=10
  php import-batch.php --limit=50
  php import-batch.php --markup=30 --vat=22
  php import-batch.php --feed=data/diff.json

Prerequisites:
  Run import-images.php first to pre-upload images

HELP;
    exit(0);
}

$config = require __DIR__ . '/config.php';

$importer = new GoldenSneakersBatchImporter($config, $options);
$success = $importer->run();

exit($success ? 0 : 1);

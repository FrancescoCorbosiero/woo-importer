<?php
/**
 * Golden Sneakers to WooCommerce Importer
 * 
 * Usage:
 *   php import.php                    # Run full import
 *   php import.php --dry-run          # Test without creating products
 *   php import.php --limit=10         # Import only first 10 products
 *   php import.php --markup=30        # Override markup percentage
 *   php import.php --vat=22           # Override VAT percentage
 * 
 * Prerequisites:
 *   Run import-images.php first to pre-upload images
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class GoldenSneakersImporter
{

    private $config;
    private $wc_client;
    private $logger;
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];
    private $dry_run = false;
    private $limit = null;
    private $failed_products = [];
    private $image_map = null;
    private $category_cache = [];  // Cache for category IDs
    private $brand_category_cache = [];  // Cache for brand category IDs

    public function __construct($config, $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->limit = $options['limit'] ?? null;

        // Override markup/vat if provided
        if (isset($options['markup'])) {
            $this->config['api']['params']['markup_percentage'] = $options['markup'];
        }
        if (isset($options['vat'])) {
            $this->config['api']['params']['vat_percentage'] = $options['vat'];
        }

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadImageMap();
    }

    /**
     * Setup logger
     */
    private function setupLogger()
    {
        $this->logger = new Logger('GoldenSneakers');

        if ($this->config['logging']['enabled']) {
            $log_dir = dirname($this->config['logging']['file']);
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }

            // File handler - keeps all logs
            $file_level = $this->config['logging']['level'] === 'debug' ? Logger::DEBUG : Logger::INFO;
            $this->logger->pushHandler(
                new RotatingFileHandler(
                    $this->config['logging']['file'],
                    7,  // Keep 7 days
                    $file_level
                )
            );
        }

        // Console handler - only important messages
        $console_level = $this->config['logging']['console_level'] === 'debug' ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $console_level));
    }

    /**
     * Setup WooCommerce REST API client
     */
    private function setupWooCommerceClient()
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'],
                'timeout' => 60,
            ]
        );
    }

    /**
     * Load image map from file
     */
    private function loadImageMap()
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
     * Get media ID from image map
     */
    private function getMediaIdForSKU($sku)
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
    private function parseTemplate($template, $data)
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
     * Sanitize title (WordPress replacement)
     */
    private function sanitize_title($title)
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
     * Main import runner
     */
    public function run()
    {
        $start_time = microtime(true);

        $this->logger->info('========================================');
        $this->logger->info('  Golden Sneakers Product Import');
        $this->logger->info('========================================');

        if ($this->dry_run) {
            $this->logger->warning('⚠️  DRY RUN MODE - No changes will be made');
        }

        $this->logger->info("Markup: {$this->config['api']['params']['markup_percentage']}% | VAT: {$this->config['api']['params']['vat_percentage']}%");
        $this->logger->info('');

        try {
            // Fetch products
            $products = $this->fetchProductsFromAPI();

            if (empty($products)) {
                $this->logger->error('❌ No products fetched from API');
                return false;
            }

            $total_products = count($products);
            $this->logger->info("📦 Fetched {$total_products} products from API");

            // Apply limit
            if ($this->limit) {
                $products = array_slice($products, 0, $this->limit);
                $this->logger->info("⚡ Processing first {$this->limit} products only");
            }

            $this->logger->info('');
            $this->logger->info('Categories auto-detected based on size format (Sneakers/Abbigliamento)');
            $this->logger->info('');

            // Process products with progress indicator (category auto-detected per product)
            $count = count($products);
            foreach ($products as $index => $product_data) {
                $progress = $index + 1;
                $percentage = round(($progress / $count) * 100);

                echo "\r Progress: {$progress}/{$count} ({$percentage}%) - {$product_data['name']}                    ";

                $this->processProduct($product_data);
            }

            echo "\n\n";

            // Summary
            $this->printSummary($start_time);

            return true;

        } catch (Exception $e) {
            $this->logger->error('❌ Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build API URL with parameters
     */
    private function getAPIUrl()
    {
        $params = http_build_query($this->config['api']['params']);
        return $this->config['api']['base_url'] . '?' . $params;
    }

    /**
     * Fetch products from Golden Sneakers API
     */
    private function fetchProductsFromAPI()
    {
        $url = $this->getAPIUrl();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api']['bearer_token'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
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
     * Process a single product
     * Category is auto-detected based on size format
     */
    private function processProduct($product_data)
    {
        try {
            $sku = $product_data['sku'];
            $name = $product_data['name'];
            $brand = $product_data['brand_name'];
            $image_url = $product_data['image_full_url'];
            $sizes = $product_data['sizes'] ?? [];

            // Skip if no sizes
            if (empty($sizes)) {
                $this->stats['skipped']++;
                $this->logger->debug("  Skipped {$name} - no sizes available");
                return;
            }

            // Auto-detect product type and get appropriate category
            $product_type = $product_data['_product_type'] ?? $this->detectProductType($sizes);
            $category_config = $this->config['categories'][$product_type] ?? null;
            if ($category_config) {
                $category_id = $this->ensureCategoryExists(
                    $category_config['name'],
                    $category_config['slug']
                );
            } else {
                $category_id = $this->ensureCategoryExists();
            }

            // Get brand category (auto-created)
            $brand_category_id = $this->ensureBrandCategoryExists($brand);

            // Build categories array
            $categories = [['id' => $category_id]];
            if ($brand_category_id) {
                $categories[] = ['id' => $brand_category_id];
            }

            // Check if product exists
            $existing_product = $this->findProductBySKU($sku);

            $product_id = $existing_product ? $existing_product->id : null;
            $is_update = (bool) $existing_product;

            // Prepare product data
            $product_payload = [
                'name' => $name,
                'type' => 'variable',
                'sku' => $sku,
                'status' => 'publish',
                'catalog_visibility' => 'visible',
                'categories' => $categories,
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

            // Generate localized descriptions from templates
            $template_data = [
                'product_name' => $name,
                'brand_name' => $brand,
                'sku' => $sku,
            ];

            $product_payload['short_description'] = $this->parseTemplate(
                $this->config['templates']['short_description'],
                $template_data
            );

            $product_payload['description'] = $this->parseTemplate(
                $this->config['templates']['long_description'],
                $template_data
            );

            // Add image if available in map
            $media_id = $this->getMediaIdForSKU($sku);
            if ($media_id) {
                $product_payload['images'] = [
                    ['id' => $media_id]
                ];
                $this->logger->debug("  📷 Using pre-uploaded image (Media ID: {$media_id})");
            } else {
                $this->logger->debug("  ⚠️  No image found in map for SKU: {$sku}");
            }

            if (!$this->dry_run) {
                if ($is_update) {
                    $result = $this->wc_client->put("products/{$product_id}", $product_payload);
                    $this->stats['products_updated']++;
                } else {
                    $result = $this->wc_client->post('products', $product_payload);
                    $product_id = $result->id;
                    $this->stats['products_created']++;
                }
            } else {
                $product_id = $product_id ?: 9999;
            }

            // Process variations
            $this->processVariations($product_id, $sizes, $sku);

        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->failed_products[] = [
                'sku' => $sku ?? 'unknown',
                'name' => $name ?? 'unknown',
                'error' => $e->getMessage()
            ];
            $this->logger->error("  ❌ Error: {$name} - " . $e->getMessage());
        }
    }

    /**
     * Process product variations
     * Uses presented_price (customer price with margin + VAT),
     * not offer_price (wholesale cost).
     */
    private function processVariations($product_id, $sizes, $parent_sku)
    {
        foreach ($sizes as $size_data) {
            try {
                $size_eu = $size_data['size_eu'];
                $size_us = $size_data['size_us'] ?? '';
                $price = $size_data['presented_price'];  // Customer price (not wholesale)
                $quantity = $size_data['available_quantity'];
                $barcode = $size_data['barcode'] ?? '';

                $variation_sku = $parent_sku . '-' . str_replace([' ', '/'], '', $size_eu);

                $existing_variation = $this->findVariationBySKU($product_id, $variation_sku);

                $variation_payload = [
                    'sku' => $variation_sku,
                    'regular_price' => (string) $price,
                    'manage_stock' => true,
                    'stock_quantity' => $quantity,
                    'stock_status' => $quantity > 0 ? 'instock' : 'outofstock',
                    'attributes' => [
                        [
                            'name' => $this->config['import']['size_attribute_name'],
                            'option' => $size_eu
                        ]
                    ],
                    'meta_data' => [
                        ['key' => '_size_us', 'value' => $size_us],
                        ['key' => '_barcode', 'value' => $barcode]
                    ]
                ];

                if (!$this->dry_run) {
                    if ($existing_variation) {
                        $this->wc_client->put(
                            "products/{$product_id}/variations/{$existing_variation->id}",
                            $variation_payload
                        );
                        $this->stats['variations_updated']++;
                    } else {
                        $this->wc_client->post(
                            "products/{$product_id}/variations",
                            $variation_payload
                        );
                        $this->stats['variations_created']++;
                    }
                }

            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->logger->error("  Variation error (Size {$size_eu}): " . $e->getMessage());
            }
        }
    }

    /**
     * Extract size options
     */
    private function extractSizeOptions($sizes)
    {
        return array_map(function ($size) {
            return $size['size_eu'];
        }, $sizes);
    }

    /**
     * Find product by SKU
     */
    private function findProductBySKU($sku)
    {
        try {
            $results = $this->wc_client->get('products', ['sku' => $sku]);
            return !empty($results) ? $results[0] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Find variation by SKU
     */
    private function findVariationBySKU($product_id, $sku)
    {
        try {
            $variations = $this->wc_client->get("products/{$product_id}/variations", ['per_page' => 100]);
            foreach ($variations as $variation) {
                if ($variation->sku === $sku) {
                    return $variation;
                }
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Ensure category exists and is properly configured
     *
     * @param string|null $name Category name (uses config default if null)
     * @param string|null $slug Category slug (uses sanitized name if null)
     * @return int Category ID
     */
    private function ensureCategoryExists($name = null, $slug = null)
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
                $this->logger->debug("Using existing category: {$category_name} (ID: {$category_id})");
                return $category_id;
            }

            // Category doesn't exist, create it
            if ($this->dry_run) {
                $this->logger->info("[DRY RUN] Would create category: {$category_name}");
                return 9999;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => $category_name,
                'slug' => $category_slug,
                'display' => 'default',
                'menu_order' => 0,
            ]);

            $this->category_cache[$category_slug] = $result->id;
            $this->logger->info("Created category: {$category_name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("Category error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure brand category exists and is properly configured
     *
     * Creates brand categories like "Nike" with slug "nike-originali".
     * Falls back to "Senza Categoria" when brand is empty or null.
     *
     * @param string|null $brand_name Brand name from product data
     * @return int Category ID
     */
    private function ensureBrandCategoryExists($brand_name = null)
    {
        // Check if brand categories are enabled
        if (!($this->config['brand_categories']['enabled'] ?? true)) {
            return null;
        }

        // Handle missing or empty brand - use uncategorized fallback
        if (empty($brand_name) || trim($brand_name) === '') {
            $brand_name = $this->config['brand_categories']['uncategorized']['name'];
            $brand_slug = $this->config['brand_categories']['uncategorized']['slug'];
        } else {
            // Generate slug from brand name with optional suffix
            $slug_suffix = $this->config['brand_categories']['slug_suffix'] ?? '-originali';
            $brand_slug = $this->sanitize_title($brand_name) . $slug_suffix;
        }

        // Check cache first
        if (isset($this->brand_category_cache[$brand_slug])) {
            return $this->brand_category_cache[$brand_slug];
        }

        try {
            // Search for existing category by slug
            $categories = $this->wc_client->get('products/categories', [
                'slug' => $brand_slug
            ]);

            if (!empty($categories)) {
                $category_id = $categories[0]->id;
                $this->brand_category_cache[$brand_slug] = $category_id;
                $this->logger->debug("Using existing brand category: {$brand_name} (ID: {$category_id})");
                return $category_id;
            }

            // Category doesn't exist, create it
            if ($this->dry_run) {
                $this->logger->info("[DRY RUN] Would create brand category: {$brand_name}");
                return 9998;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => $brand_name,
                'slug' => $brand_slug,
                'display' => 'default',
                'menu_order' => 0,
            ]);

            $this->brand_category_cache[$brand_slug] = $result->id;
            $this->logger->info("Created brand category: {$brand_name} (ID: {$result->id}, Slug: {$brand_slug})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("Brand category error: " . $e->getMessage());
            // Don't throw - continue without brand category
            return null;
        }
    }

    /**
     * Print summary
     */
    private function printSummary($start_time)
    {
        $duration = round(microtime(true) - $start_time, 2);

        $this->logger->info('========================================');
        $this->logger->info('  IMPORT SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("✅ Products Created:     {$this->stats['products_created']}");
        $this->logger->info("🔄 Products Updated:     {$this->stats['products_updated']}");
        $this->logger->info("➕ Variations Created:   {$this->stats['variations_created']}");
        $this->logger->info("♻️  Variations Updated:   {$this->stats['variations_updated']}");
        $this->logger->info("⏭️  Products Skipped:     {$this->stats['skipped']}");
        $this->logger->info("❌ Errors:               {$this->stats['errors']}");
        $this->logger->info("⏱️  Duration:             {$duration}s");
        $this->logger->info('========================================');

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
}

$config = require __DIR__ . '/config.php';

$importer = new GoldenSneakersImporter($config, $options);
$success = $importer->run();

exit($success ? 0 : 1);
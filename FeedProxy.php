<?php
/**
 * Feed Proxy - Transforms Golden Sneakers feed to WooCommerce REST API model
 *
 * This proxy layer handles ALL transformation logic:
 * - Fetches from Golden Sneakers API
 * - Resolves WooCommerce IDs (categories, brands, attributes)
 * - Maps images to media IDs
 * - Outputs data in 1:1 WooCommerce REST API format
 *
 * The importer then becomes a simple pass-through client.
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class FeedProxy
{
    private $config;
    private $wc_client;
    private $logger;

    // Caches for resolved IDs
    private $category_cache = [];
    private $brand_cache = [];
    private $attribute_cache = [];
    private $image_map = [];

    // Options
    private $dry_run = false;
    private $verbose = false;

    /**
     * Constructor
     *
     * @param array $config Configuration from config.php
     * @param array $options Options (dry_run, verbose)
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadImageMap();
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('FeedProxy');
        $level = $this->verbose ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $level));
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
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 60]
        );
    }

    /**
     * Load image map (SKU => media_id)
     */
    private function loadImageMap(): void
    {
        $map_file = __DIR__ . '/image-map.json';
        if (file_exists($map_file)) {
            $data = json_decode(file_get_contents($map_file), true) ?: [];
            foreach ($data as $sku => $info) {
                $this->image_map[$sku] = $info['media_id'] ?? null;
            }
        }
    }

    /**
     * Initialize WooCommerce taxonomies (categories, attributes, brands)
     * This pre-fetches/creates all required IDs before transformation
     */
    public function initializeTaxonomies(): void
    {
        $this->logger->info('Initializing WooCommerce taxonomies...');

        // Ensure categories exist
        foreach ($this->config['categories'] as $type => $cat_config) {
            $this->resolveCategory($cat_config['name'], $cat_config['slug']);
        }

        // Ensure global attributes exist
        $this->resolveAttribute('size');
        if ($this->config['brands']['create_attribute'] ?? false) {
            $this->resolveAttribute('brand');
        }

        $this->logger->info('  Categories: ' . count($this->category_cache) . ' cached');
        $this->logger->info('  Attributes: ' . count($this->attribute_cache) . ' cached');
    }

    /**
     * Resolve category ID (create if not exists)
     *
     * @param string $name Category name
     * @param string $slug Category slug
     * @return int|null Category ID
     */
    private function resolveCategory(string $name, string $slug): ?int
    {
        if (isset($this->category_cache[$slug])) {
            return $this->category_cache[$slug];
        }

        try {
            $categories = $this->wc_client->get('products/categories', ['slug' => $slug]);

            if (!empty($categories)) {
                $this->category_cache[$slug] = $categories[0]->id;
                return $categories[0]->id;
            }

            if ($this->dry_run) {
                $this->category_cache[$slug] = 99999;
                return 99999;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => $name,
                'slug' => $slug,
            ]);

            $this->category_cache[$slug] = $result->id;
            $this->logger->debug("  Created category: {$name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("Category error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve brand ID (create if not exists)
     *
     * @param string $name Brand name
     * @return int|null Brand ID
     */
    private function resolveBrand(string $name): ?int
    {
        if (empty($name)) {
            return null;
        }

        $slug = $this->sanitizeSlug($name);

        if (isset($this->brand_cache[$slug])) {
            return $this->brand_cache[$slug];
        }

        try {
            $brands = $this->wc_client->get('products/brands', ['slug' => $slug]);

            if (!empty($brands)) {
                $this->brand_cache[$slug] = $brands[0]->id;
                return $brands[0]->id;
            }

            if ($this->dry_run) {
                $this->brand_cache[$slug] = 99998;
                return 99998;
            }

            $result = $this->wc_client->post('products/brands', [
                'name' => $name,
                'slug' => $slug,
            ]);

            $this->brand_cache[$slug] = $result->id;
            $this->logger->debug("  Created brand: {$name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->debug("Brand error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve global attribute ID
     *
     * @param string $key Attribute key (size, brand)
     * @return int|null Attribute ID
     */
    private function resolveAttribute(string $key): ?int
    {
        $attr_config = $this->config['attributes'][$key] ?? null;
        if (!$attr_config) {
            return null;
        }

        $slug = $attr_config['slug'];

        if (isset($this->attribute_cache[$slug])) {
            return $this->attribute_cache[$slug];
        }

        try {
            $attributes = $this->wc_client->get('products/attributes');

            foreach ($attributes as $attr) {
                if ($attr->slug === $slug) {
                    $this->attribute_cache[$slug] = $attr->id;
                    return $attr->id;
                }
            }

            if ($this->dry_run) {
                $this->attribute_cache[$slug] = 99997;
                return 99997;
            }

            $result = $this->wc_client->post('products/attributes', [
                'name' => $attr_config['name'],
                'slug' => $slug,
                'type' => $attr_config['type'] ?? 'select',
                'order_by' => $attr_config['order_by'] ?? 'menu_order',
                'has_archives' => $attr_config['has_archives'] ?? true,
            ]);

            $this->attribute_cache[$slug] = $result->id;
            $this->logger->debug("  Created attribute: {$attr_config['name']} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("Attribute error ({$key}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect product type from sizes
     *
     * @param array $sizes Size data
     * @return string 'sneakers' or 'clothing'
     */
    private function detectProductType(array $sizes): string
    {
        if (empty($sizes)) {
            return 'sneakers';
        }

        $first_size = $sizes[0]['size_eu'] ?? '';

        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first_size)) {
            return 'clothing';
        }

        return 'sneakers';
    }

    /**
     * Sanitize string to slug
     *
     * @param string $str Input string
     * @return string Slug
     */
    private function sanitizeSlug(string $str): string
    {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    /**
     * Parse template with placeholders
     *
     * @param string $template Template string
     * @param array $data Placeholder values
     * @return string Parsed string
     */
    private function parseTemplate(string $template, array $data): string
    {
        return str_replace(
            ['{product_name}', '{brand_name}', '{sku}', '{store_name}'],
            [
                $data['product_name'] ?? '',
                $data['brand_name'] ?? '',
                $data['sku'] ?? '',
                $this->config['store']['name'] ?? 'ResellPiacenza'
            ],
            $template
        );
    }

    /**
     * Transform a single product to WooCommerce model
     *
     * Returns a complete WooCommerce-compatible product payload with nested variations.
     *
     * @param array $gs_product Golden Sneakers product data
     * @param bool $is_update Whether this is an update (minimal payload)
     * @return array WooCommerce product payload with _variations key
     */
    public function transformProduct(array $gs_product, bool $is_update = false): array
    {
        $sku = $gs_product['sku'];
        $name = $gs_product['name'];
        $brand = $gs_product['brand_name'] ?? '';
        $sizes = $gs_product['sizes'] ?? [];

        // Resolve IDs
        $product_type = $gs_product['_product_type'] ?? $this->detectProductType($sizes);
        $category_config = $this->config['categories'][$product_type] ?? $this->config['categories']['sneakers'];
        $category_id = $this->resolveCategory($category_config['name'], $category_config['slug']);
        $brand_id = ($this->config['brands']['enabled'] ?? true) ? $this->resolveBrand($brand) : null;
        $size_attr_id = $this->resolveAttribute('size');
        $image_id = $this->image_map[$sku] ?? null;

        // Extract size options
        $size_options = array_map(fn($s) => $s['size_eu'], $sizes);

        // For updates: minimal payload (only attributes for variation sync)
        if ($is_update) {
            $wc_product = [
                'sku' => $sku,
                'attributes' => [
                    [
                        'id' => $size_attr_id,
                        'position' => 0,
                        'visible' => true,
                        'variation' => true,
                        'options' => $size_options,
                    ]
                ],
            ];
        } else {
            // Full product payload for creates
            $template_data = [
                'product_name' => $name,
                'brand_name' => $brand,
                'sku' => $sku,
            ];

            $wc_product = [
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
                'categories' => $category_id ? [['id' => $category_id]] : [],
                'attributes' => [],
            ];

            // Brand attribute (if enabled)
            if (($this->config['brands']['create_attribute'] ?? false) && $brand) {
                $brand_attr_id = $this->resolveAttribute('brand');
                if ($brand_attr_id) {
                    $wc_product['attributes'][] = [
                        'id' => $brand_attr_id,
                        'position' => 0,
                        'visible' => true,
                        'variation' => false,
                        'options' => [$brand],
                    ];
                }
            }

            // Size attribute
            if ($size_attr_id) {
                $wc_product['attributes'][] = [
                    'id' => $size_attr_id,
                    'position' => count($wc_product['attributes']),
                    'visible' => true,
                    'variation' => true,
                    'options' => $size_options,
                ];
            }

            // Brand taxonomy
            if ($brand_id) {
                $wc_product['brands'] = [['id' => $brand_id]];
            }

            // Image
            if ($image_id) {
                $wc_product['images'] = [['id' => $image_id]];
            }
        }

        // Build variations (WooCommerce REST API format)
        $wc_product['_variations'] = [];
        foreach ($sizes as $size_data) {
            $variation_sku = $sku . '-' . str_replace([' ', '/'], '', $size_data['size_eu']);

            $wc_product['_variations'][] = [
                'sku' => $variation_sku,
                'regular_price' => (string) ($size_data['presented_price'] ?? 0),
                'manage_stock' => true,
                'stock_quantity' => (int) ($size_data['available_quantity'] ?? 0),
                'stock_status' => ($size_data['available_quantity'] ?? 0) > 0 ? 'instock' : 'outofstock',
                'attributes' => [
                    [
                        'id' => $size_attr_id,
                        'option' => $size_data['size_eu'],
                    ]
                ],
                'meta_data' => [
                    ['key' => '_size_us', 'value' => $size_data['size_us'] ?? ''],
                    ['key' => '_barcode', 'value' => $size_data['barcode'] ?? ''],
                ],
            ];
        }

        // Preserve sync metadata
        if (isset($gs_product['_sync_action'])) {
            $wc_product['_sync_action'] = $gs_product['_sync_action'];
        }
        if (isset($gs_product['_product_type'])) {
            $wc_product['_product_type'] = $gs_product['_product_type'];
        }

        return $wc_product;
    }

    /**
     * Transform entire feed to WooCommerce model
     *
     * @param array $gs_feed Golden Sneakers feed (array of products)
     * @param array $existing_skus SKUs that already exist in WooCommerce (for update detection)
     * @return array Array of WooCommerce-formatted products
     */
    public function transformFeed(array $gs_feed, array $existing_skus = []): array
    {
        $this->initializeTaxonomies();

        $this->logger->info('Transforming ' . count($gs_feed) . ' products to WooCommerce model...');

        $wc_products = [];
        foreach ($gs_feed as $gs_product) {
            $sku = $gs_product['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            // Determine if update based on existing SKUs or _sync_action
            $is_update = isset($existing_skus[$sku]) ||
                        (isset($gs_product['_sync_action']) && $gs_product['_sync_action'] === 'updated');

            $wc_products[] = $this->transformProduct($gs_product, $is_update);
        }

        $this->logger->info('  Transformed: ' . count($wc_products) . ' products');

        return $wc_products;
    }

    /**
     * Fetch and transform Golden Sneakers feed
     *
     * This is the main entry point - fetches from GS API and returns WC-ready data.
     *
     * @param array $existing_skus Optional SKUs for update detection
     * @return array WooCommerce-formatted products
     * @throws Exception on API error
     */
    public function fetchAndTransform(array $existing_skus = []): array
    {
        $this->logger->info('Fetching from Golden Sneakers API...');

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

        $gs_feed = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        $this->logger->info('  Fetched: ' . count($gs_feed) . ' products');

        return $this->transformFeed($gs_feed, $existing_skus);
    }

    /**
     * Transform from file (for diff imports)
     *
     * @param string $file_path Path to JSON file
     * @param array $existing_skus Optional SKUs for update detection
     * @return array WooCommerce-formatted products
     * @throws Exception on file error
     */
    public function transformFromFile(string $file_path, array $existing_skus = []): array
    {
        if (!file_exists($file_path)) {
            throw new Exception("File not found: {$file_path}");
        }

        $content = file_get_contents($file_path);
        $gs_feed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        $this->logger->info('Loaded ' . count($gs_feed) . ' products from file');

        return $this->transformFeed($gs_feed, $existing_skus);
    }

    /**
     * Get product signature for comparison (uses WC field names)
     *
     * @param array $wc_product WooCommerce-formatted product
     * @return string MD5 hash
     */
    public static function getProductSignature(array $wc_product): string
    {
        $sig_data = [
            'name' => $wc_product['name'] ?? '',
            'variations' => [],
        ];

        foreach ($wc_product['_variations'] ?? [] as $var) {
            $sig_data['variations'][] = implode(':', [
                $var['attributes'][0]['option'] ?? '',
                $var['regular_price'] ?? '0',
                $var['stock_quantity'] ?? 0,
            ]);
        }
        sort($sig_data['variations']);

        return md5(json_encode($sig_data));
    }
}

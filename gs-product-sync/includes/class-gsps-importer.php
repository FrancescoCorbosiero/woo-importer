<?php
/**
 * Product Importer - handles WooCommerce product sync
 *
 * Adapted from import-batch.php for WordPress plugin use.
 * Uses WooCommerce REST API internally.
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Importer {

    /**
     * Config array
     *
     * @var array
     */
    private $config;

    /**
     * Logger instance
     *
     * @var GSPS_Logger
     */
    private $logger;

    /**
     * WooCommerce REST client
     *
     * @var WC_REST_Products_Controller
     */
    private $wc_client;

    /**
     * Image map (SKU => media_id)
     *
     * @var array
     */
    private $image_map = [];

    /**
     * Category cache
     *
     * @var array
     */
    private $category_cache = [];

    /**
     * Brand category cache
     *
     * @var array
     */
    private $brand_category_cache = [];

    /**
     * Stats
     *
     * @var array
     */
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    /**
     * Failed products
     *
     * @var array
     */
    private $failed_products = [];

    /**
     * Batch size
     *
     * @var int
     */
    private $batch_size = 100;

    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @param GSPS_Logger $logger Logger instance
     */
    public function __construct(array $config, GSPS_Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
        $this->batch_size = min($config['import']['batch_size'] ?? 100, 100);

        $this->load_image_map();
    }

    /**
     * Load image map from option
     */
    private function load_image_map() {
        $this->image_map = get_option('gsps_image_map', []);
        $this->logger->debug("Loaded image map with " . count($this->image_map) . " images");
    }

    /**
     * Get media ID for SKU
     *
     * @param string $sku
     * @return int|null
     */
    private function get_media_id_for_sku($sku) {
        return $this->image_map[$sku]['media_id'] ?? null;
    }

    /**
     * Parse template string
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    private function parse_template($template, array $data) {
        $replacements = [
            '{product_name}' => $data['product_name'] ?? '',
            '{brand_name}' => $data['brand_name'] ?? '',
            '{sku}' => $data['sku'] ?? '',
            '{store_name}' => $this->config['store']['name'] ?? 'ResellPiacenza',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Sanitize string to slug
     *
     * @param string $title
     * @return string
     */
    private function sanitize_title($title) {
        return sanitize_title($title);
    }

    /**
     * Detect product type by size format
     *
     * @param array $sizes
     * @return string 'sneakers' or 'clothing'
     */
    private function detect_product_type(array $sizes) {
        if (empty($sizes)) {
            return 'sneakers';
        }

        $first_size = $sizes[0]['size_eu'] ?? '';

        // Letter sizes: S, M, L, XL, XXL
        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first_size)) {
            return 'clothing';
        }

        // Numeric sizes
        if (preg_match('/^\d+\.?\d*$/', $first_size)) {
            return 'sneakers';
        }

        return 'sneakers';
    }

    /**
     * Extract size options
     *
     * @param array $sizes
     * @return array
     */
    private function extract_size_options(array $sizes) {
        return array_map(function($size) {
            return $size['size_eu'];
        }, $sizes);
    }

    /**
     * Ensure category exists
     *
     * @param string|null $name
     * @param string|null $slug
     * @return int Category ID
     */
    private function ensure_category_exists($name = null, $slug = null) {
        $category_name = $name ?? $this->config['import']['category_name'];
        $category_slug = $slug ?? $this->sanitize_title($category_name);

        // Check cache
        if (isset($this->category_cache[$category_slug])) {
            return $this->category_cache[$category_slug];
        }

        // Check if exists
        $term = get_term_by('slug', $category_slug, 'product_cat');

        if ($term) {
            $this->category_cache[$category_slug] = $term->term_id;
            return $term->term_id;
        }

        // Create it
        $result = wp_insert_term($category_name, 'product_cat', ['slug' => $category_slug]);

        if (is_wp_error($result)) {
            $this->logger->error("Failed to create category: " . $result->get_error_message());
            return 0;
        }

        $this->category_cache[$category_slug] = $result['term_id'];
        $this->logger->info("Created category: {$category_name} (ID: {$result['term_id']})");

        return $result['term_id'];
    }

    /**
     * Ensure brand category exists
     *
     * @param string|null $brand_name
     * @return int|null
     */
    private function ensure_brand_category_exists($brand_name = null) {
        if (!($this->config['brand_categories']['enabled'] ?? true)) {
            return null;
        }

        if (empty($brand_name)) {
            $brand_name = $this->config['brand_categories']['uncategorized']['name'];
            $brand_slug = $this->config['brand_categories']['uncategorized']['slug'];
        } else {
            $slug_suffix = $this->config['brand_categories']['slug_suffix'] ?? '-originali';
            $brand_slug = $this->sanitize_title($brand_name) . $slug_suffix;
        }

        // Check cache
        if (isset($this->brand_category_cache[$brand_slug])) {
            return $this->brand_category_cache[$brand_slug];
        }

        // Check if exists
        $term = get_term_by('slug', $brand_slug, 'product_cat');

        if ($term) {
            $this->brand_category_cache[$brand_slug] = $term->term_id;
            return $term->term_id;
        }

        // Create it
        $result = wp_insert_term($brand_name, 'product_cat', ['slug' => $brand_slug]);

        if (is_wp_error($result)) {
            $this->logger->warning("Failed to create brand category: " . $result->get_error_message());
            return null;
        }

        $this->brand_category_cache[$brand_slug] = $result['term_id'];
        $this->logger->info("Created brand category: {$brand_name} (ID: {$result['term_id']})");

        return $result['term_id'];
    }

    /**
     * Fetch existing products from WooCommerce
     *
     * @return array SKU => product data
     */
    private function fetch_existing_products() {
        $this->logger->debug("Fetching existing products from WooCommerce...");

        $existing = [];
        $page = 1;
        $per_page = 100;

        do {
            $products = wc_get_products([
                'limit' => $per_page,
                'page' => $page,
                'status' => 'any',
                'return' => 'objects',
            ]);

            foreach ($products as $product) {
                $sku = $product->get_sku();
                if (!empty($sku)) {
                    $existing[$sku] = [
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                    ];
                }
            }

            $page++;
        } while (count($products) === $per_page);

        return $existing;
    }

    /**
     * Fetch existing variations for a product
     *
     * @param int $product_id
     * @return array SKU => variation data
     */
    private function fetch_existing_variations($product_id) {
        $existing = [];

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return $existing;
        }

        $variation_ids = $product->get_children();

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $sku = $variation->get_sku();
                if (!empty($sku)) {
                    $existing[$sku] = ['id' => $variation_id];
                }
            }
        }

        return $existing;
    }

    /**
     * Build product payload
     *
     * @param array $product_data
     * @param int|null $category_id
     * @param bool $is_update
     * @return array
     */
    private function build_product_payload(array $product_data, $category_id = null, $is_update = false) {
        $sku = $product_data['sku'];
        $name = $product_data['name'];
        $brand = $product_data['brand_name'] ?? null;
        $sizes = $product_data['sizes'] ?? [];

        // For updates: minimal payload - only attributes
        if ($is_update) {
            return [
                'attributes' => [
                    [
                        'name' => $this->config['import']['brand_attribute_name'],
                        'position' => 0,
                        'visible' => true,
                        'variation' => false,
                        'options' => [$brand],
                    ],
                    [
                        'name' => $this->config['import']['size_attribute_name'],
                        'position' => 1,
                        'visible' => true,
                        'variation' => true,
                        'options' => $this->extract_size_options($sizes),
                    ],
                ],
            ];
        }

        // For creates: full payload
        $product_type = $product_data['_product_type'] ?? $this->detect_product_type($sizes);

        if ($category_id === null) {
            $category_config = $this->config['categories'][$product_type] ?? null;
            if ($category_config) {
                $category_id = $this->ensure_category_exists(
                    $category_config['name'],
                    $category_config['slug']
                );
            } else {
                $category_id = $this->ensure_category_exists();
            }
        }

        $brand_category_id = $this->ensure_brand_category_exists($brand);

        $categories = [$category_id];
        if ($brand_category_id) {
            $categories[] = $brand_category_id;
        }

        $template_data = [
            'product_name' => $name,
            'brand_name' => $brand ?? '',
            'sku' => $sku,
        ];

        $payload = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'short_description' => $this->parse_template(
                $this->config['templates']['short_description'],
                $template_data
            ),
            'description' => $this->parse_template(
                $this->config['templates']['long_description'],
                $template_data
            ),
            'categories' => $categories,
            'attributes' => [
                [
                    'name' => $this->config['import']['brand_attribute_name'],
                    'position' => 0,
                    'visible' => true,
                    'variation' => false,
                    'options' => [$brand],
                ],
                [
                    'name' => $this->config['import']['size_attribute_name'],
                    'position' => 1,
                    'visible' => true,
                    'variation' => true,
                    'options' => $this->extract_size_options($sizes),
                ],
            ],
        ];

        // Add image if available
        $media_id = $this->get_media_id_for_sku($sku);
        if ($media_id) {
            $payload['image_id'] = $media_id;
        }

        return $payload;
    }

    /**
     * Create product in WooCommerce
     *
     * @param array $payload
     * @return int|WP_Error Product ID or error
     */
    private function create_product(array $payload) {
        $product = new WC_Product_Variable();

        $product->set_name($payload['name']);
        $product->set_sku($payload['sku']);
        $product->set_status($payload['status']);
        $product->set_catalog_visibility($payload['catalog_visibility']);
        $product->set_short_description($payload['short_description']);
        $product->set_description($payload['description']);
        $product->set_category_ids($payload['categories']);

        // Set attributes
        $attributes = [];
        foreach ($payload['attributes'] as $attr_data) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_data['name']);
            $attribute->set_options($attr_data['options']);
            $attribute->set_position($attr_data['position']);
            $attribute->set_visible($attr_data['visible']);
            $attribute->set_variation($attr_data['variation']);
            $attributes[] = $attribute;
        }
        $product->set_attributes($attributes);

        // Set image
        if (!empty($payload['image_id'])) {
            $product->set_image_id($payload['image_id']);
        }

        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('create_failed', 'Failed to create product');
        }

        return $product_id;
    }

    /**
     * Update product in WooCommerce
     *
     * @param int $product_id
     * @param array $payload
     * @return bool|WP_Error
     */
    private function update_product($product_id, array $payload) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('not_found', 'Product not found');
        }

        // Only update attributes (minimal update for existing products)
        if (!empty($payload['attributes'])) {
            $attributes = [];
            foreach ($payload['attributes'] as $attr_data) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($attr_data['name']);
                $attribute->set_options($attr_data['options']);
                $attribute->set_position($attr_data['position']);
                $attribute->set_visible($attr_data['visible']);
                $attribute->set_variation($attr_data['variation']);
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);
        }

        $product->save();

        return true;
    }

    /**
     * Create or update variation
     *
     * @param int $product_id
     * @param array $size_data
     * @param string $variation_sku
     * @param int|null $variation_id Existing variation ID for updates
     * @return int|WP_Error Variation ID or error
     */
    private function save_variation($product_id, array $size_data, $variation_sku, $variation_id = null) {
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
        }

        if (!$variation) {
            return new WP_Error('variation_error', 'Could not get/create variation');
        }

        $variation->set_sku($variation_sku);
        $variation->set_regular_price((string) $size_data['presented_price']);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($size_data['available_quantity']);
        $variation->set_stock_status($size_data['available_quantity'] > 0 ? 'instock' : 'outofstock');

        // Set attribute
        $variation->set_attributes([
            sanitize_title($this->config['import']['size_attribute_name']) => $size_data['size_eu'],
        ]);

        // Set meta data
        $variation->update_meta_data('_size_us', $size_data['size_us'] ?? '');
        $variation->update_meta_data('_barcode', $size_data['barcode'] ?? '');

        return $variation->save();
    }

    /**
     * Process variations for a product
     *
     * @param int $product_id
     * @param string $parent_sku
     * @param array $sizes
     */
    private function process_variations($product_id, $parent_sku, array $sizes) {
        $existing_variations = $this->fetch_existing_variations($product_id);

        foreach ($sizes as $size_data) {
            $variation_sku = $parent_sku . '-' . str_replace([' ', '/'], '', $size_data['size_eu']);
            $existing_id = $existing_variations[$variation_sku]['id'] ?? null;

            $result = $this->save_variation($product_id, $size_data, $variation_sku, $existing_id);

            if (is_wp_error($result)) {
                $this->stats['errors']++;
                $this->logger->error("Variation error for {$variation_sku}: " . $result->get_error_message());
            } else {
                if ($existing_id) {
                    $this->stats['variations_updated']++;
                } else {
                    $this->stats['variations_created']++;
                }
            }
        }
    }

    /**
     * Run import
     *
     * @param array $products Products to import
     * @return array Stats
     */
    public function run(array $products) {
        $start_time = microtime(true);

        $this->logger->info('Starting product import');
        $this->logger->info('Products to process: ' . count($products));

        // Fetch existing products
        $existing_products = $this->fetch_existing_products();
        $this->logger->info('Existing products in WooCommerce: ' . count($existing_products));

        $total = count($products);
        $current = 0;

        foreach ($products as $product_data) {
            $current++;
            $sku = $product_data['sku'];
            $name = $product_data['name'];

            // Skip if no sizes
            if (empty($product_data['sizes'])) {
                $this->stats['skipped']++;
                $this->logger->debug("Skipped {$name} - no sizes");
                continue;
            }

            $is_update = isset($existing_products[$sku]);
            $payload = $this->build_product_payload($product_data, null, $is_update);

            if ($is_update) {
                // Update existing product
                $product_id = $existing_products[$sku]['id'];
                $result = $this->update_product($product_id, $payload);

                if (is_wp_error($result)) {
                    $this->stats['errors']++;
                    $this->logger->error("Update failed for {$sku}: " . $result->get_error_message());
                    continue;
                }

                $this->stats['products_updated']++;
                $this->logger->debug("[{$current}/{$total}] Updated: {$name}");
            } else {
                // Create new product
                $product_id = $this->create_product($payload);

                if (is_wp_error($product_id)) {
                    $this->stats['errors']++;
                    $this->failed_products[] = [
                        'sku' => $sku,
                        'name' => $name,
                        'error' => $product_id->get_error_message(),
                    ];
                    $this->logger->error("Create failed for {$sku}: " . $product_id->get_error_message());
                    continue;
                }

                $this->stats['products_created']++;
                $this->logger->debug("[{$current}/{$total}] Created: {$name} (ID: {$product_id})");
            }

            // Process variations
            $this->process_variations($product_id, $sku, $product_data['sizes']);
        }

        $duration = round(microtime(true) - $start_time, 2);

        // Log summary
        $this->logger->info('Import completed in ' . $duration . 's');
        $this->logger->info('Products created: ' . $this->stats['products_created']);
        $this->logger->info('Products updated: ' . $this->stats['products_updated']);
        $this->logger->info('Variations created: ' . $this->stats['variations_created']);
        $this->logger->info('Variations updated: ' . $this->stats['variations_updated']);
        $this->logger->info('Skipped: ' . $this->stats['skipped']);
        $this->logger->info('Errors: ' . $this->stats['errors']);

        return [
            'success' => true,
            'stats' => $this->stats,
            'failed_products' => $this->failed_products,
            'duration' => $duration,
        ];
    }

    /**
     * Get stats
     *
     * @return array
     */
    public function get_stats() {
        return $this->stats;
    }
}

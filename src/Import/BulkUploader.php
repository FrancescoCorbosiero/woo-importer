<?php

namespace ResellPiacenza\Import;

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * Bulk Upload Utility
 *
 * Self-contained tool for importing your own products from JSON or CSV files.
 * Handles the full pipeline: taxonomy creation, image upload, WC import.
 *
 * @package ResellPiacenza\Import
 */
class BulkUploader
{
    private $config;
    private $wc_client;
    private $logger;

    private $dry_run = false;
    private $verbose = false;
    private $skip_media = false;
    private $input_file = null;

    // Resolved ID caches
    private $taxonomy_map = [];
    private $image_map = [];
    private $brand_cache = [];    // runtime brand slug -> ID
    private $category_cache = []; // runtime category slug -> ID

    private $stats = [
        'products_parsed' => 0,
        'brands_created' => 0,
        'images_uploaded' => 0,
        'images_skipped' => 0,
        'images_failed' => 0,
        'products_imported' => 0,
        'products_failed' => 0,
    ];

    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->skip_media = $options['skip_media'] ?? false;
        $this->input_file = $options['file'] ?? null;

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadExistingMaps();
    }

    private function setupLogger(): void
    {
        $this->logger = LoggerFactory::create('BulkUpload', [
            'file' => Config::projectRoot() . '/logs/bulk-upload.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
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
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 60]
        );
    }

    /**
     * Load existing taxonomy-map.json and image-map.json
     */
    private function loadExistingMaps(): void
    {
        $tax_file = Config::projectRoot() . '/data/taxonomy-map.json';
        if (file_exists($tax_file)) {
            $this->taxonomy_map = json_decode(file_get_contents($tax_file), true) ?: [];
        }

        $img_file = Config::projectRoot() . '/image-map.json';
        if (file_exists($img_file)) {
            $this->image_map = json_decode(file_get_contents($img_file), true) ?: [];
        }

        // Seed runtime caches from existing maps
        $this->category_cache = $this->taxonomy_map['categories'] ?? [];
        $this->brand_cache = $this->taxonomy_map['brands'] ?? [];
    }

    // =========================================================================
    // Parsing
    // =========================================================================

    /**
     * Parse input file (JSON or CSV) into a normalized product array
     *
     * @return array Normalized products
     */
    private function parseInputFile(): array
    {
        $ext = strtolower(pathinfo($this->input_file, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            return $this->parseJSON($this->input_file);
        } elseif ($ext === 'csv') {
            return $this->parseCSV($this->input_file);
        }

        throw new \Exception("Unsupported file format: .{$ext} (use .json or .csv)");
    }

    /**
     * Parse JSON input
     *
     * Expected format:
     * [
     *   {
     *     "sku": "MY-001",
     *     "name": "Product Name",
     *     "brand": "Nike",
     *     "category": "sneakers",
     *     "image_url": "https://...",
     *     "sizes": [
     *       {"size": "40", "price": 120.00, "stock": 3},
     *       {"size": "41", "price": 120.00, "stock": 5}
     *     ]
     *   }
     * ]
     */
    private function parseJSON(string $path): array
    {
        $data = json_decode(file_get_contents($path), true);

        if (!is_array($data)) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }

        $products = [];
        foreach ($data as $item) {
            $sku = $item['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            $products[] = [
                'sku' => $sku,
                'name' => $item['name'] ?? $sku,
                'brand' => $item['brand'] ?? $item['brand_name'] ?? '',
                'category' => $item['category'] ?? 'sneakers',
                'image_url' => $item['image_url'] ?? $item['image'] ?? null,
                'short_description' => $item['short_description'] ?? null,
                'description' => $item['description'] ?? null,
                'sizes' => array_map(function ($s) {
                    return [
                        'size' => (string) ($s['size'] ?? $s['size_eu'] ?? ''),
                        'price' => (string) ($s['price'] ?? $s['regular_price'] ?? '0'),
                        'stock' => (int) ($s['stock'] ?? $s['stock_quantity'] ?? 0),
                    ];
                }, $item['sizes'] ?? $item['variations'] ?? []),
            ];
        }

        return $products;
    }

    /**
     * Parse CSV input
     *
     * Expected format (one row per variation, same SKU groups into one product):
     * sku,name,brand,category,image_url,size,price,stock
     * MY-001,Product Name,Nike,sneakers,https://...,40,120.00,3
     * MY-001,Product Name,Nike,sneakers,https://...,41,120.00,5
     */
    private function parseCSV(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \Exception("Cannot open CSV file: {$path}");
        }

        // Read header
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \Exception('Empty CSV file');
        }

        $header = array_map('trim', array_map('strtolower', $header));

        // Find column indexes
        $col = function (string ...$names) use ($header): ?int {
            foreach ($names as $name) {
                $idx = array_search($name, $header);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return null;
        };

        $i_sku = $col('sku');
        $i_name = $col('name', 'product_name');
        $i_brand = $col('brand', 'brand_name');
        $i_cat = $col('category', 'cat');
        $i_image = $col('image_url', 'image', 'img');
        $i_size = $col('size', 'size_eu', 'taglia');
        $i_price = $col('price', 'regular_price');
        $i_stock = $col('stock', 'stock_quantity', 'qty');
        $i_desc = $col('description', 'short_description');

        if ($i_sku === null) {
            fclose($handle);
            throw new \Exception('CSV must have a "sku" column');
        }

        // Group rows by SKU
        $grouped = [];
        while (($row = fgetcsv($handle)) !== false) {
            $sku = trim($row[$i_sku] ?? '');
            if (!$sku) {
                continue;
            }

            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'name' => $i_name !== null ? trim($row[$i_name]) : $sku,
                    'brand' => $i_brand !== null ? trim($row[$i_brand]) : '',
                    'category' => $i_cat !== null ? trim($row[$i_cat]) : 'sneakers',
                    'image_url' => $i_image !== null ? trim($row[$i_image]) : null,
                    'short_description' => $i_desc !== null ? trim($row[$i_desc]) : null,
                    'description' => null,
                    'sizes' => [],
                ];
            }

            if ($i_size !== null) {
                $grouped[$sku]['sizes'][] = [
                    'size' => trim($row[$i_size] ?? ''),
                    'price' => (string) ($i_price !== null ? trim($row[$i_price]) : '0'),
                    'stock' => (int) ($i_stock !== null ? trim($row[$i_stock]) : 0),
                ];
            }
        }

        fclose($handle);
        return array_values($grouped);
    }

    // =========================================================================
    // Taxonomy Resolution
    // =========================================================================

    private function sanitizeSlug(string $str): string
    {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    /**
     * Resolve a category to its WC ID, creating if needed
     */
    private function resolveCategoryId(string $slug): ?int
    {
        if (isset($this->category_cache[$slug])) {
            return $this->category_cache[$slug];
        }

        // Check config categories
        foreach ($this->config['categories'] as $type => $cat) {
            if ($cat['slug'] === $slug || $type === $slug) {
                try {
                    $results = $this->wc_client->get('products/categories', ['slug' => $cat['slug']]);
                    if (!empty($results)) {
                        $this->category_cache[$slug] = $results[0]->id;
                        return $results[0]->id;
                    }

                    if ($this->dry_run) {
                        $this->category_cache[$slug] = 99999;
                        return 99999;
                    }

                    $result = $this->wc_client->post('products/categories', [
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                    ]);
                    $this->category_cache[$slug] = $result->id;
                    $this->logger->info("  Created category: {$cat['name']} (ID: {$result->id})");
                    return $result->id;
                } catch (\Exception $e) {
                    $this->logger->error("  Category error: " . $e->getMessage());
                    return null;
                }
            }
        }

        // Try as custom slug
        try {
            $results = $this->wc_client->get('products/categories', ['slug' => $slug]);
            if (!empty($results)) {
                $this->category_cache[$slug] = $results[0]->id;
                return $results[0]->id;
            }

            if ($this->dry_run) {
                $this->category_cache[$slug] = 99999;
                return 99999;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => ucfirst($slug),
                'slug' => $slug,
            ]);
            $this->category_cache[$slug] = $result->id;
            $this->logger->info("  Created category: {$slug} (ID: {$result->id})");
            return $result->id;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a brand to its WC ID, creating if needed
     */
    private function resolveBrandId(string $name): ?int
    {
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
                $this->brand_cache[$slug] = 99997;
                $this->stats['brands_created']++;
                return 99997;
            }

            $result = $this->wc_client->post('products/brands', [
                'name' => $name,
                'slug' => $slug,
            ]);

            $this->brand_cache[$slug] = $result->id;
            $this->stats['brands_created']++;
            $this->logger->info("  Created brand: {$name} (ID: {$result->id})");
            return $result->id;
        } catch (\Exception $e) {
            $this->logger->debug("  Brand error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve attribute ID from taxonomy map, WC API, or create it
     */
    private function resolveAttributeId(string $slug): ?int
    {
        // Check taxonomy-map cache
        if (isset($this->taxonomy_map['attributes'][$slug])) {
            return $this->taxonomy_map['attributes'][$slug];
        }

        // Query WC API for existing attribute
        try {
            $attributes = $this->wc_client->get('products/attributes');
            foreach ($attributes as $attr) {
                if ($attr->slug === $slug) {
                    if (!isset($this->taxonomy_map['attributes'])) {
                        $this->taxonomy_map['attributes'] = [];
                    }
                    $this->taxonomy_map['attributes'][$slug] = $attr->id;
                    $this->logger->debug("  Found attribute: {$slug} (ID: {$attr->id})");
                    return $attr->id;
                }
            }

            // Not found -- create it if we have config and not dry-run
            if ($this->dry_run) {
                return null;
            }

            $attr_config = null;
            foreach ($this->config['attributes'] as $cfg) {
                if ($cfg['slug'] === $slug) {
                    $attr_config = $cfg;
                    break;
                }
            }

            if ($attr_config) {
                $result = $this->wc_client->post('products/attributes', [
                    'name' => $attr_config['name'],
                    'slug' => $attr_config['slug'],
                    'type' => $attr_config['type'] ?? 'select',
                    'order_by' => $attr_config['order_by'] ?? 'menu_order',
                    'has_archives' => $attr_config['has_archives'] ?? true,
                ]);

                if (!isset($this->taxonomy_map['attributes'])) {
                    $this->taxonomy_map['attributes'] = [];
                }
                $this->taxonomy_map['attributes'][$slug] = $result->id;
                $this->logger->info("  Created attribute: {$attr_config['name']} (ID: {$result->id})");
                return $result->id;
            }
        } catch (\Exception $e) {
            $this->logger->debug("  Attribute resolve error ({$slug}): " . $e->getMessage());
        }

        return null;
    }

    // =========================================================================
    // Image Upload
    // =========================================================================

    /**
     * Parse template string with product placeholders
     */
    private function parseTemplate(string $template, array $data): string
    {
        return str_replace(
            ['{product_name}', '{brand_name}', '{sku}', '{store_name}'],
            [
                $data['product_name'] ?? '',
                $data['brand_name'] ?? '',
                $data['sku'] ?? '',
                $this->config['store']['name'] ?? 'ResellPiacenza',
            ],
            $template
        );
    }

    /**
     * Upload a single image to WordPress and return the media ID
     */
    private function uploadImage(string $sku, string $url, array $template_data): ?int
    {
        // Already uploaded?
        if (isset($this->image_map[$sku])) {
            $this->stats['images_skipped']++;
            return $this->image_map[$sku]['media_id'];
        }

        if ($this->dry_run) {
            $this->stats['images_uploaded']++;
            return 99996;
        }

        try {
            // Download
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
            ]);
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || empty($content)) {
                throw new \Exception("Download failed: HTTP {$http_code}");
            }

            // Write to temp file
            $temp = tempnam(sys_get_temp_dir(), 'woo_img_');
            file_put_contents($temp, $content);

            $info = @getimagesize($temp);
            if ($info === false) {
                @unlink($temp);
                throw new \Exception('Not a valid image');
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . '.' . $ext;

            // Build SEO metadata
            $title = $template_data['product_name'];
            $alt = $this->parseTemplate($this->config['templates']['image_alt'], $template_data);
            $caption = $this->parseTemplate($this->config['templates']['image_caption'], $template_data);
            $desc = $this->parseTemplate($this->config['templates']['image_description'], $template_data);

            // Build multipart body
            $boundary = 'BulkUpload' . time() . rand(1000, 9999);
            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$mime}\r\n\r\n";
            $body .= file_get_contents($temp) . "\r\n";

            foreach (['title' => $title, 'alt_text' => $alt, 'caption' => $caption, 'description' => $desc] as $field => $value) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$field}\"\r\n\r\n";
                $body .= $value . "\r\n";
            }
            $body .= "--{$boundary}--\r\n";

            @unlink($temp);

            // Upload to WordPress
            $wp_url = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/wp/v2/media';
            $auth = base64_encode(
                $this->config['wordpress']['username'] . ':' .
                $this->config['wordpress']['app_password']
            );

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $wp_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Basic {$auth}",
                    "Content-Type: multipart/form-data; boundary={$boundary}",
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 201) {
                $err = json_decode($response, true);
                throw new \Exception($err['message'] ?? "HTTP {$http_code}");
            }

            $result = json_decode($response, true);
            $media_id = $result['id'];

            // Update image map
            $this->image_map[$sku] = [
                'media_id' => $media_id,
                'url' => $url,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];
            $this->stats['images_uploaded']++;

            return $media_id;

        } catch (\Exception $e) {
            $this->stats['images_failed']++;
            $this->logger->debug("  Image failed ({$sku}): " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // Transformation
    // =========================================================================

    /**
     * Transform a normalized product into WC REST format
     */
    private function transformProduct(array $product): ?array
    {
        $sku = $product['sku'];
        $name = $product['name'];
        $brand = $product['brand'];
        $cat_slug = $product['category'] ?? 'sneakers';

        // Template data
        $tpl = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
        ];

        // Resolve IDs
        $category_id = $this->resolveCategoryId($cat_slug);
        $size_slug = $this->config['attributes']['size']['slug'] ?? 'taglia';
        $size_attr_id = $this->resolveAttributeId($size_slug);

        $size_options = array_map(fn($s) => $s['size'], $product['sizes']);

        // Build WC product
        $wc = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'short_description' => $product['short_description']
                ?? $this->parseTemplate($this->config['templates']['short_description'], $tpl),
            'description' => $product['description']
                ?? $this->parseTemplate($this->config['templates']['long_description'], $tpl),
            'categories' => [],
            'attributes' => [],
        ];

        if ($category_id) {
            $wc['categories'][] = ['id' => $category_id];
        }

        // Brand attribute (pa_marca)
        if ($brand) {
            $brand_slug = $this->config['attributes']['brand']['slug'] ?? 'marca';
            $brand_attr_id = $this->resolveAttributeId($brand_slug);
            $brand_attr = [
                'position' => 0,
                'visible' => true,
                'variation' => false,
                'options' => [$brand],
            ];
            if ($brand_attr_id) {
                $brand_attr['id'] = $brand_attr_id;
            } else {
                $brand_attr['name'] = 'pa_' . $brand_slug;
            }
            $wc['attributes'][] = $brand_attr;
        }

        // Size attribute (pa_taglia) -- always add for variations to work
        if (!empty($size_options)) {
            $size_attr = [
                'position' => count($wc['attributes']),
                'visible' => true,
                'variation' => true,
                'options' => $size_options,
            ];
            if ($size_attr_id) {
                $size_attr['id'] = $size_attr_id;
            } else {
                $size_attr['name'] = 'pa_' . $size_slug;
            }
            $wc['attributes'][] = $size_attr;
        }

        // Brand taxonomy (Perfect Brands plugin)
        if ($brand && ($this->config['brands']['enabled'] ?? true)) {
            $brand_id = $this->resolveBrandId($brand);
            if ($brand_id) {
                $wc['brands'] = [['id' => $brand_id]];
            }
        }

        // Image
        if (!$this->skip_media && !empty($product['image_url'])) {
            $media_id = $this->uploadImage($sku, $product['image_url'], $tpl);
            if ($media_id) {
                $wc['images'] = [['id' => $media_id]];
            }
        } elseif (!empty($product['image_url'])) {
            // Skip media mode: use URL directly, WC will sideload
            $wc['images'] = [['src' => $product['image_url'], 'name' => $name]];
        }

        // Variations
        $wc['_variations'] = [];
        foreach ($product['sizes'] as $size) {
            $var_sku = $sku . '-' . str_replace([' ', '/'], '', $size['size']);
            $wc['_variations'][] = [
                'sku' => $var_sku,
                'regular_price' => $size['price'],
                'manage_stock' => true,
                'stock_quantity' => $size['stock'],
                'stock_status' => $size['stock'] > 0 ? 'instock' : 'outofstock',
                'attributes' => $size_attr_id
                    ? [['id' => $size_attr_id, 'option' => $size['size']]]
                    : [['name' => 'pa_' . $size_slug, 'option' => $size['size']]],
            ];
        }

        return $wc;
    }

    // =========================================================================
    // Import
    // =========================================================================

    /**
     * Write WC products to temp file and invoke import-wc.php
     */
    private function importProducts(array $wc_products): bool
    {
        $data_dir = Config::projectRoot() . '/data';
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }

        $feed_file = $data_dir . '/bulk-upload-feed.json';
        file_put_contents(
            $feed_file,
            json_encode($wc_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->logger->info("Wrote {$feed_file} (" . count($wc_products) . " products)");

        if ($this->dry_run) {
            $this->logger->info('[DRY RUN] Skipping import');
            $this->stats['products_imported'] = count($wc_products);
            return true;
        }

        $cmd = 'php ' . escapeshellarg(Config::projectRoot() . '/bin/import-wc') .
               ' --feed=' . escapeshellarg($feed_file);

        $this->logger->info("Executing: {$cmd}");
        $this->logger->info('');

        passthru($cmd, $exit_code);

        $this->stats['products_imported'] = $exit_code === 0 ? count($wc_products) : 0;
        $this->stats['products_failed'] = $exit_code === 0 ? 0 : count($wc_products);

        return $exit_code === 0;
    }

    /**
     * Save updated maps back to disk
     */
    private function saveMaps(): void
    {
        // Save image map
        file_put_contents(
            Config::projectRoot() . '/image-map.json',
            json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // Update taxonomy map with any new brands/categories
        $this->taxonomy_map['categories'] = $this->category_cache;
        $this->taxonomy_map['brands'] = $this->brand_cache;
        $this->taxonomy_map['updated_at'] = date('Y-m-d H:i:s');

        $data_dir = Config::projectRoot() . '/data';
        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }

        file_put_contents(
            $data_dir . '/taxonomy-map.json',
            json_encode($this->taxonomy_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    // =========================================================================
    // Main
    // =========================================================================

    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Bulk Upload');
        $this->logger->info('================================');
        $this->logger->info("  File: {$this->input_file}");

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }
        if ($this->skip_media) {
            $this->logger->info('  SKIP MEDIA (images via URL)');
        }

        $this->logger->info('');

        try {
            if (!$this->input_file || !file_exists($this->input_file)) {
                throw new \Exception("Input file not found: {$this->input_file}");
            }

            // Step 1: Parse
            $this->logger->info('Parsing input file...');
            $products = $this->parseInputFile();
            $this->stats['products_parsed'] = count($products);
            $this->logger->info("  " . count($products) . " products parsed");

            if (empty($products)) {
                $this->logger->warning('No products found in input file');
                return true;
            }

            // Step 2: Ensure base taxonomies (categories + attributes)
            $this->logger->info('');
            $this->logger->info('Ensuring taxonomies...');
            // Run prepare-taxonomies for categories + attributes (no brands yet)
            foreach ($this->config['categories'] as $type => $cat) {
                $this->resolveCategoryId($cat['slug']);
            }

            // Step 3: Transform (resolves brands and uploads images along the way)
            $this->logger->info('');
            $this->logger->info('Transforming products...');
            $wc_products = [];
            foreach ($products as $idx => $product) {
                $progress = $idx + 1;
                $total = count($products);
                echo "\r  [{$progress}/{$total}] {$product['sku']}                    ";

                $wc = $this->transformProduct($product);
                if ($wc) {
                    $wc_products[] = $wc;
                }
            }
            echo "\n";

            // Step 4: Save maps
            $this->saveMaps();

            // Step 5: Import
            if (!empty($wc_products)) {
                $this->logger->info('');
                $this->logger->info('Importing to WooCommerce...');
                $this->logger->info('');
                $success = $this->importProducts($wc_products);
            } else {
                $this->logger->warning('No products to import');
                $success = true;
            }

            // Summary
            $duration = round(microtime(true) - $start_time, 1);
            $this->logger->info('');
            $this->logger->info('================================');
            $this->logger->info('  BULK UPLOAD SUMMARY');
            $this->logger->info('================================');
            $this->logger->info("  Parsed:    {$this->stats['products_parsed']} products");
            $this->logger->info("  Brands:    {$this->stats['brands_created']} created");
            $this->logger->info("  Images:    {$this->stats['images_uploaded']} uploaded, {$this->stats['images_skipped']} skipped, {$this->stats['images_failed']} failed");
            $this->logger->info("  Imported:  {$this->stats['products_imported']} products");
            if ($this->stats['products_failed'] > 0) {
                $this->logger->error("  Failed:    {$this->stats['products_failed']} products");
            }
            $this->logger->info("  Duration:  {$duration}s");
            $this->logger->info('================================');

            return $success;

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

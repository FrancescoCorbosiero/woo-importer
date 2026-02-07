<?php

namespace ResellPiacenza\Import;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * Feed Transformer
 *
 * Fetches the Golden Sneakers API feed and transforms it to WooCommerce
 * REST API format, using resolved IDs from taxonomy-map.json and
 * image-map.json.
 *
 * Outputs: data/feed-wc-latest.json
 *
 * @package ResellPiacenza\Import
 */
class GoldenSneakersTransformer
{
    private $config;
    private $logger;

    private $verbose = false;
    private $limit = null;

    // ID maps loaded from files
    private $taxonomy_map = [];
    private $image_map = [];

    private $stats = [
        'total' => 0,
        'transformed' => 0,
        'sneakers' => 0,
        'clothing' => 0,
        'with_image' => 0,
        'without_image' => 0,
        'warnings' => 0,
    ];

    /**
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->verbose = $options['verbose'] ?? false;
        $this->limit = $options['limit'] ?? null;

        $this->setupLogger();
        $this->loadMaps();
    }

    private function setupLogger(): void
    {
        $this->logger = LoggerFactory::create('Transform', [
            'file' => Config::projectRoot() . '/logs/transform-feed.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
     * Load taxonomy-map.json and image-map.json
     */
    private function loadMaps(): void
    {
        // Taxonomy map
        $tax_file = Config::projectRoot() . '/data/taxonomy-map.json';
        if (file_exists($tax_file)) {
            $this->taxonomy_map = json_decode(file_get_contents($tax_file), true) ?: [];
            $this->logger->info("Loaded taxonomy map: " .
                count($this->taxonomy_map['categories'] ?? []) . " categories, " .
                count($this->taxonomy_map['attributes'] ?? []) . " attributes, " .
                count($this->taxonomy_map['brands'] ?? []) . " brands"
            );
        } else {
            $this->logger->warning("No taxonomy-map.json found - run prepare-taxonomies.php first");
        }

        // Image map
        $img_file = Config::projectRoot() . '/image-map.json';
        if (file_exists($img_file)) {
            $this->image_map = json_decode(file_get_contents($img_file), true) ?: [];
            $this->logger->info("Loaded image map: " . count($this->image_map) . " entries");
        } else {
            $this->logger->warning("No image-map.json found - run prepare-media.php first");
        }
    }

    /**
     * Detect product type from sizes
     *
     * Numeric EU sizes (36, 37.5, 42) -> sneakers
     * Letter sizes (S, M, L, XL) -> clothing
     *
     * @param array $sizes Size data from GS feed
     * @return string 'sneakers' or 'clothing'
     */
    private function detectProductType(array $sizes): string
    {
        if (empty($sizes)) {
            return 'sneakers';
        }

        $first = $sizes[0]['size_eu'] ?? '';

        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first)) {
            return 'clothing';
        }

        return 'sneakers';
    }

    /**
     * Parse template string with product data
     *
     * @param string $template Template with {placeholders}
     * @param array $data Product data
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
                $this->config['store']['name'] ?? 'ResellPiacenza',
            ],
            $template
        );
    }

    private function sanitizeSlug(string $str): string
    {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    /**
     * Look up a category ID from the taxonomy map
     *
     * @param string $slug Category slug
     * @return int|null Category ID
     */
    private function getCategoryId(string $slug): ?int
    {
        return $this->taxonomy_map['categories'][$slug] ?? null;
    }

    /**
     * Look up an attribute ID from the taxonomy map
     *
     * @param string $slug Attribute slug
     * @return int|null Attribute ID
     */
    private function getAttributeId(string $slug): ?int
    {
        return $this->taxonomy_map['attributes'][$slug] ?? null;
    }

    /**
     * Look up a brand ID from the taxonomy map
     *
     * @param string $name Brand name
     * @return int|null Brand term ID
     */
    private function getBrandId(string $name): ?int
    {
        $slug = $this->sanitizeSlug($name);
        return $this->taxonomy_map['brands'][$slug] ?? null;
    }

    /**
     * Look up an image media ID from the image map
     *
     * @param string $sku Product SKU
     * @return int|null WordPress media ID
     */
    private function getImageId(string $sku): ?int
    {
        return $this->image_map[$sku]['media_id'] ?? null;
    }

    /**
     * Transform a single Golden Sneakers product to WooCommerce format
     *
     * @param array $gs_product GS product data
     * @return array|null WC-formatted product, or null on error
     */
    private function transformProduct(array $gs_product): ?array
    {
        $sku = $gs_product['sku'] ?? null;
        if (!$sku) {
            return null;
        }

        $name = $gs_product['name'] ?? '';
        $brand = $gs_product['brand_name'] ?? '';
        $sizes = $gs_product['sizes'] ?? [];

        // Detect product type and resolve category
        $type = $this->detectProductType($sizes);
        $cat_config = $this->config['categories'][$type] ?? $this->config['categories']['sneakers'];
        $category_id = $this->getCategoryId($cat_config['slug']);

        // Resolve attribute IDs
        $size_slug = $this->config['attributes']['size']['slug'] ?? 'taglia';
        $size_attr_id = $this->getAttributeId($size_slug);

        // Template data for descriptions
        $tpl = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
        ];

        // Extract size options
        $size_options = array_map(fn($s) => $s['size_eu'], $sizes);

        // Build WC product
        $wc_product = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'short_description' => $this->parseTemplate(
                $this->config['templates']['short_description'],
                $tpl
            ),
            'description' => $this->parseTemplate(
                $this->config['templates']['long_description'],
                $tpl
            ),
            'categories' => [],
            'attributes' => [],
        ];

        // Category
        if ($category_id) {
            $wc_product['categories'][] = ['id' => $category_id];
        } else {
            $this->stats['warnings']++;
            $this->logger->debug("  Warning: No category ID for {$cat_config['slug']} (product {$sku})");
        }

        // Brand attribute (pa_marca)
        if ($brand) {
            $brand_slug = $this->config['attributes']['brand']['slug'] ?? 'marca';
            $brand_attr_id = $this->getAttributeId($brand_slug);
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
            $wc_product['attributes'][] = $brand_attr;
        }

        // Size attribute (pa_taglia) -- always add for variations to work
        if (!empty($size_options)) {
            $size_attr = [
                'position' => count($wc_product['attributes']),
                'visible' => true,
                'variation' => true,
                'options' => $size_options,
            ];
            if ($size_attr_id) {
                $size_attr['id'] = $size_attr_id;
            } else {
                $size_attr['name'] = 'pa_' . $size_slug;
            }
            $wc_product['attributes'][] = $size_attr;
        }

        // Brand taxonomy
        if ($this->config['brands']['enabled'] ?? true) {
            $brand_id = $this->getBrandId($brand);
            if ($brand_id) {
                $wc_product['brands'] = [['id' => $brand_id]];
            }
        }

        // Image
        $image_id = $this->getImageId($sku);
        if ($image_id) {
            $wc_product['images'] = [['id' => $image_id]];
            $this->stats['with_image']++;
        } else {
            // Fallback: use source URL directly (WC will sideload it)
            $image_url = $gs_product['image_full_url'] ?? null;
            if ($image_url) {
                $wc_product['images'] = [['src' => $image_url, 'name' => $name]];
            }
            $this->stats['without_image']++;
        }

        // Variations
        $wc_product['_variations'] = [];
        foreach ($sizes as $size) {
            $var_sku = $sku . '-' . str_replace([' ', '/'], '', $size['size_eu']);

            $wc_product['_variations'][] = [
                'sku' => $var_sku,
                'regular_price' => (string) ($size['presented_price'] ?? 0),
                'manage_stock' => true,
                'stock_quantity' => (int) ($size['available_quantity'] ?? 0),
                'stock_status' => ($size['available_quantity'] ?? 0) > 0 ? 'instock' : 'outofstock',
                'attributes' => $size_attr_id
                    ? [['id' => $size_attr_id, 'option' => $size['size_eu']]]
                    : [['name' => 'pa_' . $size_slug, 'option' => $size['size_eu']]],
                'meta_data' => [
                    ['key' => '_size_us', 'value' => $size['size_us'] ?? ''],
                    ['key' => '_barcode', 'value' => $size['barcode'] ?? ''],
                ],
            ];
        }

        // Track type
        if ($type === 'clothing') {
            $this->stats['clothing']++;
        } else {
            $this->stats['sneakers']++;
        }

        $this->stats['transformed']++;
        return $wc_product;
    }

    /**
     * Fetch Golden Sneakers feed from API
     *
     * @return array Products
     */
    private function fetchFeed(): array
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
            throw new \Exception('CURL Error: ' . curl_error($ch));
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new \Exception("API returned HTTP {$http_code}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }

        $this->logger->info("  " . count($data) . " products fetched");
        return $data;
    }

    /**
     * Main entry point
     *
     * @return bool Success
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Feed Transformer');
        $this->logger->info('  GS format -> WC REST format');
        $this->logger->info('================================');
        $this->logger->info('');

        try {
            $gs_feed = $this->fetchFeed();
            $this->stats['total'] = count($gs_feed);

            if ($this->limit) {
                $gs_feed = array_slice($gs_feed, 0, $this->limit);
                $this->logger->info("Limited to first {$this->limit} products");
            }

            $this->logger->info('');
            $this->logger->info('Transforming...');

            $wc_products = [];
            foreach ($gs_feed as $product) {
                $wc = $this->transformProduct($product);
                if ($wc) {
                    $wc_products[] = $wc;
                }
            }

            // Write output
            $data_dir = Config::projectRoot() . '/data';
            if (!is_dir($data_dir)) {
                mkdir($data_dir, 0755, true);
            }

            $output_file = $data_dir . '/feed-wc-latest.json';
            file_put_contents(
                $output_file,
                json_encode($wc_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            // Summary
            $duration = round(microtime(true) - $start_time, 1);
            $this->logger->info('');
            $this->logger->info('================================');
            $this->logger->info('  TRANSFORM SUMMARY');
            $this->logger->info('================================');
            $this->logger->info("  Total:         {$this->stats['total']}");
            $this->logger->info("  Transformed:   {$this->stats['transformed']}");
            $this->logger->info("  Sneakers:      {$this->stats['sneakers']}");
            $this->logger->info("  Clothing:      {$this->stats['clothing']}");
            $this->logger->info("  With image:    {$this->stats['with_image']}");
            $this->logger->info("  Without image: {$this->stats['without_image']}");
            $this->logger->info("  Warnings:      {$this->stats['warnings']}");
            $this->logger->info("  Duration:      {$duration}s");
            $this->logger->info("  Output:        {$output_file}");
            $this->logger->info('================================');

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

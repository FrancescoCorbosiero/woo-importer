<?php

namespace ResellPiacenza\Import;

use ResellPiacenza\KicksDb\Client as KicksDbClient;
use ResellPiacenza\Pricing\PriceCalculator;
use ResellPiacenza\Support\Config;

/**
 * KicksDB -> WooCommerce Feed Transformer
 *
 * Fetches product data from KicksDB (StockX) for a list of SKUs and
 * transforms it to WooCommerce REST API format, identical to the output
 * of gs-transform.php but sourced from KicksDB instead of Golden Sneakers.
 *
 * Handles:
 * - Product metadata (title, brand, description, images)
 * - Size variations with StockX market prices
 * - Margin calculation via PriceCalculator
 * - Italian localized descriptions and image SEO
 * - Taxonomy ID resolution (categories, attributes, brands)
 * - Image handling (sideload URLs or media library IDs)
 *
 * Output format matches import-wc.php expectations exactly.
 *
 * @package ResellPiacenza\Import
 */
class KicksDbTransformer
{
    private $config;
    private $logger;
    private KicksDbClient $kicksdb;
    private PriceCalculator $calculator;

    /** @var string KicksDB market for variant pricing */
    private string $market;

    // ID maps from taxonomy-map.json and image-map.json
    private array $taxonomy_map = [];
    private array $image_map = [];

    private array $stats = [
        'total_skus' => 0,
        'products_found' => 0,
        'products_not_found' => 0,
        'products_transformed' => 0,
        'variants_total' => 0,
        'variants_with_price' => 0,
        'variants_no_price' => 0,
        'with_image' => 0,
        'without_image' => 0,
        'warnings' => 0,
    ];

    /**
     * @param array $config Full config from config.php
     * @param object|null $logger PSR-3 logger
     */
    public function __construct(array $config, $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;

        $pricing = $config['pricing'] ?? [];

        $this->kicksdb = new KicksDbClient(
            $pricing['kicksdb_api_key'] ?? '',
            ['base_url' => $pricing['kicksdb_base_url'] ?? 'https://api.kicks.dev/v3'],
            $logger
        );

        $this->calculator = new PriceCalculator($pricing['margin'] ?? []);
        $this->market = $pricing['kicksdb_market'] ?? 'US';

        $this->loadMaps();
    }

    /**
     * Load taxonomy-map.json and image-map.json
     */
    private function loadMaps(): void
    {
        $tax_file = Config::projectRoot() . '/data/taxonomy-map.json';
        if (file_exists($tax_file)) {
            $this->taxonomy_map = json_decode(file_get_contents($tax_file), true) ?: [];
            $this->log('info', "Loaded taxonomy map: " .
                count($this->taxonomy_map['categories'] ?? []) . " categories, " .
                count($this->taxonomy_map['attributes'] ?? []) . " attributes, " .
                count($this->taxonomy_map['brands'] ?? []) . " brands"
            );
        } else {
            $this->log('warning', "No taxonomy-map.json found - run prepare-taxonomies.php first");
        }

        $img_file = Config::projectRoot() . '/image-map.json';
        if (file_exists($img_file)) {
            $this->image_map = json_decode(file_get_contents($img_file), true) ?: [];
            $this->log('info', "Loaded image map: " . count($this->image_map) . " entries");
        }
    }

    // =========================================================================
    // Main Transform Pipeline
    // =========================================================================

    /**
     * Transform a list of SKUs into WC-formatted products
     *
     * For each SKU:
     *   1. Fetch product from KicksDB
     *   2. Fetch variants (sizes + prices)
     *   3. Transform to WC format
     *   4. Apply margin pricing
     *
     * @param array $skus List of SKUs/style codes
     * @return array WC-formatted products (ready for import-wc.php)
     */
    public function transform(array $skus): array
    {
        $this->stats['total_skus'] = count($skus);
        $wc_products = [];

        foreach ($skus as $idx => $sku) {
            $sku = trim($sku);
            if (empty($sku)) {
                continue;
            }

            $progress = $idx + 1;
            $this->log('info', "[{$progress}/{$this->stats['total_skus']}] Fetching {$sku}...");

            // Fetch product
            $product = $this->kicksdb->getStockXProduct($sku);
            if ($product === null) {
                $this->stats['products_not_found']++;
                $this->log('warning', "  Not found in KicksDB: {$sku}");
                continue;
            }

            $this->stats['products_found']++;

            // Extract product data (handle nested 'data' wrapper)
            $product_data = $product['data'] ?? $product;

            // Fetch variants
            $product_id = $product_data['id'] ?? $sku;
            $variants = $this->kicksdb->getStockXVariants($product_id, $this->market);

            // Handle nested variants response
            if ($variants !== null && isset($variants['data'])) {
                $variants = $variants['data'];
            }

            if ($variants === null || empty($variants)) {
                $this->log('warning', "  No variants found for {$sku}");
                // Still create product, just without variations for now
                $variants = [];
            }

            // Transform
            $wc_product = $this->transformProduct($product_data, $variants, $sku);
            if ($wc_product !== null) {
                $wc_products[] = $wc_product;
                $this->stats['products_transformed']++;
            }

            // Rate limit: 200ms between products
            usleep(200000);
        }

        return $wc_products;
    }

    /**
     * Transform a single KicksDB product to WC format
     *
     * @param array $product KicksDB product data
     * @param array $variants KicksDB variant data
     * @param string $original_sku The SKU we searched for
     * @return array|null WC-formatted product
     */
    private function transformProduct(array $product, array $variants, string $original_sku): ?array
    {
        $sku = $product['sku'] ?? $original_sku;
        $name = $product['title'] ?? $product['name'] ?? '';
        $brand = $product['brand'] ?? '';
        $model = $product['model'] ?? '';
        $gender = $product['gender'] ?? '';
        $description = $product['description'] ?? '';
        $image_url = $product['image'] ?? ($product['images'][0] ?? null);
        $colorway = $product['colorway'] ?? '';
        $category = $product['category'] ?? '';
        $release_date = $product['release_date'] ?? '';

        if (empty($name)) {
            $this->log('warning', "  Product {$sku} has no title, skipping");
            return null;
        }

        $this->log('info', "  {$name} ({$brand}) - {$sku}");

        // Template data for Italian localized strings
        $tpl = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
        ];

        // Resolve category ID -- sneakers by default
        $cat_slug = $this->config['categories']['sneakers']['slug'] ?? 'sneakers';
        $category_id = $this->taxonomy_map['categories'][$cat_slug] ?? null;

        // Resolve attribute IDs
        $size_slug = $this->config['attributes']['size']['slug'] ?? 'taglia';
        $size_attr_id = $this->taxonomy_map['attributes'][$size_slug] ?? null;
        $brand_slug = $this->config['attributes']['brand']['slug'] ?? 'marca';
        $brand_attr_id = $this->taxonomy_map['attributes'][$brand_slug] ?? null;

        // Build size options and variations
        $size_options = [];
        $wc_variations = [];

        foreach ($variants as $variant) {
            $this->stats['variants_total']++;

            $size_eu = $variant['size_eu']
                ?? $variant['size']
                ?? $this->extractEuSize($variant['title'] ?? '')
                ?? null;

            if ($size_eu === null) {
                $this->stats['variants_no_price']++;
                continue;
            }

            // Normalize: strip "EU " prefix
            $size_eu = preg_replace('/^EU\s*/i', '', trim($size_eu));

            // Get market price
            $market_price = (float) ($variant['lowest_ask']
                ?? $variant['price']
                ?? $variant['amount']
                ?? 0);

            if ($market_price <= 0) {
                $this->stats['variants_no_price']++;
                $this->log('debug', "    Size {$size_eu}: no price, skipping");
                continue;
            }

            $this->stats['variants_with_price']++;

            // Apply margin
            $selling_price = $this->calculator->calculate($market_price);

            // Variation SKU: base-size (strip dots for cleaner SKU)
            $var_sku = $sku . '-' . str_replace([' ', '/'], '', $size_eu);

            $size_options[] = $size_eu;

            $variation = [
                'sku' => $var_sku,
                'regular_price' => (string) $selling_price,
                'manage_stock' => true,
                'stock_quantity' => 0, // KicksDB doesn't provide our stock, set to 0
                'stock_status' => 'instock', // available for purchase (dropship/on-demand)
                'attributes' => $size_attr_id
                    ? [['id' => $size_attr_id, 'option' => $size_eu]]
                    : [['name' => 'pa_' . $size_slug, 'option' => $size_eu]],
                'meta_data' => [
                    ['key' => '_kicksdb_lowest_ask', 'value' => (string) $market_price],
                    ['key' => '_kicksdb_updated', 'value' => date('c')],
                ],
            ];

            // Add US/UK sizes as metadata if available
            if (!empty($variant['size_us'])) {
                $variation['meta_data'][] = ['key' => '_size_us', 'value' => $variant['size_us']];
            }
            if (!empty($variant['size_uk'])) {
                $variation['meta_data'][] = ['key' => '_size_uk', 'value' => $variant['size_uk']];
            }
            if (!empty($variant['barcode'])) {
                $variation['meta_data'][] = ['key' => '_barcode', 'value' => $variant['barcode']];
            }

            $wc_variations[] = $variation;
        }

        if (empty($wc_variations)) {
            $this->log('warning', "  No valid variations for {$sku} (no prices found)");
            return null;
        }

        // Sort sizes naturally (36, 36.5, 37, ...)
        $unique_sizes = array_unique($size_options);
        usort($unique_sizes, function ($a, $b) {
            return (float) $a <=> (float) $b;
        });

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
            'meta_data' => [
                ['key' => '_kicksdb_id', 'value' => $product['id'] ?? ''],
                ['key' => '_kicksdb_slug', 'value' => $product['slug'] ?? ''],
                ['key' => '_source', 'value' => 'kicksdb'],
            ],
        ];

        // Add optional metadata
        if ($gender) {
            $wc_product['meta_data'][] = ['key' => '_gender', 'value' => $gender];
        }
        if ($colorway) {
            $wc_product['meta_data'][] = ['key' => '_colorway', 'value' => $colorway];
        }
        if ($release_date) {
            $wc_product['meta_data'][] = ['key' => '_release_date', 'value' => $release_date];
        }

        // Category
        if ($category_id) {
            $wc_product['categories'][] = ['id' => $category_id];
        }

        // Brand attribute (pa_marca)
        if ($brand) {
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

        // Size attribute (pa_taglia)
        $size_attr = [
            'position' => count($wc_product['attributes']),
            'visible' => true,
            'variation' => true,
            'options' => $unique_sizes,
        ];
        if ($size_attr_id) {
            $size_attr['id'] = $size_attr_id;
        } else {
            $size_attr['name'] = 'pa_' . $size_slug;
        }
        $wc_product['attributes'][] = $size_attr;

        // Brand taxonomy
        if ($brand && ($this->config['brands']['enabled'] ?? true)) {
            $brand_id = $this->getBrandId($brand);
            if ($brand_id) {
                $wc_product['brands'] = [['id' => $brand_id]];
            }
        }

        // Image
        $image_id = $this->image_map[$sku]['media_id'] ?? null;
        if ($image_id) {
            $wc_product['images'] = [['id' => $image_id]];
            $this->stats['with_image']++;
        } elseif ($image_url) {
            // Sideload: WC will download and attach the image on product creation
            // Clean StockX CDN params for a reasonable filename
            $wc_product['images'] = [[
                'src' => $image_url,
                'name' => $sku,
                'alt' => $this->parseTemplate($this->config['templates']['image_alt'], $tpl),
            ]];
            $this->stats['with_image']++;
        } else {
            $this->stats['without_image']++;
        }

        // Variations
        $wc_product['_variations'] = $wc_variations;

        $this->log('info', "  Transformed: " . count($wc_variations) . " variations, sizes " .
            reset($unique_sizes) . "-" . end($unique_sizes));

        return $wc_product;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extract EU size from a KicksDB variant title
     * e.g. "Men's US 10 / EU 44" -> "44"
     */
    private function extractEuSize(string $title): ?string
    {
        if (preg_match('/EU\s+([\d.]+)/i', $title, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Parse template with product placeholders
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
     * Look up a brand ID from taxonomy map
     */
    private function getBrandId(string $name): ?int
    {
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $name));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        return $this->taxonomy_map['brands'][$slug] ?? null;
    }

    /**
     * Get transform stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get the price calculator summary
     */
    public function getPricingSummary(): array
    {
        return $this->calculator->getConfigSummary();
    }

    /**
     * Log helper
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

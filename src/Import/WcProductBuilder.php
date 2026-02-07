<?php

namespace ResellPiacenza\Import;

use ResellPiacenza\Support\Config;

/**
 * WooCommerce Product Builder
 *
 * Takes normalized product data (from any FeedAdapter) and builds
 * WooCommerce REST API payloads. Handles all shared logic:
 *
 * - Taxonomy/image map resolution
 * - Italian localized descriptions via templates
 * - Category, attribute, brand ID lookup
 * - Image handling (media library or sideload fallback)
 * - Variation construction with size attributes
 *
 * @package ResellPiacenza\Import
 */
class WcProductBuilder
{
    private array $config;
    private $logger;

    private array $taxonomy_map = [];
    private array $image_map = [];

    private array $stats = [
        'transformed' => 0,
        'with_image' => 0,
        'without_image' => 0,
        'warnings' => 0,
    ];

    /**
     * @param array $config Full config from config.php
     * @param object|null $logger PSR-3 compatible logger
     */
    public function __construct(array $config, $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->loadMaps();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Build a single WC product from normalized data
     *
     * @param array $product Normalized product (see FeedAdapter docblock)
     * @return array|null WC REST API product payload, or null on error
     */
    public function build(array $product): ?array
    {
        $sku = $product['sku'] ?? null;
        if (!$sku) {
            return null;
        }

        $name = $product['name'] ?? '';
        $brand = $product['brand'] ?? '';
        $category_type = $product['category_type'] ?? 'sneakers';
        $image_url = $product['image_url'] ?? null;
        $extra_meta = $product['meta_data'] ?? [];
        $variations = $product['variations'] ?? [];

        // Template data for Italian localized strings
        $tpl = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
        ];

        // Resolve category
        $cat_config = $this->config['categories'][$category_type]
            ?? $this->config['categories']['sneakers'];
        $category_id = $this->getCategoryId($cat_config['slug']);

        // Resolve attribute IDs
        $size_slug = $this->config['attributes']['size']['slug'] ?? 'taglia';
        $size_attr_id = $this->getAttributeId($size_slug);
        $brand_slug = $this->config['attributes']['brand']['slug'] ?? 'marca';
        $brand_attr_id = $this->getAttributeId($brand_slug);

        // Extract and sort unique sizes
        $size_options = array_map(fn($v) => $v['size_eu'], $variations);
        $unique_sizes = array_values(array_unique($size_options));
        usort($unique_sizes, fn($a, $b) => (float) $a <=> (float) $b);

        // Build base WC product
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

        // Extra source-specific metadata
        if (!empty($extra_meta)) {
            $wc_product['meta_data'] = $extra_meta;
        }

        // Category
        if ($category_id) {
            $wc_product['categories'][] = ['id' => $category_id];
        } else {
            $this->stats['warnings']++;
            $this->log('debug', "  Warning: No category ID for {$cat_config['slug']} (product {$sku})");
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
        if (!empty($unique_sizes)) {
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
        }

        // Brand taxonomy
        if ($brand && ($this->config['brands']['enabled'] ?? true)) {
            $brand_id = $this->getBrandId($brand);
            if ($brand_id) {
                $wc_product['brands'] = [['id' => $brand_id]];
            }
        }

        // Image: media library ID > sideload URL > none
        $image_id = $this->image_map[$sku]['media_id'] ?? null;
        if ($image_id) {
            $wc_product['images'] = [['id' => $image_id]];
            $this->stats['with_image']++;
        } elseif ($image_url) {
            $wc_product['images'] = [[
                'src' => $image_url,
                'name' => $sku,
                'alt' => $this->parseTemplate(
                    $this->config['templates']['image_alt'],
                    $tpl
                ),
            ]];
            $this->stats['with_image']++;
        } else {
            $this->stats['without_image']++;
        }

        // Build variations
        $wc_product['_variations'] = [];
        foreach ($variations as $var) {
            $size_eu = $var['size_eu'] ?? '';
            $var_sku = $sku . '-' . str_replace([' ', '/'], '', $size_eu);

            $wc_var = [
                'sku' => $var_sku,
                'regular_price' => (string) ($var['price'] ?? 0),
                'manage_stock' => true,
                'stock_quantity' => (int) ($var['stock_quantity'] ?? 0),
                'stock_status' => $var['stock_status'] ?? 'outofstock',
                'attributes' => $size_attr_id
                    ? [['id' => $size_attr_id, 'option' => $size_eu]]
                    : [['name' => 'pa_' . $size_slug, 'option' => $size_eu]],
            ];

            if (!empty($var['meta_data'])) {
                $wc_var['meta_data'] = $var['meta_data'];
            }

            $wc_product['_variations'][] = $wc_var;
        }

        $this->stats['transformed']++;
        return $wc_product;
    }

    /**
     * Build WC products from an iterable of normalized products
     *
     * @param iterable $products Normalized products (from FeedAdapter)
     * @return array WC REST API product payloads
     */
    public function buildAll(iterable $products): array
    {
        $wc_products = [];
        foreach ($products as $product) {
            $wc = $this->build($product);
            if ($wc !== null) {
                $wc_products[] = $wc;
            }
        }
        return $wc_products;
    }

    /**
     * Get builder stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    // =========================================================================
    // Map Loading
    // =========================================================================

    private function loadMaps(): void
    {
        // Taxonomy map
        $tax_file = Config::projectRoot() . '/data/taxonomy-map.json';
        if (file_exists($tax_file)) {
            $this->taxonomy_map = json_decode(file_get_contents($tax_file), true) ?: [];
            $this->log('info', "Builder: loaded taxonomy map — " .
                count($this->taxonomy_map['categories'] ?? []) . " categories, " .
                count($this->taxonomy_map['attributes'] ?? []) . " attributes, " .
                count($this->taxonomy_map['brands'] ?? []) . " brands"
            );
        } else {
            $this->log('warning', "No taxonomy-map.json found — run bin/prepare-taxonomies first");
        }

        // Image map
        $img_file = Config::projectRoot() . '/image-map.json';
        if (file_exists($img_file)) {
            $this->image_map = json_decode(file_get_contents($img_file), true) ?: [];
            $this->log('info', "Builder: loaded image map — " . count($this->image_map) . " entries");
        }
    }

    // =========================================================================
    // Taxonomy Helpers
    // =========================================================================

    private function getCategoryId(string $slug): ?int
    {
        return $this->taxonomy_map['categories'][$slug] ?? null;
    }

    private function getAttributeId(string $slug): ?int
    {
        return $this->taxonomy_map['attributes'][$slug] ?? null;
    }

    private function getBrandId(string $name): ?int
    {
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $name));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        return $this->taxonomy_map['brands'][$slug] ?? null;
    }

    // =========================================================================
    // Template Engine
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

    // =========================================================================
    // Logging
    // =========================================================================

    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

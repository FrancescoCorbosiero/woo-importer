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
 * - Italian localized descriptions via templates, enriched with API data
 * - Category, attribute, brand ID lookup
 * - Image handling (media library or sideload fallback) with gallery support
 * - Variation construction with size attributes
 * - Rich metadata mapping (colorway, gender, model, retail price, etc.)
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
        'with_gallery' => 0,
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
        $model = $product['model'] ?? '';
        $gender = $product['gender'] ?? '';
        $colorway = $product['colorway'] ?? '';
        $release_date = $product['release_date'] ?? '';
        $retail_price = $product['retail_price'] ?? '';
        $api_description = $product['description'] ?? '';
        $category_type = $product['category_type'] ?? 'sneakers';
        $image_url = $product['image_url'] ?? null;
        $gallery_urls = $product['gallery_urls'] ?? [];
        $extra_meta = $product['meta_data'] ?? [];
        $variations = $product['variations'] ?? [];

        // Template data for Italian localized strings
        $tpl = [
            'product_name' => $name,
            'brand_name' => $brand,
            'sku' => $sku,
            'model' => $model,
            'colorway' => $colorway,
        ];

        // Resolve category
        $cat_slug = $this->normalizeCategoryType($category_type);
        $cat_config = $this->config['categories'][$cat_slug]
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

        // Build description: use API description when available, enriched with Italian sections
        $description = $this->buildDescription($tpl, $api_description, $colorway, $release_date, $retail_price, $gender);
        $short_description = $this->buildShortDescription($tpl, $colorway);

        // Build base WC product
        $wc_product = [
            'name' => $name,
            'type' => 'variable',
            'sku' => $sku,
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'manage_stock' => false,
            'stock_status' => 'instock',
            'backorders' => 'yes',
            'sold_individually' => true,
            'short_description' => $short_description,
            'description' => $description,
            'categories' => [],
            'attributes' => [],
        ];

        // Extra source-specific metadata
        if (!empty($extra_meta)) {
            $wc_product['meta_data'] = $extra_meta;
        }

        // Category: use ID from taxonomy map, fallback to slug so WC creates/resolves it
        if ($category_id) {
            $wc_product['categories'][] = ['id' => $category_id];
        } else {
            $wc_product['categories'][] = ['slug' => $cat_config['slug']];
            $this->stats['warnings']++;
            $this->log('debug', "  No category ID for '{$cat_config['slug']}', using slug fallback (product {$sku})");
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

        // Images: primary + gallery
        $wc_product['images'] = $this->buildWcImages($sku, $name, $brand, $image_url, $gallery_urls, $tpl);

        if (!empty($wc_product['images'])) {
            $this->stats['with_image']++;
            if (count($wc_product['images']) > 1) {
                $this->stats['with_gallery']++;
            }
        } else {
            $this->stats['without_image']++;
        }

        // Build variations
        $wc_product['_variations'] = [];
        foreach ($variations as $var) {
            $size_eu = $var['size_eu'] ?? '';
            $var_sku = $sku . '-' . str_replace([' ', '/'], '', $size_eu);

            $price = (float) ($var['price'] ?? 0);
            $wc_var = [
                'sku' => $var_sku,
                'status' => 'publish',
                'regular_price' => (string) $price,
                'manage_stock' => true,
                'stock_quantity' => $this->stockForPrice($price),
                'stock_status' => 'instock',
                'backorders' => 'yes',
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
    // Description Building
    // =========================================================================

    /**
     * Build the full product description (long description)
     *
     * When we have a rich API description, use it as the product story.
     * Always append the Italian trust/shipping sections.
     */
    private function buildDescription(
        array $tpl,
        string $api_description,
        string $colorway,
        string $release_date,
        string $retail_price,
        string $gender
    ): string {
        $store = $this->config['store']['name'] ?? 'ResellPiacenza';
        $sections = [];

        // Product intro with rich data when available
        if (!empty($api_description)) {
            // Use the real product description from the source
            $sections[] = '<div class="product-description">' . $api_description . '</div>';
        } else {
            // Fallback to template intro
            $sections[] = '<p>Scopri le <strong>' . $tpl['product_name'] . '</strong>, sneakers originali '
                . $tpl['brand_name'] . ' disponibili su ' . $store . '.</p>';
        }

        // Product details table (when we have rich metadata)
        $details = [];
        if (!empty($colorway)) {
            $details[] = '<tr><td><strong>Colorway</strong></td><td>' . htmlspecialchars($colorway) . '</td></tr>';
        }
        if (!empty($tpl['model'])) {
            $details[] = '<tr><td><strong>Modello</strong></td><td>' . htmlspecialchars($tpl['model']) . '</td></tr>';
        }
        if (!empty($tpl['sku'])) {
            $details[] = '<tr><td><strong>Style Code</strong></td><td>' . htmlspecialchars($tpl['sku']) . '</td></tr>';
        }
        if (!empty($release_date)) {
            $details[] = '<tr><td><strong>Data di Rilascio</strong></td><td>' . htmlspecialchars($this->formatDate($release_date)) . '</td></tr>';
        }
        if (!empty($retail_price)) {
            $details[] = '<tr><td><strong>Prezzo Retail</strong></td><td>&euro;' . htmlspecialchars($retail_price) . '</td></tr>';
        }
        if (!empty($gender)) {
            $details[] = '<tr><td><strong>Genere</strong></td><td>' . htmlspecialchars($this->translateGender($gender)) . '</td></tr>';
        }

        if (!empty($details)) {
            $sections[] = '<h3>Dettagli Prodotto</h3>'
                . '<table class="product-details">' . implode('', $details) . '</table>';
        }

        // Italian trust sections (always present)
        $sections[] = '<h3>Garanzia di Autenticità</h3>'
            . '<p>Tutti i prodotti venduti su ' . $store . ' sono <strong>100% originali e autentici</strong>. '
            . 'Ogni articolo viene accuratamente verificato prima della spedizione.</p>';

        $sections[] = '<h3>Spedizione e Resi</h3>'
            . '<ul>'
            . '<li>✓ Spedizione rapida in tutta Italia</li>'
            . '<li>✓ Imballaggio sicuro e discreto</li>'
            . '<li>✓ Reso facile entro 14 giorni</li>'
            . '</ul>';

        $sections[] = '<h3>Perché Scegliere ' . $store . '</h3>'
            . '<p>Siamo specialisti in sneakers e streetwear di alta qualità. '
            . 'La nostra missione è offrire prodotti autentici ai migliori prezzi, '
            . 'con un servizio clienti impeccabile.</p>';

        return implode("\n\n", $sections);
    }

    /**
     * Build the short description (excerpt)
     *
     * Enriched with colorway when available.
     */
    private function buildShortDescription(array $tpl, string $colorway): string
    {
        $parts = [];
        $parts[] = 'Sneakers originali <strong>' . $tpl['brand_name'] . '</strong>.';

        if (!empty($colorway)) {
            $parts[] = 'Colorway: ' . htmlspecialchars($colorway) . '.';
        }

        $parts[] = 'Prodotto autentico al 100%. Spedizione veloce in tutta Italia.';

        return '<p>' . implode(' ', $parts) . '</p>';
    }

    // =========================================================================
    // Image Building
    // =========================================================================

    /**
     * Build WC images array from primary + gallery URLs
     *
     * Primary image is first. Gallery images follow.
     * If image is already in media library (by SKU), use its ID.
     */
    private function buildWcImages(
        string $sku,
        string $name,
        string $brand,
        ?string $image_url,
        array $gallery_urls,
        array $tpl
    ): array {
        $images = [];

        // Check media library first for pre-uploaded image
        $image_id = $this->image_map[$sku]['media_id'] ?? null;

        if ($image_id) {
            // Primary from media library
            $images[] = ['id' => $image_id];
        } elseif ($image_url) {
            // Primary via sideload
            $images[] = [
                'src' => $image_url,
                'name' => $sku,
                'alt' => $this->parseTemplate(
                    $this->config['templates']['image_alt'] ?? '{product_name} - {sku} - Acquista su {store_name}',
                    $tpl
                ),
            ];
        }

        // Gallery images via sideload
        foreach ($gallery_urls as $idx => $url) {
            $images[] = [
                'src' => $url,
                'name' => $sku . '-' . ($idx + 1),
                'alt' => $name . ' - ' . $brand,
            ];
        }

        return $images;
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
    // Helpers
    // =========================================================================

    /**
     * Determine default stock quantity based on price range
     *
     * Lower-priced items get higher stock to reflect higher demand.
     */
    private function stockForPrice(float $price): int
    {
        if ($price < 140) {
            return 80;
        }
        if ($price < 240) {
            return 50;
        }
        if ($price < 340) {
            return 30;
        }
        return 13;
    }

    /**
     * Normalize category type from API to config key
     *
     * KicksDB may return "Shoes", "sneakers", etc.
     */
    private function normalizeCategoryType(string $type): string
    {
        $lower = strtolower(trim($type));
        if (in_array($lower, ['shoes', 'sneakers', 'footwear'])) {
            return 'sneakers';
        }
        if (in_array($lower, ['clothing', 'apparel'])) {
            return 'clothing';
        }
        return 'sneakers';
    }

    /**
     * Translate gender to Italian
     */
    private function translateGender(string $gender): string
    {
        $map = [
            'men' => 'Uomo',
            'women' => 'Donna',
            'unisex' => 'Unisex',
            'youth' => 'Ragazzo',
            'child' => 'Bambino',
            'infant' => 'Neonato',
            'toddler' => 'Bambino piccolo',
            'preschool' => 'Prescolare',
        ];
        return $map[strtolower($gender)] ?? ucfirst($gender);
    }

    /**
     * Format a date string to Italian format
     */
    private function formatDate(string $date): string
    {
        // Handle ISO dates with time component
        $date = preg_replace('/T.*$/', '', $date);
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }

        $months = [
            1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
            5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
            9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
        ];

        $day = (int) date('j', $ts);
        $month = $months[(int) date('n', $ts)];
        $year = date('Y', $ts);

        return "{$day} {$month} {$year}";
    }

    /**
     * Parse template string with product placeholders
     */
    private function parseTemplate(string $template, array $data): string
    {
        return str_replace(
            ['{product_name}', '{brand_name}', '{sku}', '{store_name}', '{model}', '{colorway}'],
            [
                $data['product_name'] ?? '',
                $data['brand_name'] ?? '',
                $data['sku'] ?? '',
                $this->config['store']['name'] ?? 'ResellPiacenza',
                $data['model'] ?? '',
                $data['colorway'] ?? '',
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

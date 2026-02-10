<?php

namespace ResellPiacenza\Import;

use ResellPiacenza\KicksDb\Client as KicksDbClient;
use ResellPiacenza\Pricing\PriceCalculator;

/**
 * KicksDB Feed Adapter
 *
 * Fetches product data from KicksDB (StockX) for a list of SKUs
 * and normalizes to the common format consumed by WcProductBuilder.
 *
 * Handles:
 * - Per-SKU product + variant fetching from KicksDB v3 API
 * - EU size extraction from the sizes[] sub-array per variant
 * - Market price → selling price via PriceCalculator margin
 * - Rich metadata extraction (traits, gallery, description, identifiers)
 * - Rate limiting (200ms between requests)
 *
 * @package ResellPiacenza\Import
 */
class KicksDbAdapter implements FeedAdapter
{
    private array $config;
    private $logger;
    private KicksDbClient $kicksdb;
    private PriceCalculator $calculator;
    private string $market;
    private array $skus;

    private array $stats = [
        'total_skus' => 0,
        'products_found' => 0,
        'products_not_found' => 0,
        'variants_total' => 0,
        'variants_with_price' => 0,
        'variants_no_price' => 0,
        'variants_no_eu_size' => 0,
    ];

    /**
     * @param array $config Full config from config.php
     * @param array $skus List of SKUs/style codes to fetch
     * @param object|null $logger PSR-3 compatible logger
     */
    public function __construct(array $config, array $skus, $logger = null)
    {
        $this->config = $config;
        $this->skus = $skus;
        $this->logger = $logger;

        $pricing = $config['pricing'] ?? [];

        $this->kicksdb = new KicksDbClient(
            $pricing['kicksdb_api_key'] ?? '',
            ['base_url' => $pricing['kicksdb_base_url'] ?? 'https://api.kicks.dev/v3'],
            $logger
        );

        $this->calculator = new PriceCalculator($pricing['margin'] ?? []);
        $this->market = $pricing['kicksdb_market'] ?? 'IT';
    }

    public function getSourceName(): string
    {
        return 'KicksDB';
    }

    /**
     * Fetch each SKU from KicksDB and yield normalized products
     */
    public function fetchProducts(): iterable
    {
        $this->stats['total_skus'] = count($this->skus);

        foreach ($this->skus as $idx => $sku) {
            $sku = trim($sku);
            if (empty($sku)) {
                continue;
            }

            $progress = $idx + 1;
            $this->log('info', "[{$progress}/{$this->stats['total_skus']}] Fetching {$sku}...");

            // Fetch product with display fields and market
            $product = $this->kicksdb->getStockXProduct($sku, $this->market);
            if ($product === null) {
                $this->stats['products_not_found']++;
                $this->log('warning', "  Not found in KicksDB: {$sku}");
                continue;
            }

            $this->stats['products_found']++;
            $product_data = $product['data'] ?? $product;

            // Variants should be embedded thanks to display[variants]=true
            $variants = $product_data['variants'] ?? [];
            if (empty($variants)) {
                // Fallback: dedicated variants endpoint
                $product_id = $product_data['id'] ?? $product_data['slug'] ?? $sku;
                $this->log('debug', "  No embedded variants, trying variants endpoint for {$product_id}...");
                $raw = $this->kicksdb->getStockXVariants($product_id, $this->market);
                if ($raw !== null) {
                    $variants = $raw['data'] ?? $raw;
                }
            }

            // Normalize
            $normalized = $this->normalize($product_data, $variants, $sku);
            if ($normalized !== null) {
                yield $normalized;
            }

            // Rate limit: 200ms between products
            usleep(200000);
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get pricing configuration summary
     */
    public function getPricingSummary(): array
    {
        return $this->calculator->getConfigSummary();
    }

    // =========================================================================
    // Normalization
    // =========================================================================

    /**
     * Normalize a KicksDB product + variants to common format
     */
    private function normalize(array $product, array $variants, string $original_sku): ?array
    {
        $sku = $product['sku'] ?? $original_sku;
        $name = $product['title'] ?? $product['name'] ?? '';
        $brand = $product['brand'] ?? '';

        if (empty($name)) {
            $this->log('warning', "  Product {$sku} has no title, skipping");
            return null;
        }

        $this->log('info', "  {$name} ({$brand}) - {$sku}");

        // Parse traits array into a flat map for easy access
        $traits = $this->parseTraits($product['traits'] ?? []);

        // Primary image
        $image_url = $product['image'] ?? null;

        // Gallery: main gallery + select 360 images (pick every 6th for ~6 angles)
        $gallery_urls = $this->buildGallery($product);

        // Rich description from API (English, from StockX)
        $api_description = $product['description'] ?? '';

        // Product-level metadata
        $meta = [
            ['key' => '_kicksdb_id', 'value' => $product['id'] ?? ''],
            ['key' => '_kicksdb_slug', 'value' => $product['slug'] ?? ''],
            ['key' => '_source', 'value' => 'kicksdb'],
        ];

        // Gender
        $gender = $product['gender'] ?? '';
        if ($gender) {
            $meta[] = ['key' => '_gender', 'value' => $gender];
        }

        // Model
        $model = $product['model'] ?? '';
        if ($model) {
            $meta[] = ['key' => '_model', 'value' => $model];
        }

        // Colorway: try direct field first, then traits
        $colorway = $product['colorway'] ?? $traits['Colorway'] ?? '';
        if ($colorway) {
            $meta[] = ['key' => '_colorway', 'value' => $colorway];
        }

        // Release date: try direct field first, then traits
        $release_date = $product['release_date'] ?? $traits['Release Date'] ?? '';
        if ($release_date) {
            $meta[] = ['key' => '_release_date', 'value' => $release_date];
        }

        // Retail price from traits
        $retail_price = $traits['Retail Price'] ?? '';
        if ($retail_price) {
            $meta[] = ['key' => '_retail_price', 'value' => $retail_price];
        }

        // Style code from traits (usually same as SKU)
        $style = $traits['Style'] ?? '';
        if ($style && $style !== $sku) {
            $meta[] = ['key' => '_style', 'value' => $style];
        }

        // Country of manufacture
        if (!empty($product['country_of_manufacture'])) {
            $meta[] = ['key' => '_country_of_manufacture', 'value' => $product['country_of_manufacture']];
        }

        // Popularity / demand signals
        if (isset($product['rank'])) {
            $meta[] = ['key' => '_stockx_rank', 'value' => (string) $product['rank']];
        }
        if (isset($product['weekly_orders'])) {
            $meta[] = ['key' => '_weekly_orders', 'value' => (string) $product['weekly_orders']];
        }

        // Price reference points
        if (isset($product['avg_price'])) {
            $meta[] = ['key' => '_avg_market_price', 'value' => (string) round($product['avg_price'], 2)];
        }

        // Category info from KicksDB
        $kicksdb_category = $product['category'] ?? '';
        $kicksdb_secondary = $product['secondary_category'] ?? '';
        if ($kicksdb_category) {
            $meta[] = ['key' => '_kicksdb_category', 'value' => $kicksdb_category];
        }
        if ($kicksdb_secondary) {
            $meta[] = ['key' => '_kicksdb_secondary_category', 'value' => $kicksdb_secondary];
        }

        // Store the original English description for future LLM translation
        if ($api_description) {
            $meta[] = ['key' => '_original_description', 'value' => $api_description];
        }

        // Normalize variations
        $normalized_vars = [];
        foreach ($variants as $variant) {
            $this->stats['variants_total']++;

            // Extract EU size from the sizes[] sub-array
            $size_eu = $this->extractEuSizeFromVariant($variant);

            if ($size_eu === null) {
                $this->stats['variants_no_eu_size']++;
                $vid = $variant['id'] ?? '?';
                $this->log('debug', "    Variant {$vid}: no EU size found, skipping");
                continue;
            }

            // Get market price - filter for "standard" type only (not express)
            $market_price = $this->extractStandardPrice($variant);

            if ($market_price <= 0) {
                $this->stats['variants_no_price']++;
                $this->log('debug', "    Size {$size_eu}: no price, skipping");
                continue;
            }

            $this->stats['variants_with_price']++;

            // Per-variation metadata
            $var_meta = [
                ['key' => '_kicksdb_variant_id', 'value' => $variant['id'] ?? ''],
                ['key' => '_kicksdb_lowest_ask', 'value' => (string) $market_price],
                ['key' => '_kicksdb_updated', 'value' => $variant['updated_at'] ?? date('c')],
            ];

            // Size mappings for reference
            $size_us = $this->extractSizeByType($variant, 'us m')
                ?? $this->extractSizeByType($variant, 'us w')
                ?? $variant['size_us']
                ?? $variant['size']
                ?? '';
            if ($size_us) {
                $var_meta[] = ['key' => '_size_us', 'value' => $size_us];
            }

            $size_uk = $this->extractSizeByType($variant, 'uk') ?? $variant['size_uk'] ?? '';
            if ($size_uk) {
                $var_meta[] = ['key' => '_size_uk', 'value' => $size_uk];
            }

            // UPC/barcode from identifiers array or direct field
            $barcode = $this->extractBarcode($variant);
            if ($barcode) {
                $var_meta[] = ['key' => '_barcode', 'value' => $barcode];
            }

            // Currency from variant
            if (!empty($variant['currency'])) {
                $var_meta[] = ['key' => '_currency', 'value' => $variant['currency']];
            }

            // Sales velocity
            $sales_30d = $variant['sales_count_30_days'] ?? null;
            if ($sales_30d !== null) {
                $var_meta[] = ['key' => '_sales_30d', 'value' => (string) $sales_30d];
            }

            $selling_price = $this->calculator->calculate($market_price);

            $normalized_vars[] = [
                'size_eu' => $size_eu,
                'price' => $selling_price,
                'stock_quantity' => $this->stockForPrice($selling_price),
                'stock_status' => 'instock',
                'meta_data' => $var_meta,
            ];
        }

        if (empty($normalized_vars)) {
            $this->log('warning', "  No valid variations for {$sku} (no EU sizes or prices found)");
            return null;
        }

        $sizes = array_column($normalized_vars, 'size_eu');
        $this->log('info', "  Normalized: " . count($normalized_vars) . " variations, sizes " .
            min($sizes) . "-" . max($sizes));

        return [
            'sku' => $sku,
            'name' => $name,
            'brand' => $brand,
            'model' => $model,
            'gender' => $gender,
            'colorway' => $colorway,
            'release_date' => $release_date,
            'retail_price' => $retail_price,
            'description' => $api_description,
            'category_type' => $product['product_type'] ?? 'sneakers',
            'image_url' => $image_url,
            'gallery_urls' => $gallery_urls,
            'meta_data' => $meta,
            'variations' => $normalized_vars,
        ];
    }

    // =========================================================================
    // Stock Assignment
    // =========================================================================

    /**
     * Determine default stock quantity based on selling price range
     *
     * KicksDB has no real stock data, so we assign virtual stock
     * inversely proportional to price (cheaper items = higher demand).
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

    // =========================================================================
    // Size Extraction
    // =========================================================================

    /**
     * Extract EU size from a variant's sizes[] sub-array
     *
     * The API returns variants with a nested sizes[] array:
     *   "sizes": [
     *     {"size": "EU 35.5", "type": "eu"},
     *     {"size": "US M 3.5", "type": "us m"},
     *     ...
     *   ]
     *
     * Falls back to size_eu direct field (older/simulated responses),
     * then to parsing the title string.
     */
    private function extractEuSizeFromVariant(array $variant): ?string
    {
        // Primary: parse from sizes[] sub-array
        $eu_size = $this->extractSizeByType($variant, 'eu');
        if ($eu_size !== null) {
            // Strip "EU " prefix → "EU 35.5" becomes "35.5"
            return preg_replace('/^EU\s*/i', '', trim($eu_size));
        }

        // Fallback: direct size_eu field (some response formats)
        if (!empty($variant['size_eu'])) {
            return preg_replace('/^EU\s*/i', '', trim($variant['size_eu']));
        }

        // Fallback: parse from title like "Men's US 10 / EU 44"
        if (!empty($variant['title'])) {
            if (preg_match('/EU\s+([\d.]+)/i', $variant['title'], $matches)) {
                return $matches[1];
            }
        }

        // DO NOT fall back to $variant['size'] — that's US size and would be wrong
        return null;
    }

    /**
     * Extract a specific size type from the sizes[] sub-array
     *
     * @param array $variant Variant data
     * @param string $type Size type key (eu, us m, us w, uk, cm, kr)
     * @return string|null The size value or null
     */
    private function extractSizeByType(array $variant, string $type): ?string
    {
        foreach ($variant['sizes'] ?? [] as $size_entry) {
            if (($size_entry['type'] ?? '') === $type) {
                return $size_entry['size'] ?? null;
            }
        }
        return null;
    }

    // =========================================================================
    // Price Extraction
    // =========================================================================

    /**
     * Extract the standard market price from a variant
     *
     * Variants can have multiple prices with different types:
     * - "standard": regular shipping price (what we want)
     * - "express_standard": faster shipping, higher price
     * - "express_expedited": fastest, highest price
     *
     * If a prices[] array exists, filter for "standard" type.
     * Otherwise fall back to lowest_ask / price direct fields.
     */
    private function extractStandardPrice(array $variant): float
    {
        // If variant has a prices[] sub-array, find the "standard" entry
        if (!empty($variant['prices']) && is_array($variant['prices'])) {
            foreach ($variant['prices'] as $price_entry) {
                if (($price_entry['type'] ?? '') === 'standard') {
                    return (float) ($price_entry['price'] ?? 0);
                }
            }
            // No standard found — take lowest non-zero price
            $prices = array_filter(
                array_column($variant['prices'], 'price'),
                fn($p) => $p > 0
            );
            return !empty($prices) ? (float) min($prices) : 0.0;
        }

        // Direct fields (simulated responses, variants endpoint)
        return (float) ($variant['lowest_ask']
            ?? $variant['price']
            ?? $variant['amount']
            ?? 0);
    }

    // =========================================================================
    // Barcode / Identifiers
    // =========================================================================

    /**
     * Extract UPC/barcode from variant identifiers[] or direct field
     */
    private function extractBarcode(array $variant): ?string
    {
        // From identifiers[] array (real API response)
        foreach ($variant['identifiers'] ?? [] as $id_entry) {
            if (($id_entry['identifier_type'] ?? '') === 'UPC') {
                return $id_entry['identifier'] ?? null;
            }
        }

        // Direct field (simulated/variants endpoint)
        return $variant['barcode'] ?? null;
    }

    // =========================================================================
    // Traits Parsing
    // =========================================================================

    /**
     * Parse the traits[] array into a flat key→value map
     *
     * Input:  [{"trait": "Colorway", "value": "White/Black"}, ...]
     * Output: ["Colorway" => "White/Black", ...]
     */
    private function parseTraits(array $traits): array
    {
        $map = [];
        foreach ($traits as $trait) {
            $key = $trait['trait'] ?? null;
            $value = $trait['value'] ?? null;
            if ($key !== null && $value !== null) {
                $map[$key] = $value;
            }
        }
        return $map;
    }

    // =========================================================================
    // Gallery
    // =========================================================================

    /**
     * Build gallery image URLs from product data
     *
     * Uses main gallery images first, then selects a subset of 360 images
     * (every Nth frame for ~6 angles) to avoid uploading 36 nearly-identical photos.
     */
    private function buildGallery(array $product): array
    {
        $urls = [];

        // Main gallery images (usually product shots from different angles)
        foreach ($product['gallery'] ?? [] as $url) {
            if (is_string($url) && !empty($url)) {
                $urls[] = $url;
            }
        }

        // Select 360 images: pick ~6 evenly spaced frames
        $gallery_360 = $product['gallery_360'] ?? [];
        if (!empty($gallery_360)) {
            $total = count($gallery_360);
            $pick_count = min(6, $total);
            $step = max(1, (int) floor($total / $pick_count));
            for ($i = 0; $i < $total && count($urls) < 12; $i += $step) {
                $url = $gallery_360[$i] ?? null;
                if (is_string($url) && !empty($url)) {
                    $urls[] = $url;
                }
            }
        }

        // Remove the primary image if it's duplicated in gallery
        $primary = $product['image'] ?? null;
        if ($primary) {
            $urls = array_filter($urls, fn($u) => $u !== $primary);
        }

        return array_values($urls);
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

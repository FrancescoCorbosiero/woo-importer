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
 * - EU size extraction and normalization
 * - Market price → selling price via PriceCalculator margin
 * - KicksDB-specific metadata (source tracking, colorway, etc.)
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
        $this->market = $pricing['kicksdb_market'] ?? 'US';
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

            // Fetch product (pass market to include variant pricing data)
            $product = $this->kicksdb->getStockXProduct($sku, $this->market);
            if ($product === null) {
                $this->stats['products_not_found']++;
                $this->log('warning', "  Not found in KicksDB: {$sku}");
                continue;
            }

            $this->stats['products_found']++;
            $product_data = $product['data'] ?? $product;

            // Variants are embedded in the product response
            $variants = $product_data['variants'] ?? [];

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
        $image_url = $product['image'] ?? ($product['images'][0] ?? null);

        if (empty($name)) {
            $this->log('warning', "  Product {$sku} has no title, skipping");
            return null;
        }

        $this->log('info', "  {$name} ({$brand}) - {$sku}");

        // Product-level metadata (KicksDB-specific)
        $meta = [
            ['key' => '_kicksdb_id', 'value' => $product['id'] ?? ''],
            ['key' => '_kicksdb_slug', 'value' => $product['slug'] ?? ''],
            ['key' => '_source', 'value' => 'kicksdb'],
        ];
        if ($gender = $product['gender'] ?? '') {
            $meta[] = ['key' => '_gender', 'value' => $gender];
        }
        if ($colorway = $product['colorway'] ?? '') {
            $meta[] = ['key' => '_colorway', 'value' => $colorway];
        }
        if ($release_date = $product['release_date'] ?? '') {
            $meta[] = ['key' => '_release_date', 'value' => $release_date];
        }

        // Normalize variations
        $normalized_vars = [];
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

            // Strip "EU " prefix
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

            // Per-variation metadata
            $var_meta = [
                ['key' => '_kicksdb_lowest_ask', 'value' => (string) $market_price],
                ['key' => '_kicksdb_updated', 'value' => date('c')],
            ];
            if (!empty($variant['size_us'])) {
                $var_meta[] = ['key' => '_size_us', 'value' => $variant['size_us']];
            }
            if (!empty($variant['size_uk'])) {
                $var_meta[] = ['key' => '_size_uk', 'value' => $variant['size_uk']];
            }
            if (!empty($variant['barcode'])) {
                $var_meta[] = ['key' => '_barcode', 'value' => $variant['barcode']];
            }

            $normalized_vars[] = [
                'size_eu' => $size_eu,
                'price' => $this->calculator->calculate($market_price),
                'stock_quantity' => 0,
                'stock_status' => 'instock',
                'meta_data' => $var_meta,
            ];
        }

        if (empty($normalized_vars)) {
            $this->log('warning', "  No valid variations for {$sku} (no prices found)");
            return null;
        }

        $sizes = array_column($normalized_vars, 'size_eu');
        $this->log('info', "  Normalized: " . count($normalized_vars) . " variations, sizes " .
            min($sizes) . "-" . max($sizes));

        return [
            'sku' => $sku,
            'name' => $name,
            'brand' => $brand,
            'category_type' => 'sneakers',
            'image_url' => $image_url,
            'meta_data' => $meta,
            'variations' => $normalized_vars,
        ];
    }

    /**
     * Extract EU size from a KicksDB variant title
     * e.g. "Men's US 10 / EU 44" → "44"
     */
    private function extractEuSize(string $title): ?string
    {
        if (preg_match('/EU\s+([\d.]+)/i', $title, $matches)) {
            return $matches[1];
        }
        return null;
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

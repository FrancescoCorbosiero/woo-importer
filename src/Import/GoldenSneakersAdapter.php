<?php

namespace ResellPiacenza\Import;

/**
 * Golden Sneakers Feed Adapter
 *
 * Fetches the Golden Sneakers API feed and normalizes each product
 * to the common format consumed by WcProductBuilder.
 *
 * Handles:
 * - HTTP fetch with Bearer token auth
 * - Product type detection (sneakers vs clothing by size format)
 * - Field mapping: GS names → normalized format
 * - Price passthrough (GS prices include markup/VAT from API params)
 *
 * @package ResellPiacenza\Import
 */
class GoldenSneakersAdapter implements FeedAdapter
{
    private array $config;
    private $logger;
    private ?int $limit;

    private array $stats = [
        'total' => 0,
        'fetched' => 0,
        'sneakers' => 0,
        'clothing' => 0,
    ];

    /**
     * @param array $config Full config from config.php
     * @param object|null $logger PSR-3 compatible logger
     * @param int|null $limit Max products to yield
     */
    public function __construct(array $config, $logger = null, ?int $limit = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->limit = $limit;
    }

    public function getSourceName(): string
    {
        return 'GoldenSneakers';
    }

    /**
     * Fetch feed and yield normalized products
     */
    public function fetchProducts(): iterable
    {
        $this->log('info', 'Fetching from Golden Sneakers API...');

        $feed = $this->fetchFeed();
        $this->stats['total'] = count($feed);

        if ($this->limit) {
            $feed = array_slice($feed, 0, $this->limit);
            $this->log('info', "Limited to first {$this->limit} products");
        }

        $this->log('info', 'Normalizing...');

        foreach ($feed as $raw) {
            $normalized = $this->normalize($raw);
            if ($normalized === null) {
                continue;
            }

            if ($normalized['category_type'] === 'clothing') {
                $this->stats['clothing']++;
            } else {
                $this->stats['sneakers']++;
            }

            $this->stats['fetched']++;
            yield $normalized;
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    // =========================================================================
    // Normalization
    // =========================================================================

    /**
     * Normalize a GS product to the common format
     */
    private function normalize(array $raw): ?array
    {
        $sku = $raw['sku'] ?? null;
        if (!$sku) {
            return null;
        }

        $sizes = $raw['sizes'] ?? [];

        return [
            'sku' => $sku,
            'name' => $raw['name'] ?? '',
            'brand' => $raw['brand_name'] ?? '',
            'model' => '',
            'gender' => '',
            'colorway' => '',
            'release_date' => '',
            'retail_price' => '',
            'description' => '',
            'category_type' => $this->detectProductType($sizes),
            'image_url' => $raw['image_full_url'] ?? null,
            'gallery_urls' => [],
            'meta_data' => [
                ['key' => '_source', 'value' => 'golden_sneakers'],
            ],
            'variations' => array_map(function ($s) {
                $price = (float) ($s['presented_price'] ?? 0);
                $api_qty = (int) ($s['available_quantity'] ?? 0);

                return [
                    'size_eu' => $s['size_eu'],
                    'price' => $price,
                    'stock_quantity' => $api_qty > 0 ? $api_qty : $this->stockForPrice($price),
                    'stock_status' => 'instock',
                    'meta_data' => [
                        ['key' => '_size_us', 'value' => $s['size_us'] ?? ''],
                        ['key' => '_barcode', 'value' => $s['barcode'] ?? ''],
                    ],
                ];
            }, $sizes),
        ];
    }

    /**
     * Detect product type from size format
     *
     * Numeric EU sizes (36, 37.5, 42) → sneakers
     * Letter sizes (S, M, L, XL) → clothing
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

    // =========================================================================
    // Stock Assignment
    // =========================================================================

    /**
     * Determine default stock quantity based on selling price range
     *
     * Used as fallback when the GS API reports zero available_quantity.
     * Thresholds are tuned for GS final prices (markup/VAT included).
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
    // HTTP Fetch
    // =========================================================================

    /**
     * Fetch Golden Sneakers feed from API
     *
     * @return array Raw products
     * @throws \Exception On HTTP or parse error
     */
    private function fetchFeed(): array
    {
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
            throw new \Exception('CURL Error: ' . $error);
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new \Exception("API returned HTTP {$http_code}");
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }

        $this->log('info', "  " . count($data) . " products fetched from API");
        return $data;
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

<?php

namespace ResellPiacenza\KicksDb;

/**
 * KicksDB API Client
 *
 * Wraps all KicksDB v3 API interactions:
 * - StockX product price lookup (per size/variant)
 * - Webhook registration and management
 * - Product search by SKU/style code
 *
 * Requires KicksDB PRO plan for webhook support.
 *
 * @see https://docs.kicks.dev/introduction
 * @package ResellPiacenza\KicksDb
 */
class Client
{
    private string $api_key;
    private string $base_url;
    private int $timeout;
    private $logger;

    /** @var int Max retries for transient failures */
    private int $max_retries = 3;

    /**
     * @param string $api_key KicksDB API key (Bearer token)
     * @param array $options Optional overrides (base_url, timeout)
     * @param object|null $logger PSR-3 compatible logger
     */
    public function __construct(string $api_key, array $options = [], $logger = null)
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($options['base_url'] ?? 'https://api.kicks.dev/v3', '/');
        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->logger = $logger;
    }

    // =========================================================================
    // StockX Product Endpoints
    // =========================================================================

    /** @var array Default display fields for product endpoints */
    private const DISPLAY_FIELDS = [
        'display[variants]' => 'true',
        'display[traits]' => 'true',
        'display[identifiers]' => 'true',
    ];

    /**
     * Get a StockX product by slug, UUID, or style code (SKU)
     *
     * Tries direct lookup first (slug/UUID), then falls back to
     * search if not found (needed for style codes like DD1503-101).
     *
     * @param string $identifier Slug, UUID, or SKU
     * @param string $market Market code (IT, US, EU, etc.)
     */
    public function getStockXProduct(string $identifier, string $market = 'IT'): ?array
    {
        // Try direct lookup (works for slugs and UUIDs)
        $query = array_merge(self::DISPLAY_FIELDS, ['market' => $market]);
        $result = $this->request('GET', "/stockx/products/{$identifier}", $query, null, true);
        if ($result !== null) {
            return $result;
        }

        // Fallback: search by style code/SKU
        $this->log('debug', "Direct lookup missed for '{$identifier}', trying search...");
        $search = $this->searchStockX($identifier, 5, $market);
        if ($search === null) {
            return null;
        }

        $items = $search['data'] ?? $search;
        if (!is_array($items) || empty($items)) {
            return null;
        }

        // Prefer exact SKU match
        $match = null;
        foreach ($items as $item) {
            if (strcasecmp($item['sku'] ?? '', $identifier) === 0) {
                $match = $item;
                break;
            }
        }

        // Fall back to first result (best relevance match)
        if ($match === null) {
            $match = $items[0] ?? null;
        }

        if ($match === null) {
            return null;
        }

        // Search already includes display fields, so result should be complete.
        // Only re-fetch if we got a different identifier (slug) with full details.
        $product_id = $match['slug'] ?? $match['id'] ?? null;
        if ($product_id !== null && $product_id !== $identifier && empty($match['variants'])) {
            $full = $this->request('GET', "/stockx/products/{$product_id}", $query);
            if ($full !== null) {
                return $full;
            }
        }

        // Wrap in data envelope if search returned a bare item
        if (!isset($match['data'])) {
            return ['data' => $match];
        }
        return $match;
    }

    /**
     * Search StockX products by query string
     *
     * @param string $query Search term (SKU, name, etc.)
     * @param int $limit Max results to return
     * @param string $market Market code for pricing context
     * @param int $page Page number (1-based) for pagination
     */
    public function searchStockX(string $query, int $limit = 10, string $market = 'IT', int $page = 1): ?array
    {
        return $this->request('GET', '/stockx/products', array_merge(
            self::DISPLAY_FIELDS,
            [
                'query' => $query,
                'limit' => $limit,
                'market' => $market,
                'page' => $page,
            ]
        ));
    }

    /**
     * Browse StockX products with lightweight response (no variants/traits/identifiers)
     *
     * Used for discovery/assortment building where we only need
     * SKU, name, brand, rank, and image URL — not full product details.
     *
     * @param string $query Search term (brand name, category, etc.)
     * @param int $limit Results per page
     * @param string $market Market code
     * @param int $page Page number (1-based)
     */
    public function browseProducts(string $query, int $limit = 50, string $market = 'IT', int $page = 1): ?array
    {
        return $this->request('GET', '/stockx/products', [
            'query' => $query,
            'limit' => $limit,
            'market' => $market,
            'page' => $page,
        ]);
    }

    /**
     * Get variant/size data for a product
     *
     * @param string $product_id Product UUID or slug
     * @param string $market Market code
     */
    public function getStockXVariants(string $product_id, string $market = 'IT'): ?array
    {
        return $this->request('GET', "/stockx/products/{$product_id}/variants", [
            'market' => $market,
        ]);
    }

    /**
     * Batch fetch prices for multiple products at once
     *
     * Uses the dedicated POST /stockx/prices endpoint instead of
     * individual per-product calls. Much more efficient for bulk operations.
     *
     * @param array $skus List of SKUs to price
     * @param string $market Market code
     * @param array $product_ids Optional list of product UUIDs
     */
    public function batchGetStockXPrices(array $skus = [], string $market = 'IT', array $product_ids = []): ?array
    {
        $body = ['market' => $market];
        if (!empty($skus)) {
            $body['skus'] = array_values($skus);
        }
        if (!empty($product_ids)) {
            $body['product_ids'] = array_values($product_ids);
        }

        return $this->request('POST', '/stockx/prices', [], $body);
    }

    // =========================================================================
    // Webhook Management
    // =========================================================================

    public function registerWebhook(string $callback_url, array $product_ids, array $events = ['price_change']): ?array
    {
        return $this->request('POST', '/webhooks', [], [
            'url' => $callback_url,
            'products' => $product_ids,
            'events' => $events,
        ]);
    }

    public function listWebhooks(): ?array
    {
        return $this->request('GET', '/webhooks');
    }

    public function getWebhook(string $webhook_id): ?array
    {
        return $this->request('GET', "/webhooks/{$webhook_id}");
    }

    public function updateWebhook(string $webhook_id, array $data): ?array
    {
        return $this->request('PUT', "/webhooks/{$webhook_id}", [], $data);
    }

    public function deleteWebhook(string $webhook_id): bool
    {
        $result = $this->request('DELETE', "/webhooks/{$webhook_id}");
        return $result !== null;
    }

    public function addProductsToWebhook(string $webhook_id, array $product_ids): ?array
    {
        return $this->request('POST', "/webhooks/{$webhook_id}/products", [], [
            'products' => $product_ids,
        ]);
    }

    public function removeProductsFromWebhook(string $webhook_id, array $product_ids): ?array
    {
        return $this->request('DELETE', "/webhooks/{$webhook_id}/products", [], [
            'products' => $product_ids,
        ]);
    }

    // =========================================================================
    // Batch Price Lookup (for reconciliation)
    // =========================================================================

    /**
     * Fetch prices for multiple SKUs using the batch endpoint
     *
     * Returns an associative array keyed by SKU with product + variant data.
     * Uses POST /stockx/prices for efficiency instead of per-SKU lookups.
     *
     * @param array $skus List of SKUs
     * @param string $market Market code
     * @return array<string, array{product_id: string, sku: string, variants: array}>
     */
    public function batchGetPrices(array $skus, string $market = 'IT'): array
    {
        $results = [];

        // Batch endpoint accepts up to ~50 SKUs at a time
        $chunks = array_chunk($skus, 50);
        foreach ($chunks as $chunk) {
            $response = $this->batchGetStockXPrices($chunk, $market);
            if ($response === null) {
                $this->log('warning', 'Batch price request failed for chunk of ' . count($chunk) . ' SKUs');
                continue;
            }

            foreach ($response['data'] ?? [] as $item) {
                $sku = $item['sku'] ?? null;
                if ($sku) {
                    $results[$sku] = $item;
                }
            }

            if (count($chunks) > 1) {
                usleep(200000); // 200ms between batch calls
            }
        }

        return $results;
    }

    // =========================================================================
    // Internal HTTP Client
    // =========================================================================

    private function request(string $method, string $path, array $query = [], ?array $body = null, bool $quiet_404 = false): ?array
    {
        $url = $this->base_url . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;

        while ($attempt < $this->max_retries) {
            $attempt++;

            $ch = curl_init();
            $headers = [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ];

            $curl_opts = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => $headers,
            ];

            if ($method === 'POST' || $method === 'PUT') {
                $curl_opts[CURLOPT_CUSTOMREQUEST] = $method;
                if ($body !== null) {
                    $json_body = json_encode($body);
                    $curl_opts[CURLOPT_POSTFIELDS] = $json_body;
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Content-Length: ' . strlen($json_body);
                    $curl_opts[CURLOPT_HTTPHEADER] = $headers;
                }
            } elseif ($method === 'DELETE') {
                $curl_opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                if ($body !== null) {
                    $json_body = json_encode($body);
                    $curl_opts[CURLOPT_POSTFIELDS] = $json_body;
                    $headers[] = 'Content-Type: application/json';
                    $curl_opts[CURLOPT_HTTPHEADER] = $headers;
                }
            }

            curl_setopt_array($ch, $curl_opts);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->log('warning', "KicksDB request failed (attempt {$attempt}): {$curl_error}");
                if ($attempt < $this->max_retries) {
                    sleep(pow(2, $attempt));
                    continue;
                }
                return null;
            }

            if ($http_code === 429) {
                $this->log('warning', "KicksDB rate limited (attempt {$attempt})");
                if ($attempt < $this->max_retries) {
                    sleep(pow(2, $attempt));
                    continue;
                }
                return null;
            }

            if ($http_code >= 500) {
                $this->log('warning', "KicksDB server error {$http_code} (attempt {$attempt})");
                if ($attempt < $this->max_retries) {
                    sleep(pow(2, $attempt));
                    continue;
                }
                return null;
            }

            if ($http_code >= 400) {
                if ($http_code === 404 && $quiet_404) {
                    $this->log('debug', "KicksDB 404 for {$path} (will try search fallback)");
                } else {
                    $this->log('error', "KicksDB API error {$http_code}: {$response}");
                }
                return null;
            }

            if ($http_code === 204) {
                return [];
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('error', 'KicksDB response JSON error: ' . json_last_error_msg());
                return null;
            }

            return $decoded;
        }

        return null;
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

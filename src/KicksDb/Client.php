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

    /**
     * Get a StockX product by slug, UUID, or style code (SKU)
     *
     * Tries direct lookup first (slug/UUID), then falls back to
     * search if not found (needed for style codes like DD1503-101).
     *
     * @param string $identifier Slug, UUID, or SKU/style code
     * @param string $market Market code (e.g. 'US') — needed to include variant pricing
     */
    public function getStockXProduct(string $identifier, string $market = 'US'): ?array
    {
        $query = ['market' => $market];

        // Try direct lookup (works for slugs and UUIDs)
        $result = $this->request('GET', "/stockx/products/{$identifier}", $query, null, true);
        if ($result !== null) {
            return $result;
        }

        // Fallback: search by style code/SKU
        $this->log('debug', "Direct lookup missed for '{$identifier}', trying search...");
        $search = $this->searchStockX($identifier, 5);
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

        // Fetch full product details by slug/ID (search results may be lighter)
        $product_id = $match['slug'] ?? $match['id'] ?? null;
        if ($product_id !== null && $product_id !== $identifier) {
            $full = $this->request('GET', "/stockx/products/{$product_id}", $query);
            if ($full !== null) {
                return $full;
            }
        }

        // Return search result as-is if full fetch fails
        return $match;
    }

    public function searchStockX(string $query, int $limit = 10): ?array
    {
        return $this->request('GET', '/stockx/products', [
            'query' => $query,
            'limit' => $limit,
        ]);
    }

    public function getStockXVariants(string $product_id, string $market = 'US'): ?array
    {
        return $this->request('GET', "/stockx/products/{$product_id}/variants", [
            'market' => $market,
        ]);
    }

    public function getStockXVariantPrice(string $variant_id, string $market = 'US'): ?array
    {
        return $this->request('GET', "/stockx/variants/{$variant_id}", [
            'market' => $market,
        ]);
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

    public function batchGetPrices(array $skus, string $market = 'US'): array
    {
        $results = [];

        foreach ($skus as $sku) {
            $product = $this->getStockXProduct($sku, $market);

            if ($product === null) {
                $this->log('warning', "No KicksDB data for SKU: {$sku}");
                continue;
            }

            $product_data = $product['data'] ?? $product;
            $variants = $product_data['variants'] ?? [];

            if (!empty($variants)) {
                $results[$sku] = [
                    'product' => $product_data,
                    'variants' => $variants,
                ];
            } else {
                $this->log('warning', "No variants in KicksDB response for SKU: {$sku}");
            }

            usleep(200000); // 200ms between requests
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

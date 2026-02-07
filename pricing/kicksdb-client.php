<?php
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
 * @package ResellPiacenza\Pricing
 */

class KicksDbClient
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
     * Get StockX product by slug or style ID (SKU)
     *
     * @param string $identifier Product slug or style ID (e.g. "DD1873-102")
     * @return array|null Product data with variants/prices, null on failure
     */
    public function getStockXProduct(string $identifier): ?array
    {
        return $this->request('GET', "/stockx/products/{$identifier}");
    }

    /**
     * Search StockX products
     *
     * @param string $query Search term (SKU, name, etc.)
     * @param int $limit Max results
     * @return array|null Array of products
     */
    public function searchStockX(string $query, int $limit = 10): ?array
    {
        return $this->request('GET', '/stockx/products', [
            'query' => $query,
            'limit' => $limit,
        ]);
    }

    /**
     * Get StockX product variants (sizes) with current market prices
     *
     * @param string $product_id KicksDB product ID or slug
     * @param string $market Market code (default: US)
     * @return array|null Variant array with size + price data
     */
    public function getStockXVariants(string $product_id, string $market = 'US'): ?array
    {
        return $this->request('GET', "/stockx/products/{$product_id}/variants", [
            'market' => $market,
        ]);
    }

    /**
     * Get StockX price for a specific variant
     *
     * @param string $variant_id Variant ID
     * @param string $market Market code
     * @return array|null Price data (lowest_ask, highest_bid, last_sale, etc.)
     */
    public function getStockXVariantPrice(string $variant_id, string $market = 'US'): ?array
    {
        return $this->request('GET', "/stockx/variants/{$variant_id}", [
            'market' => $market,
        ]);
    }

    // =========================================================================
    // Webhook Management
    // =========================================================================

    /**
     * Register a webhook for price tracking
     *
     * @param string $callback_url Your endpoint URL
     * @param array $product_ids KicksDB product IDs to track
     * @param array $events Event types (e.g. ['price_change', 'out_of_stock'])
     * @return array|null Webhook registration response
     */
    public function registerWebhook(string $callback_url, array $product_ids, array $events = ['price_change']): ?array
    {
        return $this->request('POST', '/webhooks', [], [
            'url' => $callback_url,
            'products' => $product_ids,
            'events' => $events,
        ]);
    }

    /**
     * List active webhooks
     *
     * @return array|null Webhook list
     */
    public function listWebhooks(): ?array
    {
        return $this->request('GET', '/webhooks');
    }

    /**
     * Get webhook details
     *
     * @param string $webhook_id Webhook ID
     * @return array|null Webhook data
     */
    public function getWebhook(string $webhook_id): ?array
    {
        return $this->request('GET', "/webhooks/{$webhook_id}");
    }

    /**
     * Update webhook (add/remove tracked products)
     *
     * @param string $webhook_id Webhook ID
     * @param array $data Update payload
     * @return array|null Updated webhook
     */
    public function updateWebhook(string $webhook_id, array $data): ?array
    {
        return $this->request('PUT', "/webhooks/{$webhook_id}", [], $data);
    }

    /**
     * Delete a webhook
     *
     * @param string $webhook_id Webhook ID
     * @return bool Success
     */
    public function deleteWebhook(string $webhook_id): bool
    {
        $result = $this->request('DELETE', "/webhooks/{$webhook_id}");
        return $result !== null;
    }

    /**
     * Add products to an existing webhook
     *
     * @param string $webhook_id Webhook ID
     * @param array $product_ids Product IDs to add
     * @return array|null Updated webhook
     */
    public function addProductsToWebhook(string $webhook_id, array $product_ids): ?array
    {
        return $this->request('POST', "/webhooks/{$webhook_id}/products", [], [
            'products' => $product_ids,
        ]);
    }

    /**
     * Remove products from a webhook
     *
     * @param string $webhook_id Webhook ID
     * @param array $product_ids Product IDs to remove
     * @return array|null Updated webhook
     */
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
     * Fetch current prices for multiple SKUs
     *
     * Performs sequential lookups (KicksDB doesn't have a batch endpoint).
     * Use this for reconciliation, not real-time.
     *
     * @param array $skus Array of SKUs/style codes
     * @param string $market Market code
     * @return array SKU => variant prices array
     */
    public function batchGetPrices(array $skus, string $market = 'US'): array
    {
        $results = [];

        foreach ($skus as $sku) {
            $product = $this->getStockXProduct($sku);

            if ($product === null) {
                $this->log('warning', "No KicksDB data for SKU: {$sku}");
                continue;
            }

            $product_id = $product['id'] ?? $sku;
            $variants = $this->getStockXVariants($product_id, $market);

            if ($variants !== null) {
                $results[$sku] = [
                    'product' => $product,
                    'variants' => $variants,
                ];
            }

            // Respect rate limits
            usleep(200000); // 200ms between requests
        }

        return $results;
    }

    // =========================================================================
    // Internal HTTP Client
    // =========================================================================

    /**
     * Make an API request with retry logic
     *
     * @param string $method HTTP method
     * @param string $path API path (appended to base_url)
     * @param array $query Query parameters
     * @param array|null $body Request body (JSON)
     * @return array|null Decoded response or null on failure
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): ?array
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

            // Network error - retry
            if ($response === false) {
                $this->log('warning', "KicksDB request failed (attempt {$attempt}): {$curl_error}");
                if ($attempt < $this->max_retries) {
                    $delay = pow(2, $attempt);
                    sleep($delay);
                    continue;
                }
                return null;
            }

            // Rate limited - retry with backoff
            if ($http_code === 429) {
                $this->log('warning', "KicksDB rate limited (attempt {$attempt})");
                if ($attempt < $this->max_retries) {
                    $delay = pow(2, $attempt);
                    sleep($delay);
                    continue;
                }
                return null;
            }

            // Server error - retry
            if ($http_code >= 500) {
                $this->log('warning', "KicksDB server error {$http_code} (attempt {$attempt})");
                if ($attempt < $this->max_retries) {
                    $delay = pow(2, $attempt);
                    sleep($delay);
                    continue;
                }
                return null;
            }

            // Client error - don't retry
            if ($http_code >= 400) {
                $this->log('error', "KicksDB API error {$http_code}: {$response}");
                return null;
            }

            // Success (2xx) or 204 No Content
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

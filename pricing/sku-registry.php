<?php
/**
 * SKU Registry Manager
 *
 * Maintains the mapping between WooCommerce product SKUs and KicksDB
 * tracked products. Handles:
 * - Fetching all SKUs from WooCommerce inventory
 * - Registering/unregistering SKUs with KicksDB webhook tracking
 * - Persisting the registry to disk for fast access
 * - Diffing to detect new/removed products
 *
 * @package ResellPiacenza\Pricing
 */

require_once __DIR__ . '/kicksdb-client.php';

class SkuRegistry
{
    private $wc_client;
    private KicksDbClient $kicksdb;
    private $logger;

    /** @var string Path to persisted registry file */
    private string $registry_file;

    /** @var string|null Active webhook ID in KicksDB */
    private ?string $webhook_id;

    /** @var string Callback URL for KicksDB webhooks */
    private string $callback_url;

    /**
     * @param object $wc_client WooCommerce REST API client
     * @param KicksDbClient $kicksdb KicksDB client
     * @param array $config Registry config (webhook_id, callback_url, registry_file)
     * @param object|null $logger PSR-3 logger
     */
    public function __construct($wc_client, KicksDbClient $kicksdb, array $config, $logger = null)
    {
        $this->wc_client = $wc_client;
        $this->kicksdb = $kicksdb;
        $this->logger = $logger;

        $this->webhook_id = $config['webhook_id'] ?? null;
        $this->callback_url = $config['callback_url'] ?? '';
        $this->registry_file = $config['registry_file'] ?? __DIR__ . '/../data/sku-registry.json';
    }

    // =========================================================================
    // WooCommerce SKU Extraction
    // =========================================================================

    /**
     * Fetch all product SKUs from WooCommerce
     *
     * @return array ['SKU-123' => ['id' => 456, 'name' => 'Product Name'], ...]
     */
    public function fetchWcSkus(): array
    {
        $skus = [];
        $page = 1;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'publish',
                    'type' => 'variable',
                ]);

                foreach ($products as $product) {
                    if (!empty($product->sku)) {
                        $skus[$product->sku] = [
                            'id' => $product->id,
                            'name' => $product->name ?? '',
                        ];
                    }
                }
                $page++;
            } catch (\Exception $e) {
                $this->log('error', "Error fetching WC products page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === 100);

        $this->log('info', "Fetched " . count($skus) . " SKUs from WooCommerce");
        return $skus;
    }

    // =========================================================================
    // Registry Persistence
    // =========================================================================

    /**
     * Load persisted registry
     *
     * @return array ['skus' => [...], 'webhook_id' => '...', 'last_sync' => '...']
     */
    public function loadRegistry(): array
    {
        if (!file_exists($this->registry_file)) {
            return ['skus' => [], 'webhook_id' => null, 'last_sync' => null];
        }

        $data = json_decode(file_get_contents($this->registry_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'Registry file corrupt: ' . json_last_error_msg());
            return ['skus' => [], 'webhook_id' => null, 'last_sync' => null];
        }

        // Restore webhook_id from registry if not set in config
        if ($this->webhook_id === null && !empty($data['webhook_id'])) {
            $this->webhook_id = $data['webhook_id'];
        }

        return $data;
    }

    /**
     * Save registry to disk
     */
    public function saveRegistry(array $registry): void
    {
        $dir = dirname($this->registry_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $registry['last_sync'] = date('c');
        $registry['webhook_id'] = $this->webhook_id;

        file_put_contents(
            $this->registry_file,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    // =========================================================================
    // Sync Logic
    // =========================================================================

    /**
     * Full sync: compare WC inventory with registered SKUs, update KicksDB webhook
     *
     * @return array ['added' => [...], 'removed' => [...], 'unchanged' => int]
     */
    public function sync(): array
    {
        $this->log('info', 'Starting SKU registry sync...');

        $registry = $this->loadRegistry();
        $registered_skus = $registry['skus'] ?? [];
        $wc_skus = $this->fetchWcSkus();

        // Diff
        $new_skus = array_diff_key($wc_skus, $registered_skus);
        $removed_skus = array_diff_key($registered_skus, $wc_skus);
        $unchanged = count($wc_skus) - count($new_skus);

        $this->log('info', "  New: " . count($new_skus) . ", Removed: " . count($removed_skus) . ", Unchanged: {$unchanged}");

        // Register new SKUs with KicksDB
        if (!empty($new_skus)) {
            $this->registerSkus(array_keys($new_skus));
        }

        // Unregister removed SKUs from KicksDB
        if (!empty($removed_skus)) {
            $this->unregisterSkus(array_keys($removed_skus));
        }

        // Update registry
        $registry['skus'] = $wc_skus;
        $this->saveRegistry($registry);

        return [
            'added' => array_keys($new_skus),
            'removed' => array_keys($removed_skus),
            'unchanged' => $unchanged,
            'total' => count($wc_skus),
        ];
    }

    /**
     * Register a single SKU (called by WC product listener on product.created)
     *
     * @param string $sku Product SKU
     * @param array $product_data Optional product metadata
     * @return bool Success
     */
    public function registerSingleSku(string $sku, array $product_data = []): bool
    {
        $this->log('info', "Registering new SKU: {$sku}");

        $registry = $this->loadRegistry();

        // Add to registry
        $registry['skus'][$sku] = $product_data;
        $this->saveRegistry($registry);

        // Register with KicksDB
        return $this->registerSkus([$sku]);
    }

    /**
     * Unregister a single SKU (called on product.deleted)
     *
     * @param string $sku Product SKU
     * @return bool Success
     */
    public function unregisterSingleSku(string $sku): bool
    {
        $this->log('info', "Unregistering SKU: {$sku}");

        $registry = $this->loadRegistry();
        unset($registry['skus'][$sku]);
        $this->saveRegistry($registry);

        return $this->unregisterSkus([$sku]);
    }

    // =========================================================================
    // KicksDB Webhook Management
    // =========================================================================

    /**
     * Register SKUs with KicksDB webhook tracking
     *
     * If no webhook exists yet, creates one. Otherwise adds products to existing webhook.
     */
    private function registerSkus(array $skus): bool
    {
        if (empty($skus)) {
            return true;
        }

        $this->log('info', "  Registering " . count($skus) . " SKUs with KicksDB...");

        // Resolve KicksDB product IDs from SKUs
        $product_ids = $this->resolveKicksDbIds($skus);

        if (empty($product_ids)) {
            $this->log('warning', "  No KicksDB products found for any SKUs");
            return false;
        }

        try {
            if ($this->webhook_id === null) {
                // Create new webhook
                $result = $this->kicksdb->registerWebhook(
                    $this->callback_url,
                    $product_ids,
                    ['price_change']
                );

                if ($result !== null && isset($result['id'])) {
                    $this->webhook_id = $result['id'];
                    $this->log('info', "  Created KicksDB webhook: {$this->webhook_id}");
                    return true;
                }

                $this->log('error', "  Failed to create KicksDB webhook");
                return false;
            }

            // Add to existing webhook
            $result = $this->kicksdb->addProductsToWebhook($this->webhook_id, $product_ids);
            if ($result !== null) {
                $this->log('info', "  Added " . count($product_ids) . " products to webhook {$this->webhook_id}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->log('error', "KicksDB webhook registration error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unregister SKUs from KicksDB webhook tracking
     */
    private function unregisterSkus(array $skus): bool
    {
        if (empty($skus) || $this->webhook_id === null) {
            return true;
        }

        $product_ids = $this->resolveKicksDbIds($skus);

        if (empty($product_ids)) {
            return true;
        }

        try {
            $result = $this->kicksdb->removeProductsFromWebhook($this->webhook_id, $product_ids);
            if ($result !== null) {
                $this->log('info', "  Removed " . count($product_ids) . " products from webhook {$this->webhook_id}");
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->log('error', "KicksDB webhook unregister error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve SKUs to KicksDB product IDs
     *
     * Queries KicksDB for each SKU and extracts the product ID.
     * Results are cached in the registry for future lookups.
     *
     * @param array $skus SKUs/style codes
     * @return array KicksDB product IDs
     */
    private function resolveKicksDbIds(array $skus): array
    {
        $ids = [];

        foreach ($skus as $sku) {
            $product = $this->kicksdb->getStockXProduct($sku);

            if ($product !== null && isset($product['id'])) {
                $ids[] = $product['id'];
            } else {
                $this->log('warning', "  SKU not found in KicksDB: {$sku}");
            }

            // Respect rate limits
            usleep(200000);
        }

        $this->log('info', "  Resolved " . count($ids) . "/" . count($skus) . " SKUs to KicksDB IDs");
        return $ids;
    }

    // =========================================================================
    // Getters
    // =========================================================================

    /**
     * Get currently registered SKUs
     */
    public function getRegisteredSkus(): array
    {
        $registry = $this->loadRegistry();
        return $registry['skus'] ?? [];
    }

    /**
     * Get active webhook ID
     */
    public function getWebhookId(): ?string
    {
        return $this->webhook_id;
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

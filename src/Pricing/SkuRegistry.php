<?php

namespace ResellPiacenza\Pricing;

use ResellPiacenza\KicksDb\Client as KicksDbClient;

/**
 * SKU Registry Manager
 *
 * Maintains the mapping between WooCommerce product SKUs and KicksDB
 * tracked products.
 *
 * @package ResellPiacenza\Pricing
 */
class SkuRegistry
{
    private $wc_client;
    private KicksDbClient $kicksdb;
    private $logger;

    private string $registry_file;
    private ?string $webhook_id;
    private string $callback_url;

    public function __construct($wc_client, KicksDbClient $kicksdb, array $config, $logger = null)
    {
        $this->wc_client = $wc_client;
        $this->kicksdb = $kicksdb;
        $this->logger = $logger;

        $this->webhook_id = $config['webhook_id'] ?? null;
        $this->callback_url = $config['callback_url'] ?? '';
        $this->registry_file = $config['registry_file'] ?? dirname(__DIR__, 2) . '/data/sku-registry.json';
    }

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

        if ($this->webhook_id === null && !empty($data['webhook_id'])) {
            $this->webhook_id = $data['webhook_id'];
        }

        return $data;
    }

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

    public function sync(): array
    {
        $this->log('info', 'Starting SKU registry sync...');

        $registry = $this->loadRegistry();
        $registered_skus = $registry['skus'] ?? [];
        $wc_skus = $this->fetchWcSkus();

        $new_skus = array_diff_key($wc_skus, $registered_skus);
        $removed_skus = array_diff_key($registered_skus, $wc_skus);
        $unchanged = count($wc_skus) - count($new_skus);

        $this->log('info', "  New: " . count($new_skus) . ", Removed: " . count($removed_skus) . ", Unchanged: {$unchanged}");

        if (!empty($new_skus)) {
            $this->registerSkus(array_keys($new_skus));
        }

        if (!empty($removed_skus)) {
            $this->unregisterSkus(array_keys($removed_skus));
        }

        $registry['skus'] = $wc_skus;
        $this->saveRegistry($registry);

        return [
            'added' => array_keys($new_skus),
            'removed' => array_keys($removed_skus),
            'unchanged' => $unchanged,
            'total' => count($wc_skus),
        ];
    }

    public function registerSingleSku(string $sku, array $product_data = []): bool
    {
        $this->log('info', "Registering new SKU: {$sku}");

        $registry = $this->loadRegistry();
        $registry['skus'][$sku] = $product_data;
        $this->saveRegistry($registry);

        return $this->registerSkus([$sku]);
    }

    public function unregisterSingleSku(string $sku): bool
    {
        $this->log('info', "Unregistering SKU: {$sku}");

        $registry = $this->loadRegistry();
        unset($registry['skus'][$sku]);
        $this->saveRegistry($registry);

        return $this->unregisterSkus([$sku]);
    }

    private function registerSkus(array $skus): bool
    {
        if (empty($skus)) {
            return true;
        }

        $this->log('info', "  Registering " . count($skus) . " SKUs with KicksDB...");

        $product_ids = $this->resolveKicksDbIds($skus);

        if (empty($product_ids)) {
            $this->log('warning', "  No KicksDB products found for any SKUs");
            return false;
        }

        try {
            if ($this->webhook_id === null) {
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

            usleep(200000);
        }

        $this->log('info', "  Resolved " . count($ids) . "/" . count($skus) . " SKUs to KicksDB IDs");
        return $ids;
    }

    public function getRegisteredSkus(): array
    {
        $registry = $this->loadRegistry();
        return $registry['skus'] ?? [];
    }

    public function getWebhookId(): ?string
    {
        return $this->webhook_id;
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

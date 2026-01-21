<?php

declare(strict_types=1);

namespace WooImporter\Services;

use WooImporter\Database\Database;
use WooImporter\Models\Product;
use WooImporter\Models\ProductVariation;
use WooImporter\Models\WcProductMap;
use WooImporter\Models\WcVariationMap;
use WooImporter\Models\WebhookQueue;
use WooImporter\Models\SyncLog;
use Monolog\Logger;

/**
 * WebhookHandler - Handles WooCommerce → Database synchronization
 *
 * Processes incoming webhooks from WooCommerce and updates the database.
 * This allows WooCommerce to be the master for inventory changes.
 */
class WebhookHandler
{
    private Database $db;
    private Product $productModel;
    private ProductVariation $variationModel;
    private WcProductMap $productMap;
    private WcVariationMap $variationMap;
    private WebhookQueue $webhookQueue;
    private SyncLog $syncLog;
    private Logger $logger;
    private array $config;

    // Supported webhook topics
    private const SUPPORTED_TOPICS = [
        'product.created',
        'product.updated',
        'product.deleted',
        'product.restored',
    ];

    public function __construct(array $config, Logger $logger, ?Database $db = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->db = $db ?? Database::getInstance();

        $this->productModel = new Product($this->db);
        $this->variationModel = new ProductVariation($this->db);
        $this->productMap = new WcProductMap($this->db);
        $this->variationMap = new WcVariationMap($this->db);
        $this->webhookQueue = new WebhookQueue($this->db);
        $this->syncLog = new SyncLog($this->db);
    }

    /**
     * Receive and queue a webhook for processing
     *
     * @param string $topic Webhook topic (e.g., 'product.updated')
     * @param array $payload Webhook payload data
     * @param string|null $webhookId Optional webhook delivery ID for deduplication
     * @return array Response with success status
     */
    public function receive(string $topic, array $payload, ?string $webhookId = null): array
    {
        $this->logger->info("Webhook received: {$topic}");

        // Validate topic
        if (!in_array($topic, self::SUPPORTED_TOPICS)) {
            $this->logger->warning("Unsupported webhook topic: {$topic}");
            return ['success' => false, 'error' => 'Unsupported topic'];
        }

        // Check for duplicate
        if ($webhookId && $this->webhookQueue->isDuplicate($webhookId)) {
            $this->logger->debug("Duplicate webhook ignored: {$webhookId}");
            return ['success' => true, 'message' => 'Duplicate ignored'];
        }

        // Extract resource info
        $resource = $this->extractResource($topic);
        $resourceId = $payload['id'] ?? 0;

        if (!$resourceId) {
            $this->logger->error("Invalid webhook payload: missing ID");
            return ['success' => false, 'error' => 'Missing resource ID'];
        }

        // Queue for processing
        $queueId = $this->webhookQueue->enqueue(
            $topic,
            $resource,
            (int) $resourceId,
            $payload,
            $webhookId
        );

        $this->logger->info("Webhook queued: ID {$queueId}");

        // Process immediately (synchronous mode)
        // For high-volume, this could be changed to async processing
        $result = $this->processQueuedWebhook($queueId);

        return $result;
    }

    /**
     * Process a queued webhook
     */
    public function processQueuedWebhook(int $queueId): array
    {
        $webhook = $this->webhookQueue->find($queueId);
        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook not found'];
        }

        if ($webhook['status'] !== 'pending') {
            return ['success' => true, 'message' => 'Already processed'];
        }

        $this->webhookQueue->markProcessing($queueId);

        try {
            $payload = json_decode($webhook['payload'], true);
            $topic = $webhook['topic'];

            $this->db->beginTransaction();

            switch ($topic) {
                case 'product.created':
                    $this->handleProductCreated($payload);
                    break;
                case 'product.updated':
                    $this->handleProductUpdated($payload);
                    break;
                case 'product.deleted':
                    $this->handleProductDeleted($payload);
                    break;
                case 'product.restored':
                    $this->handleProductRestored($payload);
                    break;
            }

            $this->db->commit();
            $this->webhookQueue->markCompleted($queueId);

            return ['success' => true];

        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->webhookQueue->markFailed($queueId, $e->getMessage());
            $this->logger->error("Webhook processing failed: " . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle product.created webhook
     *
     * When a product is created in WooCommerce that we don't know about,
     * we can optionally add it to our database.
     */
    private function handleProductCreated(array $payload): void
    {
        $wcProductId = (int) $payload['id'];
        $sku = $payload['sku'] ?? null;

        $this->logger->info("Processing product.created for WC ID: {$wcProductId}, SKU: {$sku}");

        // Check if we already have a mapping
        $existingMap = $this->productMap->findByWcProductId($wcProductId);
        if ($existingMap) {
            $this->logger->debug("Product already mapped, skipping");
            return;
        }

        // Check if product exists by SKU
        if ($sku) {
            $existingProduct = $this->productModel->findBySku($sku);
            if ($existingProduct) {
                // Create mapping for existing product
                $this->productMap->createMapping(
                    (int) $existingProduct['id'],
                    $wcProductId,
                    $payload['type'] ?? 'variable'
                );
                $this->logger->info("Mapped existing product {$sku} to WC ID {$wcProductId}");
                return;
            }
        }

        // Create new product from WooCommerce data (if enabled)
        if ($this->config['webhooks']['create_from_wc'] ?? false) {
            $this->createProductFromWooCommerce($payload);
        } else {
            $this->logger->debug("Product creation from WC disabled, ignoring");
        }
    }

    /**
     * Handle product.updated webhook
     *
     * This is the main handler for syncing WooCommerce changes back to database.
     */
    private function handleProductUpdated(array $payload): void
    {
        $wcProductId = (int) $payload['id'];
        $sku = $payload['sku'] ?? null;

        $this->logger->info("Processing product.updated for WC ID: {$wcProductId}, SKU: {$sku}");

        // Find local product by WC mapping
        $mapping = $this->productMap->findByWcProductId($wcProductId);

        if (!$mapping) {
            // Try to find by SKU and create mapping
            if ($sku) {
                $product = $this->productModel->findBySku($sku);
                if ($product) {
                    $this->productMap->createMapping(
                        (int) $product['id'],
                        $wcProductId,
                        $payload['type'] ?? 'variable'
                    );
                    $mapping = ['product_id' => $product['id']];
                    $this->logger->info("Created mapping for SKU {$sku}");
                }
            }
        }

        if (!$mapping) {
            $this->logger->debug("No local product found for WC ID {$wcProductId}");
            return;
        }

        $productId = (int) $mapping['product_id'];

        // Update product status based on WC status
        $wcStatus = $payload['status'] ?? 'publish';
        $localStatus = $wcStatus === 'publish' ? 'active' : 'inactive';

        $changes = [];

        // Check for status change
        $currentProduct = $this->productModel->find($productId);
        if ($currentProduct && $currentProduct['status'] !== $localStatus) {
            $changes['status'] = ['from' => $currentProduct['status'], 'to' => $localStatus];
        }

        // Update product
        $this->productModel->update($productId, [
            'status' => $localStatus,
            'last_woo_sync' => date('Y-m-d H:i:s'),
        ]);

        // Process variations if present
        if (!empty($payload['variations']) || ($payload['type'] ?? '') === 'variable') {
            $this->syncVariationsFromWooCommerce($productId, $wcProductId, $payload);
        }

        // Log the update
        $this->syncLog->logWebhook(
            SyncLog::ACTION_UPDATE,
            SyncLog::ENTITY_PRODUCT,
            $wcProductId,
            $productId,
            $sku,
            $changes,
            'Product updated via webhook'
        );
    }

    /**
     * Handle product.deleted webhook
     */
    private function handleProductDeleted(array $payload): void
    {
        $wcProductId = (int) $payload['id'];

        $this->logger->info("Processing product.deleted for WC ID: {$wcProductId}");

        $mapping = $this->productMap->findByWcProductId($wcProductId);
        if (!$mapping) {
            $this->logger->debug("No local product found for deleted WC ID {$wcProductId}");
            return;
        }

        $productId = (int) $mapping['product_id'];
        $product = $this->productModel->find($productId);
        $sku = $product['sku'] ?? null;

        // Mark as deleted in database (soft delete)
        $this->productModel->update($productId, [
            'status' => 'deleted',
            'last_woo_sync' => date('Y-m-d H:i:s'),
        ]);

        // Zero out all stock
        $this->variationModel->markOutOfStock($productId);

        // Remove mappings
        $this->productMap->deleteByProductId($productId);

        // Log
        $this->syncLog->logWebhook(
            SyncLog::ACTION_DELETE,
            SyncLog::ENTITY_PRODUCT,
            $wcProductId,
            $productId,
            $sku,
            null,
            'Product deleted via webhook'
        );

        $this->logger->info("Product {$sku} marked as deleted");
    }

    /**
     * Handle product.restored webhook
     */
    private function handleProductRestored(array $payload): void
    {
        $wcProductId = (int) $payload['id'];
        $sku = $payload['sku'] ?? null;

        $this->logger->info("Processing product.restored for WC ID: {$wcProductId}");

        // Try to find product by SKU
        if ($sku) {
            $product = $this->productModel->findBySku($sku);
            if ($product) {
                $this->productModel->update((int) $product['id'], [
                    'status' => 'active',
                    'last_woo_sync' => date('Y-m-d H:i:s'),
                ]);

                // Recreate mapping if needed
                $this->productMap->createMapping(
                    (int) $product['id'],
                    $wcProductId,
                    $payload['type'] ?? 'variable'
                );

                $this->syncLog->logWebhook(
                    SyncLog::ACTION_UPDATE,
                    SyncLog::ENTITY_PRODUCT,
                    $wcProductId,
                    (int) $product['id'],
                    $sku,
                    ['status' => 'restored'],
                    'Product restored via webhook'
                );

                $this->logger->info("Product {$sku} restored");
            }
        }
    }

    /**
     * Sync variations from WooCommerce to database
     */
    private function syncVariationsFromWooCommerce(int $productId, int $wcProductId, array $payload): void
    {
        // If variations are included in payload (for some WC configurations)
        $variations = $payload['meta_data'] ?? [];

        // For variable products, we need to fetch variations separately
        // This is handled by a separate webhook or can be triggered manually
        $this->logger->debug("Variation sync triggered for product {$productId}");

        // The actual variation data comes from product.updated with stock changes
        // or from the stock_quantity in line_items during order processing
    }

    /**
     * Create product in database from WooCommerce data
     */
    private function createProductFromWooCommerce(array $payload): void
    {
        $sku = $payload['sku'] ?? null;
        if (!$sku) {
            $this->logger->warning("Cannot create product without SKU");
            return;
        }

        $productId = $this->productModel->create([
            'sku' => $sku,
            'name' => $payload['name'] ?? $sku,
            'brand_name' => $this->extractBrandFromPayload($payload),
            'status' => $payload['status'] === 'publish' ? 'active' : 'inactive',
            'source' => 'woocommerce',
            'last_woo_sync' => date('Y-m-d H:i:s'),
        ]);

        // Create mapping
        $this->productMap->createMapping(
            $productId,
            (int) $payload['id'],
            $payload['type'] ?? 'simple'
        );

        $this->syncLog->logWebhook(
            SyncLog::ACTION_CREATE,
            SyncLog::ENTITY_PRODUCT,
            (int) $payload['id'],
            $productId,
            $sku,
            null,
            'Product created from WooCommerce webhook'
        );

        $this->logger->info("Created product {$sku} from WooCommerce");
    }

    /**
     * Handle stock change webhook (for variation stock updates)
     */
    public function handleStockChange(int $wcProductId, int $wcVariationId, int $newQuantity): void
    {
        $this->logger->info("Stock change: WC variation {$wcVariationId} = {$newQuantity}");

        $varMapping = $this->variationMap->findByWcVariationId($wcVariationId);
        if (!$varMapping) {
            $this->logger->debug("No mapping found for WC variation {$wcVariationId}");
            return;
        }

        $variationId = (int) $varMapping['variation_id'];
        $variation = $this->variationModel->find($variationId);

        if ($variation) {
            $oldQuantity = $variation['stock_quantity'];

            $this->variationModel->updateStock($variationId, $newQuantity);

            $this->syncLog->logWebhook(
                SyncLog::ACTION_UPDATE,
                SyncLog::ENTITY_VARIATION,
                $wcVariationId,
                $variationId,
                $variation['sku'],
                ['stock' => ['from' => $oldQuantity, 'to' => $newQuantity]],
                'Stock updated via webhook'
            );

            $this->logger->info("Updated stock for {$variation['sku']}: {$oldQuantity} -> {$newQuantity}");
        }
    }

    /**
     * Process pending webhooks in queue
     */
    public function processQueue(int $limit = 50): array
    {
        $pending = $this->webhookQueue->getPending($limit);
        $this->logger->info("Processing " . count($pending) . " pending webhooks");

        $results = [
            'processed' => 0,
            'failed' => 0,
        ];

        foreach ($pending as $webhook) {
            $result = $this->processQueuedWebhook((int) $webhook['id']);
            if ($result['success']) {
                $results['processed']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Extract resource type from topic
     */
    private function extractResource(string $topic): string
    {
        return explode('.', $topic)[0] ?? 'unknown';
    }

    /**
     * Extract brand from WooCommerce payload
     */
    private function extractBrandFromPayload(array $payload): ?string
    {
        // Check attributes for brand
        $attributes = $payload['attributes'] ?? [];
        foreach ($attributes as $attr) {
            $name = strtolower($attr['name'] ?? '');
            if (in_array($name, ['marca', 'brand', 'pa_marca', 'pa_brand'])) {
                return $attr['options'][0] ?? null;
            }
        }
        return null;
    }

    /**
     * Verify webhook signature (HMAC)
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        return hash_equals($expected, $signature);
    }
}

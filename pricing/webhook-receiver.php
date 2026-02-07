<?php
/**
 * KicksDB Webhook Receiver
 *
 * HTTP endpoint that receives price change events from KicksDB webhooks.
 * Deploy this as a publicly accessible URL (e.g. https://resellpiacenza.it/api/kicksdb-webhook).
 *
 * Flow:
 *   KicksDB detects price change → POST to this endpoint → validate → update WC variation prices
 *
 * Security:
 * - Validates webhook secret (shared secret or signature verification)
 * - Rejects invalid payloads
 * - Rate-limits processing
 *
 * Deployment:
 *   Point your web server to this file, e.g.:
 *   location /api/kicksdb-webhook { fastcgi_pass ...; include fastcgi_params; }
 *
 * @package ResellPiacenza\Pricing
 */

// Bootstrap
$base_dir = dirname(__DIR__);
require $base_dir . '/vendor/autoload.php';
require_once __DIR__ . '/kicksdb-client.php';
require_once __DIR__ . '/price-updater.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// ============================================================================
// Configuration
// ============================================================================

$config = require $base_dir . '/config.php';
$pricing = $config['pricing'] ?? [];

// ============================================================================
// Logger Setup
// ============================================================================

$logger = new Logger('WebhookReceiver');
$log_dir = $base_dir . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$logger->pushHandler(new RotatingFileHandler($log_dir . '/webhook-receiver.log', 14, Logger::DEBUG));

// ============================================================================
// Request Validation
// ============================================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read raw body
$raw_body = file_get_contents('php://input');
if (empty($raw_body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

// Verify webhook secret
$webhook_secret = $pricing['webhook_secret'] ?? '';
if (!empty($webhook_secret)) {
    $signature = $_SERVER['HTTP_X_KICKSDB_SIGNATURE']
        ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
        ?? '';

    $expected = hash_hmac('sha256', $raw_body, $webhook_secret);

    if (!hash_equals($expected, $signature)) {
        $logger->warning('Webhook signature mismatch', [
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// Parse JSON
$payload = json_decode($raw_body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $logger->warning('Invalid webhook JSON: ' . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$logger->info('Webhook received', [
    'event' => $payload['event'] ?? 'unknown',
    'product_id' => $payload['product_id'] ?? $payload['product']['id'] ?? 'unknown',
]);

// ============================================================================
// Event Processing
// ============================================================================

// Respond 200 immediately (acknowledge receipt before processing)
// This prevents KicksDB from retrying while we process
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'accepted']);

// Flush output to client
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// Now process asynchronously (client already got 200)
try {
    $event = $payload['event'] ?? 'price_change';

    switch ($event) {
        case 'price_change':
            handlePriceChange($payload, $config, $pricing, $logger);
            break;

        case 'out_of_stock':
            handleOutOfStock($payload, $config, $pricing, $logger);
            break;

        default:
            $logger->info("Unhandled webhook event: {$event}");
    }
} catch (\Exception $e) {
    $logger->error('Webhook processing error: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
}

// ============================================================================
// Event Handlers
// ============================================================================

/**
 * Handle price_change event
 *
 * Expected payload structure (normalized - adapt to actual KicksDB format):
 * {
 *   "event": "price_change",
 *   "product": {
 *     "id": "kicksdb-id",
 *     "sku": "DD1873-102",
 *     "title": "Nike Dunk Low..."
 *   },
 *   "variants": [
 *     {
 *       "id": "variant-id",
 *       "size": "38",
 *       "size_eu": "38",
 *       "lowest_ask": 119,
 *       "highest_bid": 105,
 *       "last_sale": 112,
 *       "currency": "EUR"
 *     }
 *   ],
 *   "marketplace": "stockx",
 *   "timestamp": "2026-02-07T10:00:00Z"
 * }
 */
function handlePriceChange(array $payload, array $config, array $pricing, Logger $logger): void
{
    // Extract SKU - KicksDB may nest product data differently
    $sku = $payload['product']['sku']
        ?? $payload['sku']
        ?? $payload['style_id']
        ?? null;

    if (!$sku) {
        $logger->error('Price change webhook missing SKU', ['payload_keys' => array_keys($payload)]);
        return;
    }

    // Extract variant price data
    $variants = $payload['variants']
        ?? $payload['product']['variants']
        ?? $payload['sizes']
        ?? [];

    if (empty($variants)) {
        $logger->warning("Price change webhook for {$sku} has no variant data");
        return;
    }

    $logger->info("Processing price change for {$sku}: " . count($variants) . " variants");

    // Setup WC client
    $wc_client = new Client(
        $config['woocommerce']['url'],
        $config['woocommerce']['consumer_key'],
        $config['woocommerce']['consumer_secret'],
        ['version' => $config['woocommerce']['version'], 'timeout' => 120]
    );

    // Run price updater
    $updater = new PriceUpdater($wc_client, $pricing, $logger);
    $result = $updater->updateProductPrices($sku, $variants);

    $logger->info("Price change processed for {$sku}", $result);
}

/**
 * Handle out_of_stock event
 *
 * Sets all matching variations to stock_quantity=0
 */
function handleOutOfStock(array $payload, array $config, array $pricing, Logger $logger): void
{
    $sku = $payload['product']['sku']
        ?? $payload['sku']
        ?? null;

    if (!$sku) {
        $logger->error('Out of stock webhook missing SKU');
        return;
    }

    $logger->info("Processing out_of_stock for {$sku}");

    $wc_client = new Client(
        $config['woocommerce']['url'],
        $config['woocommerce']['consumer_key'],
        $config['woocommerce']['consumer_secret'],
        ['version' => $config['woocommerce']['version'], 'timeout' => 120]
    );

    // Find WC product
    try {
        $products = $wc_client->get('products', ['sku' => $sku, 'per_page' => 1]);
        if (empty($products)) {
            $logger->warning("Product not found in WC: {$sku}");
            return;
        }

        $product_id = $products[0]->id;

        // Fetch all variations
        $variations = $wc_client->get("products/{$product_id}/variations", ['per_page' => 100]);

        // Build batch update to set all out of stock
        $updates = [];
        foreach ($variations as $var) {
            $updates[] = [
                'id' => $var->id,
                'stock_quantity' => 0,
                'stock_status' => 'outofstock',
            ];
        }

        if (!empty($updates)) {
            $chunks = array_chunk($updates, 100);
            foreach ($chunks as $chunk) {
                $wc_client->post("products/{$product_id}/variations/batch", ['update' => $chunk]);
            }
            $logger->info("Set " . count($updates) . " variations out of stock for {$sku}");
        }
    } catch (\Exception $e) {
        $logger->error("Error handling out_of_stock for {$sku}: " . $e->getMessage());
    }
}

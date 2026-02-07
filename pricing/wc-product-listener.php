<?php
/**
 * WooCommerce Product Webhook Listener
 *
 * HTTP endpoint that receives WooCommerce product webhooks.
 * When a new product is created/deleted in WC, this endpoint
 * auto-registers/unregisters the SKU with KicksDB price tracking.
 *
 * WooCommerce Webhook Setup:
 *   WP Admin → WooCommerce → Settings → Advanced → Webhooks → Add webhook
 *   - Name: "KicksDB SKU Registry"
 *   - Status: Active
 *   - Topic: "Product created" (add another for "Product deleted")
 *   - Delivery URL: https://resellpiacenza.it/api/wc-product-listener
 *   - Secret: (same as WC_WEBHOOK_SECRET in .env)
 *
 * @package ResellPiacenza\Pricing
 */

// Bootstrap
$base_dir = dirname(__DIR__);
require $base_dir . '/vendor/autoload.php';
require_once __DIR__ . '/kicksdb-client.php';
require_once __DIR__ . '/sku-registry.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// ============================================================================
// Configuration
// ============================================================================

$config = require $base_dir . '/config.php';
$pricing = $config['pricing'] ?? [];

// ============================================================================
// Logger
// ============================================================================

$logger = new Logger('WcProductListener');
$log_dir = $base_dir . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$logger->pushHandler(new RotatingFileHandler($log_dir . '/wc-product-listener.log', 7, Logger::DEBUG));

// ============================================================================
// Request Validation
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // WC sends a ping on webhook creation - accept it
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read body
$raw_body = file_get_contents('php://input');
if (empty($raw_body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

// Verify WC webhook signature
$wc_webhook_secret = $pricing['wc_webhook_secret'] ?? '';
if (!empty($wc_webhook_secret)) {
    $signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';
    $expected = base64_encode(hash_hmac('sha256', $raw_body, $wc_webhook_secret, true));

    if (!hash_equals($expected, $signature)) {
        $logger->warning('WC webhook signature mismatch');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// Parse JSON
$payload = json_decode($raw_body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Determine event from WC webhook headers
$topic = $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? 'unknown';
$resource = $_SERVER['HTTP_X_WC_WEBHOOK_RESOURCE'] ?? 'unknown';
$event = $_SERVER['HTTP_X_WC_WEBHOOK_EVENT'] ?? 'unknown';

$logger->info("WC webhook received: {$topic}", [
    'resource' => $resource,
    'event' => $event,
    'product_id' => $payload['id'] ?? 'unknown',
]);

// Respond immediately
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'accepted']);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// ============================================================================
// Event Processing
// ============================================================================

try {
    $sku = $payload['sku'] ?? '';
    $product_id = $payload['id'] ?? null;
    $product_name = $payload['name'] ?? '';
    $product_type = $payload['type'] ?? '';

    // Only process variable products (sneakers with size variations)
    if ($product_type !== 'variable' && !empty($product_type)) {
        $logger->info("Skipping non-variable product: {$sku} (type: {$product_type})");
        exit;
    }

    if (empty($sku)) {
        $logger->warning("WC webhook product has no SKU (ID: {$product_id})");
        exit;
    }

    // Setup clients
    $wc_client = new Client(
        $config['woocommerce']['url'],
        $config['woocommerce']['consumer_key'],
        $config['woocommerce']['consumer_secret'],
        ['version' => $config['woocommerce']['version'], 'timeout' => 120]
    );

    $kicksdb = new KicksDbClient(
        $pricing['kicksdb_api_key'] ?? '',
        ['base_url' => $pricing['kicksdb_base_url'] ?? 'https://api.kicks.dev/v3'],
        $logger
    );

    $registry = new SkuRegistry($wc_client, $kicksdb, [
        'webhook_id' => $pricing['kicksdb_webhook_id'] ?? null,
        'callback_url' => $pricing['webhook_callback_url'] ?? '',
        'registry_file' => $base_dir . '/data/sku-registry.json',
    ], $logger);

    switch ($event) {
        case 'created':
            $logger->info("New product detected: {$sku} ({$product_name})");
            $registry->registerSingleSku($sku, [
                'id' => $product_id,
                'name' => $product_name,
            ]);
            break;

        case 'deleted':
        case 'trashed':
            $logger->info("Product removed: {$sku}");
            $registry->unregisterSingleSku($sku);
            break;

        case 'updated':
            // On update, check if SKU changed or product was unpublished
            $status = $payload['status'] ?? 'publish';
            if ($status === 'trash' || $status === 'draft') {
                $logger->info("Product unpublished/trashed: {$sku}");
                $registry->unregisterSingleSku($sku);
            }
            break;

        default:
            $logger->info("Unhandled WC event: {$event} for {$sku}");
    }
} catch (\Exception $e) {
    $logger->error('WC webhook processing error: ' . $e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
}

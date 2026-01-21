<?php

/**
 * WooCommerce Webhook Receiver
 *
 * This endpoint receives webhooks from WooCommerce and processes them
 * to keep the local database in sync with WooCommerce inventory.
 *
 * Setup in WooCommerce:
 * 1. Go to WooCommerce > Settings > Advanced > Webhooks
 * 2. Add a new webhook:
 *    - Name: Inventory Sync
 *    - Status: Active
 *    - Topic: Product updated (or All product events)
 *    - Delivery URL: https://your-server.com/woo-importer/webhooks/receiver.php
 *    - Secret: Your secret key (set in .env as WEBHOOK_SECRET)
 *    - API Version: WP REST API Integration v3
 *
 * @package ResellPiacenza\WooImport
 */

declare(strict_types=1);

// Set content type for JSON responses
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Load autoloader and config
require dirname(__DIR__) . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use WooImporter\Database\Database;
use WooImporter\Services\WebhookHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Load configuration
$config = require dirname(__DIR__) . '/config.php';

// Setup logger
$logger = new Logger('Webhook');
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logger->pushHandler(new RotatingFileHandler($logDir . '/webhooks.log', 7, Logger::DEBUG));

// Get raw request body
$rawPayload = file_get_contents('php://input');

// Log incoming request
$logger->info('Webhook received', [
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
    'content_length' => strlen($rawPayload),
]);

// Parse headers
$headers = getallheaders();
$topic = $headers['X-WC-Webhook-Topic'] ?? $headers['x-wc-webhook-topic'] ?? null;
$signature = $headers['X-WC-Webhook-Signature'] ?? $headers['x-wc-webhook-signature'] ?? null;
$webhookId = $headers['X-WC-Webhook-Delivery-ID'] ?? $headers['x-wc-webhook-delivery-id'] ?? null;
$source = $headers['X-WC-Webhook-Source'] ?? $headers['x-wc-webhook-source'] ?? null;

// Log webhook details
$logger->info('Webhook details', [
    'topic' => $topic,
    'webhook_id' => $webhookId,
    'source' => $source,
]);

// Verify signature if secret is configured
$webhookSecret = $_ENV['WEBHOOK_SECRET'] ?? $config['webhooks']['secret'] ?? null;
if ($webhookSecret && $signature) {
    $expectedSignature = base64_encode(hash_hmac('sha256', $rawPayload, $webhookSecret, true));

    if (!hash_equals($expectedSignature, $signature)) {
        $logger->warning('Invalid webhook signature', [
            'expected' => substr($expectedSignature, 0, 10) . '...',
            'received' => substr($signature, 0, 10) . '...',
        ]);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    $logger->debug('Signature verified');
}

// Parse payload
$payload = json_decode($rawPayload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $logger->error('Invalid JSON payload', ['error' => json_last_error_msg()]);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

// Handle ping requests (WooCommerce sends these to verify the endpoint)
if ($topic === 'action.woocommerce_webhook_ping' || empty($payload)) {
    $logger->info('Ping request received');
    echo json_encode(['status' => 'ok', 'message' => 'Webhook endpoint active']);
    exit;
}

// Validate topic
if (!$topic) {
    $logger->warning('Missing webhook topic');
    http_response_code(400);
    echo json_encode(['error' => 'Missing X-WC-Webhook-Topic header']);
    exit;
}

// Initialize database
try {
    $db = Database::getInstance();
    $db->reconnectIfNeeded();
} catch (\Exception $e) {
    $logger->error('Database connection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Process webhook
try {
    $handler = new WebhookHandler($config, $logger, $db);
    $result = $handler->receive($topic, $payload, $webhookId);

    if ($result['success']) {
        $logger->info('Webhook processed successfully', $result);
        echo json_encode($result);
    } else {
        $logger->warning('Webhook processing returned error', $result);
        http_response_code(422);
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    $logger->error('Webhook processing failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

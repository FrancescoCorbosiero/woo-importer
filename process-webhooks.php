<?php

/**
 * Webhook Queue Processor CLI
 *
 * Processes pending webhooks from the queue.
 * Run this if webhooks are set to async mode or to retry failed webhooks.
 *
 * Usage:
 *   php process-webhooks.php                # Process pending webhooks
 *   php process-webhooks.php --limit=100    # Process up to 100 webhooks
 *   php process-webhooks.php --retry        # Retry failed webhooks
 *   php process-webhooks.php --stats        # Show queue statistics
 *   php process-webhooks.php --cleanup      # Clean old completed webhooks
 *
 * @package ResellPiacenza\WooImport
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use WooImporter\Database\Database;
use WooImporter\Models\WebhookQueue;
use WooImporter\Services\WebhookHandler;

// Parse CLI arguments
$options = getopt('', ['help', 'limit:', 'retry', 'stats', 'cleanup', 'verbose']);

if (isset($options['help'])) {
    echo <<<HELP
Webhook Queue Processor

Processes pending webhooks from the queue and provides queue management.

Usage:
  php process-webhooks.php [options]

Options:
  --limit=N       Process up to N webhooks (default: 50)
  --retry         Retry failed webhooks
  --stats         Show queue statistics
  --cleanup       Clean old completed webhooks (older than 7 days)
  --verbose       Show detailed logging output
  --help          Show this help message

Examples:
  php process-webhooks.php                 # Process pending webhooks
  php process-webhooks.php --stats         # View queue statistics
  php process-webhooks.php --retry         # Retry failed webhooks
  php process-webhooks.php --cleanup       # Clean up old entries

HELP;
    exit(0);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 50;
$retry = isset($options['retry']);
$stats = isset($options['stats']);
$cleanup = isset($options['cleanup']);
$verbose = isset($options['verbose']);

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load configuration
$config = require __DIR__ . '/config.php';

// Setup logger
$logger = new Logger('WebhookProcessor');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger->pushHandler(new RotatingFileHandler($logDir . '/webhook-processor.log', 7, Logger::DEBUG));

$consoleLevel = $verbose ? Logger::DEBUG : Logger::INFO;
$logger->pushHandler(new StreamHandler('php://stdout', $consoleLevel));

$logger->info('Webhook Queue Processor');
$logger->info('');

// Initialize database
try {
    $db = Database::getInstance();
} catch (\Exception $e) {
    $logger->error('Database connection failed: ' . $e->getMessage());
    exit(1);
}

$queue = new WebhookQueue($db);

// Show statistics
if ($stats) {
    $queueStats = $queue->getStats();
    $logger->info('Queue Statistics:');
    $logger->info('  Pending:    ' . $queueStats['pending']);
    $logger->info('  Processing: ' . $queueStats['processing']);
    $logger->info('  Completed:  ' . $queueStats['completed']);
    $logger->info('  Failed:     ' . $queueStats['failed']);
    $logger->info('');

    if ($queueStats['failed'] > 0) {
        $logger->info('Recent Failed Webhooks:');
        $failed = $queue->getFailed(10);
        foreach ($failed as $webhook) {
            $logger->info("  [{$webhook['id']}] {$webhook['topic']} - {$webhook['error_message']}");
        }
    }

    exit(0);
}

// Cleanup old webhooks
if ($cleanup) {
    $logger->info('Cleaning up old completed webhooks...');
    $deleted = $queue->cleanOld(7);
    $logger->info("  Deleted {$deleted} old entries");
    exit(0);
}

// Retry failed webhooks
if ($retry) {
    $logger->info('Retrying failed webhooks...');
    $failed = $queue->getFailed($limit);

    if (empty($failed)) {
        $logger->info('  No failed webhooks to retry');
        exit(0);
    }

    foreach ($failed as $webhook) {
        $queue->retry((int) $webhook['id']);
    }

    $logger->info('  Marked ' . count($failed) . ' webhooks for retry');
    // Continue to process them
}

// Process pending webhooks
$handler = new WebhookHandler($config, $logger, $db);
$result = $handler->processQueue($limit);

$logger->info('');
$logger->info('Processing Complete:');
$logger->info("  Processed: {$result['processed']}");
$logger->info("  Failed:    {$result['failed']}");

exit($result['failed'] > 0 ? 1 : 0);

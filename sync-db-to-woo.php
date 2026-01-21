<?php

/**
 * Database to WooCommerce Sync CLI
 *
 * Reads products from the database (source of truth) and syncs to WooCommerce.
 * Only syncs products that have changed since the last sync.
 *
 * Usage:
 *   php sync-db-to-woo.php                # Sync pending changes
 *   php sync-db-to-woo.php --dry-run      # Preview changes without syncing
 *   php sync-db-to-woo.php --limit=50     # Limit number of products to sync
 *   php sync-db-to-woo.php --verbose      # Detailed logging
 *   php sync-db-to-woo.php --force        # Sync all products (ignore timestamps)
 *
 * Recommended workflow:
 *   1. Run sync-feed-to-db.php (every 30 minutes via cron)
 *   2. Run sync-db-to-woo.php (immediately after, or separately)
 *
 * @package ResellPiacenza\WooImport
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use WooImporter\Database\Database;
use WooImporter\Services\WooSyncService;

// Parse CLI arguments
$options = getopt('', ['dry-run', 'verbose', 'help', 'limit:', 'force']);

if (isset($options['help'])) {
    echo <<<HELP
Database to WooCommerce Sync

Reads products from the local database (source of truth) and syncs to WooCommerce.
Only products that have changed since the last sync will be processed.

Usage:
  php sync-db-to-woo.php [options]

Options:
  --dry-run       Preview changes without syncing to WooCommerce
  --limit=N       Limit number of products to sync (default: all pending)
  --force         Sync all products regardless of last sync timestamp
  --verbose       Show detailed logging output
  --help          Show this help message

Examples:
  php sync-db-to-woo.php                    # Sync all pending changes
  php sync-db-to-woo.php --dry-run          # Preview what would sync
  php sync-db-to-woo.php --limit=50         # Sync first 50 pending products
  php sync-db-to-woo.php --verbose          # Sync with debug output

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$limit = isset($options['limit']) ? (int) $options['limit'] : null;
$force = isset($options['force']);

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load configuration
$config = require __DIR__ . '/config.php';

// Setup logger
$logger = new Logger('DbToWoo');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger->pushHandler(new RotatingFileHandler($logDir . '/woo-sync.log', 7, Logger::DEBUG));

$consoleLevel = $verbose ? Logger::DEBUG : Logger::INFO;
$logger->pushHandler(new StreamHandler('php://stdout', $consoleLevel));

// Banner
$logger->info('========================================');
$logger->info('  Database → WooCommerce Sync');
$logger->info('========================================');

if ($dryRun) {
    $logger->info('  Mode: DRY RUN (no changes will be made)');
}
if ($limit) {
    $logger->info("  Limit: {$limit} products");
}
if ($force) {
    $logger->info('  Mode: FORCE (syncing all products)');
}

$logger->info('');

// Initialize database
try {
    $db = Database::getInstance();
    $logger->info('Database connected');
} catch (\Exception $e) {
    $logger->error('Database connection failed: ' . $e->getMessage());
    exit(1);
}

// If force mode, reset all last_woo_sync timestamps
if ($force && !$dryRun) {
    $logger->info('Force mode: Resetting sync timestamps...');
    $db->execute("UPDATE products SET last_woo_sync = NULL WHERE status = 'active'");
}

// Run sync
$service = new WooSyncService($config, $logger, $db);
$result = $service->sync($dryRun, $limit);

// Exit with appropriate code
if ($result['success']) {
    $logger->info('');
    $logger->info('WooCommerce sync completed successfully!');
    exit(0);
} else {
    $logger->error('');
    $logger->error('WooCommerce sync failed: ' . ($result['error'] ?? 'Unknown error'));
    exit(1);
}

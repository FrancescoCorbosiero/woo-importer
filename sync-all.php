<?php

/**
 * Full Sync Pipeline CLI
 *
 * Runs the complete sync pipeline:
 * 1. Feed → Database (fetch from Golden Sneakers API)
 * 2. Database → WooCommerce (push changes to store)
 *
 * This is the recommended script for cron jobs.
 *
 * Usage:
 *   php sync-all.php                   # Full sync pipeline
 *   php sync-all.php --dry-run         # Preview all changes
 *   php sync-all.php --verbose         # Detailed logging
 *   php sync-all.php --feed-only       # Only sync feed to database
 *   php sync-all.php --woo-only        # Only sync database to WooCommerce
 *
 * Recommended cron schedule (every 30 minutes):
 *   0,30 * * * * php /path/to/sync-all.php >> /path/to/logs/cron.log 2>&1
 *
 * @package ResellPiacenza\WooImport
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use WooImporter\Database\Database;
use WooImporter\Services\FeedSyncService;
use WooImporter\Services\WooSyncService;

// Parse CLI arguments
$options = getopt('', ['dry-run', 'verbose', 'help', 'feed-only', 'woo-only', 'skip-images']);

if (isset($options['help'])) {
    echo <<<HELP
Full Sync Pipeline

Runs the complete synchronization pipeline:
1. Feed → Database: Fetch products from Golden Sneakers API
2. Database → WooCommerce: Push changes to your store

Usage:
  php sync-all.php [options]

Options:
  --dry-run       Preview changes without making any modifications
  --feed-only     Only run Feed → Database sync
  --woo-only      Only run Database → WooCommerce sync
  --skip-images   Skip image processing (faster for testing)
  --verbose       Show detailed logging output
  --help          Show this help message

Examples:
  php sync-all.php                      # Full sync pipeline
  php sync-all.php --dry-run            # Preview all changes
  php sync-all.php --feed-only          # Only update database from feed
  php sync-all.php --woo-only           # Only push DB changes to WooCommerce

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$feedOnly = isset($options['feed-only']);
$wooOnly = isset($options['woo-only']);

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load configuration
$config = require __DIR__ . '/config.php';

// Setup logger
$logger = new Logger('SyncAll');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger->pushHandler(new RotatingFileHandler($logDir . '/sync-all.log', 7, Logger::DEBUG));

$consoleLevel = $verbose ? Logger::DEBUG : Logger::INFO;
$logger->pushHandler(new StreamHandler('php://stdout', $consoleLevel));

// Banner
$logger->info('╔════════════════════════════════════════╗');
$logger->info('║     Full Sync Pipeline                 ║');
$logger->info('║     Feed → Database → WooCommerce      ║');
$logger->info('╚════════════════════════════════════════╝');

if ($dryRun) {
    $logger->info('  Mode: DRY RUN (no changes will be made)');
}

$logger->info('');
$logger->info('Started at: ' . date('Y-m-d H:i:s'));
$logger->info('');

$startTime = microtime(true);
$exitCode = 0;

// Initialize database
try {
    $db = Database::getInstance();
    $logger->info('Database connected');
    $logger->info('');
} catch (\Exception $e) {
    $logger->error('Database connection failed: ' . $e->getMessage());
    exit(1);
}

// Step 1: Feed → Database
if (!$wooOnly) {
    $logger->info('────────────────────────────────────────');
    $logger->info('  Step 1: Feed → Database');
    $logger->info('────────────────────────────────────────');

    $feedService = new FeedSyncService($config, $logger, $db);
    $feedResult = $feedService->sync($dryRun);

    if (!$feedResult['success']) {
        $logger->error('Feed sync failed, aborting pipeline');
        $exitCode = 1;
    }

    $logger->info('');
}

// Step 2: Database → WooCommerce
if (!$feedOnly && $exitCode === 0) {
    $logger->info('────────────────────────────────────────');
    $logger->info('  Step 2: Database → WooCommerce');
    $logger->info('────────────────────────────────────────');

    $wooService = new WooSyncService($config, $logger, $db);
    $wooResult = $wooService->sync($dryRun);

    if (!$wooResult['success']) {
        $logger->error('WooCommerce sync failed');
        $exitCode = 1;
    }

    $logger->info('');
}

// Summary
$duration = round(microtime(true) - $startTime, 2);

$logger->info('════════════════════════════════════════');
$logger->info('  Pipeline Complete');
$logger->info('════════════════════════════════════════');
$logger->info("  Total Duration: {$duration}s");
$logger->info('  Finished at: ' . date('Y-m-d H:i:s'));

if ($exitCode === 0) {
    $logger->info('  Status: SUCCESS');
} else {
    $logger->error('  Status: FAILED');
}

$logger->info('');

exit($exitCode);

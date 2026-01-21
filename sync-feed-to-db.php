<?php

/**
 * Feed to Database Sync CLI
 *
 * Fetches products from Golden Sneakers API and syncs to the database.
 * The database becomes the source of truth for inventory.
 *
 * Usage:
 *   php sync-feed-to-db.php                # Full sync
 *   php sync-feed-to-db.php --dry-run      # Preview changes without writing
 *   php sync-feed-to-db.php --verbose      # Detailed logging
 *
 * Recommended cron schedule (every 30 minutes):
 *   0,30 * * * * php /path/to/sync-feed-to-db.php >> /path/to/logs/cron.log 2>&1
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

// Parse CLI arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Feed to Database Sync

Fetches products from Golden Sneakers API and syncs to the local database.
The database serves as the single source of truth for inventory management.

Usage:
  php sync-feed-to-db.php [options]

Options:
  --dry-run     Preview changes without writing to database
  --verbose     Show detailed logging output
  --help        Show this help message

Examples:
  php sync-feed-to-db.php                # Full sync to database
  php sync-feed-to-db.php --dry-run      # Preview what would change
  php sync-feed-to-db.php --verbose      # Full sync with debug output

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load configuration
$config = require __DIR__ . '/config.php';

// Setup logger
$logger = new Logger('FeedToDb');

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logger->pushHandler(new RotatingFileHandler($logDir . '/feed-sync.log', 7, Logger::DEBUG));

$consoleLevel = $verbose ? Logger::DEBUG : Logger::INFO;
$logger->pushHandler(new StreamHandler('php://stdout', $consoleLevel));

// Banner
$logger->info('========================================');
$logger->info('  Feed → Database Sync');
$logger->info('========================================');

if ($dryRun) {
    $logger->info('  Mode: DRY RUN (no changes will be made)');
}

$logger->info('');

// Initialize database
try {
    $db = Database::getInstance();
    $logger->info('Database connected');
} catch (\Exception $e) {
    $logger->error('Database connection failed: ' . $e->getMessage());
    $logger->error('');
    $logger->error('Make sure database is configured in .env:');
    $logger->error('  DB_HOST=localhost');
    $logger->error('  DB_DATABASE=woo_importer');
    $logger->error('  DB_USERNAME=your_user');
    $logger->error('  DB_PASSWORD=your_password');
    exit(1);
}

// Run sync
$service = new FeedSyncService($config, $logger, $db);
$result = $service->sync($dryRun);

// Exit with appropriate code
if ($result['success']) {
    $logger->info('');
    $logger->info('Sync completed successfully!');
    exit(0);
} else {
    $logger->error('');
    $logger->error('Sync failed: ' . ($result['error'] ?? 'Unknown error'));
    exit(1);
}

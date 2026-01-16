<?php
/**
 * Golden Sneakers Delta Sync
 *
 * Compares current API feed with saved state and triggers
 * batch import only for changed products.
 *
 * Usage:
 *   php sync-check.php                 # Normal sync
 *   php sync-check.php --dry-run       # Preview changes
 *   php sync-check.php --check-only    # Check without importing
 *   php sync-check.php --force-full    # Force full import
 *   php sync-check.php --verbose       # Detailed logging
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class DeltaSyncChecker
{
    private $config;
    private $logger;

    // Options
    private $dry_run = false;
    private $check_only = false;
    private $force_full = false;
    private $verbose = false;

    // Paths
    private $data_dir;
    private $feed_file;
    private $diff_file;

    // Stats
    private $stats = [
        'new' => 0,
        'updated' => 0,
        'removed' => 0,
        'unchanged' => 0,
    ];

    // Changed products
    private $diff_products = [];
    private $removed_skus = [];

    /**
     * Constructor
     *
     * @param array $config Configuration array from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->check_only = $options['check_only'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->verbose = $options['verbose'] ?? false;

        // Setup paths
        $this->data_dir = __DIR__ . '/data';
        $this->feed_file = $this->data_dir . '/feed.json';
        $this->diff_file = $this->data_dir . '/diff.json';

        $this->setupLogger();
        $this->ensureDataDirectory();
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('DeltaSync');

        // File handler
        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/sync.log', 7, Logger::DEBUG)
        );

        // Console handler
        $console_level = $this->verbose ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $console_level));
    }

    /**
     * Ensure data directory exists
     */
    private function ensureDataDirectory(): void
    {
        if (!is_dir($this->data_dir)) {
            mkdir($this->data_dir, 0755, true);
        }
    }

    /**
     * Main run method
     *
     * @return bool True on success, false on failure
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Golden Sneakers Delta Sync');
        $this->logger->info('================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }
        if ($this->check_only) {
            $this->logger->info('  CHECK ONLY MODE - Will not trigger import');
        }
        if ($this->force_full) {
            $this->logger->warning('  FORCE FULL MODE - Ignoring diff');
        }

        $this->logger->info('');

        try {
            // Step 1: Fetch current feed from API
            $this->logger->info('Fetching current feed from API...');
            $current_feed = $this->fetchFeedFromAPI();

            if (empty($current_feed)) {
                $this->logger->error('Failed to fetch feed from API');
                return false;
            }

            $this->logger->info("   " . $this->countProducts($current_feed) . " products loaded");

            // Step 2: Load saved feed
            $this->logger->info('');
            $this->logger->info('Loading saved feed...');
            $saved_feed = $this->loadSavedFeed();

            if ($saved_feed === null) {
                $this->logger->info('   No saved feed found - this is first sync');

                // First run: save feed and do full import
                $this->saveFeed($current_feed);

                if ($this->check_only) {
                    $this->logger->info('   First run would trigger full import');
                    return true;
                }

                $this->logger->info('   Triggering full import...');
                return $this->triggerFullImport();
            }

            $saved_time = $this->getSavedFeedTime();
            $this->logger->info("   " . $this->countProducts($saved_feed) . " products from last sync");
            if ($saved_time) {
                $this->logger->info("   Last sync: {$saved_time}");
            }

            // Step 3: Force full import if requested
            if ($this->force_full) {
                $this->logger->info('');
                $this->logger->info('Force full import requested...');
                $this->saveFeed($current_feed);

                if ($this->check_only || $this->dry_run) {
                    $this->logger->info('   Would trigger full import');
                    return true;
                }

                return $this->triggerFullImport();
            }

            // Step 4: Compare feeds
            $this->logger->info('');
            $this->logger->info('Comparing feeds...');
            $this->compareFeedsAndBuildDiff($current_feed, $saved_feed);

            // Step 5: Report findings
            $this->logger->info('');
            $this->printDiffSummary();

            $total_changes = $this->stats['new'] + $this->stats['updated'] + $this->stats['removed'];

            // Step 6: Handle results
            if ($total_changes === 0) {
                $this->logger->info('');
                $this->logger->info('No changes detected - nothing to sync');
                $this->updateSyncTimestamp();
                return true;
            }

            // Step 7: Save diff and trigger import
            if (!$this->check_only && !$this->dry_run) {
                $this->logger->info('');

                // Save current feed as new baseline
                $this->saveFeed($current_feed);

                // Save diff for import
                $this->saveDiff();

                // Trigger batch import with diff
                $this->logger->info('Triggering batch import for changes...');
                $this->logger->info('');

                $success = $this->triggerDiffImport();

                $duration = round(microtime(true) - $start_time, 2);
                $this->logger->info('');
                $this->logger->info("Sync complete in {$duration}s");

                return $success;
            }

            // Dry run or check only
            $duration = round(microtime(true) - $start_time, 2);
            $this->logger->info('');
            $this->logger->info("Check complete in {$duration}s");

            return true;

        } catch (Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch feed from Golden Sneakers API
     *
     * @return array|null Feed data or null on error
     */
    private function fetchFeedFromAPI(): ?array
    {
        $params = http_build_query($this->config['api']['params']);
        $url = $this->config['api']['base_url'] . '?' . $params;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api']['bearer_token'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->logger->error('CURL Error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }

        curl_close($ch);

        if ($http_code !== 200) {
            $this->logger->error("API returned HTTP {$http_code}");
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Load saved feed from file
     *
     * @return array|null Feed data or null if not found
     */
    private function loadSavedFeed(): ?array
    {
        if (!file_exists($this->feed_file)) {
            return null;
        }

        $content = file_get_contents($this->feed_file);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to parse saved feed, treating as first run');
            return null;
        }

        return $data;
    }

    /**
     * Get saved feed modification time
     *
     * @return string|null Formatted datetime or null
     */
    private function getSavedFeedTime(): ?string
    {
        if (!file_exists($this->feed_file)) {
            return null;
        }

        return date('Y-m-d H:i:s', filemtime($this->feed_file));
    }

    /**
     * Count products in feed
     *
     * @param array $feed Feed data
     * @return int Product count
     */
    private function countProducts(array $feed): int
    {
        return count($feed);
    }

    /**
     * Generate signature for a product (for change detection)
     *
     * @param array $product Product data
     * @return string MD5 hash signature
     */
    private function getProductSignature(array $product): string
    {
        // Include all fields that matter for sync
        $sig_data = [
            'name' => $product['name'] ?? '',
            'brand' => $product['brand_name'] ?? '',
            'image' => $product['image_full_url'] ?? '',
            'sizes' => [],
        ];

        // Add size data (sorted for consistency)
        $sizes = $product['sizes'] ?? [];
        foreach ($sizes as $size) {
            $sig_data['sizes'][] = implode(':', [
                $size['size_eu'] ?? '',
                $size['offer_price'] ?? 0,
                $size['available_quantity'] ?? 0,
            ]);
        }
        sort($sig_data['sizes']);

        return md5(json_encode($sig_data));
    }

    /**
     * Compare feeds and build diff
     *
     * @param array $current Current feed from API
     * @param array $saved Saved feed from file
     */
    private function compareFeedsAndBuildDiff(array $current, array $saved): void
    {
        // Index both feeds by SKU
        $current_by_sku = [];
        foreach ($current as $product) {
            $sku = $product['sku'] ?? null;
            if ($sku) {
                $current_by_sku[$sku] = $product;
            }
        }

        $saved_by_sku = [];
        foreach ($saved as $product) {
            $sku = $product['sku'] ?? null;
            if ($sku) {
                $saved_by_sku[$sku] = $product;
            }
        }

        // Find new and updated products
        foreach ($current_by_sku as $sku => $product) {
            if (!isset($saved_by_sku[$sku])) {
                // New product
                $this->stats['new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("   + NEW: {$sku} - {$product['name']}");
                }
            } else {
                // Existing product - check for changes
                $current_sig = $this->getProductSignature($product);
                $saved_sig = $this->getProductSignature($saved_by_sku[$sku]);

                if ($current_sig !== $saved_sig) {
                    // Product changed
                    $this->stats['updated']++;
                    $product['_sync_action'] = 'updated';
                    $this->diff_products[] = $product;

                    if ($this->verbose) {
                        $changes = $this->detectChangeType($product, $saved_by_sku[$sku]);
                        $this->logger->debug("   ~ CHANGED: {$sku} ({$changes})");
                    }
                } else {
                    $this->stats['unchanged']++;
                }
            }
        }

        // Find removed products
        foreach ($saved_by_sku as $sku => $product) {
            if (!isset($current_by_sku[$sku])) {
                // Product removed - create zero-stock version
                $this->stats['removed']++;
                $this->removed_skus[] = $sku;

                // Set all sizes to zero stock
                $zero_stock_product = $product;
                $zero_stock_product['_sync_action'] = 'removed';
                foreach ($zero_stock_product['sizes'] as &$size) {
                    $size['available_quantity'] = 0;
                }
                $this->diff_products[] = $zero_stock_product;

                if ($this->verbose) {
                    $this->logger->debug("   - REMOVED: {$sku} - {$product['name']}");
                }
            }
        }
    }

    /**
     * Detect what type of change occurred
     *
     * @param array $current Current product data
     * @param array $saved Saved product data
     * @return string Description of changes
     */
    private function detectChangeType(array $current, array $saved): string
    {
        $changes = [];

        if (($current['name'] ?? '') !== ($saved['name'] ?? '')) {
            $changes[] = 'name';
        }

        // Check sizes
        $current_sizes = $this->indexSizesByEU($current['sizes'] ?? []);
        $saved_sizes = $this->indexSizesByEU($saved['sizes'] ?? []);

        $price_changed = false;
        $stock_changed = false;

        foreach ($current_sizes as $size_eu => $size_data) {
            if (!isset($saved_sizes[$size_eu])) {
                $changes[] = 'new_size';
                continue;
            }

            if ($size_data['offer_price'] != $saved_sizes[$size_eu]['offer_price']) {
                $price_changed = true;
            }
            if ($size_data['available_quantity'] != $saved_sizes[$size_eu]['available_quantity']) {
                $stock_changed = true;
            }
        }

        if ($price_changed) {
            $changes[] = 'price';
        }
        if ($stock_changed) {
            $changes[] = 'stock';
        }

        return implode(', ', $changes) ?: 'unknown';
    }

    /**
     * Index sizes by EU size
     *
     * @param array $sizes Array of size data
     * @return array Indexed by size_eu
     */
    private function indexSizesByEU(array $sizes): array
    {
        $indexed = [];
        foreach ($sizes as $size) {
            $indexed[$size['size_eu']] = $size;
        }
        return $indexed;
    }

    /**
     * Print diff summary
     */
    private function printDiffSummary(): void
    {
        $this->logger->info('Changes Detected:');
        $this->logger->info("   + New products:      {$this->stats['new']}");
        $this->logger->info("   ~ Updated products:  {$this->stats['updated']}");
        $this->logger->info("   - Removed products:  {$this->stats['removed']}");
        $this->logger->info("     Unchanged:         {$this->stats['unchanged']}");
        $this->logger->info("   ---------------------");

        $total = $this->stats['new'] + $this->stats['updated'] + $this->stats['removed'];
        $this->logger->info("   Total to sync:       {$total}");
    }

    /**
     * Save current feed to file
     *
     * @param array $feed Feed data
     */
    private function saveFeed(array $feed): void
    {
        file_put_contents(
            $this->feed_file,
            json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->logger->debug("Saved feed to {$this->feed_file}");
    }

    /**
     * Save diff to file
     */
    private function saveDiff(): void
    {
        file_put_contents(
            $this->diff_file,
            json_encode($this->diff_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->logger->info("Saved diff ({$this->getTotalChanges()} products) to {$this->diff_file}");
    }

    /**
     * Update sync timestamp
     */
    private function updateSyncTimestamp(): void
    {
        if (file_exists($this->feed_file)) {
            touch($this->feed_file);
        }
    }

    /**
     * Get total changes count
     *
     * @return int Total number of changed products
     */
    private function getTotalChanges(): int
    {
        return $this->stats['new'] + $this->stats['updated'] + $this->stats['removed'];
    }

    /**
     * Trigger full import (no diff)
     *
     * @return bool True on success
     */
    private function triggerFullImport(): bool
    {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/import-batch.php');

        $this->logger->info("Executing: {$cmd}");
        $this->logger->info('');

        passthru($cmd, $exit_code);

        return $exit_code === 0;
    }

    /**
     * Trigger import with diff file
     *
     * @return bool True on success
     */
    private function triggerDiffImport(): bool
    {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/import-batch.php') .
               ' --feed=' . escapeshellarg($this->diff_file);

        $this->logger->info("Executing: {$cmd}");
        $this->logger->info('');

        passthru($cmd, $exit_code);

        return $exit_code === 0;
    }
}

// ============================================================================
// CLI Runner
// ============================================================================

// Show help
if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
Golden Sneakers Delta Sync
Compares API feed with saved state and imports only changes.

Usage:
  php sync-check.php [options]

Options:
  --dry-run         Preview changes without importing
  --check-only      Check for changes without importing
  --force-full      Force full import (ignore diff)
  --verbose, -v     Show detailed change information
  --help, -h        Show this help message

Examples:
  php sync-check.php                    # Normal sync
  php sync-check.php --check-only       # Just check for changes
  php sync-check.php --verbose          # See detailed changes
  php sync-check.php --force-full       # Force full re-import

Cron setup:
  # Check every 30 minutes
  */30 * * * * cd /path/to/project && php sync-check.php >> logs/cron.log 2>&1

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'check_only' => in_array('--check-only', $argv),
    'force_full' => in_array('--force-full', $argv),
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
];

$config = require __DIR__ . '/config.php';

$checker = new DeltaSyncChecker($config, $options);
$success = $checker->run();

exit($success ? 0 : 1);

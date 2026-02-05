<?php
/**
 * WooCommerce Delta Sync
 *
 * Pure pass-through sync for WC-formatted feeds.
 * Feed is source of truth - must contain all resolved IDs.
 *
 * Features:
 * - Delta detection (new/updated/removed)
 * - Batch import via import-wc.php
 * - Zero transformation logic
 *
 * Usage:
 *   php sync-wc.php                    # Full sync
 *   php sync-wc.php --dry-run          # Preview changes
 *   php sync-wc.php --check-only       # Just show diff
 *   php sync-wc.php --force-full       # Force full import
 *   php sync-wc.php --verbose          # Detailed output
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class WooCommerceDeltaSync
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
        'products_new' => 0,
        'products_updated' => 0,
        'products_removed' => 0,
        'products_unchanged' => 0,
    ];

    // Diff products
    private $diff_products = [];

    /**
     * Constructor
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->check_only = $options['check_only'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->verbose = $options['verbose'] ?? false;

        $this->data_dir = __DIR__ . '/data';
        $this->feed_file = $this->data_dir . '/feed-wc.json';
        $this->diff_file = $this->data_dir . '/diff-wc.json';

        $this->setupLogger();
        $this->ensureDataDirectory();
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('SyncWC');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/sync-wc.log', 7, Logger::DEBUG)
        );

        $level = $this->verbose ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $level));
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
     * Generate signature for product comparison
     */
    private function getProductSignature(array $product): string
    {
        $sig_data = [
            'name' => $product['name'] ?? '',
            'variations' => [],
        ];

        foreach ($product['_variations'] ?? [] as $var) {
            $option = $var['attributes'][0]['option'] ?? '';
            $sig_data['variations'][] = implode(':', [
                $option,
                $var['regular_price'] ?? '0',
                $var['stock_quantity'] ?? 0,
            ]);
        }
        sort($sig_data['variations']);

        return md5(json_encode($sig_data));
    }

    /**
     * Fetch feed from REST API
     */
    private function fetchFeedFromAPI(): ?array
    {
        $url = $this->config['api']['base_url'];
        $params = $this->config['api']['params'] ?? [];

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        $headers = ['Accept: application/json'];

        if (!empty($this->config['api']['bearer_token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['api']['bearer_token'];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
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
            $this->logger->error('JSON error: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Load saved feed
     */
    private function loadSavedFeed(): ?array
    {
        if (!file_exists($this->feed_file)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->feed_file), true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Get saved feed timestamp
     */
    private function getSavedFeedTime(): ?string
    {
        if (!file_exists($this->feed_file)) {
            return null;
        }
        return date('Y-m-d H:i:s', filemtime($this->feed_file));
    }

    /**
     * Compare feeds and build diff
     */
    private function compareFeedsAndBuildDiff(array $current, array $saved): void
    {
        $current_by_sku = [];
        foreach ($current as $product) {
            if ($sku = $product['sku'] ?? null) {
                $current_by_sku[$sku] = $product;
            }
        }

        $saved_by_sku = [];
        foreach ($saved as $product) {
            if ($sku = $product['sku'] ?? null) {
                $saved_by_sku[$sku] = $product;
            }
        }

        // New and updated
        foreach ($current_by_sku as $sku => $product) {
            if (!isset($saved_by_sku[$sku])) {
                $this->stats['products_new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("   + NEW: {$sku}");
                }
            } else {
                $current_sig = $this->getProductSignature($product);
                $saved_sig = $this->getProductSignature($saved_by_sku[$sku]);

                if ($current_sig !== $saved_sig) {
                    $this->stats['products_updated']++;
                    $product['_sync_action'] = 'updated';
                    $this->diff_products[] = $product;

                    if ($this->verbose) {
                        $this->logger->debug("   ~ CHANGED: {$sku}");
                    }
                } else {
                    $this->stats['products_unchanged']++;
                }
            }
        }

        // Removed
        foreach ($saved_by_sku as $sku => $product) {
            if (!isset($current_by_sku[$sku])) {
                $this->stats['products_removed']++;
                $product['_sync_action'] = 'removed';
                foreach ($product['_variations'] ?? [] as &$var) {
                    $var['stock_quantity'] = 0;
                    $var['stock_status'] = 'outofstock';
                }
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("   - REMOVED: {$sku}");
                }
            }
        }
    }

    /**
     * Print diff summary
     */
    private function printDiffSummary(): void
    {
        $this->logger->info('Changes Detected:');
        $this->logger->info("   + New:       {$this->stats['products_new']}");
        $this->logger->info("   ~ Updated:   {$this->stats['products_updated']}");
        $this->logger->info("   - Removed:   {$this->stats['products_removed']}");
        $this->logger->info("     Unchanged: {$this->stats['products_unchanged']}");
        $this->logger->info("   ---------------------");

        $total = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];
        $this->logger->info("   Total:       {$total}");
    }

    /**
     * Save feed
     */
    private function saveFeed(array $feed): void
    {
        file_put_contents(
            $this->feed_file,
            json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Save diff
     */
    private function saveDiff(): void
    {
        file_put_contents(
            $this->diff_file,
            json_encode($this->diff_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->logger->info("Saved diff to {$this->diff_file}");
    }

    /**
     * Trigger import
     */
    private function triggerImport(): bool
    {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/import-wc.php') .
               ' --feed=' . escapeshellarg($this->diff_file);

        $this->logger->info("Executing: {$cmd}");
        $this->logger->info('');

        passthru($cmd, $exit_code);

        return $exit_code === 0;
    }

    /**
     * Main run
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  WooCommerce Delta Sync');
        $this->logger->info('================================');
        $this->logger->info('  Mode: Pass-through (IDs only)');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }
        if ($this->check_only) {
            $this->logger->info('  CHECK ONLY');
        }
        if ($this->force_full) {
            $this->logger->warning('  FORCE FULL');
        }

        $this->logger->info('');

        try {
            // Fetch
            $this->logger->info('Fetching feed...');
            $current_feed = $this->fetchFeedFromAPI();

            if (empty($current_feed)) {
                $this->logger->error('Failed to fetch feed');
                return false;
            }

            $this->logger->info("   {$this->countProducts($current_feed)} products");

            // Load saved
            $this->logger->info('');
            $this->logger->info('Loading baseline...');
            $saved_feed = $this->loadSavedFeed();

            if ($saved_feed === null || $this->force_full) {
                $reason = $saved_feed === null ? 'First run' : 'Force full';
                $this->logger->info("   {$reason} - all products");

                $this->diff_products = $current_feed;
                foreach ($this->diff_products as &$p) {
                    $p['_sync_action'] = 'new';
                    $this->stats['products_new']++;
                }
                unset($p);
            } else {
                $saved_time = $this->getSavedFeedTime();
                $this->logger->info("   {$this->countProducts($saved_feed)} from {$saved_time}");

                $this->logger->info('');
                $this->logger->info('Comparing...');
                $this->compareFeedsAndBuildDiff($current_feed, $saved_feed);
            }

            // Report
            $this->logger->info('');
            $this->printDiffSummary();

            $total = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];

            if ($total === 0) {
                $this->logger->info('');
                $this->logger->info('Nothing to sync');
                return true;
            }

            // Import
            if (!$this->check_only && !$this->dry_run) {
                $this->saveFeed($current_feed);
                $this->saveDiff();

                $this->logger->info('');
                $this->logger->info('Importing...');
                $this->logger->info('');

                $success = $this->triggerImport();

                $duration = round(microtime(true) - $start_time, 1);
                $this->logger->info('');
                $this->logger->info("Done in {$duration}s");

                return $success;
            }

            $duration = round(microtime(true) - $start_time, 1);
            $this->logger->info('');
            $this->logger->info("Done in {$duration}s");

            return true;

        } catch (Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }

    private function countProducts(array $feed): int
    {
        return count($feed);
    }
}

// ============================================================================
// CLI
// ============================================================================

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
WooCommerce Delta Sync (Pass-through)

Usage:
  php sync-wc.php [options]

Options:
  --dry-run         Preview changes
  --check-only      Show diff only
  --force-full      Force full import
  --verbose, -v     Detailed output
  --help, -h        Show help

Feed must provide ALL resolved IDs:
  {
    "sku": "SKU-123",
    "name": "Product",
    "type": "variable",
    "categories": [{"id": 15}],
    "brands": [{"id": 8}],
    "images": [{"id": 234}],
    "attributes": [{"id": 1, "options": ["36", "37"]}],
    "_variations": [
      {
        "sku": "SKU-123-36",
        "regular_price": "99.00",
        "stock_quantity": 5,
        "stock_status": "instock",
        "attributes": [{"id": 1, "option": "36"}]
      }
    ]
  }

Cron:
  */30 * * * * cd /path && php sync-wc.php >> logs/cron.log 2>&1

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

$sync = new WooCommerceDeltaSync($config, $options);
exit($sync->run() ? 0 : 1);

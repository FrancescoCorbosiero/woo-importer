<?php

namespace ResellPiacenza\Import;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

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
 * @package ResellPiacenza\Import
 */
class DeltaSync
{
    private $config;
    private $logger;

    // Options
    private $dry_run = false;
    private $check_only = false;
    private $force_full = false;
    private $verbose = false;
    private $feed_file_input = null;

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
        $this->feed_file_input = $options['feed_file'] ?? null;

        $this->data_dir = Config::projectRoot() . '/data';
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
        $this->logger = LoggerFactory::create('SyncWC', [
            'file' => Config::projectRoot() . '/logs/sync-wc.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
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
     * Load feed from a local JSON file
     *
     * @param string $path File path
     * @return array|null Feed data or null on error
     */
    private function loadFeedFromFile(string $path): ?array
    {
        if (!file_exists($path)) {
            $this->logger->error("Feed file not found: {$path}");
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON error: ' . json_last_error_msg());
            return null;
        }

        return $data;
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
        $cmd = 'php ' . escapeshellarg(Config::projectRoot() . '/bin/import-wc') .
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
        if ($this->feed_file_input) {
            $this->logger->info('  Source: ' . $this->feed_file_input);
        } else {
            $this->logger->info('  Source: API');
        }

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
            // Fetch current feed (from file or API)
            if ($this->feed_file_input) {
                $this->logger->info("Loading feed from {$this->feed_file_input}...");
                $current_feed = $this->loadFeedFromFile($this->feed_file_input);
            } else {
                $this->logger->info('Fetching feed from API...');
                $current_feed = $this->fetchFeedFromAPI();
            }

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

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }

    private function countProducts(array $feed): int
    {
        return count($feed);
    }
}

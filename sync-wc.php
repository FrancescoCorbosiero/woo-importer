<?php
/**
 * WooCommerce Delta Sync
 *
 * Single entrypoint for syncing WC-formatted feeds.
 * Expects feed already in WooCommerce REST API format.
 *
 * Features:
 * - Delta detection (new/updated/removed)
 * - Image uploads for new products
 * - Batch import via import-wc.php
 *
 * Usage:
 *   php sync-wc.php                    # Full sync
 *   php sync-wc.php --dry-run          # Preview changes
 *   php sync-wc.php --check-only       # Just show diff
 *   php sync-wc.php --skip-images      # Skip image uploads
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
    private $skip_images = false;
    private $force_full = false;
    private $verbose = false;

    // Paths
    private $data_dir;
    private $feed_file;
    private $diff_file;
    private $image_map_file;

    // Data
    private $image_map = [];
    private $image_map_changed = false;

    // Stats
    private $stats = [
        'products_new' => 0,
        'products_updated' => 0,
        'products_removed' => 0,
        'products_unchanged' => 0,
        'images_uploaded' => 0,
        'images_skipped' => 0,
        'images_failed' => 0,
    ];

    // Diff products
    private $diff_products = [];

    /**
     * Constructor
     *
     * @param array $config Configuration
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->check_only = $options['check_only'] ?? false;
        $this->skip_images = $options['skip_images'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->verbose = $options['verbose'] ?? false;

        $this->data_dir = __DIR__ . '/data';
        $this->feed_file = $this->data_dir . '/feed-wc.json';
        $this->diff_file = $this->data_dir . '/diff-wc.json';
        $this->image_map_file = __DIR__ . '/image-map.json';

        $this->setupLogger();
        $this->ensureDataDirectory();
        $this->loadImageMap();
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
     * Load image map
     */
    private function loadImageMap(): void
    {
        if (file_exists($this->image_map_file)) {
            $this->image_map = json_decode(file_get_contents($this->image_map_file), true) ?: [];
            $this->logger->debug("Loaded image map: " . count($this->image_map) . " entries");
        }
    }

    /**
     * Save image map
     */
    private function saveImageMap(): void
    {
        if ($this->image_map_changed && !$this->dry_run) {
            file_put_contents(
                $this->image_map_file,
                json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $this->logger->info("   Updated image-map.json");
        }
    }

    /**
     * Generate signature for product comparison (WC format)
     *
     * @param array $product WC-formatted product
     * @return string MD5 hash
     */
    private function getProductSignature(array $product): string
    {
        $sig_data = [
            'name' => $product['name'] ?? '',
            'variations' => [],
        ];

        foreach ($product['_variations'] ?? [] as $var) {
            $option = '';
            if (!empty($var['attributes'][0]['option'])) {
                $option = $var['attributes'][0]['option'];
            }
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
     * Upload image to WordPress
     *
     * @param string $sku Product SKU
     * @param string $url Image URL
     * @param string $name Product name
     * @return int|null Media ID
     */
    private function uploadImage(string $sku, string $url, string $name): ?int
    {
        if (empty($url)) {
            return null;
        }

        try {
            $temp_file = tempnam(sys_get_temp_dir(), 'woo_img_');

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
            ]);

            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || empty($content)) {
                throw new Exception("HTTP {$http_code}");
            }

            file_put_contents($temp_file, $content);

            $info = @getimagesize($temp_file);
            if (!$info) {
                unlink($temp_file);
                throw new Exception("Invalid image");
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                unlink($temp_file);
                throw new Exception("Unsupported type: {$mime}");
            }

            // SEO metadata
            $store = $this->config['store']['name'] ?? 'Store';
            $alt_text = "{$name} - {$sku} - Acquista su {$store}";

            // Build multipart
            $boundary = 'WooUpload' . time();
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . '.' . $ext;

            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$mime}\r\n\r\n";
            $body .= file_get_contents($temp_file) . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"title\"\r\n\r\n{$name}\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n{$alt_text}\r\n";

            $body .= "--{$boundary}--\r\n";

            // Upload
            $wp_url = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/wp/v2/media';
            $auth = base64_encode(
                $this->config['wordpress']['username'] . ':' .
                $this->config['wordpress']['app_password']
            );

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $wp_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Basic {$auth}",
                    "Content-Type: multipart/form-data; boundary={$boundary}",
                ],
                CURLOPT_TIMEOUT => 60,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            unlink($temp_file);

            if ($http_code !== 201) {
                $error = json_decode($response, true);
                throw new Exception($error['message'] ?? "HTTP {$http_code}");
            }

            $result = json_decode($response, true);
            return $result['id'] ?? null;

        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            $this->logger->debug("   Image failed for {$sku}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch feed from REST API (expects WC format)
     *
     * @return array|null WC-formatted products
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
     *
     * @return array|null Saved products
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
     *
     * @return string|null Formatted time
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
     *
     * @param array $current Current feed
     * @param array $saved Saved feed
     */
    private function compareFeedsAndBuildDiff(array $current, array $saved): void
    {
        // Index by SKU
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

        // Find new and updated
        foreach ($current_by_sku as $sku => $product) {
            if (!isset($saved_by_sku[$sku])) {
                // New
                $this->stats['products_new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("   + NEW: {$sku}");
                }
            } else {
                // Check changes
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

        // Find removed
        foreach ($saved_by_sku as $sku => $product) {
            if (!isset($current_by_sku[$sku])) {
                $this->stats['products_removed']++;

                // Zero out stock
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
     * Process images for diff products
     *
     * @param array $products Products needing images
     */
    private function processImages(array $products): void
    {
        if ($this->skip_images) {
            $this->logger->info('   Skipping images (--skip-images)');
            return;
        }

        $to_upload = [];

        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) continue;

            // Already have image?
            if (isset($this->image_map[$sku])) {
                $this->stats['images_skipped']++;
                continue;
            }

            // Has image URL? (stored in _image_url for upload, or extract from images)
            $image_url = $product['_image_url'] ?? null;
            if ($image_url) {
                $to_upload[] = $product;
            }
        }

        if (empty($to_upload)) {
            $this->logger->info("   All images already uploaded");
            return;
        }

        $this->logger->info("   Uploading " . count($to_upload) . " images...");

        $count = count($to_upload);
        foreach ($to_upload as $i => $product) {
            $sku = $product['sku'];
            $progress = $i + 1;

            echo "\r   Progress: {$progress}/{$count} - {$sku}                    ";

            if ($this->dry_run) {
                $this->stats['images_uploaded']++;
                continue;
            }

            $media_id = $this->uploadImage(
                $sku,
                $product['_image_url'] ?? '',
                $product['name'] ?? ''
            );

            if ($media_id) {
                $this->image_map[$sku] = [
                    'media_id' => $media_id,
                    'url' => $product['_image_url'],
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];
                $this->image_map_changed = true;
                $this->stats['images_uploaded']++;

                // Update product with image ID
                // (handled in diff file)
            } else {
                $this->stats['images_failed']++;
            }
        }

        echo "\n";
    }

    /**
     * Inject image IDs into diff products
     */
    private function injectImageIds(): void
    {
        foreach ($this->diff_products as &$product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) continue;

            if (isset($this->image_map[$sku]['media_id'])) {
                $media_id = $this->image_map[$sku]['media_id'];
                $product['images'] = [['id' => $media_id]];
            }

            // Remove internal image URL key
            unset($product['_image_url']);
        }
    }

    /**
     * Print diff summary
     */
    private function printDiffSummary(): void
    {
        $this->logger->info('Changes Detected:');
        $this->logger->info("   + New products:      {$this->stats['products_new']}");
        $this->logger->info("   ~ Updated products:  {$this->stats['products_updated']}");
        $this->logger->info("   - Removed products:  {$this->stats['products_removed']}");
        $this->logger->info("     Unchanged:         {$this->stats['products_unchanged']}");
        $this->logger->info("   ---------------------");

        $total = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];
        $this->logger->info("   Total to sync:       {$total}");
    }

    /**
     * Save feed
     *
     * @param array $feed Feed data
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
     *
     * @return bool Success
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
     *
     * @return bool Success
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  WooCommerce Delta Sync');
        $this->logger->info('================================');
        $this->logger->info('  Feed format: WooCommerce REST API');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }
        if ($this->check_only) {
            $this->logger->info('  CHECK ONLY MODE');
        }
        if ($this->skip_images) {
            $this->logger->info('  SKIP IMAGES MODE');
        }
        if ($this->force_full) {
            $this->logger->warning('  FORCE FULL MODE');
        }

        $this->logger->info('');

        try {
            // Step 1: Fetch current feed
            $this->logger->info('Fetching current feed from API...');
            $current_feed = $this->fetchFeedFromAPI();

            if (empty($current_feed)) {
                $this->logger->error('Failed to fetch feed from API');
                return false;
            }

            $this->logger->info("   " . count($current_feed) . " products loaded");

            // Step 2: Load saved feed
            $this->logger->info('');
            $this->logger->info('Loading saved feed...');
            $saved_feed = $this->loadSavedFeed();

            if ($saved_feed === null || $this->force_full) {
                $reason = $saved_feed === null ? 'First run' : 'Force full requested';
                $this->logger->info("   {$reason} - processing all products");

                $this->diff_products = $current_feed;
                foreach ($this->diff_products as &$p) {
                    $p['_sync_action'] = 'new';
                    $this->stats['products_new']++;
                }
                unset($p);
            } else {
                $saved_time = $this->getSavedFeedTime();
                $this->logger->info("   " . count($saved_feed) . " products from last sync");
                if ($saved_time) {
                    $this->logger->info("   Last sync: {$saved_time}");
                }

                // Step 3: Compare
                $this->logger->info('');
                $this->logger->info('Comparing feeds...');
                $this->compareFeedsAndBuildDiff($current_feed, $saved_feed);
            }

            // Step 4: Report
            $this->logger->info('');
            $this->printDiffSummary();

            $total_changes = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];

            if ($total_changes === 0) {
                $this->logger->info('');
                $this->logger->info('No changes detected - nothing to sync');
                return true;
            }

            // Step 5: Images
            if (!$this->check_only) {
                $this->logger->info('');
                $this->logger->info('Processing images...');
                $this->processImages($this->diff_products);
                $this->saveImageMap();

                // Inject image IDs
                $this->injectImageIds();
            }

            // Step 6: Import
            if (!$this->check_only && !$this->dry_run) {
                // Save current as baseline
                $this->saveFeed($current_feed);

                // Save diff
                $this->saveDiff();

                // Trigger import
                $this->logger->info('');
                $this->logger->info('Triggering import...');
                $this->logger->info('');

                $success = $this->triggerImport();

                $duration = round(microtime(true) - $start_time, 2);
                $this->logger->info('');
                $this->logger->info("Sync complete in {$duration}s");

                return $success;
            }

            $duration = round(microtime(true) - $start_time, 2);
            $this->logger->info('');
            $this->logger->info("Check complete in {$duration}s");

            return true;

        } catch (Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// CLI
// ============================================================================

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
WooCommerce Delta Sync
Syncs WC-formatted feeds to WooCommerce.

Usage:
  php sync-wc.php [options]

Options:
  --dry-run         Preview changes without importing
  --check-only      Check for changes, no import
  --skip-images     Skip image processing
  --force-full      Force full import (ignore diff)
  --verbose, -v     Detailed logging
  --help, -h        Show this help

Expected feed format: WooCommerce REST API (1:1)
  [
    {
      "name": "Product",
      "sku": "SKU-123",
      "type": "variable",
      "_image_url": "https://...",  (optional, for upload)
      "_variations": [...]
    }
  ]

Cron:
  */30 * * * * cd /path && php sync-wc.php >> logs/cron.log 2>&1

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'check_only' => in_array('--check-only', $argv),
    'skip_images' => in_array('--skip-images', $argv),
    'force_full' => in_array('--force-full', $argv),
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
];

$config = require __DIR__ . '/config.php';

$sync = new WooCommerceDeltaSync($config, $options);
$success = $sync->run();

exit($success ? 0 : 1);

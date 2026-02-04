<?php
/**
 * Delta Sync v2 - Uses FeedProxy for WooCommerce-native data format
 *
 * All transformation is done by FeedProxy. This sync:
 * - Compares feeds using WooCommerce field names
 * - Passes WC-ready data to the pass-through importer
 *
 * Usage:
 *   php sync-v2.php                    # Full sync
 *   php sync-v2.php --dry-run          # Preview changes
 *   php sync-v2.php --check-only       # Just show diff
 *   php sync-v2.php --skip-images      # Skip image uploads
 *   php sync-v2.php --force-full       # Force full import
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/FeedProxy.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class DeltaSyncV2
{
    private $config;
    private $logger;
    private $proxy;

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

    // Image map
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

    // Diff data (WooCommerce format)
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
        $this->feed_file = $this->data_dir . '/feed-wc.json';  // WC format
        $this->diff_file = $this->data_dir . '/diff-wc.json';
        $this->image_map_file = __DIR__ . '/image-map.json';

        $this->setupLogger();
        $this->ensureDataDirectory();
        $this->loadImageMap();

        $this->proxy = new FeedProxy($config, [
            'dry_run' => $this->dry_run,
            'verbose' => $this->verbose,
        ]);
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('SyncV2');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/sync-v2.log', 7, Logger::DEBUG)
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
        }
    }

    /**
     * Upload image to WordPress
     *
     * @param string $sku Product SKU
     * @param string $url Image URL
     * @param string $name Product name
     * @param string $brand Brand name
     * @return int|null Media ID
     */
    private function uploadImage(string $sku, string $url, string $name, string $brand): ?int
    {
        try {
            $temp_file = tempnam(sys_get_temp_dir(), 'woo_img_');

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
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
            $store = $this->config['store']['name'] ?? 'ResellPiacenza';

            // Build multipart
            $boundary = 'Upload' . time();
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . '.' . $ext;

            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$mime}\r\n\r\n";
            $body .= file_get_contents($temp_file) . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"title\"\r\n\r\n{$name}\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n";
            $body .= "{$name} - {$sku} - Acquista su {$store}\r\n";

            $body .= "--{$boundary}--\r\n";

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
                throw new Exception("Upload failed: HTTP {$http_code}");
            }

            $result = json_decode($response, true);
            return $result['id'] ?? null;

        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            $this->logger->debug("  Image upload failed for {$sku}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch feed from Golden Sneakers API (raw format)
     *
     * @return array|null Raw GS feed
     */
    private function fetchRawFeed(): ?array
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

        if (curl_errno($ch) || $http_code !== 200) {
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    /**
     * Load saved feed (WC format)
     *
     * @return array|null Saved WC products
     */
    private function loadSavedFeed(): ?array
    {
        if (!file_exists($this->feed_file)) {
            return null;
        }
        return json_decode(file_get_contents($this->feed_file), true);
    }

    /**
     * Compare feeds and build diff
     *
     * Uses WooCommerce field names for comparison.
     *
     * @param array $current Current WC products
     * @param array $saved Saved WC products
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
                // New product
                $this->stats['products_new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("  + NEW: {$sku}");
                }
            } else {
                // Check for changes using WC field names
                $current_sig = FeedProxy::getProductSignature($product);
                $saved_sig = FeedProxy::getProductSignature($saved_by_sku[$sku]);

                if ($current_sig !== $saved_sig) {
                    $this->stats['products_updated']++;
                    $product['_sync_action'] = 'updated';
                    $this->diff_products[] = $product;

                    if ($this->verbose) {
                        $this->logger->debug("  ~ CHANGED: {$sku}");
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

                // Zero out stock in variations
                $product['_sync_action'] = 'removed';
                foreach ($product['_variations'] ?? [] as &$var) {
                    $var['stock_quantity'] = 0;
                    $var['stock_status'] = 'outofstock';
                }
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $this->logger->debug("  - REMOVED: {$sku}");
                }
            }
        }
    }

    /**
     * Process images for diff products
     *
     * @param array $raw_feed Raw GS feed (for image URLs)
     */
    private function processImages(array $raw_feed): void
    {
        if ($this->skip_images) {
            $this->logger->info('  Skipping images (--skip-images)');
            return;
        }

        // Index raw feed by SKU for image URLs
        $raw_by_sku = [];
        foreach ($raw_feed as $p) {
            if ($sku = $p['sku'] ?? null) {
                $raw_by_sku[$sku] = $p;
            }
        }

        $to_upload = [];
        foreach ($this->diff_products as $product) {
            $sku = $product['sku'];

            if (isset($this->image_map[$sku])) {
                $this->stats['images_skipped']++;
                continue;
            }

            if (isset($raw_by_sku[$sku])) {
                $to_upload[] = $raw_by_sku[$sku];
            }
        }

        if (empty($to_upload)) {
            $this->logger->info('  All images already uploaded');
            return;
        }

        $this->logger->info("  Uploading " . count($to_upload) . " images...");

        foreach ($to_upload as $i => $p) {
            echo "\r  Progress: " . ($i + 1) . "/" . count($to_upload) . " - {$p['sku']}     ";

            if ($this->dry_run) {
                $this->stats['images_uploaded']++;
                continue;
            }

            $media_id = $this->uploadImage(
                $p['sku'],
                $p['image_full_url'] ?? '',
                $p['name'] ?? '',
                $p['brand_name'] ?? ''
            );

            if ($media_id) {
                $this->image_map[$p['sku']] = [
                    'media_id' => $media_id,
                    'url' => $p['image_full_url'],
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];
                $this->image_map_changed = true;
                $this->stats['images_uploaded']++;
            } else {
                $this->stats['images_failed']++;
            }
        }
        echo "\n";
    }

    /**
     * Main run
     *
     * @return bool Success
     */
    public function run(): bool
    {
        $start = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Delta Sync v2 (Proxy Model)');
        $this->logger->info('================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }
        if ($this->check_only) {
            $this->logger->info('  CHECK ONLY MODE');
        }

        try {
            // Step 1: Fetch raw feed (for image URLs)
            $this->logger->info('');
            $this->logger->info('Fetching from Golden Sneakers API...');
            $raw_feed = $this->fetchRawFeed();

            if (empty($raw_feed)) {
                $this->logger->error('Failed to fetch feed');
                return false;
            }
            $this->logger->info("  Fetched " . count($raw_feed) . " products");

            // Step 2: Transform to WC format via proxy
            $this->logger->info('');
            $this->logger->info('Transforming to WooCommerce format...');
            $current_wc = $this->proxy->transformFeed($raw_feed);

            // Step 3: Load saved feed (WC format)
            $this->logger->info('');
            $this->logger->info('Loading saved feed...');
            $saved_wc = $this->loadSavedFeed();

            if ($saved_wc === null || $this->force_full) {
                $reason = $saved_wc === null ? 'First run' : 'Force full';
                $this->logger->info("  {$reason} - processing all products");

                $this->diff_products = $current_wc;
                foreach ($this->diff_products as &$p) {
                    $p['_sync_action'] = 'new';
                    $this->stats['products_new']++;
                }
            } else {
                $this->logger->info("  Loaded " . count($saved_wc) . " saved products");

                // Step 4: Compare
                $this->logger->info('');
                $this->logger->info('Comparing feeds (WC format)...');
                $this->compareFeedsAndBuildDiff($current_wc, $saved_wc);
            }

            // Summary
            $this->logger->info('');
            $this->logger->info('Changes Detected:');
            $this->logger->info("  + New:       {$this->stats['products_new']}");
            $this->logger->info("  ~ Updated:   {$this->stats['products_updated']}");
            $this->logger->info("  - Removed:   {$this->stats['products_removed']}");
            $this->logger->info("    Unchanged: {$this->stats['products_unchanged']}");

            $total = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];

            if ($total === 0) {
                $this->logger->info('');
                $this->logger->info('No changes - nothing to sync');
                return true;
            }

            // Step 5: Images
            if (!$this->check_only) {
                $this->logger->info('');
                $this->logger->info('Processing images...');
                $this->processImages($raw_feed);
                $this->saveImageMap();
            }

            // Step 6: Import
            if (!$this->check_only && !$this->dry_run) {
                // Save current as baseline
                file_put_contents(
                    $this->feed_file,
                    json_encode($current_wc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );

                // Save diff
                file_put_contents(
                    $this->diff_file,
                    json_encode($this->diff_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );

                $this->logger->info('');
                $this->logger->info('Triggering import...');
                $this->logger->info('');

                $cmd = 'php ' . escapeshellarg(__DIR__ . '/import-wc.php') .
                       ' --feed=' . escapeshellarg($this->diff_file);

                passthru($cmd, $exit_code);

                $duration = round(microtime(true) - $start, 1);
                $this->logger->info('');
                $this->logger->info("Sync complete in {$duration}s");

                return $exit_code === 0;
            }

            $duration = round(microtime(true) - $start, 1);
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
Delta Sync v2 - Uses FeedProxy for WooCommerce-native format.

Usage:
  php sync-v2.php [options]

Options:
  --dry-run         Preview without changes
  --check-only      Only check for changes
  --skip-images     Skip image processing
  --force-full      Force full import
  --verbose, -v     Verbose output
  --help, -h        Show help

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
$sync = new DeltaSyncV2($config, $options);
exit($sync->run() ? 0 : 1);

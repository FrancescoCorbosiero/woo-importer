<?php
/**
 * Golden Sneakers Delta Sync - Single Entrypoint
 *
 * Handles EVERYTHING:
 * - Feed comparison (delta detection)
 * - Image uploads (for new/changed products)
 * - Product import (via import-batch.php)
 *
 * Usage:
 *   php sync-check.php                 # Full sync (images + products)
 *   php sync-check.php --dry-run       # Preview changes, no actions
 *   php sync-check.php --check-only    # Check diff, no import
 *   php sync-check.php --skip-images   # Skip image processing
 *   php sync-check.php --force-full    # Force full import (ignore diff)
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
    private $skip_images = false;
    private $force_full = false;
    private $ignore_locks = false;
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

    // Changed products
    private $diff_products = [];

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
        $this->skip_images = $options['skip_images'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->ignore_locks = $options['ignore_locks'] ?? false;
        $this->verbose = $options['verbose'] ?? false;

        // Setup paths
        $this->data_dir = __DIR__ . '/data';
        $this->feed_file = $this->data_dir . '/feed.json';
        $this->diff_file = $this->data_dir . '/diff.json';
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
        $this->logger = new Logger('DeltaSync');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/sync.log', 7, Logger::DEBUG)
        );

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
     * Load existing image map
     */
    private function loadImageMap(): void
    {
        if (file_exists($this->image_map_file)) {
            $content = file_get_contents($this->image_map_file);
            $this->image_map = json_decode($content, true) ?: [];
            $this->logger->debug("Loaded image map: " . count($this->image_map) . " entries");
        }
    }

    /**
     * Save image map if changed
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
     * Detect product type by size format
     *
     * Sneakers: numeric sizes (36, 37.5, 42, 10.5)
     * Clothing: letter sizes (S, M, L, XL, XXL)
     *
     * @param array $sizes Array of size data from feed
     * @return string 'sneakers' or 'clothing'
     */
    private function detectProductType(array $sizes): string
    {
        if (empty($sizes)) {
            return 'sneakers';  // Default
        }

        // Check first size's size_eu value
        $first_size = $sizes[0]['size_eu'] ?? '';

        // Letter sizes pattern: S, M, L, XL, XXL, XS, 2XL, 3XL
        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first_size)) {
            return 'clothing';
        }

        // Numeric sizes (with optional decimal): 36, 37.5, 42, 10.5
        if (preg_match('/^\d+\.?\d*$/', $first_size)) {
            return 'sneakers';
        }

        // Default to sneakers for unknown formats
        return 'sneakers';
    }

    /**
     * Generate signature for product comparison
     * Uses presented_price (customer price), not offer_price (wholesale)
     *
     * @param array $product Product data
     * @return string MD5 hash signature
     */
    private function getProductSignature(array $product): string
    {
        $sig_data = [
            'name' => $product['name'] ?? '',
            'brand' => $product['brand_name'] ?? '',
            'image' => $product['image_full_url'] ?? '',
            'sizes' => [],
        ];

        $sizes = $product['sizes'] ?? [];
        foreach ($sizes as $size) {
            $sig_data['sizes'][] = implode(':', [
                $size['size_eu'] ?? '',
                $size['presented_price'] ?? 0,  // Use presented_price (customer price)
                $size['available_quantity'] ?? 0,
            ]);
        }
        sort($sig_data['sizes']);

        return md5(json_encode($sig_data));
    }

    /**
     * Upload image to WordPress
     * Returns media ID or null on failure
     *
     * @param string $sku Product SKU
     * @param string $url Image URL
     * @param string $product_name Product name for SEO
     * @param string $brand_name Brand name for SEO
     * @return int|null Media ID or null on failure
     */
    private function uploadImage(string $sku, string $url, string $product_name, string $brand_name): ?int
    {
        try {
            // Download image
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

            $image_content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || empty($image_content)) {
                throw new Exception("HTTP {$http_code}");
            }

            file_put_contents($temp_file, $image_content);

            // Validate image
            $image_info = @getimagesize($temp_file);
            if ($image_info === false) {
                unlink($temp_file);
                throw new Exception("Invalid image format");
            }

            $mime_type = $image_info['mime'];
            $extension = image_type_to_extension($image_info[2], false);

            if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                unlink($temp_file);
                throw new Exception("Unsupported type: {$mime_type}");
            }

            // Build SEO metadata (Italian)
            $store_name = $this->config['store']['name'] ?? 'ResellPiacenza';
            $title = $product_name;
            $alt_text = "{$product_name} - {$sku} - Acquista su {$store_name}";
            $caption = "{$brand_name} {$product_name}";
            $description = "Acquista {$product_name} ({$sku}) su {$store_name}. Prodotto originale {$brand_name}. Spedizione rapida in tutta Italia.";

            // Sanitize filename
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . '.' . $extension;

            // Build multipart request
            $boundary = 'WooImageUpload' . time();
            $file_content = file_get_contents($temp_file);

            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$mime_type}\r\n\r\n";
            $body .= $file_content . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"title\"\r\n\r\n";
            $body .= $title . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n";
            $body .= $alt_text . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"caption\"\r\n\r\n";
            $body .= $caption . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n";
            $body .= $description . "\r\n";

            $body .= "--{$boundary}--\r\n";

            // Upload to WordPress
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
                $error_data = json_decode($response, true);
                throw new Exception($error_data['message'] ?? "HTTP {$http_code}");
            }

            $result = json_decode($response, true);
            return $result['id'];

        } catch (Exception $e) {
            $this->logger->debug("   Image upload failed for {$sku}: " . $e->getMessage());
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return null;
        }
    }

    /**
     * Process images for diff products
     *
     * @param array $products Products that need images
     */
    private function processImagesForDiff(array $products): void
    {
        if ($this->skip_images) {
            $this->logger->info('');
            $this->logger->info('   Skipping image processing (--skip-images)');
            return;
        }

        $this->logger->info('');
        $this->logger->info('Processing images for changed products...');

        $to_upload = [];

        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) continue;

            // Check if image already exists in map
            if (isset($this->image_map[$sku])) {
                $this->stats['images_skipped']++;
                continue;
            }

            $to_upload[] = $product;
        }

        if (empty($to_upload)) {
            $this->logger->info("   All images already uploaded");
            return;
        }

        $this->logger->info("   Uploading " . count($to_upload) . " new images...");

        $count = count($to_upload);
        foreach ($to_upload as $index => $product) {
            $sku = $product['sku'];
            $progress = $index + 1;

            echo "\r   Progress: {$progress}/{$count} - {$sku}                    ";

            if ($this->dry_run) {
                $this->stats['images_uploaded']++;
                continue;
            }

            $media_id = $this->uploadImage(
                $sku,
                $product['image_full_url'] ?? '',
                $product['name'] ?? '',
                $product['brand_name'] ?? ''
            );

            if ($media_id) {
                $this->image_map[$sku] = [
                    'media_id' => $media_id,
                    'url' => $product['image_full_url'],
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];
                $this->image_map_changed = true;
                $this->stats['images_uploaded']++;
            } else {
                $this->stats['images_failed']++;
            }
        }

        echo "\n";

        $this->logger->info("   Uploaded: {$this->stats['images_uploaded']}");
        if ($this->stats['images_failed'] > 0) {
            $this->logger->warning("   Failed: {$this->stats['images_failed']}");
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
        $this->logger->info('  Single Entrypoint: Images + Products');

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
        if ($this->ignore_locks) {
            $this->logger->warning('  IGNORE LOCKS MODE');
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

            $this->logger->info("   " . count($current_feed) . " products loaded");

            // Step 2: Load saved feed
            $this->logger->info('');
            $this->logger->info('Loading saved feed...');
            $saved_feed = $this->loadSavedFeed();

            if ($saved_feed === null || $this->force_full) {
                $reason = $saved_feed === null ? 'First run' : 'Force full requested';
                $this->logger->info("   {$reason} - processing all products");

                // Treat all products as "new"
                $this->diff_products = $current_feed;
                foreach ($this->diff_products as &$p) {
                    $p['_sync_action'] = 'new';
                    $p['_product_type'] = $this->detectProductType($p['sizes'] ?? []);
                    $this->stats['products_new']++;
                }
                unset($p);
            } else {
                $saved_time = $this->getSavedFeedTime();
                $this->logger->info("   " . count($saved_feed) . " products from last sync");
                if ($saved_time) {
                    $this->logger->info("   Last sync: {$saved_time}");
                }

                // Step 3: Compare feeds
                $this->logger->info('');
                $this->logger->info('Comparing feeds...');
                $this->compareFeedsAndBuildDiff($current_feed, $saved_feed);
            }

            // Step 4: Report findings
            $this->logger->info('');
            $this->printDiffSummary();

            $total_changes = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];

            if ($total_changes === 0) {
                $this->logger->info('');
                $this->logger->info('No changes detected - nothing to sync');
                return true;
            }

            // Step 5: Process images for diff products
            if (!$this->check_only) {
                $this->processImagesForDiff($this->diff_products);
                $this->saveImageMap();
            }

            // Step 6: Trigger import
            if (!$this->check_only && !$this->dry_run) {
                // Save current feed as baseline
                $this->saveFeed($current_feed);

                // Save diff
                $this->saveDiff();

                // Trigger import
                $this->logger->info('');
                $this->logger->info('Triggering batch import...');
                $this->logger->info('');

                $success = $this->triggerDiffImport();

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
     * Compare feeds and build diff
     *
     * @param array $current Current feed from API
     * @param array $saved Saved feed from file
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
            $product['_product_type'] = $this->detectProductType($product['sizes'] ?? []);

            if (!isset($saved_by_sku[$sku])) {
                $this->stats['products_new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;

                if ($this->verbose) {
                    $type = $product['_product_type'];
                    $this->logger->debug("   + NEW [{$type}]: {$sku}");
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

        // Find removed
        foreach ($saved_by_sku as $sku => $product) {
            if (!isset($current_by_sku[$sku])) {
                $this->stats['products_removed']++;

                // Zero out stock
                $product['_sync_action'] = 'removed';
                $product['_product_type'] = $this->detectProductType($product['sizes'] ?? []);
                foreach ($product['sizes'] as &$size) {
                    $size['available_quantity'] = 0;
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
        // Count by type
        $sneakers_count = 0;
        $clothing_count = 0;
        foreach ($this->diff_products as $p) {
            if (($p['_product_type'] ?? 'sneakers') === 'clothing') {
                $clothing_count++;
            } else {
                $sneakers_count++;
            }
        }

        $this->logger->info('Changes Detected:');
        $this->logger->info("   + New products:      {$this->stats['products_new']}");
        $this->logger->info("   ~ Updated products:  {$this->stats['products_updated']}");
        $this->logger->info("   - Removed products:  {$this->stats['products_removed']}");
        $this->logger->info("     Unchanged:         {$this->stats['products_unchanged']}");
        $this->logger->info("   ---------------------");

        $total = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];
        $this->logger->info("   Total to sync:       {$total}");
        $this->logger->info("      Sneakers:         {$sneakers_count}");
        $this->logger->info("      Abbigliamento:    {$clothing_count}");
    }

    /**
     * Save feed to file
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
     * Save diff to file
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
     * Trigger import with diff file
     *
     * @return bool True on success
     */
    private function triggerDiffImport(): bool
    {
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/import-batch.php') .
               ' --feed=' . escapeshellarg($this->diff_file);

        // Pass ignore-locks option if set
        if ($this->ignore_locks) {
            $cmd .= ' --ignore-locks';
        }

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
Golden Sneakers Delta Sync - Single Entrypoint
Handles images + products in one command.

Usage:
  php sync-check.php [options]

Options:
  --dry-run         Preview changes without importing
  --check-only      Check for changes without importing
  --skip-images     Skip image processing
  --force-full      Force full import (ignore diff)
  --ignore-locks    Ignore field locks and overwrite all fields
  --verbose, -v     Show detailed change information
  --help, -h        Show this help message

Field Locking:
  Products can have fields locked to prevent sync from overwriting them.
  This preserves manual edits (SEO content, custom descriptions, etc.)
  Use lock-fields.php to manage locks, or --ignore-locks to bypass.

Examples:
  php sync-check.php                    # Full sync (respects field locks)
  php sync-check.php --check-only       # Just check for changes
  php sync-check.php --dry-run          # Preview what would happen
  php sync-check.php --skip-images      # Products only, no images
  php sync-check.php --force-full       # Force full re-import
  php sync-check.php --ignore-locks     # Sync all fields, ignore locks

Cron setup:
  # Run every 30 minutes (respects field locks)
  */30 * * * * cd /path/to/project && php sync-check.php >> logs/cron.log 2>&1

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'check_only' => in_array('--check-only', $argv),
    'skip_images' => in_array('--skip-images', $argv),
    'force_full' => in_array('--force-full', $argv),
    'ignore_locks' => in_array('--ignore-locks', $argv),
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
];

$config = require __DIR__ . '/config.php';

$checker = new DeltaSyncChecker($config, $options);
$success = $checker->run();

exit($success ? 0 : 1);

<?php
/**
 * WooCommerce Image Pre-Uploader
 *
 * Uploads images from feed to WordPress Media Library.
 * Maintains image-map.json (SKU → media_id) for robust imports.
 *
 * Usage:
 *   php import-images-wc.php                    # Process from API feed
 *   php import-images-wc.php --feed=FILE        # Process from file
 *   php import-images-wc.php --dry-run          # Preview without upload
 *   php import-images-wc.php --force            # Re-upload existing
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class WooCommerceImageUploader
{
    private $config;
    private $logger;

    // Options
    private $dry_run = false;
    private $force = false;
    private $feed_file = null;

    // Image map
    private $image_map = [];
    private $image_map_file;
    private $image_map_changed = false;

    // Stats
    private $stats = [
        'uploaded' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

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
        $this->force = $options['force'] ?? false;
        $this->feed_file = $options['feed_file'] ?? null;

        $this->image_map_file = __DIR__ . '/image-map.json';

        $this->setupLogger();
        $this->loadImageMap();
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('ImageUploader');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/images-wc.log', 7, Logger::DEBUG)
        );
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
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
            $this->logger->info("Saved image-map.json");
        }
    }

    /**
     * Fetch feed from API
     *
     * @return array|null Products
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

        if (curl_errno($ch) || $http_code !== 200) {
            $this->logger->error("API error: HTTP {$http_code}");
            curl_close($ch);
            return null;
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    /**
     * Load feed from file
     *
     * @return array|null Products
     */
    private function loadFeedFromFile(): ?array
    {
        if (!file_exists($this->feed_file)) {
            $this->logger->error("File not found: {$this->feed_file}");
            return null;
        }

        $data = json_decode(file_get_contents($this->feed_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("JSON error: " . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /**
     * Upload image to WordPress
     *
     * @param string $sku Product SKU (used for filename)
     * @param string $url Image URL
     * @param string $name Product name (for SEO)
     * @param int $index Image index (0 = main, 1+ = gallery)
     * @return int|null Media ID
     */
    private function uploadImage(string $sku, string $url, string $name, int $index = 0): ?int
    {
        if (empty($url)) {
            return null;
        }

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

            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200 || empty($content)) {
                throw new Exception("Download failed: HTTP {$http_code}");
            }

            file_put_contents($temp_file, $content);

            // Validate image
            $info = @getimagesize($temp_file);
            if (!$info) {
                unlink($temp_file);
                throw new Exception("Invalid image format");
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                unlink($temp_file);
                throw new Exception("Unsupported type: {$mime}");
            }

            // Build filename and SEO metadata
            $store = $this->config['store']['name'] ?? 'Store';
            $suffix = $index > 0 ? "-{$index}" : '';
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . $suffix . '.' . $ext;

            $title = $name;
            $alt_text = "{$name} - {$sku} - Acquista su {$store}";
            $caption = $name;
            $description = "Acquista {$name} ({$sku}) su {$store}. Spedizione rapida in tutta Italia.";

            // Build multipart request
            $boundary = 'WooImageUpload' . time() . rand(1000, 9999);

            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$mime}\r\n\r\n";
            $body .= file_get_contents($temp_file) . "\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"title\"\r\n\r\n{$title}\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n{$alt_text}\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"caption\"\r\n\r\n{$caption}\r\n";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n{$description}\r\n";

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
                $error = json_decode($response, true);
                throw new Exception($error['message'] ?? "Upload failed: HTTP {$http_code}");
            }

            $result = json_decode($response, true);
            return $result['id'] ?? null;

        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            $this->logger->debug("  Failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get map key for image (SKU + index for galleries)
     *
     * @param string $sku Product SKU
     * @param int $index Image index
     * @return string Map key
     */
    private function getMapKey(string $sku, int $index): string
    {
        return $index === 0 ? $sku : "{$sku}__img{$index}";
    }

    /**
     * Process products and upload images
     *
     * @param array $products WC-formatted products
     */
    private function processProducts(array $products): void
    {
        $total = count($products);
        $current = 0;

        foreach ($products as $product) {
            $current++;
            $sku = $product['sku'] ?? null;
            $name = $product['name'] ?? '';
            $images = $product['images'] ?? [];

            if (!$sku || empty($images)) {
                continue;
            }

            foreach ($images as $index => $image) {
                $src = $image['src'] ?? null;
                if (!$src) {
                    continue;
                }

                $map_key = $this->getMapKey($sku, $index);

                // Skip if already uploaded (unless --force)
                if (isset($this->image_map[$map_key]) && !$this->force) {
                    $this->stats['skipped']++;
                    continue;
                }

                $img_label = $index === 0 ? 'main' : "gallery-{$index}";
                echo "\r  [{$current}/{$total}] {$sku} ({$img_label})                    ";

                if ($this->dry_run) {
                    $this->stats['uploaded']++;
                    continue;
                }

                $media_id = $this->uploadImage($sku, $src, $name, $index);

                if ($media_id) {
                    $this->image_map[$map_key] = [
                        'media_id' => $media_id,
                        'src' => $src,
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ];
                    $this->image_map_changed = true;
                    $this->stats['uploaded']++;
                } else {
                    $this->stats['failed']++;
                }
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
        $this->logger->info('  WooCommerce Image Uploader');
        $this->logger->info('================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }
        if ($this->force) {
            $this->logger->warning('  FORCE MODE (re-upload all)');
        }

        $this->logger->info('');

        // Load feed
        $this->logger->info('Loading feed...');
        $products = $this->feed_file
            ? $this->loadFeedFromFile()
            : $this->fetchFeedFromAPI();

        if (empty($products)) {
            $this->logger->error('No products loaded');
            return false;
        }

        $this->logger->info("  Loaded " . count($products) . " products");
        $this->logger->info("  Existing mappings: " . count($this->image_map));

        // Process
        $this->logger->info('');
        $this->logger->info('Uploading images...');
        $this->processProducts($products);

        // Save
        $this->saveImageMap();

        // Summary
        $duration = round(microtime(true) - $start, 1);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  SUMMARY');
        $this->logger->info('================================');
        $this->logger->info("  Uploaded: {$this->stats['uploaded']}");
        $this->logger->info("  Skipped:  {$this->stats['skipped']}");
        $this->logger->info("  Failed:   {$this->stats['failed']}");
        $this->logger->info("  Duration: {$duration}s");
        $this->logger->info('================================');

        return true;
    }

    /**
     * Get image map (for external use)
     *
     * @return array Image map
     */
    public function getImageMap(): array
    {
        return $this->image_map;
    }
}

// ============================================================================
// CLI
// ============================================================================

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
WooCommerce Image Pre-Uploader
Uploads images to WordPress, maintains image-map.json.

Usage:
  php import-images-wc.php [options]

Options:
  --feed=FILE       Load feed from file instead of API
  --dry-run         Preview without uploading
  --force           Re-upload even if already in map
  --help, -h        Show this help

The image-map.json maps SKU → media_id for use by sync-wc.php.
Supports multiple images per product (galleries).

Examples:
  php import-images-wc.php                    # From API
  php import-images-wc.php --feed=feed.json   # From file
  php import-images-wc.php --force            # Re-upload all

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'force' => in_array('--force', $argv),
    'feed_file' => null,
];

foreach ($argv as $arg) {
    if (strpos($arg, '--feed=') === 0) {
        $options['feed_file'] = str_replace('--feed=', '', $arg);
    }
}

$config = require __DIR__ . '/config.php';
$uploader = new WooCommerceImageUploader($config, $options);
exit($uploader->run() ? 0 : 1);

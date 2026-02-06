<?php
/**
 * Media Preparation
 *
 * Downloads product images from Golden Sneakers API and uploads them
 * to the WordPress media library with Italian SEO metadata.
 * Maintains image-map.json for use by the transform step.
 *
 * Outputs: image-map.json (SKU => {media_id, url, uploaded_at})
 *
 * Usage:
 *   php prepare-media.php                      # Upload new images only
 *   php prepare-media.php --limit=10           # Process first 10 only
 *   php prepare-media.php --force              # Re-upload all images
 *   php prepare-media.php --update-metadata    # Update SEO metadata only
 *   php prepare-media.php --dry-run            # Preview without uploading
 *   php prepare-media.php --verbose            # Detailed output
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class MediaPreparer
{
    private $config;
    private $logger;
    private $image_map = [];

    private $dry_run = false;
    private $verbose = false;
    private $limit = null;
    private $force = false;
    private $update_metadata = false;

    private $stats = [
        'total' => 0,
        'uploaded' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    /**
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->force = $options['force'] ?? false;
        $this->update_metadata = $options['update_metadata'] ?? false;

        $this->setupLogger();
        $this->loadExistingMap();
    }

    private function setupLogger(): void
    {
        $this->logger = new Logger('MediaPrep');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/prepare-media.log', 7, Logger::DEBUG)
        );

        $level = $this->verbose ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $level));
    }

    /**
     * Load existing image map
     */
    private function loadExistingMap(): void
    {
        $map_file = __DIR__ . '/image-map.json';
        if (file_exists($map_file)) {
            $this->image_map = json_decode(file_get_contents($map_file), true) ?: [];
            $this->logger->info("Loaded existing image map: " . count($this->image_map) . " entries");
        }
    }

    /**
     * Save image map to file
     */
    private function saveImageMap(): void
    {
        $map_file = __DIR__ . '/image-map.json';
        file_put_contents(
            $map_file,
            json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Parse template string with product placeholders
     *
     * @param string $template Template with {placeholders}
     * @param array $data Placeholder values
     * @return string Parsed string
     */
    private function parseTemplate(string $template, array $data): string
    {
        return str_replace(
            ['{product_name}', '{brand_name}', '{sku}', '{store_name}'],
            [
                $data['product_name'] ?? '',
                $data['brand_name'] ?? '',
                $data['sku'] ?? '',
                $this->config['store']['name'] ?? 'ResellPiacenza',
            ],
            $template
        );
    }

    /**
     * Fetch products from Golden Sneakers API
     *
     * @return array Products
     */
    private function fetchProducts(): array
    {
        $this->logger->info('Fetching feed from API...');

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
            throw new Exception('CURL Error: ' . curl_error($ch));
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception("API returned HTTP {$http_code}");
        }

        $products = json_decode($response, true);
        if (!is_array($products)) {
            throw new Exception('Invalid JSON response');
        }

        $this->logger->info("  " . count($products) . " products");
        return $products;
    }

    /**
     * Extract unique images from product list
     *
     * @param array $products GS products
     * @return array Image data entries
     */
    private function extractUniqueImages(array $products): array
    {
        $images = [];
        $seen = [];

        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            $url = $product['image_full_url'] ?? null;

            if (!$sku || !$url || isset($seen[$url])) {
                continue;
            }

            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand_name'] ?? '',
            ];
            $seen[$url] = true;
        }

        return $images;
    }

    /**
     * Upload a single image to WordPress media library
     *
     * @param string $file_path Local temp file path
     * @param string $sku Product SKU
     * @param string $extension File extension
     * @param string $mime_type MIME type
     * @param array $template_data Template placeholder values
     * @return int WordPress media ID
     */
    private function uploadToWordPress(
        string $file_path,
        string $sku,
        string $extension,
        string $mime_type,
        array $template_data
    ): int {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $sku) . '.' . $extension;

        // Build Italian SEO metadata from templates
        $title = $template_data['product_name'];
        $alt_text = $this->parseTemplate($this->config['templates']['image_alt'], $template_data);
        $caption = $this->parseTemplate($this->config['templates']['image_caption'], $template_data);
        $description = $this->parseTemplate($this->config['templates']['image_description'], $template_data);

        // Build multipart form data
        $boundary = 'WooImageUpload' . time() . rand(1000, 9999);
        $file_content = file_get_contents($file_path);

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime_type}\r\n\r\n";
        $body .= $file_content . "\r\n";

        foreach (['title' => $title, 'alt_text' => $alt_text, 'caption' => $caption, 'description' => $description] as $field => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$field}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        // WordPress REST API
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

        if ($http_code !== 201) {
            $error = json_decode($response, true);
            throw new Exception($error['message'] ?? "HTTP {$http_code}");
        }

        $result = json_decode($response, true);
        return $result['id'];
    }

    /**
     * Update SEO metadata for an existing media item
     *
     * @param int $media_id WordPress media ID
     * @param array $template_data Template placeholder values
     */
    private function updateMediaMetadata(int $media_id, array $template_data): void
    {
        $wp_url = rtrim($this->config['woocommerce']['url'], '/') . "/wp-json/wp/v2/media/{$media_id}";
        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        $payload = json_encode([
            'title' => $template_data['product_name'],
            'alt_text' => $this->parseTemplate($this->config['templates']['image_alt'], $template_data),
            'caption' => $this->parseTemplate($this->config['templates']['image_caption'], $template_data),
            'description' => $this->parseTemplate($this->config['templates']['image_description'], $template_data),
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $wp_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic {$auth}",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception("Metadata update failed: HTTP {$http_code}");
        }
    }

    /**
     * Delete a media item from WordPress
     *
     * @param int $media_id WordPress media ID
     */
    private function deleteMedia(int $media_id): void
    {
        $wp_url = rtrim($this->config['woocommerce']['url'], '/') . "/wp-json/wp/v2/media/{$media_id}?force=true";
        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $wp_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"],
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Process a single image entry
     *
     * @param array $image_data Image data (sku, url, product_name, brand_name)
     */
    private function processImage(array $image_data): void
    {
        $sku = $image_data['sku'];
        $url = $image_data['url'];
        $temp_file = null;

        $template_data = [
            'product_name' => $image_data['product_name'],
            'brand_name' => $image_data['brand_name'],
            'sku' => $sku,
        ];

        try {
            $exists = isset($this->image_map[$sku]);
            $existing_id = $exists ? ($this->image_map[$sku]['media_id'] ?? null) : null;

            // Skip if exists and not in update mode
            if ($exists && !$this->force && !$this->update_metadata) {
                $this->stats['skipped']++;
                return;
            }

            // Metadata-only update
            if ($this->update_metadata && $exists && $existing_id) {
                if (!$this->dry_run) {
                    $this->updateMediaMetadata($existing_id, $template_data);
                }
                $this->stats['updated']++;
                $this->logger->debug("  Updated metadata: {$sku}");
                return;
            }

            if ($this->dry_run) {
                $this->stats[$exists ? 'updated' : 'uploaded']++;
                return;
            }

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
            if ($info === false) {
                throw new Exception('Invalid image format');
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                throw new Exception("Unsupported type: {$mime}");
            }

            // Delete old media if force re-uploading
            if ($this->force && $exists && $existing_id) {
                $this->deleteMedia($existing_id);
            }

            // Upload to WordPress
            $media_id = $this->uploadToWordPress($temp_file, $sku, $ext, $mime, $template_data);

            unlink($temp_file);
            $temp_file = null;

            // Update map
            $this->image_map[$sku] = [
                'media_id' => $media_id,
                'url' => $url,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $this->stats[$exists ? 'updated' : 'uploaded']++;

        } catch (Exception $e) {
            $this->stats['failed']++;
            $this->logger->error("  Failed {$sku}: " . $e->getMessage());

            if ($temp_file && file_exists($temp_file)) {
                @unlink($temp_file);
            }
        }
    }

    /**
     * Main entry point
     *
     * @return bool Success
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Media Preparation');
        $this->logger->info('================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }
        if ($this->force) {
            $this->logger->warning('  FORCE RE-UPLOAD');
        }
        if ($this->update_metadata) {
            $this->logger->info('  METADATA UPDATE ONLY');
        }

        $this->logger->info('');

        try {
            $products = $this->fetchProducts();
            $images = $this->extractUniqueImages($products);
            $this->stats['total'] = count($images);

            $this->logger->info("{$this->stats['total']} unique images to process");

            if ($this->limit) {
                $images = array_slice($images, 0, $this->limit);
                $this->logger->info("Limited to first {$this->limit}");
            }

            $this->logger->info('');

            $count = count($images);
            foreach ($images as $index => $image_data) {
                $progress = $index + 1;
                $pct = round(($progress / $count) * 100);
                echo "\r  Progress: {$progress}/{$count} ({$pct}%) - {$image_data['sku']}                    ";

                $this->processImage($image_data);

                // Save periodically
                if ($progress % 50 === 0) {
                    $this->saveImageMap();
                }
            }

            echo "\n";

            // Final save
            $this->saveImageMap();

            // Summary
            $duration = round(microtime(true) - $start_time, 1);
            $this->logger->info('');
            $this->logger->info('================================');
            $this->logger->info('  MEDIA SUMMARY');
            $this->logger->info('================================');
            $this->logger->info("  Total:     {$this->stats['total']}");
            $this->logger->info("  Uploaded:  {$this->stats['uploaded']}");
            $this->logger->info("  Updated:   {$this->stats['updated']}");
            $this->logger->info("  Skipped:   {$this->stats['skipped']}");
            $this->logger->info("  Failed:    {$this->stats['failed']}");
            $this->logger->info("  Duration:  {$duration}s");
            $this->logger->info("  Saved to:  image-map.json");
            $this->logger->info('================================');

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
Media Preparation
Downloads and uploads product images to WordPress with Italian SEO metadata.

Usage:
  php prepare-media.php [options]

Options:
  --dry-run             Preview without uploading
  --limit=N             Process first N images only
  --force               Re-upload all images (replaces existing)
  --update-metadata     Update SEO metadata only (no re-upload)
  --verbose, -v         Detailed output
  --help, -h            Show help

Output:
  image-map.json - SKU to WordPress media ID mapping

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
    'force' => in_array('--force', $argv),
    'update_metadata' => in_array('--update-metadata', $argv),
    'limit' => null,
];

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) str_replace('--limit=', '', $arg);
    }
}

$config = require __DIR__ . '/config.php';
$preparer = new MediaPreparer($config, $options);
exit($preparer->run() ? 0 : 1);

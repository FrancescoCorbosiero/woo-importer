<?php
/**
 * Golden Sneakers Image Pre-Import
 * Downloads and uploads all product images to WordPress media library
 * Creates image-map.json for use by main import script
 * 
 * Usage:
 *   php import-images.php                  # Skip existing images
 *   php import-images.php --limit=10       # Process first 10 only
 *   php import-images.php --force-update   # Re-upload and update all images
 *   php import-images.php --update-metadata # Update metadata only (no re-upload)
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ImagePreImporter
{

    private $config;
    private $wc_client;
    private $logger;
    private $image_map = [];
    private $stats = [
        'total' => 0,
        'uploaded' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];
    private $limit = null;
    private $force_update = false;
    private $update_metadata_only = false;

    public function __construct($config, $options = [])
    {
        $this->config = $config;
        $this->limit = $options['limit'] ?? null;
        $this->force_update = $options['force_update'] ?? false;
        $this->update_metadata_only = $options['update_metadata_only'] ?? false;

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->loadExistingMap();
    }

    private function setupLogger()
    {
        $this->logger = new Logger('ImageImport');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

        // Also log to file
        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        $this->logger->pushHandler(new StreamHandler($log_dir . '/image-import.log', Logger::DEBUG));
    }

    private function setupWooCommerceClient()
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'],
                'timeout' => 60,
            ]
        );
    }

    /**
     * Load existing image map if it exists
     */
    private function loadExistingMap()
    {
        $map_file = __DIR__ . '/image-map.json';
        if (file_exists($map_file)) {
            $this->image_map = json_decode(file_get_contents($map_file), true) ?: [];
            $this->logger->info("📂 Loaded existing image map with " . count($this->image_map) . " entries");
        }
    }

    /**
     * Save image map to file
     */
    private function saveImageMap()
    {
        $map_file = __DIR__ . '/image-map.json';
        file_put_contents($map_file, json_encode($this->image_map, JSON_PRETTY_PRINT));
    }

    /**
     * Sanitize filename
     */
    private function sanitizeFileName($filename)
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        $filename = preg_replace('/\.+/', '.', $filename);
        return $filename;
    }

    /**
     * Parse template string with placeholders
     *
     * @param string $template Template string with {placeholders}
     * @param array $data Associative array of placeholder values
     * @return string Parsed string
     */
    private function parseTemplate($template, $data)
    {
        $replacements = [
            '{product_name}' => $data['product_name'] ?? '',
            '{brand_name}' => $data['brand_name'] ?? '',
            '{sku}' => $data['sku'] ?? '',
            '{store_name}' => $this->config['store']['name'] ?? 'ResellPiacenza',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Main runner
     */
    public function run()
    {
        $start_time = microtime(true);

        $this->logger->info('========================================');
        $this->logger->info('  Golden Sneakers Image Pre-Import');
        $this->logger->info('========================================');

        if ($this->force_update) {
            $this->logger->warning('🔄 FORCE UPDATE MODE - Will re-upload all images');
        } elseif ($this->update_metadata_only) {
            $this->logger->warning('📝 METADATA UPDATE MODE - Will only update metadata for existing images');
        }

        $this->logger->info('');

        try {
            // Fetch products
            $products = $this->fetchProductsFromAPI();

            if (empty($products)) {
                $this->logger->error('❌ No products fetched');
                return false;
            }

            // Extract unique images
            $images = $this->extractUniqueImages($products);
            $this->stats['total'] = count($images);

            $this->logger->info("📦 Found {$this->stats['total']} unique images to process");

            if ($this->limit) {
                $images = array_slice($images, 0, $this->limit);
                $this->logger->info("⚡ Processing first {$this->limit} images only");
            }

            $this->logger->info('');

            // Process each image
            $count = count($images);
            foreach ($images as $index => $image_data) {
                $progress = $index + 1;
                $percentage = round(($progress / $count) * 100);

                echo "\r🔄 Progress: {$progress}/{$count} ({$percentage}%) - {$image_data['sku']}                    ";

                $this->processImage($image_data);

                // Save map periodically
                if ($progress % 50 === 0) {
                    $this->saveImageMap();
                }
            }

            echo "\n\n";

            // Save final map
            $this->saveImageMap();

            // Summary
            $this->printSummary($start_time);

            return true;

        } catch (Exception $e) {
            $this->logger->error('❌ Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch products from API
     */
    private function fetchProductsFromAPI()
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
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception("CURL Error: " . curl_error($ch));
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception("API returned HTTP {$http_code}");
        }

        return json_decode($response, true);
    }

    /**
     * Extract unique images from products
     */
    private function extractUniqueImages($products)
    {
        $images = [];
        $seen_urls = [];

        foreach ($products as $product) {
            $url = $product['image_full_url'];
            $sku = $product['sku'];

            if (!in_array($url, $seen_urls)) {
                $images[] = [
                    'sku' => $sku,
                    'url' => $url,
                    'product_name' => $product['name'],
                    'brand_name' => $product['brand_name'],
                ];
                $seen_urls[] = $url;
            }
        }

        return $images;
    }

    /**
     * Process single image
     */
    private function processImage($image_data)
    {
        $sku = $image_data['sku'];
        $url = $image_data['url'];
        $product_name = $image_data['product_name'];
        $brand_name = $image_data['brand_name'];

        try {
            // Check if already exists
            $exists = isset($this->image_map[$sku]);
            $existing_media_id = $exists ? $this->image_map[$sku]['media_id'] : null;

            // Skip if exists and not in update mode
            if ($exists && !$this->force_update && !$this->update_metadata_only) {
                $this->stats['skipped']++;
                return;
            }

            // Update metadata only (no re-upload)
            if ($this->update_metadata_only && $exists && $existing_media_id) {
                $this->updateMediaMetadata($existing_media_id, $sku, $product_name, $brand_name);
                $this->stats['updated']++;
                return;
            }

            // Download and upload image
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
                throw new Exception("HTTP {$http_code} from {$url}");
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

            // Delete old media if updating
            if ($this->force_update && $exists && $existing_media_id) {
                $this->deleteMedia($existing_media_id);
            }

            // Upload to WordPress with metadata
            $media_id = $this->uploadToWordPress(
                $temp_file,
                $sku,
                $extension,
                $mime_type,
                $product_name,
                $brand_name
            );

            unlink($temp_file);

            // Save to map
            $this->image_map[$sku] = [
                'media_id' => $media_id,
                'url' => $url,
                'uploaded_at' => date('Y-m-d H:i:s')
            ];

            if ($exists && $this->force_update) {
                $this->stats['updated']++;
            } else {
                $this->stats['uploaded']++;
            }

        } catch (Exception $e) {
            $this->stats['failed']++;
            $this->logger->error("  ❌ Failed {$sku}: " . $e->getMessage());

            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
        }
    }

    /**
     * Update metadata for existing media
     */
    private function updateMediaMetadata($media_id, $sku, $product_name, $brand_name)
    {
        // Build SEO metadata using Italian templates
        $template_data = [
            'product_name' => $product_name,
            'brand_name' => $brand_name,
            'sku' => $sku,
        ];

        $title = $product_name;
        $alt_text = $this->parseTemplate($this->config['templates']['image_alt'], $template_data);
        $caption = $this->parseTemplate($this->config['templates']['image_caption'], $template_data);
        $description = $this->parseTemplate($this->config['templates']['image_description'], $template_data);

        $wp_url = rtrim($this->config['woocommerce']['url'], '/') . "/wp-json/wp/v2/media/{$media_id}";

        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        $payload = json_encode([
            'title' => $title,
            'alt_text' => $alt_text,
            'caption' => $caption,
            'description' => $description,
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
     * Delete media from WordPress
     */
    private function deleteMedia($media_id)
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
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic {$auth}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Upload image to WordPress with SEO metadata
     */
    private function uploadToWordPress($file_path, $sku, $extension, $mime_type, $product_name, $brand_name)
    {
        $filename = $this->sanitizeFileName($sku . '.' . $extension);

        // Build SEO-optimized metadata using Italian templates
        $template_data = [
            'product_name' => $product_name,
            'brand_name' => $brand_name,
            'sku' => $sku,
        ];

        $title = $product_name;
        $alt_text = $this->parseTemplate($this->config['templates']['image_alt'], $template_data);
        $caption = $this->parseTemplate($this->config['templates']['image_caption'], $template_data);
        $description = $this->parseTemplate($this->config['templates']['image_description'], $template_data);

        // Prepare WordPress REST API request
        $boundary = 'WooCommerceImageUpload' . time();
        $file_content = file_get_contents($file_path);

        // Build multipart form data with metadata
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime_type}\r\n\r\n";
        $body .= $file_content . "\r\n";

        // Add title
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"title\"\r\n\r\n";
        $body .= $title . "\r\n";

        // Add alt_text
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n";
        $body .= $alt_text . "\r\n";

        // Add caption
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"caption\"\r\n\r\n";
        $body .= $caption . "\r\n";

        // Add description
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"description\"\r\n\r\n";
        $body .= $description . "\r\n";

        $body .= "--{$boundary}--\r\n";

        // WordPress REST API endpoint
        $wp_url = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/wp/v2/media';

        // Create authorization header
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
                "Content-Disposition: attachment; filename=\"{$filename}\"",
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 201) {
            $error_data = json_decode($response, true);
            $error_msg = $error_data['message'] ?? "HTTP {$http_code}";
            throw new Exception("Upload failed: {$error_msg}");
        }

        $result = json_decode($response, true);
        return $result['id'];
    }

    /**
     * Print summary
     */
    private function printSummary($start_time)
    {
        $duration = round(microtime(true) - $start_time, 2);

        $this->logger->info('========================================');
        $this->logger->info('  IMAGE IMPORT SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("📊 Total Images:       {$this->stats['total']}");
        $this->logger->info("✅ Uploaded:           {$this->stats['uploaded']}");
        $this->logger->info("🔄 Updated:            {$this->stats['updated']}");
        $this->logger->info("⏭️  Skipped (cached):   {$this->stats['skipped']}");
        $this->logger->info("❌ Failed:             {$this->stats['failed']}");
        $this->logger->info("⏱️  Duration:           {$duration}s");
        $this->logger->info('========================================');

        if ($this->stats['uploaded'] > 0 || $this->stats['updated'] > 0) {
            $this->logger->info('');
            $this->logger->info("✅ Image map saved to: image-map.json");
            $this->logger->info("   You can now run: php import.php");
        }
    }
}

// ============================================================================
// CLI Runner
// ============================================================================

$options = [
    'limit' => null,
    'force_update' => in_array('--force-update', $argv),
    'update_metadata_only' => in_array('--update-metadata', $argv),
];

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) str_replace('--limit=', '', $arg);
    }
}

$config = require __DIR__ . '/config.php';

$importer = new ImagePreImporter($config, $options);
$success = $importer->run();

exit($success ? 0 : 1);
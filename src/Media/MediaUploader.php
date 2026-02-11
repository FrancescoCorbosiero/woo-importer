<?php

namespace ResellPiacenza\Media;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;
use ResellPiacenza\Support\Template;

/**
 * Media Uploader (Source-Agnostic)
 *
 * Downloads product images and uploads them to the WordPress media
 * library with Italian SEO metadata.
 * Maintains image-map.json for use by the transform/import steps.
 *
 * Outputs: image-map.json (SKU => {media_id, url, uploaded_at})
 *
 * @package ResellPiacenza\Media
 */
class MediaUploader
{
    private $config;
    private $logger;
    private $image_map = [];

    private $dry_run = false;
    private $verbose = false;
    private $limit = null;
    private $force = false;
    private $update_metadata = false;

    // Image source
    private $image_source = null; // 'gs', 'file', 'feed', or null
    private $urls_file = null;
    private $feed_file = null;

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
        $this->image_source = $options['image_source'] ?? null;
        $this->urls_file = $options['urls_file'] ?? null;
        $this->feed_file = $options['feed_file'] ?? null;

        $this->setupLogger();
        $this->loadExistingMap();
    }

    private function setupLogger(): void
    {
        $this->logger = LoggerFactory::create('MediaPrep', [
            'file' => Config::projectRoot() . '/logs/prepare-media.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
     * Load existing image map
     */
    private function loadExistingMap(): void
    {
        $map_file = Config::projectRoot() . '/image-map.json';
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
        $map_file = Config::projectRoot() . '/image-map.json';
        file_put_contents(
            $map_file,
            json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Resolve image list from the configured source
     *
     * @return array Image entries [{sku, url, product_name, brand_name}, ...]
     */
    private function resolveImages(): array
    {
        switch ($this->image_source) {
            case 'gs':
                return $this->fetchImagesFromGS();
            case 'file':
                return $this->loadImagesFromFile($this->urls_file);
            case 'feed':
                return $this->loadImagesFromFeed($this->feed_file);
            default:
                throw new \Exception('No image source specified (use --from-gs, --urls-file=FILE, or --from-feed=FILE)');
        }
    }

    /**
     * Fetch images from Golden Sneakers API
     */
    private function fetchImagesFromGS(): array
    {
        $this->logger->info('Fetching GS feed for image discovery...');

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
            throw new \Exception('CURL Error: ' . curl_error($ch));
        }

        curl_close($ch);

        if ($http_code !== 200) {
            throw new \Exception("API returned HTTP {$http_code}");
        }

        $products = json_decode($response, true);
        if (!is_array($products)) {
            throw new \Exception('Invalid JSON response');
        }

        $this->logger->info("  " . count($products) . " products");

        // Extract unique images
        $images = [];
        $seen = [];
        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            $img_url = $product['image_full_url'] ?? null;
            if (!$sku || !$img_url || isset($seen[$img_url])) {
                continue;
            }
            $images[] = [
                'sku' => $sku,
                'url' => $img_url,
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand_name'] ?? '',
            ];
            $seen[$img_url] = true;
        }

        return $images;
    }

    /**
     * Load images from a JSON file
     *
     * Accepts formats:
     *   [{"sku": "X", "url": "https://...", "product_name": "...", "brand_name": "..."}]
     *   [{"sku": "X", "image_url": "https://...", "name": "...", "brand": "..."}]
     */
    private function loadImagesFromFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \Exception("URLs file not found: {$path}");
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \Exception("Invalid JSON in URLs file");
        }

        $images = [];
        $seen = [];
        foreach ($data as $item) {
            $sku = $item['sku'] ?? null;
            $url = $item['url'] ?? $item['image_url'] ?? null;
            if (!$sku || !$url || isset($seen[$url])) {
                continue;
            }
            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'product_name' => $item['product_name'] ?? $item['name'] ?? '',
                'brand_name' => $item['brand_name'] ?? $item['brand'] ?? '',
            ];
            $seen[$url] = true;
        }

        $this->logger->info("  Loaded " . count($images) . " images from {$path}");
        return $images;
    }

    /**
     * Load images from a raw normalized feed file (from import-kicksdb --save-raw)
     *
     * Reads the normalized product format and extracts both primary and gallery URLs.
     * Each image entry includes a 'gallery_urls' key for gallery images.
     *
     * @param string $path Path to feed JSON file
     * @return array Image entries [{sku, url, product_name, brand_name, gallery_urls}, ...]
     */
    private function loadImagesFromFeed(string $path): array
    {
        if (!file_exists($path)) {
            throw new \Exception("Feed file not found: {$path}");
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \Exception("Invalid JSON in feed file");
        }

        $images = [];
        foreach ($data as $product) {
            $sku = $product['sku'] ?? null;
            $url = $product['image_url'] ?? null;
            if (!$sku || !$url) {
                continue;
            }

            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand'] ?? '',
                'gallery_urls' => $product['gallery_urls'] ?? [],
            ];
        }

        $total_gallery = array_sum(array_map(fn($i) => count($i['gallery_urls'] ?? []), $images));
        $this->logger->info("  Loaded " . count($images) . " products from feed ({$total_gallery} gallery images)");
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
        $alt_text = Template::parse($this->config['templates']['image_alt'], $template_data);
        $caption = Template::parse($this->config['templates']['image_caption'], $template_data);
        $description = Template::parse($this->config['templates']['image_description'], $template_data);

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
            throw new \Exception($error['message'] ?? "HTTP {$http_code}");
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
            'alt_text' => Template::parse($this->config['templates']['image_alt'], $template_data),
            'caption' => Template::parse($this->config['templates']['image_caption'], $template_data),
            'description' => Template::parse($this->config['templates']['image_description'], $template_data),
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
            throw new \Exception("Metadata update failed: HTTP {$http_code}");
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
        $gallery_urls = $image_data['gallery_urls'] ?? [];
        $temp_file = null;

        $template_data = [
            'product_name' => $image_data['product_name'],
            'brand_name' => $image_data['brand_name'],
            'sku' => $sku,
            'store_name' => $this->config['store']['name'] ?? 'ResellPiacenza',
        ];

        try {
            $exists = isset($this->image_map[$sku]);
            $existing_id = $exists ? ($this->image_map[$sku]['media_id'] ?? null) : null;
            $existing_gallery = $exists ? ($this->image_map[$sku]['gallery_ids'] ?? []) : [];

            // Check if gallery is already fully uploaded
            $gallery_complete = !empty($gallery_urls)
                ? count($existing_gallery) >= count($gallery_urls)
                : true;

            // Skip if primary + gallery both exist and not in update mode
            if ($exists && $existing_id && $gallery_complete && !$this->force && !$this->update_metadata) {
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

            // Upload primary image (if not already uploaded or force)
            $media_id = $existing_id;
            if (!$existing_id || $this->force) {
                // Delete old media if force re-uploading
                if ($this->force && $existing_id) {
                    $this->deleteMedia($existing_id);
                }

                $media_id = $this->downloadAndUpload($url, $sku, $template_data);
            }

            // Upload gallery images
            $gallery_ids = [];
            if (!empty($gallery_urls)) {
                // Delete old gallery if force re-uploading
                if ($this->force && !empty($existing_gallery)) {
                    foreach ($existing_gallery as $gid) {
                        $this->deleteMedia($gid);
                    }
                }

                if (!$this->force && !empty($existing_gallery)) {
                    // Keep existing gallery
                    $gallery_ids = $existing_gallery;
                } else {
                    foreach ($gallery_urls as $idx => $gallery_url) {
                        try {
                            $gallery_sku = $sku . '-' . ($idx + 1);
                            $gallery_template = [
                                'product_name' => $image_data['product_name'] . ' - ' . ($idx + 1),
                                'brand_name' => $image_data['brand_name'],
                                'sku' => $gallery_sku,
                            ];
                            $gid = $this->downloadAndUpload($gallery_url, $gallery_sku, $gallery_template);
                            $gallery_ids[] = $gid;
                        } catch (\Exception $e) {
                            $this->logger->warning("  Gallery image {$idx} failed for {$sku}: " . $e->getMessage());
                        }
                    }
                }
            }

            // Update map with primary + gallery
            $this->image_map[$sku] = [
                'media_id' => $media_id,
                'gallery_ids' => $gallery_ids,
                'url' => $url,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $this->stats[$exists ? 'updated' : 'uploaded']++;

        } catch (\Exception $e) {
            $this->stats['failed']++;
            $this->logger->error("  Failed {$sku}: " . $e->getMessage());
        }
    }

    /**
     * Download an image from URL and upload to WordPress
     *
     * @param string $url Image URL
     * @param string $sku SKU or identifier for the filename
     * @param array $template_data Template placeholder values
     * @return int WordPress media ID
     */
    private function downloadAndUpload(string $url, string $sku, array $template_data): int
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'woo_img_');

        try {
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
                throw new \Exception("Download failed: HTTP {$http_code}");
            }

            file_put_contents($temp_file, $content);

            $info = @getimagesize($temp_file);
            if ($info === false) {
                throw new \Exception('Invalid image format');
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                throw new \Exception("Unsupported type: {$mime}");
            }

            $media_id = $this->uploadToWordPress($temp_file, $sku, $ext, $mime, $template_data);

            unlink($temp_file);
            return $media_id;

        } catch (\Exception $e) {
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            throw $e;
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
            $images = $this->resolveImages();
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

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

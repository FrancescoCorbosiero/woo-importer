<?php

namespace ResellPiacenza\Media;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

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
    private $image_source = null; // 'gs', 'file', or null
    private $urls_file = null;

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
     * Resolve image list from the configured source
     *
     * @return array Image entries [{sku, url, product_name, brand_name}, ...]
     */
    private function resolveImages(): array
    {
        switch ($this->image_source) {
            case 'gs':
                return $this->fetchImagesFromGS();
            case 'kicksdb':
                return $this->fetchImagesFromKicksDB();
            case 'file':
                return $this->loadImagesFromFile($this->urls_file);
            default:
                throw new \Exception('No image source specified (use --from-gs, --from-kicksdb, or --urls-file=FILE)');
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
     * Fetch images from KicksDB assortment file
     */
    private function fetchImagesFromKicksDB(): array
    {
        $assortment_file = Config::projectRoot() . '/data/kicksdb-assortment.json';

        if (!file_exists($assortment_file)) {
            throw new \Exception("KicksDB assortment not found: {$assortment_file}. Run bin/kicksdb-discover first.");
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $products = $data['products'] ?? [];

        $this->logger->info("  " . count($products) . " products in KicksDB assortment");

        $images = [];
        $seen = [];
        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            $url = $product['image_url'] ?? null;
            if (!$sku || !$url || isset($seen[$url])) {
                continue;
            }
            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand'] ?? '',
            ];
            $seen[$url] = true;
        }

        $this->logger->info("  " . count($images) . " unique images from KicksDB assortment");

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
                throw new \Exception("Download failed: HTTP {$http_code}");
            }

            file_put_contents($temp_file, $content);

            // Validate image
            $info = @getimagesize($temp_file);
            if ($info === false) {
                throw new \Exception('Invalid image format');
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                throw new \Exception("Unsupported type: {$mime}");
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

        } catch (\Exception $e) {
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

    /**
     * Validate image-map entries against WordPress media library
     *
     * Checks each media_id (and gallery_ids) still exists in WordPress.
     * Removes stale entries where media has been deleted.
     *
     * @return array Stats: ['total', 'valid', 'stale', 'removed_skus']
     */
    public function validateMap(): array
    {
        $stats = ['total' => 0, 'valid' => 0, 'stale' => 0, 'removed_skus' => []];
        $stats['total'] = count($this->image_map);

        if (empty($this->image_map)) {
            $this->logger->info('Image map is empty, nothing to validate');
            return $stats;
        }

        $this->logger->info("Validating {$stats['total']} image-map entries...");

        $wp_url = rtrim($this->config['woocommerce']['url'] ?? '', '/');
        $auth = base64_encode(
            ($this->config['wordpress']['username'] ?? '') . ':' .
            ($this->config['wordpress']['app_password'] ?? '')
        );

        // Batch validate: collect all media IDs first, then check in batches
        $sku_to_ids = [];
        foreach ($this->image_map as $sku => $entry) {
            $ids = [];
            if (!empty($entry['media_id'])) {
                $ids[] = $entry['media_id'];
            }
            foreach ($entry['gallery_ids'] ?? [] as $gid) {
                $ids[] = $gid;
            }
            $sku_to_ids[$sku] = $ids;
        }

        // Collect all unique IDs and batch-check them
        $all_ids = [];
        foreach ($sku_to_ids as $ids) {
            $all_ids = array_merge($all_ids, $ids);
        }
        $all_ids = array_unique($all_ids);

        $valid_ids = [];
        $id_chunks = array_chunk($all_ids, 100);

        foreach ($id_chunks as $chunk) {
            $include = implode(',', $chunk);
            $check_url = "{$wp_url}/wp-json/wp/v2/media?include={$include}&per_page=100&_fields=id";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $check_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"],
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $items = json_decode($response, true) ?: [];
                foreach ($items as $item) {
                    if (!empty($item['id'])) {
                        $valid_ids[$item['id']] = true;
                    }
                }
            } else {
                $this->logger->warning("  Media batch check returned HTTP {$http_code}, falling back to individual checks");
                // Fallback: individual HEAD requests
                foreach ($chunk as $mid) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => "{$wp_url}/wp-json/wp/v2/media/{$mid}",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_NOBODY => true,
                        CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"],
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($code === 200) {
                        $valid_ids[$mid] = true;
                    }
                    usleep(50000);
                }
            }
        }

        // Remove stale entries
        foreach ($this->image_map as $sku => $entry) {
            $primary_id = $entry['media_id'] ?? null;

            if ($primary_id && !isset($valid_ids[$primary_id])) {
                $stats['stale']++;
                $stats['removed_skus'][] = $sku;
                unset($this->image_map[$sku]);
                $this->logger->info("  Stale: SKU {$sku} (media_id {$primary_id} no longer exists)");
                continue;
            }

            // Clean stale gallery IDs
            if (!empty($entry['gallery_ids'])) {
                $clean_gallery = array_filter(
                    $entry['gallery_ids'],
                    fn($gid) => isset($valid_ids[$gid])
                );
                $removed_count = count($entry['gallery_ids']) - count($clean_gallery);
                if ($removed_count > 0) {
                    $this->image_map[$sku]['gallery_ids'] = array_values($clean_gallery);
                    $this->logger->debug("  SKU {$sku}: removed {$removed_count} stale gallery IDs");
                }
            }

            $stats['valid']++;
        }

        // Save cleaned map
        if ($stats['stale'] > 0) {
            $this->saveImageMap();
            $this->logger->info("  Cleaned image-map.json: removed {$stats['stale']} stale entries");
        } else {
            $this->logger->info("  All {$stats['valid']} entries are valid");
        }

        return $stats;
    }
}

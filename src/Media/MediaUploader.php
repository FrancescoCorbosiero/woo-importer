<?php

namespace ResellPiacenza\Media;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;
use ResellPiacenza\Support\Storage;

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

    /** @var Storage|null SQLite storage for media map */
    private ?Storage $storage = null;

    /** @var int Number of concurrent downloads/uploads (curl_multi) */
    private int $concurrency = 6;

    /** @var bool Queue images for async processing instead of uploading now */
    private bool $asyncQueue = false;

    /** @var MediaQueue|null Queue instance (when async mode enabled) */
    private ?MediaQueue $mediaQueue = null;

    private $stats = [
        'total' => 0,
        'uploaded' => 0,
        'gallery_uploaded' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    /**
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     * @param Storage|null $storage SQLite storage (replaces image-map.json when available)
     */
    public function __construct(array $config, array $options = [], ?Storage $storage = null)
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->force = $options['force'] ?? false;
        $this->update_metadata = $options['update_metadata'] ?? false;
        $this->image_source = $options['image_source'] ?? null;
        $this->urls_file = $options['urls_file'] ?? null;
        $this->concurrency = $options['concurrency'] ?? 6;
        $this->asyncQueue = $options['async_queue'] ?? false;
        $this->storage = $storage;

        // Initialize async queue if enabled
        if ($this->asyncQueue && $this->storage !== null) {
            $this->mediaQueue = new MediaQueue($this->storage);
        }

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
        $map_file = Config::imageMapFile();
        if (file_exists($map_file)) {
            $this->image_map = json_decode(file_get_contents($map_file), true) ?: [];
            $this->logger->info("Loaded existing image map: " . count($this->image_map) . " entries");
        }
    }

    /**
     * Save image map to file (and to SQLite Storage when available)
     */
    private function saveImageMap(): void
    {
        // Always save JSON file for backward compatibility
        $map_file = Config::imageMapFile();
        file_put_contents(
            $map_file,
            json_encode($this->image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        // Also persist to SQLite Storage
        if ($this->storage !== null) {
            foreach ($this->image_map as $sku => $entry) {
                $url = $entry['url'] ?? '';
                $mediaId = $entry['media_id'] ?? 0;
                if ($url && $mediaId) {
                    $this->storage->setMediaMapping($url, $mediaId, $url);
                }
            }
        }
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
                'gallery_urls' => [],
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand_name'] ?? '',
            ];
            $seen[$img_url] = true;
        }

        return $images;
    }

    /**
     * Fetch images from KicksDB assortment file (primary + gallery)
     */
    private function fetchImagesFromKicksDB(): array
    {
        // Prefer merged assortment (has GS products too), fall back to KicksDB-only
        $merged_file = Config::dataDir() . '/merged-assortment.json';
        $kdb_file = Config::dataDir() . '/kicksdb-assortment.json';

        if (file_exists($merged_file)) {
            $assortment_file = $merged_file;
        } elseif (file_exists($kdb_file)) {
            $assortment_file = $kdb_file;
        } else {
            throw new \Exception("No assortment file found. Run bin/kicksdb-discover first.");
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $products = $data['products'] ?? [];

        $this->logger->info("  " . count($products) . " products in assortment ({$assortment_file})");

        $images = [];
        $seen = [];
        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            $url = $product['image_url'] ?? null;
            if (!$sku || !$url || isset($seen[$url])) {
                continue;
            }

            // Extract gallery URLs from _raw (full API response from discovery)
            $gallery_urls = $this->extractGalleryUrls($product, $url);

            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'gallery_urls' => $gallery_urls,
                'product_name' => $product['name'] ?? '',
                'brand_name' => $product['brand'] ?? '',
            ];
            $seen[$url] = true;
        }

        $gallery_count = array_sum(array_map(fn($i) => count($i['gallery_urls']), $images));
        $this->logger->info("  " . count($images) . " unique images + {$gallery_count} gallery images from assortment");

        return $images;
    }

    /**
     * Extract gallery URLs from a product's _raw data
     *
     * Picks main gallery images + ~6 evenly spaced 360 frames.
     * Same logic as KicksDbAdapter::buildGallery().
     *
     * @param array $product Assortment product entry
     * @param string $primary_url Primary image URL (excluded from gallery)
     * @return array Gallery image URLs
     */
    private function extractGalleryUrls(array $product, string $primary_url): array
    {
        $raw = $product['_raw'] ?? [];
        if (empty($raw)) {
            return [];
        }

        $urls = [];

        // Main gallery images
        foreach ($raw['gallery'] ?? [] as $url) {
            if (is_string($url) && !empty($url)) {
                $urls[] = $url;
            }
        }

        // 360 images: pick ~6 evenly spaced frames
        // Skip first frame — same angle as primary product image (just more padded)
        $gallery_360 = $raw['gallery_360'] ?? [];
        if (!empty($gallery_360) && is_array($gallery_360)) {
            array_shift($gallery_360);
            $total = count($gallery_360);
            $pick_count = min(6, $total);
            $step = max(1, (int) floor($total / $pick_count));
            for ($i = 0; $i < $total && count($urls) < 12; $i += $step) {
                $url = $gallery_360[$i] ?? null;
                if (is_string($url) && !empty($url)) {
                    $urls[] = $url;
                }
            }
        }

        // Remove primary image if duplicated in gallery
        $urls = array_filter($urls, fn($u) => $u !== $primary_url);

        return array_values(array_unique($urls));
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
                'gallery_urls' => [],
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
     * Download an image URL to a temp file and validate it
     *
     * @param string $url Image URL
     * @return array{file: string, mime: string, ext: string} Temp file info
     * @throws \Exception On download or validation failure
     */
    private function downloadAndValidate(string $url): array
    {
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
            @unlink($temp_file);
            throw new \Exception("Download failed: HTTP {$http_code}");
        }

        file_put_contents($temp_file, $content);

        $info = @getimagesize($temp_file);
        if ($info === false) {
            @unlink($temp_file);
            throw new \Exception('Invalid image format');
        }

        $mime = $info['mime'];
        $ext = image_type_to_extension($info[2], false);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            @unlink($temp_file);
            throw new \Exception("Unsupported type: {$mime}");
        }

        return ['file' => $temp_file, 'mime' => $mime, 'ext' => $ext];
    }

    /**
     * Download multiple URLs concurrently using curl_multi
     *
     * @param array $urls Array of URLs to download
     * @param int $concurrency Max concurrent connections
     * @return array Associative array: url => ['content' => string, 'http_code' => int, 'error' => string|null]
     */
    private function downloadBatch(array $urls, int $concurrency): array
    {
        $results = [];
        $queue = $urls;
        $active = [];

        $mh = curl_multi_init();

        // Process in batches
        while (!empty($queue) || !empty($active)) {
            // Fill up to concurrency limit
            while (count($active) < $concurrency && !empty($queue)) {
                $url = array_shift($queue);
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0',
                ]);
                curl_multi_add_handle($mh, $ch);
                $active[(int) $ch] = ['url' => $url, 'handle' => $ch];
            }

            // Execute
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }

            // Check for completed handles
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int) $ch;

                if (isset($active[$key])) {
                    $url = $active[$key]['url'];
                    $content = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);

                    $results[$url] = [
                        'content' => $content,
                        'http_code' => $httpCode,
                        'error' => $error ?: null,
                    ];

                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($active[$key]);
                }
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Upload multiple images to WordPress concurrently using curl_multi
     *
     * @param array $uploadJobs Array of upload jobs: [{file, sku, ext, mime, template_data}, ...]
     * @return array Results: [{sku => media_id}] or [{sku => error_string}]
     */
    private function uploadBatchToWordPress(array $uploadJobs): array
    {
        $wpUrl = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/wp/v2/media';
        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        $results = [];
        $mh = curl_multi_init();
        $active = [];

        foreach ($uploadJobs as $idx => $job) {
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $job['sku']) . '.' . $job['ext'];
            $templateData = $job['template_data'];

            $title = $templateData['product_name'];
            $altText = $this->parseTemplate($this->config['templates']['image_alt'], $templateData);
            $caption = $this->parseTemplate($this->config['templates']['image_caption'], $templateData);
            $description = $this->parseTemplate($this->config['templates']['image_description'], $templateData);

            $boundary = 'WooImageUpload' . time() . rand(1000, 9999) . $idx;
            $fileContent = file_get_contents($job['file']);

            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
            $body .= "Content-Type: {$job['mime']}\r\n\r\n";
            $body .= $fileContent . "\r\n";

            foreach (['title' => $title, 'alt_text' => $altText, 'caption' => $caption, 'description' => $description] as $field => $value) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$field}\"\r\n\r\n";
                $body .= $value . "\r\n";
            }

            $body .= "--{$boundary}--\r\n";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $wpUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Basic {$auth}",
                    "Content-Type: multipart/form-data; boundary={$boundary}",
                ],
                CURLOPT_TIMEOUT => 120,
            ]);

            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = ['handle' => $ch, 'sku' => $job['sku'], 'file' => $job['file']];
        }

        // Execute all uploads
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        // Collect results
        foreach ($active as $key => $info) {
            $ch = $info['handle'];
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $sku = $info['sku'];

            if ($httpCode === 201) {
                $data = json_decode($response, true);
                $results[$sku] = ['media_id' => $data['id']];
            } else {
                $error = json_decode($response, true);
                $results[$sku] = ['error' => $error['message'] ?? "HTTP {$httpCode}"];
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Process a batch of images in parallel (download → validate → upload)
     *
     * @param array $batch Array of image_data entries
     */
    private function processBatch(array $batch): void
    {
        // Step 1: Filter out already-uploaded and collect URLs to download
        $toDownload = [];
        $skuMeta = [];

        foreach ($batch as $imageData) {
            $sku = $imageData['sku'];
            $url = $imageData['url'];
            $galleryUrls = $imageData['gallery_urls'] ?? [];

            $templateData = [
                'product_name' => $imageData['product_name'],
                'brand_name' => $imageData['brand_name'],
                'sku' => $sku,
            ];

            $exists = isset($this->image_map[$sku]);
            $existingId = $exists ? ($this->image_map[$sku]['media_id'] ?? null) : null;
            $existingGallery = $exists ? ($this->image_map[$sku]['gallery_ids'] ?? []) : [];

            $galleryComplete = !empty($existingGallery) || empty($galleryUrls);
            if ($exists && $existingId && $galleryComplete && !$this->force && !$this->update_metadata) {
                $this->stats['skipped']++;
                continue;
            }

            if ($this->update_metadata && $exists && $existingId) {
                if (!$this->dry_run) {
                    $this->updateMediaMetadata($existingId, $templateData);
                }
                $this->stats['updated']++;
                continue;
            }

            if ($this->dry_run) {
                $this->stats[$exists ? 'updated' : 'uploaded']++;
                continue;
            }

            $skuMeta[$sku] = [
                'template_data' => $templateData,
                'exists' => $exists,
                'existing_id' => $existingId,
                'existing_gallery' => $existingGallery,
                'gallery_urls' => $galleryUrls,
            ];

            // Queue primary image for download
            if (!$existingId || $this->force) {
                $toDownload[$url] = $sku;
            }

            // Queue gallery images for download
            if (!empty($galleryUrls) && (empty($existingGallery) || $this->force)) {
                foreach ($galleryUrls as $galIdx => $galUrl) {
                    $galSku = $sku . '-gallery-' . ($galIdx + 1);
                    $toDownload[$galUrl] = $galSku;
                }
            }
        }

        if (empty($toDownload)) {
            return;
        }

        // Step 2: Download all images in parallel
        $downloadResults = $this->downloadBatch(array_keys($toDownload), $this->concurrency);

        // Step 3: Validate downloads and prepare upload jobs
        $uploadJobs = [];
        $tempFiles = [];

        foreach ($downloadResults as $url => $result) {
            $sku = $toDownload[$url];
            $isGallery = str_contains($sku, '-gallery-');
            $baseSku = $isGallery ? preg_replace('/-gallery-\d+$/', '', $sku) : $sku;

            if ($result['error'] || $result['http_code'] !== 200 || empty($result['content'])) {
                $this->logger->warning("  Download failed {$sku}: " . ($result['error'] ?? "HTTP {$result['http_code']}"));
                if (!$isGallery) {
                    $this->stats['failed']++;
                }
                continue;
            }

            // Validate image
            $tempFile = tempnam(sys_get_temp_dir(), 'woo_img_');
            file_put_contents($tempFile, $result['content']);
            $tempFiles[] = $tempFile;

            $info = @getimagesize($tempFile);
            if ($info === false) {
                @unlink($tempFile);
                $this->logger->warning("  Invalid image format: {$sku}");
                if (!$isGallery) {
                    $this->stats['failed']++;
                }
                continue;
            }

            $mime = $info['mime'];
            $ext = image_type_to_extension($info[2], false);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                @unlink($tempFile);
                $this->logger->warning("  Unsupported type {$mime}: {$sku}");
                if (!$isGallery) {
                    $this->stats['failed']++;
                }
                continue;
            }

            // Determine template data from base SKU
            $templateData = $skuMeta[$baseSku]['template_data'] ?? [
                'product_name' => '', 'brand_name' => '', 'sku' => $baseSku,
            ];

            $uploadJobs[] = [
                'file' => $tempFile,
                'sku' => $sku,
                'base_sku' => $baseSku,
                'ext' => $ext,
                'mime' => $mime,
                'template_data' => $templateData,
                'url' => $url,
                'is_gallery' => $isGallery,
            ];
        }

        if (empty($uploadJobs)) {
            return;
        }

        // Step 4: Upload all in parallel (within concurrency limit, batch by chunks)
        $uploadChunks = array_chunk($uploadJobs, $this->concurrency);

        foreach ($uploadChunks as $chunk) {
            $uploadResults = $this->uploadBatchToWordPress($chunk);

            // Step 5: Process results and update image_map
            foreach ($chunk as $job) {
                $sku = $job['sku'];
                $baseSku = $job['base_sku'];
                $result = $uploadResults[$sku] ?? null;

                // Clean up temp file
                @unlink($job['file']);

                if (!$result || isset($result['error'])) {
                    $errorMsg = $result['error'] ?? 'Unknown upload error';
                    $this->logger->error("  Upload failed {$sku}: {$errorMsg}");
                    if (!$job['is_gallery']) {
                        $this->stats['failed']++;
                    }
                    continue;
                }

                $mediaId = $result['media_id'];

                if ($job['is_gallery']) {
                    // Gallery image
                    if (!isset($this->image_map[$baseSku]['gallery_ids'])) {
                        $this->image_map[$baseSku]['gallery_ids'] = [];
                    }
                    $this->image_map[$baseSku]['gallery_ids'][] = $mediaId;
                    $this->stats['gallery_uploaded']++;
                } else {
                    // Primary image - delete old if force mode
                    $meta = $skuMeta[$baseSku] ?? [];
                    if ($this->force && !empty($meta['existing_id'])) {
                        $this->deleteMedia($meta['existing_id']);
                    }

                    $this->image_map[$baseSku] = [
                        'media_id' => $mediaId,
                        'url' => $job['url'],
                        'gallery_ids' => $meta['existing_gallery'] ?? [],
                        'uploaded_at' => date('Y-m-d H:i:s'),
                    ];

                    $this->stats[$meta['exists'] ? 'updated' : 'uploaded']++;
                }
            }
        }
    }

    /**
     * Process a single image entry (primary + gallery) — sequential fallback
     *
     * @param array $image_data Image data (sku, url, gallery_urls, product_name, brand_name)
     */
    private function processImage(array $image_data): void
    {
        $sku = $image_data['sku'];
        $url = $image_data['url'];
        $gallery_urls = $image_data['gallery_urls'] ?? [];

        $template_data = [
            'product_name' => $image_data['product_name'],
            'brand_name' => $image_data['brand_name'],
            'sku' => $sku,
        ];

        $exists = isset($this->image_map[$sku]);
        $existing_id = $exists ? ($this->image_map[$sku]['media_id'] ?? null) : null;
        $existing_gallery = $exists ? ($this->image_map[$sku]['gallery_ids'] ?? []) : [];

        // Skip if primary + gallery already uploaded (and not force mode)
        $gallery_complete = !empty($existing_gallery) || empty($gallery_urls);
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

        // Upload primary image (if not already uploaded)
        if (!$existing_id || $this->force) {
            try {
                if ($this->force && $existing_id) {
                    $this->deleteMedia($existing_id);
                }

                $img = $this->downloadAndValidate($url);
                $media_id = $this->uploadToWordPress($img['file'], $sku, $img['ext'], $img['mime'], $template_data);
                @unlink($img['file']);

                $this->image_map[$sku] = [
                    'media_id' => $media_id,
                    'url' => $url,
                    'gallery_ids' => $existing_gallery,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                ];

                $this->stats[$exists ? 'updated' : 'uploaded']++;
            } catch (\Exception $e) {
                $this->stats['failed']++;
                $this->logger->error("  Failed {$sku}: " . $e->getMessage());
                return; // Skip gallery if primary fails
            }
        }

        // Upload gallery images (if not already uploaded)
        if (!empty($gallery_urls) && (empty($existing_gallery) || $this->force)) {
            if ($this->force && !empty($existing_gallery)) {
                foreach ($existing_gallery as $gid) {
                    $this->deleteMedia($gid);
                }
            }

            $gallery_ids = [];
            foreach ($gallery_urls as $gal_idx => $gal_url) {
                try {
                    $gal_sku = $sku . '-gallery-' . ($gal_idx + 1);
                    $img = $this->downloadAndValidate($gal_url);
                    $gal_id = $this->uploadToWordPress($img['file'], $gal_sku, $img['ext'], $img['mime'], $template_data);
                    @unlink($img['file']);
                    $gallery_ids[] = $gal_id;
                } catch (\Exception $e) {
                    $this->logger->warning("  Gallery {$sku}[{$gal_idx}]: " . $e->getMessage());
                }
            }

            $this->image_map[$sku]['gallery_ids'] = $gallery_ids;

            if (!empty($gallery_ids)) {
                $this->stats['gallery_uploaded'] += count($gallery_ids);
                $this->logger->debug("  {$sku}: uploaded " . count($gallery_ids) . " gallery images");
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

            // Async queue mode: enqueue all images and return immediately
            if ($this->asyncQueue && $this->mediaQueue !== null) {
                return $this->runAsyncEnqueue($images, $start_time);
            }

            $this->logger->info("  Concurrency: {$this->concurrency}");
            $this->logger->info('');

            $count = count($images);

            // Parallel batch processing (curl_multi)
            if ($this->concurrency > 1) {
                $batchSize = $this->concurrency * 2; // Process larger batches to keep pipeline full
                $batches = array_chunk($images, $batchSize);
                $processed = 0;

                foreach ($batches as $batchIdx => $batch) {
                    $processed += count($batch);
                    $pct = round(($processed / $count) * 100);
                    $firstSku = $batch[0]['sku'] ?? '?';
                    echo "\r  Batch " . ($batchIdx + 1) . "/" . count($batches) . " ({$pct}%) - {$firstSku}...                    ";

                    $this->processBatch($batch);

                    // Save periodically
                    if ($processed % 50 < $batchSize) {
                        $this->saveImageMap();
                    }
                }
            } else {
                // Sequential fallback (concurrency=1)
                foreach ($images as $index => $image_data) {
                    $progress = $index + 1;
                    $pct = round(($progress / $count) * 100);
                    echo "\r  Progress: {$progress}/{$count} ({$pct}%) - {$image_data['sku']}                    ";

                    $this->processImage($image_data);

                    if ($progress % 50 === 0) {
                        $this->saveImageMap();
                    }
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
            $this->logger->info("  Total:       {$this->stats['total']}");
            $this->logger->info("  Uploaded:    {$this->stats['uploaded']}");
            $this->logger->info("  Gallery:     {$this->stats['gallery_uploaded']}");
            $this->logger->info("  Updated:     {$this->stats['updated']}");
            $this->logger->info("  Skipped:     {$this->stats['skipped']}");
            $this->logger->info("  Failed:      {$this->stats['failed']}");
            $this->logger->info("  Concurrency: {$this->concurrency}");
            $this->logger->info("  Duration:    {$duration}s");
            $this->logger->info("  Saved to:    image-map.json");
            $this->logger->info('================================');

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enqueue all images for async processing (non-blocking)
     *
     * @param array $images Resolved image list
     * @param float $startTime Start time for duration
     * @return bool Success
     */
    private function runAsyncEnqueue(array $images, float $startTime): bool
    {
        $this->logger->info('  Mode: ASYNC QUEUE (enqueue only, no upload)');
        $this->logger->info('');

        $enqueued = 0;
        $skipped = 0;

        foreach ($images as $imageData) {
            $sku = $imageData['sku'];
            $exists = isset($this->image_map[$sku]);
            $existingId = $exists ? ($this->image_map[$sku]['media_id'] ?? null) : null;
            $existingGallery = $exists ? ($this->image_map[$sku]['gallery_ids'] ?? []) : [];
            $galleryComplete = !empty($existingGallery) || empty($imageData['gallery_urls'] ?? []);

            if ($exists && $existingId && $galleryComplete && !$this->force) {
                $skipped++;
                continue;
            }

            $count = $this->mediaQueue->enqueueProduct($imageData);
            $enqueued += $count;
        }

        $duration = round(microtime(true) - $startTime, 1);
        $queueStats = $this->mediaQueue->getStats();

        $this->logger->info('================================');
        $this->logger->info('  ASYNC QUEUE SUMMARY');
        $this->logger->info('================================');
        $this->logger->info("  Enqueued:     {$enqueued}");
        $this->logger->info("  Skipped:      {$skipped} (already uploaded)");
        $this->logger->info("  Queue total:  {$queueStats['total']}");
        $this->logger->info("  Queue pending:{$queueStats['pending']}");
        $this->logger->info("  Duration:     {$duration}s");
        $this->logger->info('');
        $this->logger->info('  Run bin/process-media-queue to upload in background');
        $this->logger->info('================================');

        return true;
    }

    /**
     * Process queued media uploads (called by bin/process-media-queue)
     *
     * Dequeues batches, downloads and uploads in parallel, updates image-map.
     *
     * @param int $batchSize Items per batch
     * @param int|null $maxItems Max total items to process (null = all pending)
     * @return array Processing stats
     */
    public function processQueue(int $batchSize = 10, ?int $maxItems = null): array
    {
        if ($this->mediaQueue === null) {
            throw new \Exception('Async queue not available (requires Storage)');
        }

        // Reset any stuck processing items
        $reset = $this->mediaQueue->resetStuck(10);
        if ($reset > 0) {
            $this->logger->info("  Reset {$reset} stuck queue items");
        }

        $stats = ['processed' => 0, 'uploaded' => 0, 'failed' => 0, 'skus_updated' => 0];
        $processed = 0;

        while (true) {
            if ($maxItems !== null && $processed >= $maxItems) {
                break;
            }

            $effectiveBatch = $batchSize;
            if ($maxItems !== null) {
                $effectiveBatch = min($batchSize, $maxItems - $processed);
            }

            $items = $this->mediaQueue->dequeue($effectiveBatch);
            if (empty($items)) {
                break;
            }

            $this->logger->info("  Processing batch of " . count($items) . " items...");

            // Collect URLs for parallel download
            $urls = array_column($items, 'source_url');
            $downloadResults = $this->downloadBatch($urls, $this->concurrency);

            // Prepare upload jobs
            $uploadJobs = [];
            $jobToQueueId = [];

            foreach ($items as $item) {
                $url = $item['source_url'];
                $result = $downloadResults[$url] ?? null;

                if (!$result || $result['error'] || $result['http_code'] !== 200 || empty($result['content'])) {
                    $error = $result['error'] ?? "HTTP " . ($result['http_code'] ?? 0);
                    $this->mediaQueue->markFailed((int) $item['id'], "Download: {$error}");
                    $stats['failed']++;
                    $processed++;
                    continue;
                }

                // Validate
                $tempFile = tempnam(sys_get_temp_dir(), 'woo_img_');
                file_put_contents($tempFile, $result['content']);

                $info = @getimagesize($tempFile);
                if ($info === false) {
                    @unlink($tempFile);
                    $this->mediaQueue->markFailed((int) $item['id'], 'Invalid image format');
                    $stats['failed']++;
                    $processed++;
                    continue;
                }

                $mime = $info['mime'];
                $ext = image_type_to_extension($info[2], false);

                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                    @unlink($tempFile);
                    $this->mediaQueue->markFailed((int) $item['id'], "Unsupported: {$mime}");
                    $stats['failed']++;
                    $processed++;
                    continue;
                }

                $uploadSku = $item['type'] === 'gallery'
                    ? $item['sku'] . '-gallery-' . $item['gallery_index']
                    : $item['sku'];

                $uploadJobs[] = [
                    'file' => $tempFile,
                    'sku' => $uploadSku,
                    'ext' => $ext,
                    'mime' => $mime,
                    'template_data' => [
                        'product_name' => $item['product_name'],
                        'brand_name' => $item['brand_name'],
                        'sku' => $item['sku'],
                    ],
                    'queue_id' => (int) $item['id'],
                    'base_sku' => $item['sku'],
                    'type' => $item['type'],
                ];
                $jobToQueueId[$uploadSku] = (int) $item['id'];
            }

            // Parallel upload
            if (!empty($uploadJobs)) {
                $uploadChunks = array_chunk($uploadJobs, $this->concurrency);
                foreach ($uploadChunks as $chunk) {
                    $uploadResults = $this->uploadBatchToWordPress($chunk);

                    foreach ($chunk as $job) {
                        $uploadSku = $job['sku'];
                        $queueId = $job['queue_id'];
                        $baseSku = $job['base_sku'];
                        $result = $uploadResults[$uploadSku] ?? null;

                        @unlink($job['file']);

                        if (!$result || isset($result['error'])) {
                            $error = $result['error'] ?? 'Upload failed';
                            $this->mediaQueue->markFailed($queueId, $error);
                            $stats['failed']++;
                        } else {
                            $mediaId = $result['media_id'];
                            $this->mediaQueue->markCompleted($queueId, $mediaId);
                            $stats['uploaded']++;

                            // Update image-map immediately
                            if ($job['type'] === 'primary') {
                                $this->image_map[$baseSku] = [
                                    'media_id' => $mediaId,
                                    'url' => $items[array_search($queueId, array_column($items, 'id', 'id'))] ['source_url'] ?? '',
                                    'gallery_ids' => $this->image_map[$baseSku]['gallery_ids'] ?? [],
                                    'uploaded_at' => date('Y-m-d H:i:s'),
                                ];
                            } else {
                                if (!isset($this->image_map[$baseSku]['gallery_ids'])) {
                                    $this->image_map[$baseSku]['gallery_ids'] = [];
                                }
                                $this->image_map[$baseSku]['gallery_ids'][] = $mediaId;
                            }
                        }

                        $processed++;
                    }
                }
            }

            // Save image map after each batch
            $this->saveImageMap();
            $stats['processed'] = $processed;

            $this->logger->info("  Batch done: {$stats['uploaded']} uploaded, {$stats['failed']} failed");
        }

        // Sync completed queue items to image-map and purge
        $completedSkus = $this->mediaQueue->getSkusWithCompletedUploads();
        foreach ($completedSkus as $sku) {
            $completed = $this->mediaQueue->getCompletedForSku($sku);
            if ($completed['primary']) {
                $this->image_map[$sku] = array_merge(
                    $this->image_map[$sku] ?? [],
                    ['media_id' => $completed['primary'], 'uploaded_at' => date('Y-m-d H:i:s')]
                );
            }
            if (!empty($completed['gallery'])) {
                $this->image_map[$sku]['gallery_ids'] = $completed['gallery'];
            }
            $this->mediaQueue->purgeCompleted($sku);
            $stats['skus_updated']++;
        }

        $this->saveImageMap();
        return $stats;
    }

    /**
     * Get the MediaQueue instance (for external status checks)
     *
     * @return MediaQueue|null
     */
    public function getMediaQueue(): ?MediaQueue
    {
        return $this->mediaQueue;
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

<?php
/**
 * Image Importer - handles image uploads to WordPress media library
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Image_Importer {

    /**
     * Config array
     *
     * @var array
     */
    private $config;

    /**
     * Logger instance
     *
     * @var GSPS_Logger
     */
    private $logger;

    /**
     * Image map
     *
     * @var array
     */
    private $image_map = [];

    /**
     * Stats
     *
     * @var array
     */
    private $stats = [
        'total' => 0,
        'uploaded' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    /**
     * Constructor
     *
     * @param array $config
     * @param GSPS_Logger $logger
     */
    public function __construct(array $config, GSPS_Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
        $this->load_image_map();
    }

    /**
     * Load image map from option
     */
    private function load_image_map() {
        $this->image_map = get_option('gsps_image_map', []);
    }

    /**
     * Save image map to option
     */
    private function save_image_map() {
        update_option('gsps_image_map', $this->image_map);
    }

    /**
     * Parse template string
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    private function parse_template($template, array $data) {
        $replacements = [
            '{product_name}' => $data['product_name'] ?? '',
            '{brand_name}' => $data['brand_name'] ?? '',
            '{sku}' => $data['sku'] ?? '',
            '{store_name}' => $this->config['store']['name'] ?? 'ResellPiacenza',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Sanitize filename
     *
     * @param string $filename
     * @return string
     */
    private function sanitize_filename($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        $filename = preg_replace('/\.+/', '.', $filename);
        return $filename;
    }

    /**
     * Download image from URL
     *
     * @param string $url
     * @return string|WP_Error Temp file path or error
     */
    private function download_image($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('download_failed', "HTTP {$code}");
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_response', 'Empty response body');
        }

        // Save to temp file
        $temp_file = wp_tempnam('gsps_img_');
        file_put_contents($temp_file, $body);

        return $temp_file;
    }

    /**
     * Upload image to WordPress media library
     *
     * @param string $file_path Local file path
     * @param string $sku Product SKU
     * @param string $product_name Product name
     * @param string $brand_name Brand name
     * @return int|WP_Error Attachment ID or error
     */
    private function upload_to_media_library($file_path, $sku, $product_name, $brand_name) {
        // Validate image
        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            return new WP_Error('invalid_image', 'Invalid image format');
        }

        $mime_type = $image_info['mime'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($mime_type, $allowed_types)) {
            return new WP_Error('unsupported_type', "Unsupported type: {$mime_type}");
        }

        $extension = image_type_to_extension($image_info[2], false);
        $filename = $this->sanitize_filename($sku . '.' . $extension);

        // Build SEO metadata
        $template_data = [
            'product_name' => $product_name,
            'brand_name' => $brand_name,
            'sku' => $sku,
        ];

        $title = $product_name;
        $alt_text = $this->parse_template($this->config['templates']['image_alt'], $template_data);
        $caption = $this->parse_template($this->config['templates']['image_caption'], $template_data);
        $description = $this->parse_template($this->config['templates']['image_description'], $template_data);

        // Prepare file array for WordPress
        $file_array = [
            'name' => $filename,
            'tmp_name' => $file_path,
        ];

        // Include required WordPress functions
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0, $title);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Update metadata
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        wp_update_post([
            'ID' => $attachment_id,
            'post_title' => $title,
            'post_excerpt' => $caption,
            'post_content' => $description,
        ]);

        return $attachment_id;
    }

    /**
     * Process single image
     *
     * @param array $image_data
     * @return bool
     */
    private function process_image(array $image_data) {
        $sku = $image_data['sku'];
        $url = $image_data['url'];
        $product_name = $image_data['product_name'];
        $brand_name = $image_data['brand_name'];

        // Check if already exists
        if (isset($this->image_map[$sku])) {
            $this->stats['skipped']++;
            return true;
        }

        // Download image
        $temp_file = $this->download_image($url);

        if (is_wp_error($temp_file)) {
            $this->stats['failed']++;
            $this->logger->error("Download failed for {$sku}: " . $temp_file->get_error_message());
            return false;
        }

        // Upload to media library
        $attachment_id = $this->upload_to_media_library($temp_file, $sku, $product_name, $brand_name);

        // Clean up temp file
        @unlink($temp_file);

        if (is_wp_error($attachment_id)) {
            $this->stats['failed']++;
            $this->logger->error("Upload failed for {$sku}: " . $attachment_id->get_error_message());
            return false;
        }

        // Save to image map
        $this->image_map[$sku] = [
            'media_id' => $attachment_id,
            'url' => $url,
            'uploaded_at' => current_time('mysql'),
        ];

        $this->stats['uploaded']++;

        return true;
    }

    /**
     * Extract unique images from products
     *
     * @param array $products
     * @return array
     */
    private function extract_unique_images(array $products) {
        $images = [];
        $seen_urls = [];

        foreach ($products as $product) {
            $url = $product['image_full_url'] ?? null;
            $sku = $product['sku'] ?? null;

            if (!$url || !$sku) {
                continue;
            }

            if (!in_array($url, $seen_urls)) {
                $images[] = [
                    'sku' => $sku,
                    'url' => $url,
                    'product_name' => $product['name'] ?? '',
                    'brand_name' => $product['brand_name'] ?? '',
                ];
                $seen_urls[] = $url;
            }
        }

        return $images;
    }

    /**
     * Run image import
     *
     * @param array $products Products with images to import
     * @return array Stats
     */
    public function run(array $products) {
        $start_time = microtime(true);

        $this->logger->info('Starting image import');

        // Extract unique images
        $images = $this->extract_unique_images($products);
        $this->stats['total'] = count($images);

        $this->logger->info("Found {$this->stats['total']} unique images to process");

        // Process each image
        $count = count($images);
        foreach ($images as $index => $image_data) {
            $progress = $index + 1;
            $this->logger->debug("[{$progress}/{$count}] Processing: {$image_data['sku']}");

            $this->process_image($image_data);

            // Save map periodically
            if ($progress % 50 === 0) {
                $this->save_image_map();
            }
        }

        // Final save
        $this->save_image_map();

        $duration = round(microtime(true) - $start_time, 2);

        // Log summary
        $this->logger->info('Image import completed in ' . $duration . 's');
        $this->logger->info('Total: ' . $this->stats['total']);
        $this->logger->info('Uploaded: ' . $this->stats['uploaded']);
        $this->logger->info('Skipped: ' . $this->stats['skipped']);
        $this->logger->info('Failed: ' . $this->stats['failed']);

        return [
            'success' => true,
            'stats' => $this->stats,
            'duration' => $duration,
        ];
    }

    /**
     * Get image map
     *
     * @return array
     */
    public function get_image_map() {
        return $this->image_map;
    }

    /**
     * Get stats
     *
     * @return array
     */
    public function get_stats() {
        return $this->stats;
    }
}

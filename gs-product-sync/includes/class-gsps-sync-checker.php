<?php
/**
 * Sync Checker - handles delta sync detection and orchestration
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Sync_Checker {

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
     * Options
     *
     * @var array
     */
    private $options = [
        'skip_images' => false,
        'force_full' => false,
    ];

    /**
     * Stats
     *
     * @var array
     */
    private $stats = [
        'products_new' => 0,
        'products_updated' => 0,
        'products_removed' => 0,
        'products_unchanged' => 0,
    ];

    /**
     * Diff products
     *
     * @var array
     */
    private $diff_products = [];

    /**
     * Option name for saved feed
     */
    const FEED_OPTION = 'gsps_saved_feed';

    /**
     * Option name for last sync time
     */
    const LAST_SYNC_OPTION = 'gsps_last_sync';

    /**
     * Constructor
     *
     * @param array $config
     * @param GSPS_Logger $logger
     * @param array $options
     */
    public function __construct(array $config, GSPS_Logger $logger, array $options = []) {
        $this->config = $config;
        $this->logger = $logger;
        $this->options = wp_parse_args($options, $this->options);
    }

    /**
     * Detect product type by size format
     *
     * @param array $sizes
     * @return string
     */
    private function detect_product_type(array $sizes) {
        if (empty($sizes)) {
            return 'sneakers';
        }

        $first_size = $sizes[0]['size_eu'] ?? '';

        if (preg_match('/^[XSML]{1,3}L?$|^\d*XL$/i', $first_size)) {
            return 'clothing';
        }

        if (preg_match('/^\d+\.?\d*$/', $first_size)) {
            return 'sneakers';
        }

        return 'sneakers';
    }

    /**
     * Generate product signature for comparison
     *
     * @param array $product
     * @return string
     */
    private function get_product_signature(array $product) {
        $sig_data = [
            'name' => $product['name'] ?? '',
            'brand' => $product['brand_name'] ?? '',
            'image' => $product['image_full_url'] ?? '',
            'sizes' => [],
        ];

        foreach ($product['sizes'] ?? [] as $size) {
            $sig_data['sizes'][] = implode(':', [
                $size['size_eu'] ?? '',
                $size['presented_price'] ?? 0,
                $size['available_quantity'] ?? 0,
            ]);
        }
        sort($sig_data['sizes']);

        return md5(wp_json_encode($sig_data));
    }

    /**
     * Compare feeds and build diff
     *
     * @param array $current
     * @param array $saved
     */
    private function compare_feeds(array $current, array $saved) {
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
            $product['_product_type'] = $this->detect_product_type($product['sizes'] ?? []);

            if (!isset($saved_by_sku[$sku])) {
                $this->stats['products_new']++;
                $product['_sync_action'] = 'new';
                $this->diff_products[] = $product;
                $this->logger->debug("NEW: {$sku}");
            } else {
                $current_sig = $this->get_product_signature($product);
                $saved_sig = $this->get_product_signature($saved_by_sku[$sku]);

                if ($current_sig !== $saved_sig) {
                    $this->stats['products_updated']++;
                    $product['_sync_action'] = 'updated';
                    $this->diff_products[] = $product;
                    $this->logger->debug("CHANGED: {$sku}");
                } else {
                    $this->stats['products_unchanged']++;
                }
            }
        }

        // Find removed (zero out stock)
        foreach ($saved_by_sku as $sku => $product) {
            if (!isset($current_by_sku[$sku])) {
                $this->stats['products_removed']++;
                $product['_sync_action'] = 'removed';
                $product['_product_type'] = $this->detect_product_type($product['sizes'] ?? []);

                // Zero out stock
                foreach ($product['sizes'] as &$size) {
                    $size['available_quantity'] = 0;
                }

                $this->diff_products[] = $product;
                $this->logger->debug("REMOVED: {$sku}");
            }
        }
    }

    /**
     * Run sync check and import
     *
     * @return array Result with stats
     */
    public function run() {
        $start_time = microtime(true);

        $this->logger->info('Starting delta sync');

        // Fetch current feed from API
        $this->logger->info('Fetching current feed from API...');

        $api_client = new GSPS_API_Client($this->config);
        $current_feed = $api_client->fetch_products();

        if (is_wp_error($current_feed)) {
            $this->logger->error('Failed to fetch feed: ' . $current_feed->get_error_message());
            return [
                'success' => false,
                'error' => $current_feed->get_error_message(),
            ];
        }

        if (empty($current_feed)) {
            $this->logger->error('Empty feed received from API');
            return [
                'success' => false,
                'error' => 'Empty feed',
            ];
        }

        $this->logger->info('Fetched ' . count($current_feed) . ' products from API');

        // Load saved feed
        $saved_feed = get_option(self::FEED_OPTION, null);
        $last_sync = get_option(self::LAST_SYNC_OPTION, null);

        if ($saved_feed === null || $this->options['force_full']) {
            $reason = $saved_feed === null ? 'First run' : 'Force full requested';
            $this->logger->info("{$reason} - processing all products");

            // Treat all as new
            foreach ($current_feed as $product) {
                $product['_sync_action'] = 'new';
                $product['_product_type'] = $this->detect_product_type($product['sizes'] ?? []);
                $this->diff_products[] = $product;
                $this->stats['products_new']++;
            }
        } else {
            $this->logger->info('Loaded saved feed with ' . count($saved_feed) . ' products');
            if ($last_sync) {
                $this->logger->info('Last sync: ' . $last_sync);
            }

            // Compare feeds
            $this->logger->info('Comparing feeds...');
            $this->compare_feeds($current_feed, $saved_feed);
        }

        // Report diff stats
        $this->logger->info('Diff results:');
        $this->logger->info('  New: ' . $this->stats['products_new']);
        $this->logger->info('  Updated: ' . $this->stats['products_updated']);
        $this->logger->info('  Removed: ' . $this->stats['products_removed']);
        $this->logger->info('  Unchanged: ' . $this->stats['products_unchanged']);

        $total_changes = $this->stats['products_new'] + $this->stats['products_updated'] + $this->stats['products_removed'];

        if ($total_changes === 0) {
            $this->logger->info('No changes detected - nothing to sync');

            // Update last sync time anyway
            update_option(self::LAST_SYNC_OPTION, current_time('mysql'));

            return [
                'success' => true,
                'message' => 'No changes detected',
                'stats' => $this->stats,
                'duration' => round(microtime(true) - $start_time, 2),
            ];
        }

        // Process images for diff products (if not skipped)
        $image_stats = null;
        if (!$this->options['skip_images']) {
            $this->logger->info('Processing images for changed products...');

            $image_importer = new GSPS_Image_Importer($this->config, $this->logger);
            $image_result = $image_importer->run($this->diff_products);
            $image_stats = $image_result['stats'];
        }

        // Run product import
        $this->logger->info('Starting product import...');

        $importer = new GSPS_Importer($this->config, $this->logger);
        $import_result = $importer->run($this->diff_products);

        // Save current feed as baseline
        update_option(self::FEED_OPTION, $current_feed);
        update_option(self::LAST_SYNC_OPTION, current_time('mysql'));

        $duration = round(microtime(true) - $start_time, 2);

        $this->logger->info("Sync completed in {$duration}s");

        return [
            'success' => true,
            'message' => 'Sync completed',
            'diff_stats' => $this->stats,
            'import_stats' => $import_result['stats'],
            'image_stats' => $image_stats,
            'duration' => $duration,
        ];
    }

    /**
     * Get diff stats
     *
     * @return array
     */
    public function get_stats() {
        return $this->stats;
    }

    /**
     * Get last sync info
     *
     * @return array
     */
    public static function get_last_sync_info() {
        $last_sync = get_option(self::LAST_SYNC_OPTION, null);
        $saved_feed = get_option(self::FEED_OPTION, null);

        return [
            'last_sync' => $last_sync,
            'products_count' => $saved_feed ? count($saved_feed) : 0,
        ];
    }

    /**
     * Clear saved feed (force full sync next time)
     *
     * @return bool
     */
    public static function clear_saved_feed() {
        delete_option(self::FEED_OPTION);
        return true;
    }
}

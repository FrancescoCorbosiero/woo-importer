<?php
/**
 * AJAX handlers for admin actions
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        // Sync actions
        add_action('wp_ajax_gsps_run_sync', [$this, 'run_sync']);
        add_action('wp_ajax_gsps_run_full_sync', [$this, 'run_full_sync']);
        add_action('wp_ajax_gsps_run_images_only', [$this, 'run_images_only']);

        // Test actions
        add_action('wp_ajax_gsps_test_api', [$this, 'test_api']);

        // Log actions
        add_action('wp_ajax_gsps_get_logs', [$this, 'get_logs']);
        add_action('wp_ajax_gsps_clear_logs', [$this, 'clear_logs']);

        // Status actions
        add_action('wp_ajax_gsps_get_status', [$this, 'get_status']);
    }

    /**
     * Verify nonce and capability
     *
     * @return bool
     */
    private function verify_request() {
        if (!check_ajax_referer('gsps_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return false;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return false;
        }

        return true;
    }

    /**
     * Run delta sync
     */
    public function run_sync() {
        if (!$this->verify_request()) {
            return;
        }

        // Increase time limit
        set_time_limit(300);

        $logger = new GSPS_Logger('manual_sync');
        $logger->info('Manual sync triggered');

        $settings = new GSPS_Settings();
        $config = $settings->get_importer_config();

        $options = [
            'skip_images' => false,
            'force_full' => false,
        ];

        $sync_checker = new GSPS_Sync_Checker($config, $logger, $options);
        $result = $sync_checker->run();

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'stats' => $result['diff_stats'] ?? null,
                'import_stats' => $result['import_stats'] ?? null,
                'duration' => $result['duration'],
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? 'Sync failed',
            ]);
        }
    }

    /**
     * Run full sync (force)
     */
    public function run_full_sync() {
        if (!$this->verify_request()) {
            return;
        }

        // Increase time limit
        set_time_limit(600);

        $logger = new GSPS_Logger('manual_full_sync');
        $logger->info('Manual full sync triggered');

        $settings = new GSPS_Settings();
        $config = $settings->get_importer_config();

        $options = [
            'skip_images' => false,
            'force_full' => true,
        ];

        $sync_checker = new GSPS_Sync_Checker($config, $logger, $options);
        $result = $sync_checker->run();

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'stats' => $result['diff_stats'] ?? null,
                'import_stats' => $result['import_stats'] ?? null,
                'duration' => $result['duration'],
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? 'Sync failed',
            ]);
        }
    }

    /**
     * Run images only
     */
    public function run_images_only() {
        if (!$this->verify_request()) {
            return;
        }

        // Increase time limit
        set_time_limit(600);

        $logger = new GSPS_Logger('manual_images');
        $logger->info('Manual image import triggered');

        $settings = new GSPS_Settings();
        $config = $settings->get_importer_config();

        // Fetch products from API
        $api_client = new GSPS_API_Client($config);
        $products = $api_client->fetch_products();

        if (is_wp_error($products)) {
            wp_send_json_error([
                'message' => $products->get_error_message(),
            ]);
            return;
        }

        $image_importer = new GSPS_Image_Importer($config, $logger);
        $result = $image_importer->run($products);

        wp_send_json_success([
            'message' => 'Image import completed',
            'stats' => $result['stats'],
            'duration' => $result['duration'],
        ]);
    }

    /**
     * Test API connection
     */
    public function test_api() {
        if (!$this->verify_request()) {
            return;
        }

        $settings = new GSPS_Settings();
        $config = $settings->get_importer_config();

        $api_client = new GSPS_API_Client($config);
        $result = $api_client->test_connection();

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'count' => $result['count'],
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * Get logs
     */
    public function get_logs() {
        if (!$this->verify_request()) {
            return;
        }

        $args = [
            'context' => sanitize_text_field($_POST['context'] ?? ''),
            'level' => isset($_POST['level']) ? array_map('sanitize_text_field', (array) $_POST['level']) : null,
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'per_page' => absint($_POST['per_page'] ?? 50),
            'page' => absint($_POST['page'] ?? 1),
        ];

        // Remove empty values
        $args = array_filter($args);

        $result = GSPS_Logger::get_logs($args);

        wp_send_json_success($result);
    }

    /**
     * Clear all logs
     */
    public function clear_logs() {
        if (!$this->verify_request()) {
            return;
        }

        GSPS_Logger::clear_all();

        wp_send_json_success([
            'message' => 'Logs cleared',
        ]);
    }

    /**
     * Get current status
     */
    public function get_status() {
        if (!$this->verify_request()) {
            return;
        }

        $settings = new GSPS_Settings();
        $sync_info = GSPS_Sync_Checker::get_last_sync_info();
        $last_run = GSPS_Scheduler::get_last_run();
        $next_run = GSPS_Scheduler::get_next_run();
        $log_stats = GSPS_Logger::get_stats();
        $image_map = get_option('gsps_image_map', []);

        wp_send_json_success([
            'api_configured' => $settings->is_api_configured(),
            'scheduler_active' => GSPS_Scheduler::is_scheduled(),
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'last_sync' => $sync_info['last_sync'],
            'products_in_feed' => $sync_info['products_count'],
            'last_scheduled_run' => $last_run,
            'log_stats' => $log_stats,
            'images_count' => count($image_map),
        ]);
    }
}

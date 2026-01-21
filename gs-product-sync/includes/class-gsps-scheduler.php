<?php
/**
 * Scheduler - handles WP-Cron scheduling for automatic sync
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Scheduler {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'gsps_scheduled_sync';

    /**
     * Cleanup cron hook
     */
    const CLEANUP_HOOK = 'gsps_scheduled_cleanup';

    /**
     * Constructor
     */
    public function __construct() {
        // Register custom intervals
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        // Register cron hooks
        add_action(self::CRON_HOOK, [$this, 'run_scheduled_sync']);
        add_action(self::CLEANUP_HOOK, [$this, 'run_scheduled_cleanup']);
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_intervals($schedules) {
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'gs-product-sync'),
        ];

        $schedules['thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'gs-product-sync'),
        ];

        return $schedules;
    }

    /**
     * Schedule events on plugin activation
     */
    public static function schedule_events() {
        $settings = get_option(GSPS_Settings::OPTION_NAME, []);
        $enabled = $settings['scheduler']['enabled'] ?? true;
        $interval = $settings['scheduler']['interval'] ?? 'thirty_minutes';

        // Clear existing
        self::clear_events();

        if ($enabled) {
            // Schedule sync
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), $interval, self::CRON_HOOK);
            }

            // Schedule daily cleanup
            if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
                wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
            }
        }
    }

    /**
     * Clear scheduled events
     */
    public static function clear_events() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    /**
     * Reschedule with new interval
     *
     * @param string $interval
     */
    public static function reschedule($interval) {
        wp_clear_scheduled_hook(self::CRON_HOOK);

        if (!empty($interval)) {
            wp_schedule_event(time(), $interval, self::CRON_HOOK);
        }
    }

    /**
     * Run scheduled sync
     */
    public function run_scheduled_sync() {
        // Check if enabled
        $settings = get_option(GSPS_Settings::OPTION_NAME, []);
        if (!($settings['scheduler']['enabled'] ?? true)) {
            return;
        }

        // Check if API is configured
        if (empty($settings['api']['bearer_token'])) {
            return;
        }

        // Create logger
        $logger = new GSPS_Logger('scheduled_sync');
        $logger->info('Starting scheduled sync');

        // Get config
        $gsps_settings = new GSPS_Settings();
        $config = $gsps_settings->get_importer_config();

        // Run sync
        $options = [
            'skip_images' => $settings['scheduler']['skip_images'] ?? false,
            'force_full' => false,
        ];

        $sync_checker = new GSPS_Sync_Checker($config, $logger, $options);
        $result = $sync_checker->run();

        // Update last run status
        update_option('gsps_last_scheduled_run', [
            'time' => current_time('mysql'),
            'success' => $result['success'],
            'message' => $result['message'] ?? ($result['error'] ?? 'Unknown'),
            'stats' => $result['diff_stats'] ?? null,
        ]);

        $logger->info('Scheduled sync completed', $result);
    }

    /**
     * Run scheduled cleanup
     */
    public function run_scheduled_cleanup() {
        $settings = get_option(GSPS_Settings::OPTION_NAME, []);
        $retention_days = $settings['logging']['retention_days'] ?? 7;

        $deleted = GSPS_Logger::cleanup($retention_days);

        if ($deleted > 0) {
            $logger = new GSPS_Logger('cleanup');
            $logger->info("Cleaned up {$deleted} old log entries");
        }
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false
     */
    public static function get_next_run() {
        return wp_next_scheduled(self::CRON_HOOK);
    }

    /**
     * Get last scheduled run info
     *
     * @return array|null
     */
    public static function get_last_run() {
        return get_option('gsps_last_scheduled_run', null);
    }

    /**
     * Check if scheduler is running
     *
     * @return bool
     */
    public static function is_scheduled() {
        return wp_next_scheduled(self::CRON_HOOK) !== false;
    }

    /**
     * Get available intervals
     *
     * @return array
     */
    public static function get_intervals() {
        return [
            'fifteen_minutes' => __('Ogni 15 minuti', 'gs-product-sync'),
            'thirty_minutes' => __('Ogni 30 minuti', 'gs-product-sync'),
            'hourly' => __('Ogni ora', 'gs-product-sync'),
            'twicedaily' => __('Due volte al giorno', 'gs-product-sync'),
            'daily' => __('Una volta al giorno', 'gs-product-sync'),
        ];
    }
}

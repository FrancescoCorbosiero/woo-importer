<?php
/**
 * Admin class - handles admin menu and pages
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('GS Product Sync', 'gs-product-sync'),
            __('GS Sync', 'gs-product-sync'),
            'manage_woocommerce',
            'gsps-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-update',
            56
        );

        // Dashboard submenu
        add_submenu_page(
            'gsps-dashboard',
            __('Dashboard', 'gs-product-sync'),
            __('Dashboard', 'gs-product-sync'),
            'manage_woocommerce',
            'gsps-dashboard',
            [$this, 'render_dashboard_page']
        );

        // Settings submenu
        add_submenu_page(
            'gsps-dashboard',
            __('Impostazioni', 'gs-product-sync'),
            __('Impostazioni', 'gs-product-sync'),
            'manage_woocommerce',
            'gsps-settings',
            [$this, 'render_settings_page']
        );

        // Logs submenu
        add_submenu_page(
            'gsps-dashboard',
            __('Logs', 'gs-product-sync'),
            __('Logs', 'gs-product-sync'),
            'manage_woocommerce',
            'gsps-logs',
            [$this, 'render_logs_page']
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        // Only on our pages
        if (strpos($hook, 'gsps-') === false) {
            return;
        }

        wp_enqueue_style(
            'gsps-admin',
            GSPS_PLUGIN_URL . 'admin/css/admin.css',
            [],
            GSPS_VERSION
        );

        wp_enqueue_script(
            'gsps-admin',
            GSPS_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            GSPS_VERSION,
            true
        );

        wp_localize_script('gsps-admin', 'gsps_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gsps_admin_nonce'),
            'strings' => [
                'confirm_sync' => __('Avviare la sincronizzazione?', 'gs-product-sync'),
                'confirm_full_sync' => __('Avviare una sincronizzazione completa? Tutti i prodotti verranno processati.', 'gs-product-sync'),
                'confirm_clear_logs' => __('Eliminare tutti i log?', 'gs-product-sync'),
                'running' => __('In esecuzione...', 'gs-product-sync'),
                'success' => __('Completato', 'gs-product-sync'),
                'error' => __('Errore', 'gs-product-sync'),
            ],
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('gsps_settings', GSPS_Settings::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitize settings
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $sanitized = [];

        // API settings
        $sanitized['api'] = [
            'base_url' => esc_url_raw($input['api']['base_url'] ?? ''),
            'bearer_token' => sanitize_text_field($input['api']['bearer_token'] ?? ''),
            'markup_percentage' => absint($input['api']['markup_percentage'] ?? 25),
            'vat_percentage' => absint($input['api']['vat_percentage'] ?? 22),
            'rounding_type' => sanitize_text_field($input['api']['rounding_type'] ?? 'whole'),
        ];

        // Store settings
        $sanitized['store'] = [
            'name' => sanitize_text_field($input['store']['name'] ?? ''),
            'locale' => sanitize_text_field($input['store']['locale'] ?? 'it_IT'),
        ];

        // Import settings
        $sanitized['import'] = [
            'category_name' => sanitize_text_field($input['import']['category_name'] ?? ''),
            'size_attribute_name' => sanitize_text_field($input['import']['size_attribute_name'] ?? ''),
            'size_attribute_slug' => sanitize_title($input['import']['size_attribute_slug'] ?? ''),
            'brand_attribute_name' => sanitize_text_field($input['import']['brand_attribute_name'] ?? ''),
            'brand_attribute_slug' => sanitize_title($input['import']['brand_attribute_slug'] ?? ''),
            'batch_size' => min(100, absint($input['import']['batch_size'] ?? 100)),
            'create_out_of_stock' => !empty($input['import']['create_out_of_stock']),
        ];

        // Categories
        $sanitized['categories'] = [
            'sneakers' => [
                'name' => sanitize_text_field($input['categories']['sneakers']['name'] ?? 'Sneakers'),
                'slug' => sanitize_title($input['categories']['sneakers']['slug'] ?? 'sneakers'),
            ],
            'clothing' => [
                'name' => sanitize_text_field($input['categories']['clothing']['name'] ?? 'Abbigliamento'),
                'slug' => sanitize_title($input['categories']['clothing']['slug'] ?? 'abbigliamento'),
            ],
        ];

        // Brand categories
        $sanitized['brand_categories'] = [
            'enabled' => !empty($input['brand_categories']['enabled']),
            'slug_suffix' => sanitize_text_field($input['brand_categories']['slug_suffix'] ?? '-originali'),
            'uncategorized' => [
                'name' => sanitize_text_field($input['brand_categories']['uncategorized']['name'] ?? 'Senza Categoria'),
                'slug' => sanitize_title($input['brand_categories']['uncategorized']['slug'] ?? 'senza-categoria'),
            ],
        ];

        // Brands
        $sanitized['brands'] = [
            'enabled' => !empty($input['brands']['enabled']),
        ];

        // Templates
        $sanitized['templates'] = [
            'image_alt' => wp_kses_post($input['templates']['image_alt'] ?? ''),
            'image_caption' => wp_kses_post($input['templates']['image_caption'] ?? ''),
            'image_description' => wp_kses_post($input['templates']['image_description'] ?? ''),
            'short_description' => wp_kses_post($input['templates']['short_description'] ?? ''),
            'long_description' => wp_kses_post($input['templates']['long_description'] ?? ''),
        ];

        // Scheduler
        $sanitized['scheduler'] = [
            'enabled' => !empty($input['scheduler']['enabled']),
            'interval' => sanitize_text_field($input['scheduler']['interval'] ?? 'thirty_minutes'),
            'skip_images' => !empty($input['scheduler']['skip_images']),
        ];

        // Logging
        $sanitized['logging'] = [
            'enabled' => !empty($input['logging']['enabled']),
            'level' => sanitize_text_field($input['logging']['level'] ?? 'info'),
            'retention_days' => absint($input['logging']['retention_days'] ?? 7),
        ];

        // Reschedule cron if interval changed
        $old_settings = get_option(GSPS_Settings::OPTION_NAME, []);
        $old_interval = $old_settings['scheduler']['interval'] ?? '';
        $old_enabled = $old_settings['scheduler']['enabled'] ?? false;

        if ($sanitized['scheduler']['enabled'] !== $old_enabled ||
            $sanitized['scheduler']['interval'] !== $old_interval) {

            if ($sanitized['scheduler']['enabled']) {
                GSPS_Scheduler::reschedule($sanitized['scheduler']['interval']);
            } else {
                GSPS_Scheduler::clear_events();
            }
        }

        return $sanitized;
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        include GSPS_PLUGIN_DIR . 'admin/views/dashboard-page.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include GSPS_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        include GSPS_PLUGIN_DIR . 'admin/views/logs-page.php';
    }
}

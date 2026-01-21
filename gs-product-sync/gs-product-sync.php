<?php
/**
 * Plugin Name: Golden Sneakers Product Sync
 * Plugin URI: https://resellpiacenza.it
 * Description: Syncs products from Golden Sneakers API to WooCommerce with delta updates, image management, and inventory synchronization.
 * Version: 1.0.0
 * Author: ResellPiacenza
 * Author URI: https://resellpiacenza.it
 * Text Domain: gs-product-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

// Plugin constants
define('GSPS_VERSION', '1.0.0');
define('GSPS_PLUGIN_FILE', __FILE__);
define('GSPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GSPS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GSPS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class GS_Product_Sync {

    /**
     * Single instance
     *
     * @var GS_Product_Sync
     */
    private static $instance = null;

    /**
     * Settings instance
     *
     * @var GSPS_Settings
     */
    public $settings;

    /**
     * Logger instance
     *
     * @var GSPS_Logger
     */
    public $logger;

    /**
     * Get single instance
     *
     * @return GS_Product_Sync
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-settings.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-logger.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-api-client.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-importer.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-image-importer.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-sync-checker.php';
        require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-scheduler.php';

        // Admin
        if (is_admin()) {
            require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-admin.php';
            require_once GSPS_PLUGIN_DIR . 'includes/class-gsps-ajax.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize components after plugins loaded
        add_action('plugins_loaded', [$this, 'init']);

        // Activation/deactivation
        register_activation_hook(GSPS_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(GSPS_PLUGIN_FILE, [$this, 'deactivate']);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check WooCommerce dependency
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        // Initialize components
        $this->settings = new GSPS_Settings();
        $this->logger = new GSPS_Logger();

        // Initialize admin
        if (is_admin()) {
            new GSPS_Admin();
            new GSPS_Ajax();
        }

        // Initialize scheduler
        new GSPS_Scheduler();

        // Load translations
        load_plugin_textdomain('gs-product-sync', false, dirname(GSPS_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create default options
        GSPS_Settings::set_defaults();

        // Create logs table
        GSPS_Logger::create_table();

        // Schedule cron
        GSPS_Scheduler::schedule_events();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        GSPS_Scheduler::clear_events();
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong>Golden Sneakers Product Sync</strong> richiede WooCommerce per funzionare.</p>
        </div>
        <?php
    }

    /**
     * Get config array (for backward compatibility with standalone scripts)
     *
     * @return array
     */
    public function get_config() {
        return $this->settings->get_all();
    }
}

/**
 * Main instance
 *
 * @return GS_Product_Sync
 */
function GSPS() {
    return GS_Product_Sync::instance();
}

// Initialize
GSPS();

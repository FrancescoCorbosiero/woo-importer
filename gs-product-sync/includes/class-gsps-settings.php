<?php
/**
 * Settings class - manages plugin options via WordPress Options API
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Settings {

    /**
     * Option name in database
     */
    const OPTION_NAME = 'gsps_settings';

    /**
     * Cached settings
     *
     * @var array
     */
    private $settings = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = $this->load_settings();
    }

    /**
     * Get default settings
     *
     * @return array
     */
    public static function get_defaults() {
        return [
            // Golden Sneakers API
            'api' => [
                'base_url' => 'https://www.goldensneakers.net/api/assortment/',
                'bearer_token' => '',
                'markup_percentage' => 25,
                'vat_percentage' => 22,
                'rounding_type' => 'whole',
            ],

            // Store Information
            'store' => [
                'name' => 'ResellPiacenza',
                'locale' => 'it_IT',
            ],

            // Import Settings
            'import' => [
                'category_name' => 'Sneakers',
                'size_attribute_name' => 'Taglia',
                'size_attribute_slug' => 'taglia',
                'brand_attribute_name' => 'Marca',
                'brand_attribute_slug' => 'marca',
                'batch_size' => 100,
                'create_out_of_stock' => true,
            ],

            // Categories (auto-detected)
            'categories' => [
                'sneakers' => [
                    'name' => 'Sneakers',
                    'slug' => 'sneakers',
                ],
                'clothing' => [
                    'name' => 'Abbigliamento',
                    'slug' => 'abbigliamento',
                ],
            ],

            // Brand Categories
            'brand_categories' => [
                'enabled' => true,
                'slug_suffix' => '-originali',
                'uncategorized' => [
                    'name' => 'Senza Categoria',
                    'slug' => 'senza-categoria',
                ],
            ],

            // Brands Taxonomy
            'brands' => [
                'enabled' => true,
            ],

            // Templates (Italian)
            'templates' => [
                'image_alt' => '{product_name} - {sku} - Acquista su {store_name}',
                'image_caption' => '{brand_name} {product_name}',
                'image_description' => 'Acquista {product_name} ({sku}) su {store_name}. Sneakers originali {brand_name}. Spedizione rapida in tutta Italia.',
                'short_description' => '<p>Sneakers originali <strong>{brand_name}</strong>. Prodotto autentico al 100%. Spedizione veloce in tutta Italia.</p>',
                'long_description' => '
<p>Scopri le <strong>{product_name}</strong>, sneakers originali {brand_name} disponibili su {store_name}.</p>

<h3>Garanzia di Autenticita</h3>
<p>Tutti i prodotti venduti su {store_name} sono <strong>100% originali e autentici</strong>. Ogni articolo viene accuratamente verificato prima della spedizione.</p>

<h3>Spedizione e Resi</h3>
<ul>
    <li>Spedizione rapida in tutta Italia</li>
    <li>Imballaggio sicuro e discreto</li>
    <li>Reso facile entro 14 giorni</li>
</ul>

<h3>Perche Scegliere {store_name}</h3>
<p>Siamo specialisti in sneakers e streetwear di alta qualita. La nostra missione e offrire prodotti autentici ai migliori prezzi, con un servizio clienti impeccabile.</p>
',
            ],

            // Scheduler
            'scheduler' => [
                'enabled' => true,
                'interval' => 'thirty_minutes', // hourly, thirty_minutes, fifteen_minutes
                'skip_images' => false,
            ],

            // Logging
            'logging' => [
                'enabled' => true,
                'level' => 'info', // debug, info, warning, error
                'retention_days' => 7,
            ],
        ];
    }

    /**
     * Set default options on activation
     */
    public static function set_defaults() {
        if (false === get_option(self::OPTION_NAME)) {
            update_option(self::OPTION_NAME, self::get_defaults());
        }
    }

    /**
     * Load settings from database
     *
     * @return array
     */
    private function load_settings() {
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args_recursive($saved, self::get_defaults());
    }

    /**
     * Get all settings
     *
     * @return array
     */
    public function get_all() {
        return $this->settings;
    }

    /**
     * Get a setting value
     *
     * @param string $key Dot notation key (e.g., 'api.bearer_token')
     * @param mixed $default Default value
     * @return mixed
     */
    public function get($key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->settings;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set a setting value
     *
     * @param string $key Dot notation key
     * @param mixed $value Value to set
     */
    public function set($key, $value) {
        $keys = explode('.', $key);
        $settings = &$this->settings;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $settings[$k] = $value;
            } else {
                if (!isset($settings[$k]) || !is_array($settings[$k])) {
                    $settings[$k] = [];
                }
                $settings = &$settings[$k];
            }
        }
    }

    /**
     * Save settings to database
     *
     * @return bool
     */
    public function save() {
        return update_option(self::OPTION_NAME, $this->settings);
    }

    /**
     * Update multiple settings at once
     *
     * @param array $data Settings data
     * @return bool
     */
    public function update(array $data) {
        $this->settings = wp_parse_args_recursive($data, $this->settings);
        return $this->save();
    }

    /**
     * Reset to defaults
     *
     * @return bool
     */
    public function reset() {
        $this->settings = self::get_defaults();
        return $this->save();
    }

    /**
     * Get config array formatted for importer classes
     * (Backward compatibility with standalone scripts)
     *
     * @return array
     */
    public function get_importer_config() {
        $settings = $this->settings;

        // Build WooCommerce config using site URL
        $settings['woocommerce'] = [
            'url' => home_url(),
            'consumer_key' => '', // Not needed - we use internal WC functions
            'consumer_secret' => '',
            'version' => 'wc/v3',
        ];

        // WordPress config for media uploads
        $settings['wordpress'] = [
            'username' => '', // Not needed - we're inside WordPress
            'app_password' => '',
        ];

        // Logging config
        $settings['logging']['file'] = WP_CONTENT_DIR . '/uploads/gsps-logs/import.log';
        $settings['logging']['console_level'] = $settings['logging']['level'];

        // API params
        $settings['api']['params'] = [
            'rounding_type' => $settings['api']['rounding_type'],
            'markup_percentage' => $settings['api']['markup_percentage'],
            'vat_percentage' => $settings['api']['vat_percentage'],
        ];

        return $settings;
    }

    /**
     * Check if API is configured
     *
     * @return bool
     */
    public function is_api_configured() {
        return !empty($this->settings['api']['bearer_token']);
    }

    /**
     * Validate API connection
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_api_connection() {
        if (!$this->is_api_configured()) {
            return [
                'success' => false,
                'message' => 'API token non configurato',
            ];
        }

        $api_client = new GSPS_API_Client($this->get_importer_config());
        return $api_client->test_connection();
    }
}

/**
 * Recursive wp_parse_args
 *
 * @param array $args
 * @param array $defaults
 * @return array
 */
if (!function_exists('wp_parse_args_recursive')) {
    function wp_parse_args_recursive($args, $defaults) {
        $result = $defaults;

        foreach ($args as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = wp_parse_args_recursive($value, $result[$key]);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

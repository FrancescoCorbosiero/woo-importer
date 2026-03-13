<?php
/**
 * Plugin Name: Resell Catalogo
 * Description: Dashboard per gestione catalogo prodotti ResellPiacenza — upload catalog.json, visualizzazione struttura, e importazione rapida prodotti da KicksDB.
 * Version: 1.0.0
 * Author: ResellPiacenza
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: resell-catalogo
 *
 * @package ResellPiacenza\Catalogo
 */

defined('ABSPATH') || exit;

define('RESELL_CATALOGO_VERSION', '1.0.0');
define('RESELL_CATALOGO_FILE', __FILE__);
define('RESELL_CATALOGO_DIR', plugin_dir_path(__FILE__));
define('RESELL_CATALOGO_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class — singleton
 */
final class Resell_Catalogo
{
    private static ?self $instance = null;

    /** @var string Option key for KicksDB API key */
    const OPT_KICKSDB_KEY = 'resell_catalogo_kicksdb_key';
    const OPT_KICKSDB_MARKET = 'resell_catalogo_kicksdb_market';

    /** @var string Upload directory inside wp-content/uploads */
    const UPLOAD_SUBDIR = 'resell-catalogo';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_resell_upload_catalog', [$this, 'ajax_upload_catalog']);
        add_action('wp_ajax_resell_delete_catalog', [$this, 'ajax_delete_catalog']);
        add_action('wp_ajax_resell_search_kicksdb', [$this, 'ajax_search_kicksdb']);
        add_action('wp_ajax_resell_import_product', [$this, 'ajax_import_product']);
        add_action('wp_ajax_resell_save_settings', [$this, 'ajax_save_settings']);
    }

    // =========================================================================
    // Admin Menu
    // =========================================================================

    public function register_menu(): void
    {
        add_menu_page(
            'Catalogo',
            'Catalogo',
            'manage_woocommerce',
            'resell-catalogo',
            [$this, 'render_page'],
            'dashicons-store',
            56 // After WooCommerce
        );
    }

    // =========================================================================
    // Assets
    // =========================================================================

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_resell-catalogo') {
            return;
        }

        wp_enqueue_style(
            'resell-catalogo',
            RESELL_CATALOGO_URL . 'assets/style.css',
            [],
            RESELL_CATALOGO_VERSION
        );

        wp_enqueue_script(
            'resell-catalogo',
            RESELL_CATALOGO_URL . 'assets/app.js',
            ['jquery'],
            RESELL_CATALOGO_VERSION,
            true
        );

        wp_localize_script('resell-catalogo', 'resellCatalogo', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('resell_catalogo'),
        ]);
    }

    // =========================================================================
    // Page Render
    // =========================================================================

    public function render_page(): void
    {
        $catalog = $this->load_catalog();
        $has_api_key = !empty(get_option(self::OPT_KICKSDB_KEY));
        $market = get_option(self::OPT_KICKSDB_MARKET, 'IT');

        include RESELL_CATALOGO_DIR . 'templates/page-catalogo.php';
    }

    // =========================================================================
    // Catalog File Management
    // =========================================================================

    /**
     * Get the path to the stored catalog.json
     */
    private function get_catalog_path(): string
    {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Protect directory from direct access
            file_put_contents($dir . '/.htaccess', "Deny from all\n");
            file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }

        return $dir . '/catalog.json';
    }

    /**
     * Load and parse the catalog file
     *
     * @return array|null Parsed catalog or null if not found
     */
    private function load_catalog(): ?array
    {
        $path = $this->get_catalog_path();
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Extract catalog metadata for the UI
     */
    private function get_catalog_meta(array $catalog): array
    {
        $meta = [
            'file_size'      => filesize($this->get_catalog_path()),
            'modified'       => filemtime($this->get_catalog_path()),
            'sections'       => 0,
            'total_products' => 0,
            'total_brands'   => 0,
            'breakdown'      => [],
        ];

        foreach ($catalog['sections'] ?? [] as $section) {
            $meta['sections']++;
            $section_name = $section['name'] ?? 'Unknown';
            $section_products = 0;
            $section_brands = 0;
            $brands_detail = [];

            // Count products in brand-mode sections (Sneakers, Abbigliamento)
            foreach ($section['brands'] ?? [] as $brand) {
                $section_brands++;
                $brand_name = $brand['name'] ?? 'Unknown';
                $brand_products = 0;

                // Sneakers: brands[].subcategories[].products[]
                foreach ($brand['subcategories'] ?? [] as $subcat) {
                    $count = count($subcat['products'] ?? []);
                    $brand_products += $count;
                }

                // Abbigliamento: brands[].products[]
                $brand_products += count($brand['products'] ?? []);

                $brands_detail[] = [
                    'name'     => $brand_name,
                    'products' => $brand_products,
                ];
                $section_products += $brand_products;
            }

            // Count products in query-mode sections (Accessori)
            foreach ($section['items'] ?? [] as $item) {
                $count = count($item['products'] ?? []);
                $section_products += $count;
                $brands_detail[] = [
                    'name'     => $item['name'] ?? $item['label'] ?? 'Unknown',
                    'products' => $count,
                ];
            }

            $meta['total_products'] += $section_products;
            $meta['total_brands'] += $section_brands;

            $meta['breakdown'][] = [
                'name'         => $section_name,
                'slug'         => $section['slug'] ?? '',
                'wc_category'  => $section['wc_category'] ?? '',
                'products'     => $section_products,
                'brands'       => $brands_detail,
                'subcategories' => $this->extract_subcategories($section),
            ];
        }

        return $meta;
    }

    /**
     * Extract subcategory names from a section
     */
    private function extract_subcategories(array $section): array
    {
        $subcats = [];

        // Keyword-based subcategories (Abbigliamento, Accessori)
        foreach ($section['subcategories'] ?? [] as $sc) {
            $subcats[] = $sc['name'] ?? '';
        }

        // Brand-mode subcategories (Sneakers): each brand's subcategories
        foreach ($section['brands'] ?? [] as $brand) {
            foreach ($brand['subcategories'] ?? [] as $sc) {
                $name = $sc['name'] ?? '';
                if ($name && !in_array($name, $subcats)) {
                    $subcats[] = $name;
                }
            }
        }

        return $subcats;
    }

    // =========================================================================
    // AJAX: Upload Catalog
    // =========================================================================

    public function ajax_upload_catalog(): void
    {
        check_ajax_referer('resell_catalogo', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permesso negato.']);
        }

        if (empty($_FILES['catalog_file'])) {
            wp_send_json_error(['message' => 'Nessun file selezionato.']);
        }

        $file = $_FILES['catalog_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Errore upload: codice ' . $file['error']]);
        }

        // Validate JSON
        $content = file_get_contents($file['tmp_name']);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'File JSON non valido: ' . json_last_error_msg()]);
        }

        // Validate structure
        if (!isset($data['sections']) || !is_array($data['sections'])) {
            wp_send_json_error(['message' => 'Struttura non valida: manca la chiave "sections".']);
        }

        // Save
        $path = $this->get_catalog_path();
        $formatted = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $formatted);

        $meta = $this->get_catalog_meta($data);

        wp_send_json_success([
            'message' => 'Catalogo caricato con successo.',
            'meta'    => $meta,
            'catalog' => $data,
        ]);
    }

    // =========================================================================
    // AJAX: Delete Catalog
    // =========================================================================

    public function ajax_delete_catalog(): void
    {
        check_ajax_referer('resell_catalogo', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permesso negato.']);
        }

        $path = $this->get_catalog_path();
        if (file_exists($path)) {
            unlink($path);
        }

        wp_send_json_success(['message' => 'Catalogo eliminato.']);
    }

    // =========================================================================
    // AJAX: Save Settings
    // =========================================================================

    public function ajax_save_settings(): void
    {
        check_ajax_referer('resell_catalogo', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permesso negato.']);
        }

        $api_key = sanitize_text_field($_POST['kicksdb_api_key'] ?? '');
        $market = sanitize_text_field($_POST['kicksdb_market'] ?? 'IT');

        update_option(self::OPT_KICKSDB_KEY, $api_key);
        update_option(self::OPT_KICKSDB_MARKET, $market);

        wp_send_json_success(['message' => 'Impostazioni salvate.']);
    }

    // =========================================================================
    // AJAX: Search KicksDB
    // =========================================================================

    public function ajax_search_kicksdb(): void
    {
        check_ajax_referer('resell_catalogo', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permesso negato.']);
        }

        $query = sanitize_text_field($_POST['query'] ?? '');
        if (strlen($query) < 2) {
            wp_send_json_error(['message' => 'Inserisci almeno 2 caratteri.']);
        }

        $api_key = get_option(self::OPT_KICKSDB_KEY);
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API Key KicksDB non configurata. Vai in Impostazioni.']);
        }

        $market = get_option(self::OPT_KICKSDB_MARKET, 'IT');

        // Call KicksDB search API
        $url = 'https://api.kicks.dev/v3/stockx/products?' . http_build_query([
            'query'               => $query,
            'limit'               => 12,
            'market'              => $market,
            'display[variants]'   => 'true',
            'display[traits]'     => 'true',
            'display[identifiers]' => 'true',
        ]);

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Errore API: ' . $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401 || $code === 403) {
            wp_send_json_error(['message' => 'API Key non valida o scaduta.']);
        }

        if ($code !== 200) {
            wp_send_json_error(['message' => "Errore KicksDB (HTTP {$code})."]);
        }

        // Normalize results for the UI
        $products = [];
        foreach ($body['data'] ?? [] as $item) {
            $sku = $item['sku'] ?? $item['slug'] ?? '';
            $product_type = strtolower($item['product_type'] ?? 'sneakers');

            // Extract price range from variants
            $prices = [];
            foreach ($item['variants'] ?? [] as $variant) {
                $price = $this->extract_variant_price($variant);
                if ($price > 0) {
                    $prices[] = $price;
                }
            }

            $products[] = [
                'sku'          => $sku,
                'title'        => $item['title'] ?? '',
                'brand'        => $item['brand'] ?? '',
                'image'        => $item['image'] ?? '',
                'product_type' => $product_type,
                'gender'       => $item['gender'] ?? '',
                'colorway'     => $item['colorway'] ?? '',
                'release_date' => $item['release_date'] ?? '',
                'slug'         => $item['slug'] ?? '',
                'variant_count' => count($item['variants'] ?? []),
                'price_min'    => !empty($prices) ? min($prices) : null,
                'price_max'    => !empty($prices) ? max($prices) : null,
                '_raw'         => $item, // Full data for import
            ];
        }

        wp_send_json_success([
            'products' => $products,
            'total'    => count($products),
        ]);
    }

    // =========================================================================
    // AJAX: Import Product
    // =========================================================================

    public function ajax_import_product(): void
    {
        check_ajax_referer('resell_catalogo', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permesso negato.']);
        }

        $raw_json = stripslashes($_POST['product_data'] ?? '');
        $raw = json_decode($raw_json, true);

        if (empty($raw)) {
            wp_send_json_error(['message' => 'Dati prodotto mancanti.']);
        }

        $category_key = sanitize_text_field($_POST['category'] ?? 'sneakers');

        // Check if SKU already exists in WooCommerce
        $sku = $raw['sku'] ?? $raw['slug'] ?? '';
        if (empty($sku)) {
            wp_send_json_error(['message' => 'SKU mancante.']);
        }

        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            $edit_url = admin_url("post.php?post={$existing_id}&action=edit");
            wp_send_json_error([
                'message'  => "Prodotto con SKU \"{$sku}\" esiste gi\u{e0}.",
                'edit_url' => $edit_url,
            ]);
        }

        try {
            $result = $this->create_wc_product($raw, $category_key);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Errore importazione: ' . $e->getMessage()]);
        }
    }

    // =========================================================================
    // WooCommerce Product Creation
    // =========================================================================

    /**
     * Create a WooCommerce product from raw KicksDB data
     *
     * Mirrors the logic from WcProductBuilder + WooCommerceImporter but runs
     * entirely within WordPress (no REST API calls needed — direct WC CRUD).
     *
     * @param array $raw Full KicksDB API product data
     * @param string $category_key Category config key (sneakers, clothing, accessories)
     * @return array Result with product_id and edit_url
     */
    private function create_wc_product(array $raw, string $category_key): array
    {
        $sku = $raw['sku'] ?? $raw['slug'] ?? '';
        $title = $raw['title'] ?? $raw['name'] ?? '';
        $brand = $raw['brand'] ?? '';
        $image_url = $raw['image'] ?? '';
        $product_type_raw = strtolower($raw['product_type'] ?? 'sneakers');
        $is_clothing = in_array($product_type_raw, ['streetwear', 'apparel', 'clothing', 'collectibles']);

        // Parse variants
        $variations = [];
        foreach ($raw['variants'] ?? [] as $variant) {
            $size = $is_clothing
                ? $this->extract_letter_size($variant)
                : $this->extract_eu_size($variant);

            if ($size === null) {
                continue;
            }

            $market_price = $this->extract_variant_price($variant);
            if ($market_price <= 0) {
                continue;
            }

            // Apply margin (simple tiered calculation)
            $selling_price = $this->apply_margin($market_price);

            $variations[] = [
                'size'           => $size,
                'price'          => $selling_price,
                'market_price'   => $market_price,
                'stock'          => $this->calculate_stock($market_price),
            ];
        }

        // Determine product type: One Size → simple, multiple sizes → variable
        $is_one_size = count($variations) === 1
            && strtolower($variations[0]['size'] ?? '') === 'one size';
        $is_simple = $is_one_size || empty($variations);

        if (empty($variations)) {
            throw new \Exception("Nessuna variazione valida trovata (nessuna taglia/prezzo).");
        }

        // Resolve WooCommerce category
        $category_id = $this->resolve_category($category_key);

        // Upload primary image
        $image_id = 0;
        if (!empty($image_url)) {
            $image_id = $this->sideload_image($image_url, $title, $brand, $sku);
        }

        // Extract metadata
        $traits = [];
        foreach ($raw['traits'] ?? [] as $t) {
            $key = $t['trait'] ?? '';
            $val = $t['value'] ?? '';
            if ($key && $val) {
                $traits[$key] = $val;
            }
        }

        $colorway = $raw['colorway'] ?? $traits['Colorway'] ?? $traits['Color'] ?? '';
        $gender = $raw['gender'] ?? '';
        $model = $raw['model'] ?? '';
        $release_date = $raw['release_date'] ?? $traits['Release Date'] ?? '';

        // Gender translation (English → Italian)
        $gender_map = [
            'men'    => 'Uomo',
            'women'  => 'Donna',
            'youth'  => 'Bambino',
            'child'  => 'Bambino',
            'infant' => 'Neonato',
            'toddler' => 'Neonato',
            'unisex' => 'Unisex',
        ];
        $gender_it = $gender_map[strtolower($gender)] ?? $gender;

        // Create product
        if ($is_simple) {
            $product = new \WC_Product_Simple();
            $var = $variations[0];
            $product->set_regular_price((string) $var['price']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($var['stock']);
            $product->set_stock_status('instock');
        } else {
            $product = new \WC_Product_Variable();
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        $product->set_name($title);
        $product->set_sku($sku);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');

        // Category
        if ($category_id) {
            $product->set_category_ids([$category_id]);
        }

        // Image
        if ($image_id) {
            $product->set_image_id($image_id);
        }

        // Italian descriptions
        $store_name = get_bloginfo('name');
        $short_desc = sprintf(
            '<p>Prodotto originale <strong>%s</strong>. Articolo autentico al 100%%. Spedizione veloce in tutta Italia.</p>',
            esc_html($brand)
        );
        $product->set_short_description($short_desc);

        $long_desc = sprintf(
            '<p>Scopri <strong>%s</strong>, prodotto originale %s disponibile su %s.</p>' .
            '<h3>Garanzia di Autenticità</h3>' .
            '<p>Tutti i prodotti venduti su %s sono <strong>100%% originali e autentici</strong>.</p>' .
            '<h3>Spedizione e Resi</h3>' .
            '<ul><li>Spedizione rapida in tutta Italia</li><li>Imballaggio sicuro e discreto</li><li>Reso facile entro 14 giorni</li></ul>',
            esc_html($title),
            esc_html($brand),
            esc_html($store_name),
            esc_html($store_name)
        );
        $product->set_description($long_desc);

        // Metadata
        $product->update_meta_data('_source', 'catalogo_dashboard');
        $product->update_meta_data('_kicksdb_slug', $raw['slug'] ?? '');
        if ($colorway) {
            $product->update_meta_data('_colorway', $colorway);
        }
        if ($release_date) {
            $product->update_meta_data('_release_date', $release_date);
        }

        // Set up attributes
        $attributes = [];

        // Taglia (Size) attribute
        if (!$is_simple) {
            $sizes = array_column($variations, 'size');
            $size_attr = new \WC_Product_Attribute();
            $size_attr->set_name('pa_taglia');
            $size_attr->set_options($sizes);
            $size_attr->set_visible(true);
            $size_attr->set_variation(true);

            // Ensure attribute taxonomy exists
            $this->ensure_attribute('Taglia', 'taglia');
            // Ensure terms exist
            foreach ($sizes as $size_val) {
                $this->ensure_attribute_term('pa_taglia', (string) $size_val);
            }

            $attributes[] = $size_attr;
        }

        // Marca (Brand) attribute
        if (!empty($brand)) {
            $this->ensure_attribute('Marca', 'marca');
            $this->ensure_attribute_term('pa_marca', $brand);

            $brand_attr = new \WC_Product_Attribute();
            $brand_attr->set_name('pa_marca');
            $brand_attr->set_options([$brand]);
            $brand_attr->set_visible(true);
            $brand_attr->set_variation(false);
            $attributes[] = $brand_attr;
        }

        // Colorway attribute
        if (!empty($colorway)) {
            $this->ensure_attribute('Colorway', 'colorway');
            $this->ensure_attribute_term('pa_colorway', $colorway);

            $cw_attr = new \WC_Product_Attribute();
            $cw_attr->set_name('pa_colorway');
            $cw_attr->set_options([$colorway]);
            $cw_attr->set_visible(true);
            $cw_attr->set_variation(false);
            $attributes[] = $cw_attr;
        }

        // Genere (Gender) attribute
        if (!empty($gender_it)) {
            $this->ensure_attribute('Genere', 'genere');
            $this->ensure_attribute_term('pa_genere', $gender_it);

            $gender_attr = new \WC_Product_Attribute();
            $gender_attr->set_name('pa_genere');
            $gender_attr->set_options([$gender_it]);
            $gender_attr->set_visible(true);
            $gender_attr->set_variation(false);
            $attributes[] = $gender_attr;
        }

        // Modello (Model) attribute
        if (!empty($model)) {
            $this->ensure_attribute('Modello', 'modello');
            $this->ensure_attribute_term('pa_modello', $model);

            $model_attr = new \WC_Product_Attribute();
            $model_attr->set_name('pa_modello');
            $model_attr->set_options([$model]);
            $model_attr->set_visible(true);
            $model_attr->set_variation(false);
            $attributes[] = $model_attr;
        }

        // Data di Rilascio (Release Date)
        if (!empty($release_date)) {
            $this->ensure_attribute('Data di Rilascio', 'data-di-rilascio');
            $this->ensure_attribute_term('pa_data-di-rilascio', $release_date);

            $rd_attr = new \WC_Product_Attribute();
            $rd_attr->set_name('pa_data-di-rilascio');
            $rd_attr->set_options([$release_date]);
            $rd_attr->set_visible(true);
            $rd_attr->set_variation(false);
            $attributes[] = $rd_attr;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }

        // Save parent product
        $product_id = $product->save();

        // Create variations for variable products
        if (!$is_simple && $product_id) {
            foreach ($variations as $var) {
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product_id);
                $variation->set_sku($sku . '-' . $this->slugify_size($var['size']));
                $variation->set_regular_price((string) $var['price']);
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($var['stock']);
                $variation->set_stock_status('instock');
                $variation->set_attributes(['pa_taglia' => (string) $var['size']]);

                $variation->update_meta_data('_kicksdb_lowest_ask', (string) $var['market_price']);

                $variation->save();
            }

            // Sync variations to update the parent
            \WC_Product_Variable::sync($product_id);
        }

        // Assign brand taxonomy (Perfect Brands / WC Brands)
        if (!empty($brand) && taxonomy_exists('pwb-brand')) {
            wp_set_object_terms($product_id, $brand, 'pwb-brand', false);
        }

        $edit_url = admin_url("post.php?post={$product_id}&action=edit");
        $view_url = get_permalink($product_id);

        return [
            'message'    => sprintf('Prodotto "%s" importato con successo!', $title),
            'product_id' => $product_id,
            'edit_url'   => $edit_url,
            'view_url'   => $view_url,
            'variations' => count($variations),
            'type'       => $is_simple ? 'simple' : 'variable',
        ];
    }

    // =========================================================================
    // Helpers: Pricing
    // =========================================================================

    /**
     * Apply tiered margin to market price (mirrors PriceCalculator)
     */
    private function apply_margin(float $market_price): float
    {
        if ($market_price <= 0) {
            return 0.0;
        }

        $tiers = [
            ['min' => 0, 'max' => 100, 'margin' => 35],
            ['min' => 100, 'max' => 200, 'margin' => 28],
            ['min' => 200, 'max' => 500, 'margin' => 22],
            ['min' => 500, 'max' => PHP_FLOAT_MAX, 'margin' => 18],
        ];

        $margin = 25; // flat default
        foreach ($tiers as $tier) {
            if ($market_price >= $tier['min'] && $market_price < $tier['max']) {
                $margin = $tier['margin'];
                break;
            }
        }

        $price = $market_price * (1 + $margin / 100);

        // Floor price
        if ($price < 59) {
            $price = 59;
        }

        // Round up to whole number
        return (float) ceil($price);
    }

    /**
     * Calculate stock quantity based on market price tier
     */
    private function calculate_stock(float $market_price): int
    {
        $tiers = [
            ['min' => 0, 'max' => 100, 'stock' => 80],
            ['min' => 100, 'max' => 200, 'stock' => 50],
            ['min' => 200, 'max' => 500, 'stock' => 25],
            ['min' => 500, 'max' => PHP_FLOAT_MAX, 'stock' => 12],
        ];

        foreach ($tiers as $tier) {
            if ($market_price >= $tier['min'] && $market_price < $tier['max']) {
                return $tier['stock'];
            }
        }

        return 25;
    }

    // =========================================================================
    // Helpers: Size Extraction
    // =========================================================================

    /**
     * Extract EU size from variant (mirrors KicksDbAdapter logic)
     */
    private function extract_eu_size(array $variant): ?string
    {
        // From sizes[] sub-array
        foreach ($variant['sizes'] ?? [] as $size_entry) {
            if (($size_entry['type'] ?? '') === 'eu') {
                $raw = $size_entry['size'] ?? '';
                return preg_replace('/^EU\s*/i', '', trim($raw));
            }
        }

        // Direct field fallback
        if (!empty($variant['size_eu'])) {
            return preg_replace('/^EU\s*/i', '', trim($variant['size_eu']));
        }

        // Title fallback
        if (!empty($variant['title'])) {
            if (preg_match('/EU\s+([\d.]+)/i', $variant['title'], $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Extract letter size for clothing/accessories (mirrors KicksDbAdapter logic)
     */
    private function extract_letter_size(array $variant): ?string
    {
        $raw_fallback = null;

        foreach ($variant['sizes'] ?? [] as $size_entry) {
            $cleaned = trim($size_entry['size'] ?? '');
            if (empty($cleaned)) {
                continue;
            }

            if (preg_match('/^(one\s*size|os)$/i', $cleaned)) {
                return 'One Size';
            }

            $cleaned = preg_replace('/^(US|EU|UK)\s+/i', '', $cleaned);
            if (preg_match('/^[XSML]{1,3}L?$|^\d*X{0,3}L$/i', $cleaned)) {
                return strtoupper($cleaned);
            }

            if ($raw_fallback === null) {
                $raw_fallback = $cleaned;
            }
        }

        $direct = trim($variant['size'] ?? '');
        if (!empty($direct)) {
            if (preg_match('/^(one\s*size|os)$/i', $direct)) {
                return 'One Size';
            }
            if (preg_match('/^[XSML]{1,3}L?$|^\d*X{0,3}L$/i', $direct)) {
                return strtoupper($direct);
            }
            if ($raw_fallback === null) {
                $raw_fallback = $direct;
            }
        }

        return $raw_fallback;
    }

    /**
     * Extract standard market price from variant (mirrors KicksDbAdapter logic)
     */
    private function extract_variant_price(array $variant): float
    {
        if (!empty($variant['prices']) && is_array($variant['prices'])) {
            foreach ($variant['prices'] as $p) {
                if (($p['type'] ?? '') === 'standard') {
                    return (float) ($p['price'] ?? 0);
                }
            }
            $prices = array_filter(array_column($variant['prices'], 'price'), fn($p) => $p > 0);
            return !empty($prices) ? (float) min($prices) : 0.0;
        }

        return (float) ($variant['lowest_ask'] ?? $variant['price'] ?? $variant['amount'] ?? 0);
    }

    // =========================================================================
    // Helpers: WooCommerce Taxonomy
    // =========================================================================

    /**
     * Resolve WC category ID from config key
     */
    private function resolve_category(string $key): int
    {
        $map = [
            'sneakers'    => ['name' => 'Sneakers', 'slug' => 'sneakers'],
            'clothing'    => ['name' => 'Abbigliamento', 'slug' => 'abbigliamento'],
            'accessories' => ['name' => 'Accessori', 'slug' => 'accessori'],
        ];

        $cat = $map[$key] ?? $map['sneakers'];

        $term = get_term_by('slug', $cat['slug'], 'product_cat');
        if ($term) {
            return $term->term_id;
        }

        // Create if missing
        $result = wp_insert_term($cat['name'], 'product_cat', ['slug' => $cat['slug']]);
        if (is_wp_error($result)) {
            return 0;
        }

        return $result['term_id'];
    }

    /**
     * Ensure a WooCommerce product attribute taxonomy exists
     */
    private function ensure_attribute(string $name, string $slug): void
    {
        $taxonomy = 'pa_' . $slug;
        if (taxonomy_exists($taxonomy)) {
            return;
        }

        // Check if attribute exists in DB
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
            $slug
        ));

        if (!$exists) {
            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                [
                    'attribute_name'    => $slug,
                    'attribute_label'   => $name,
                    'attribute_type'    => 'select',
                    'attribute_orderby' => 'menu_order',
                    'attribute_public'  => 1,
                ]
            );

            // Clear WC attribute cache
            delete_transient('wc_attribute_taxonomies');
            \WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }

        // Register taxonomy for current request
        register_taxonomy($taxonomy, 'product', [
            'labels'       => ['name' => $name],
            'hierarchical' => false,
            'show_ui'      => false,
            'query_var'    => true,
            'rewrite'      => ['slug' => $slug],
        ]);
    }

    /**
     * Ensure an attribute term exists
     */
    private function ensure_attribute_term(string $taxonomy, string $value): void
    {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }

        $slug = sanitize_title($value);
        $term = get_term_by('slug', $slug, $taxonomy);

        if (!$term) {
            wp_insert_term($value, $taxonomy, ['slug' => $slug]);
        }
    }

    // =========================================================================
    // Helpers: Image
    // =========================================================================

    /**
     * Sideload an image from URL into the WordPress media library
     *
     * @param string $url Image URL
     * @param string $title Product title (for alt text)
     * @param string $brand Brand name
     * @param string $sku Product SKU
     * @return int Media attachment ID (0 on failure)
     */
    private function sideload_image(string $url, string $title, string $brand, string $sku): int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 15);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $file_array = [
            'name'     => sanitize_file_name($sku . '.jpg'),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, 0);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return 0;
        }

        // Set Italian SEO metadata
        $store_name = get_bloginfo('name');
        $alt = sprintf('%s - %s - Acquista su %s', $title, $sku, $store_name);
        $caption = sprintf('%s %s', $brand, $title);

        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        wp_update_post([
            'ID'           => $id,
            'post_excerpt' => $caption,
            'post_content' => sprintf(
                'Acquista %s (%s) su %s. Prodotto originale %s. Spedizione rapida in tutta Italia.',
                $title, $sku, $store_name, $brand
            ),
        ]);

        return $id;
    }

    // =========================================================================
    // Helpers: Misc
    // =========================================================================

    /**
     * Create a slug-safe size string for variation SKU
     */
    private function slugify_size(string $size): string
    {
        $size = strtolower(trim($size));
        $size = str_replace([' ', '/'], ['-', '-'], $size);
        return preg_replace('/[^a-z0-9.\-]/', '', $size);
    }
}

// Boot
Resell_Catalogo::instance();

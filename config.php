<?php
/**
 * Golden Sneakers Import Configuration
 * 
 * Loads settings from .env file with Italian defaults.
 * Copy .env.example to .env and customize your settings.
 * 
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env file if it exists
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

/**
 * Get environment variable with default fallback
 * 
 * @param string $key Environment variable name
 * @param mixed $default Default value if not set
 * @return mixed
 */
function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    // Handle boolean strings
    $lowered = strtolower($value);
    if ($lowered === 'true' || $lowered === '(true)') {
        return true;
    }
    if ($lowered === 'false' || $lowered === '(false)') {
        return false;
    }
    if ($lowered === 'null' || $lowered === '(null)') {
        return null;
    }

    return $value;
}

return [
    // ===========================================
    // Golden Sneakers API Settings
    // ===========================================
    'api' => [
        'base_url' => env('GS_API_URL', 'https://www.goldensneakers.net/api/assortment/'),
        'bearer_token' => env('GS_BEARER_TOKEN', ''),
        'params' => [
            'rounding_type' => env('GS_ROUNDING_TYPE', 'whole'),
            'markup_percentage' => (int) env('GS_MARKUP_PERCENTAGE', 25),
            'vat_percentage' => (int) env('GS_VAT_PERCENTAGE', 22),
        ],
    ],

    // ===========================================
    // WooCommerce REST API Settings
    // ===========================================
    'woocommerce' => [
        'url' => env('WC_URL', ''),
        'consumer_key' => env('WC_CONSUMER_KEY', ''),
        'consumer_secret' => env('WC_CONSUMER_SECRET', ''),
        'version' => env('WC_API_VERSION', 'wc/v3'),
    ],

    // ===========================================
    // WordPress Settings (for media uploads)
    // ===========================================
    'wordpress' => [
        'username' => env('WP_USERNAME', ''),
        'app_password' => env('WP_APP_PASSWORD', ''),
    ],

    // ===========================================
    // Store Information
    // ===========================================
    'store' => [
        'name' => env('STORE_NAME', 'ResellPiacenza'),
        'locale' => env('STORE_LOCALE', 'it_IT'),
    ],

    // ===========================================
    // Import Settings
    // ===========================================
    'import' => [
        // Category
        'category_name' => env('IMPORT_CATEGORY_NAME', 'Sneakers'),

        // Behavior
        'batch_size' => (int) env('IMPORT_BATCH_SIZE', 100),
        'create_out_of_stock' => env('IMPORT_CREATE_OUT_OF_STOCK', true),
    ],

    // ===========================================
    // Global WooCommerce Attributes (pa_* taxonomies)
    // ===========================================
    // These are registered as global attributes for filtering support
    // The importer will ensure they exist before using them
    'attributes' => [
        'size' => [
            'name' => env('ATTRIBUTE_SIZE_NAME', 'Taglia'),
            'slug' => env('ATTRIBUTE_SIZE_SLUG', 'taglia'),
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => true,
        ],
        'brand' => [
            'name' => env('ATTRIBUTE_BRAND_NAME', 'Marca'),
            'slug' => env('ATTRIBUTE_BRAND_SLUG', 'marca'),
            'type' => 'select',
            'order_by' => 'name',
            'has_archives' => true,
        ],
    ],

    // ===========================================
    // Localized Templates (Italian defaults)
    // ===========================================
    // Available placeholders: {product_name}, {brand_name}, {sku}, {store_name}
    'templates' => [
        // Image SEO metadata
        'image_alt' => '{product_name} - {sku} - Acquista su {store_name}',
        'image_caption' => '{brand_name} {product_name}',
        'image_description' => 'Acquista {product_name} ({sku}) su {store_name}. Sneakers originali {brand_name}. Spedizione rapida in tutta Italia.',

        // Product short description (excerpt)
        'short_description' => '<p>Sneakers originali <strong>{brand_name}</strong>. Prodotto autentico al 100%. Spedizione veloce in tutta Italia.</p>',

        // Product long description (main content)
        // This is a placeholder until LLM integration is implemented
        'long_description' => '
<p>Scopri le <strong>{product_name}</strong>, sneakers originali {brand_name} disponibili su {store_name}.</p>

<h3>Garanzia di Autenticità</h3>
<p>Tutti i prodotti venduti su {store_name} sono <strong>100% originali e autentici</strong>. Ogni articolo viene accuratamente verificato prima della spedizione.</p>

<h3>Spedizione e Resi</h3>
<ul>
    <li>✓ Spedizione rapida in tutta Italia</li>
    <li>✓ Imballaggio sicuro e discreto</li>
    <li>✓ Reso facile entro 14 giorni</li>
</ul>

<h3>Perché Scegliere {store_name}</h3>
<p>Siamo specialisti in sneakers e streetwear di alta qualità. La nostra missione è offrire prodotti autentici ai migliori prezzi, con un servizio clienti impeccabile.</p>
',
    ],

    // ===========================================
    // Categories (Auto-detected by size format)
    // ===========================================
    // Numeric sizes (36, 37.5, 42) → Sneakers
    // Letter sizes (S, M, L, XL) → Clothing
    'categories' => [
        'sneakers' => [
            'name' => env('CATEGORY_SNEAKERS_NAME', 'Sneakers'),
            'slug' => env('CATEGORY_SNEAKERS_SLUG', 'sneakers'),
        ],
        'clothing' => [
            'name' => env('CATEGORY_CLOTHING_NAME', 'Abbigliamento'),
            'slug' => env('CATEGORY_CLOTHING_SLUG', 'abbigliamento'),
        ],
    ],

    // ===========================================
    // Brands Taxonomy (Perfect Brands / WooCommerce Brands plugin)
    // ===========================================
    // Products are assigned to brands via the brands taxonomy (not categories)
    // Uses /products/brands endpoint - requires a WooCommerce brands plugin
    // This is SEPARATE from the brand product attribute (pa_marca)
    'brands' => [
        'enabled' => env('BRANDS_ENABLED', true),
        // Set to true to also create brand as product attribute (for filtering widgets)
        'create_attribute' => env('BRANDS_CREATE_ATTRIBUTE', false),
    ],

    // ===========================================
    // KicksDB Pricing Configuration
    // ===========================================
    'pricing' => [
        // KicksDB API
        'kicksdb_api_key' => env('KICKSDB_API_KEY', ''),
        'kicksdb_base_url' => env('KICKSDB_BASE_URL', 'https://api.kicks.dev/v3'),
        'kicksdb_market' => env('KICKSDB_MARKET', 'IT'),

        // KicksDB Discovery (auto-assortment)
        'kicksdb_assortment_size' => (int) env('KICKSDB_ASSORTMENT_SIZE', 800),
        'kicksdb_discovery_brands' => array_map('trim', explode(',', env('KICKSDB_DISCOVERY_BRANDS', 'Nike,Jordan,Adidas,New Balance,Yeezy'))),
        'kicksdb_discovery_page_size' => (int) env('KICKSDB_DISCOVERY_PAGE_SIZE', 50),

        // KicksDB Webhook
        'kicksdb_webhook_id' => env('KICKSDB_WEBHOOK_ID', null),
        'webhook_callback_url' => env('KICKSDB_WEBHOOK_CALLBACK_URL', ''),
        'webhook_secret' => env('KICKSDB_WEBHOOK_SECRET', ''),

        // WooCommerce product webhook secret (for auto-registration)
        'wc_webhook_secret' => env('WC_PRODUCT_WEBHOOK_SECRET', ''),

        // Margin configuration
        'margin' => [
            // Flat margin (default when no tier matches)
            'flat_margin' => (float) env('PRICING_FLAT_MARGIN', 25),

            // Tiered margins: higher margin on lower-priced items
            // Format: ['min' => X, 'max' => Y, 'margin' => Z]
            'tiers' => json_decode(env('PRICING_TIERS', '[]'), true) ?: [
                ['min' => 0, 'max' => 100, 'margin' => 35],
                ['min' => 100, 'max' => 200, 'margin' => 28],
                ['min' => 200, 'max' => 500, 'margin' => 22],
                ['min' => 500, 'max' => null, 'margin' => 18],
            ],

            // Absolute minimum selling price (0 = disabled)
            'floor_price' => (float) env('PRICING_FLOOR_PRICE', 59),

            // Rounding: 'whole' (ceil), 'half' (0.50 steps), 'none'
            'rounding' => env('PRICING_ROUNDING', 'whole'),

            // Stock tiers: dynamic stock based on market price (KicksDB only)
            // Lower price → higher stock, higher price → lower stock
            'stock_tiers' => json_decode(env('PRICING_STOCK_TIERS', '[]'), true) ?: [
                ['min' => 0, 'max' => 100, 'stock' => 80],
                ['min' => 100, 'max' => 200, 'stock' => 50],
                ['min' => 200, 'max' => 500, 'stock' => 25],
                ['min' => 500, 'max' => null, 'stock' => 12],
            ],
        ],

        // Price alert threshold (% change to trigger email). 0 = disabled
        'alert_threshold' => (float) env('PRICING_ALERT_THRESHOLD', 30),
        'alert_email' => env('PRICING_ALERT_EMAIL', ''),
        'store_name' => env('STORE_NAME', 'ResellPiacenza'),

        // Batch size for WC API variation updates
        'batch_size' => (int) env('PRICING_BATCH_SIZE', 100),
    ],

    // ===========================================
    // Logging Configuration
    // ===========================================
    'logging' => [
        'enabled' => env('LOG_ENABLED', true),
        'file' => __DIR__ . '/logs/import.log',
        'level' => env('LOG_LEVEL', 'info'),
        'console_level' => env('LOG_CONSOLE_LEVEL', 'info'),
    ],
];
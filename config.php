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

        // Size attribute (Italian: Taglia)
        'size_attribute_name' => env('IMPORT_SIZE_ATTRIBUTE_NAME', 'Taglia'),
        'size_attribute_slug' => env('IMPORT_SIZE_ATTRIBUTE_SLUG', 'taglia'),

        // Brand attribute (Italian: Marca)
        'brand_attribute_name' => env('IMPORT_BRAND_ATTRIBUTE_NAME', 'Marca'),
        'brand_attribute_slug' => env('IMPORT_BRAND_ATTRIBUTE_SLUG', 'marca'),

        // Behavior
        'batch_size' => (int) env('IMPORT_BATCH_SIZE', 100),
        'create_out_of_stock' => env('IMPORT_CREATE_OUT_OF_STOCK', true),
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
    // Logging Configuration
    // ===========================================
    'logging' => [
        'enabled' => env('LOG_ENABLED', true),
        'file' => __DIR__ . '/logs/import.log',
        'level' => env('LOG_LEVEL', 'info'),
        'console_level' => env('LOG_CONSOLE_LEVEL', 'info'),
    ],
];
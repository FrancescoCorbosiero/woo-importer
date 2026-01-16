<?php
/**
 * Golden Sneakers Import Configuration
 */

return [
    // API Settings
    'api' => [
        'base_url' => 'https://www.goldensneakers.net/api/assortment/',
        'bearer_token' => 'JWT_TOKEN',  // Replace this
        'params' => [
            'rounding_type' => 'whole',
            'markup_percentage' => 25,  // Default markup %
            'vat_percentage' => 22,     // Default VAT %
        ],
    ],

    // WooCommerce Settings
    'woocommerce' => [
        'url' => 'WOO_URL',
        'consumer_key' => 'WOO_CONSUMER_KEY',  // Get from WooCommerce settings
        'consumer_secret' => 'WOO_CONSUMER_SECRET',  // Get from WooCommerce settings
        'version' => 'wc/v3',
    ],

    // Import Settings
    'import' => [
        'category_name' => 'Sneakers',
        'size_attribute' => 'Size',
        'brand_attribute' => 'Brand',
        'batch_size' => 100,
        'create_out_of_stock' => true,
    ],

    // WordPress Settings (for media uploads)
    'wordpress' => [
        'username' => 'WP_USERNAME',  // Your WordPress admin username
        'app_password' => 'WP_APP_PASSWORD',  // Application password from Step 1
    ],

    // Logging
    'logging' => [
        'enabled' => true,
        'file' => __DIR__ . '/logs/import.log',
        'level' => 'info',  // Changed from 'debug' to 'info' for less verbose logs
        'console_level' => 'info',  // What shows on screen
    ],
];

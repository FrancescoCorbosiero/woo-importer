# WOO-IMPORTER UPGRADE PROJECT

## 📋 Project Overview

This project upgrades the Golden Sneakers to WooCommerce importer with:
- Multi-language support (Italian default)
- Environment variable configuration (.env)
- SEO-optimized image metadata
- Proper WooCommerce attribute handling
- Clean, professional code structure

## 🎯 Goals

1. **Environment Configuration**: Move sensitive data to `.env` file
2. **Multi-language Support**: Italian as default, fully configurable
3. **SEO-Optimized Images**: Italian metadata, proper alt texts
4. **Proper Attribute Handling**: Use WooCommerce attribute slugs correctly
5. **Generic Product Descriptions**: Elegant placeholder until LLM integration

## 📁 File Structure

```
woo-importer/
├── .env.example          # Template for environment variables
├── .env                  # Actual config (gitignored)
├── .gitignore            # Updated to include .env
├── config.php            # Loads from .env, contains defaults
├── import.php            # Main product importer (updated)
├── import-images.php     # Image importer (updated)
├── composer.json         # Dependencies (add vlucas/phpdotenv)
├── image-map.json        # Generated: SKU → Media ID mapping
└── logs/                 # Log files directory
```

## 🔧 Technical Requirements

### Dependencies
- PHP >= 7.4
- automattic/woocommerce ^3.0
- monolog/monolog ^2.0
- vlucas/phpdotenv ^5.0 (NEW)

### JSON Feed Structure (Reference)
```json
{
    "id": 8,
    "sku": "DD1873-102",
    "name": "Nike Dunk Low Next Nature White Black Panda (Women's)",
    "brand_name": "Nike",
    "image_full_url": "https://www.goldensneakers.net/images/DD1873-102/main/",
    "size_mapper_name": "Nike WMNS",
    "sizes": [
        {
            "size_us": "5",
            "size_eu": "35.5",
            "offer_price": 55.0,
            "presented_price": 84,
            "available_quantity": 0,
            "barcode": null
        }
    ]
}
```

## 🌍 Localization Strategy

### Default Language: Italian (it_IT)

All user-facing text should be in Italian by default:
- Category names
- Attribute names
- Image SEO metadata
- Product descriptions
- Error messages (logs can stay English)

### Configurable Strings
```php
'locale' => [
    'language' => 'it_IT',
    'category_name' => 'Sneakers',
    'size_attribute_name' => 'Taglia',
    'size_attribute_slug' => 'taglia',
    'brand_attribute_name' => 'Marca', 
    'brand_attribute_slug' => 'marca',
    'image_alt_template' => '{product_name} - {sku} - Acquista su {store_name}',
    'image_caption_template' => '{brand_name} {product_name}',
    'image_description_template' => 'Acquista {product_name} ({sku}) su {store_name}. Sneakers originali {brand_name}. Spedizione rapida in tutta Italia.',
    'default_short_description' => 'Sneakers originali {brand_name}. Prodotto autentico al 100%. Spedizione veloce in Italia.',
    'default_long_description' => '<p>Scopri le <strong>{product_name}</strong>, sneakers originali {brand_name} disponibili su {store_name}.</p><p>✓ Prodotto 100% autentico<br>✓ Spedizione rapida in tutta Italia<br>✓ Reso facile entro 14 giorni</p>',
],
'store' => [
    'name' => 'ResellPiacenza',
    'url' => 'resellpiacenza.it',
],
```

## 🏷️ WooCommerce Attribute Strategy

### Best Practice for Attributes
WooCommerce REST API handles attributes best when:
1. Using **global attributes** (created in WooCommerce > Attributes)
2. Referencing by **slug** (e.g., `pa_taglia`, `pa_marca`)

### Implementation Approach
```php
// For product creation, use this format:
'attributes' => [
    [
        'name' => 'pa_marca',           // Use slug with pa_ prefix
        'position' => 0,
        'visible' => true,
        'variation' => false,
        'options' => ['Nike']
    ],
    [
        'name' => 'pa_taglia',          // Use slug with pa_ prefix
        'position' => 1,
        'visible' => true,
        'variation' => true,
        'options' => ['35.5', '36', '36.5', ...]
    ]
]
```

### Attribute Slugs Convention
| Display Name (IT) | Slug | WooCommerce Taxonomy |
|-------------------|------|---------------------|
| Taglia | taglia | pa_taglia |
| Marca | marca | pa_marca |

## 📝 Product Description Strategy

Until LLM integration, use elegant generic descriptions:

### Short Description (Italian)
```
Sneakers originali {brand_name}. Prodotto autentico al 100%. Spedizione veloce in Italia.
```

### Long Description (Italian)
```html
<p>Scopri le <strong>{product_name}</strong>, sneakers originali {brand_name} disponibili su {store_name}.</p>

<p>
✓ Prodotto 100% autentico<br>
✓ Spedizione rapida in tutta Italia<br>
✓ Reso facile entro 14 giorni
</p>
```

## 🖼️ Image SEO Requirements

### Filename
Format: `{sku}.{extension}` (sanitized)
Example: `DD1873-102.jpg`

### Title
`{product_name}`
Example: `Nike Dunk Low Next Nature White Black Panda (Women's)`

### Alt Text (Critical for SEO)
Template: `{product_name} - {sku} - Acquista su {store_name}`
Example: `Nike Dunk Low Next Nature White Black Panda (Women's) - DD1873-102 - Acquista su ResellPiacenza`

### Caption
Template: `{brand_name} {product_name}`
Example: `Nike Nike Dunk Low Next Nature White Black Panda (Women's)`

### Description
Template: `Acquista {product_name} ({sku}) su {store_name}. Sneakers originali {brand_name}. Spedizione rapida in tutta Italia.`

## ⚙️ Environment Variables (.env)

```env
# API Configuration
GS_API_URL=https://www.goldensneakers.net/api/assortment/
GS_BEARER_TOKEN=your_jwt_token_here
GS_MARKUP_PERCENTAGE=25
GS_VAT_PERCENTAGE=22
GS_ROUNDING_TYPE=whole

# WooCommerce Configuration
WC_URL=https://your-store.com
WC_CONSUMER_KEY=ck_xxxxx
WC_CONSUMER_SECRET=cs_xxxxx
WC_API_VERSION=wc/v3

# WordPress Configuration (for media uploads)
WP_USERNAME=admin
WP_APP_PASSWORD=xxxx_xxxx_xxxx_xxxx

# Store Configuration
STORE_NAME=ResellPiacenza
STORE_LOCALE=it_IT

# Import Configuration
IMPORT_CATEGORY=Sneakers
IMPORT_SIZE_ATTRIBUTE_NAME=Taglia
IMPORT_SIZE_ATTRIBUTE_SLUG=taglia
IMPORT_BRAND_ATTRIBUTE_NAME=Marca
IMPORT_BRAND_ATTRIBUTE_SLUG=marca
IMPORT_BATCH_SIZE=100
IMPORT_CREATE_OUT_OF_STOCK=true

# Logging
LOG_ENABLED=true
LOG_LEVEL=info
LOG_CONSOLE_LEVEL=info
```

## 🚀 Implementation Checklist

- [ ] Add vlucas/phpdotenv to composer.json
- [ ] Create .env.example with all variables
- [ ] Update .gitignore to exclude .env
- [ ] Refactor config.php to load from .env with defaults
- [ ] Update import.php:
  - [ ] Use attribute slugs (pa_taglia, pa_marca)
  - [ ] Add short_description and description
  - [ ] Use localized strings
- [ ] Update import-images.php:
  - [ ] Italian SEO metadata
  - [ ] Template-based strings
- [ ] Test with dry-run mode
- [ ] Document changes in README

## 📋 Code Style Guidelines

- Use PSR-12 coding standard
- Add PHPDoc comments for all methods
- Use meaningful variable names
- Keep methods focused and small
- Handle errors gracefully with logging
- Use constants for magic strings

## 🧪 Testing

Before deploying:
1. Run `php import.php --dry-run --limit=5`
2. Verify attribute slugs are correct
3. Check image metadata in WordPress Media Library
4. Validate product descriptions appear correctly
5. Test with Google Rich Results Test after import
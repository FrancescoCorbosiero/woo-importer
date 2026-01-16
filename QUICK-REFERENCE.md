# 🚀 QUICK REFERENCE: WOO-IMPORTER UPGRADE

## One-Liner Summary
Add .env support, Italian localization, SEO image metadata, and proper WooCommerce attribute slugs.

---

## Files to Change

| File | Action | Priority |
|------|--------|----------|
| `composer.json` | Add `vlucas/phpdotenv` | 1 |
| `.env.example` | CREATE new | 2 |
| `.gitignore` | CREATE/UPDATE | 2 |
| `config.php` | REFACTOR completely | 3 |
| `import.php` | UPDATE attributes + add descriptions | 4 |
| `import-images.php` | UPDATE SEO metadata to Italian | 5 |

---

## Key Changes Summary

### 1. Environment Variables
```php
// OLD: Hardcoded in config
'bearer_token' => 'JWT_TOKEN',

// NEW: From .env
'bearer_token' => env('GS_BEARER_TOKEN', ''),
```

### 2. Attribute Slugs
```php
// OLD: Display name
'name' => 'Brand',

// NEW: Taxonomy slug with pa_ prefix
'name' => 'pa_marca',
```

### 3. Image SEO (Italian)
```php
// OLD: English
$alt_text = "Buy at ResellPiacenza";

// NEW: Italian template
$alt_text = "Acquista su ResellPiacenza";
```

### 4. Product Descriptions
```php
// OLD: None
// No description field

// NEW: Italian template
'short_description' => '<p>Sneakers originali <strong>{brand_name}</strong>...</p>',
'description' => '<p>Scopri le <strong>{product_name}</strong>...</p>',
```

---

## .env Variables Quick List

```env
# Required
GS_BEARER_TOKEN=xxx
WC_URL=https://store.com
WC_CONSUMER_KEY=ck_xxx
WC_CONSUMER_SECRET=cs_xxx
WP_USERNAME=admin
WP_APP_PASSWORD=xxxx

# Optional (have defaults)
STORE_NAME=ResellPiacenza
IMPORT_SIZE_ATTRIBUTE_SLUG=taglia
IMPORT_BRAND_ATTRIBUTE_SLUG=marca
```

---

## Testing Commands

```bash
# 1. Install dependencies
composer install

# 2. Setup environment
cp .env.example .env
nano .env  # Fill in your credentials

# 3. Test image import (dry concept - it will upload)
php import-images.php --limit=2

# 4. Test product import
php import.php --dry-run --limit=3

# 5. Real import (small batch first)
php import.php --limit=10

# 6. Full import
php import.php
```

---

## Validation Points

After import, verify in WooCommerce:

1. **Products → Attributes**
   - "Taglia" attribute exists with slug `taglia`
   - "Marca" attribute exists with slug `marca`

2. **Products → [Any Product]**
   - Short description shows Italian text
   - Long description shows Italian HTML
   - Attributes show "Taglia" and "Marca" (not "Size" and "Brand")

3. **Media Library → [Any Image]**
   - Alt text is in Italian
   - Caption is in Italian
   - Description is in Italian

---

## Common Issues

| Issue | Solution |
|-------|----------|
| "Attribute not found" | Create `pa_taglia` and `pa_marca` in WooCommerce first |
| "Invalid API key" | Check WC_CONSUMER_KEY and WC_CONSUMER_SECRET |
| ".env not loading" | Run `composer install` to get phpdotenv |
| "Images not linking" | Run `import-images.php` before `import.php` |
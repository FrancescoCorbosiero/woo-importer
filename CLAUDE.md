# WOO-IMPORTER

## Project Overview

WooCommerce product importer for ResellPiacenza. Supports four import pipelines:

1. **Golden Sneakers (GS)** - Automated supplier sync (cron every 30 min)
2. **KicksDB** - Popular sneakers auto-discovery (cron every 6 hours)
3. **Shopify CSV Import** - One-time migration from Shopify exports
4. **Manual Bulk Upload** - CSV/JSON file import for ad-hoc products

All pipelines produce WooCommerce variable products with Italian SEO metadata, size variations (Taglia), brand attributes (Marca), and gallery images.

## Architecture

```
Data Sources           Adapters / Parsers         Core Pipeline              WooCommerce
─────────────         ──────────────────         ──────────────             ───────────
GS API            →   GsFeedAdapter          →   WcProductBuilder      →   WooCommerceImporter
KicksDB API       →   KicksDbFeedAdapter     →   WcProductBuilder      →   WooCommerceImporter
Shopify CSV       →   ShopifyCsvParser       →   BulkUploader          →   WooCommerceImporter
CSV/JSON files    →   (BulkUploader inline)  →   BulkUploader          →   WooCommerceImporter
```

### Key classes

| Class | File | Purpose |
|-------|------|---------|
| `Config` | `src/Support/Config.php` | Loads `.env`, provides config array |
| `TaxonomyManager` | `src/Import/TaxonomyManager.php` | Creates/resolves WC categories, attributes, brands |
| `MediaUploader` | `src/Import/MediaUploader.php` | Uploads images to WordPress media library |
| `WcProductBuilder` | `src/Import/WcProductBuilder.php` | Transforms normalized feed → WC product payload |
| `WooCommerceImporter` | `src/Import/WooCommerceImporter.php` | Batch creates/updates products + variations via WC REST API |
| `BulkUploader` | `src/Import/BulkUploader.php` | Self-contained pipeline for CSV/JSON/Shopify imports |
| `ShopifyCsvParser` | `src/Import/ShopifyCsvParser.php` | Parses Shopify product export CSVs |
| `GsFeedAdapter` | `src/Import/GsFeedAdapter.php` | Normalizes Golden Sneakers API response |
| `KicksDbFeedAdapter` | `src/Import/KicksDbFeedAdapter.php` | Normalizes KicksDB API response |

## File Structure

```
woo-importer/
├── bin/                          # CLI scripts
│   ├── bulk-upload               # Manual CSV/JSON import
│   ├── gs-transform              # GS feed → WC format
│   ├── import-dir                # Shopify CSV directory import
│   ├── import-kicksdb            # KicksDB standalone import
│   ├── import-wc                 # Generic WC JSON import
│   ├── kicksdb-discover          # KicksDB product discovery
│   ├── kicksdb-transform         # KicksDB feed → WC format
│   ├── nuke-products             # Delete all WC products (dangerous)
│   ├── prepare-media             # Upload/validate images
│   ├── prepare-taxonomies        # Create categories/attributes/brands
│   ├── pricing-reconcile         # Update stale prices from KicksDB
│   └── sync-wc                   # Delta sync → WC import
├── src/
│   ├── Import/                   # Import pipeline classes
│   └── Support/                  # Config, Logger, helpers
├── gs-sync.sh                    # GS cron pipeline wrapper
├── kicksdb-sync.sh               # KicksDB cron pipeline wrapper
├── import/                       # Shopify CSV export files (input)
├── data/                         # Generated intermediate feeds (gitignored)
├── logs/                         # Log files (gitignored)
├── docs/                         # Documentation and model references
├── .env                          # Credentials (gitignored)
├── .env.example                  # Env template
├── crontab.txt                   # Cron schedule reference
├── image-map.json                # SKU → media ID mapping (gitignored)
└── composer.json                 # PHP dependencies
```

## Import Pipelines

### 1. Golden Sneakers (GS) - Automated Sync

**Entry point:** `./gs-sync.sh` or individual `bin/` scripts

**Pipeline:** `prepare-taxonomies → prepare-media → gs-transform → sync-wc`

```bash
# Full automated sync (what cron runs)
./gs-sync.sh

# Preview mode
./gs-sync.sh --dry-run --verbose

# Skip image uploads
./gs-sync.sh --skip-media

# Force full import (ignore delta diff)
./gs-sync.sh --force-full
```

**Data flow:**
- GS API → `data/feed.json` → `GsFeedAdapter` → `WcProductBuilder` → `data/feed-wc-latest.json`
- Delta: compares `feed-wc-latest.json` vs `feed-wc.json` → `diff-wc.json` → imports only changes

### 2. KicksDB - Auto-Discovery Sync

**Entry point:** `./kicksdb-sync.sh` or individual `bin/` scripts

**Pipeline:** `kicksdb-discover → prepare-taxonomies → prepare-media → kicksdb-transform → sync-wc`

```bash
# Full sync
./kicksdb-sync.sh

# Preview mode
./kicksdb-sync.sh --dry-run --verbose

# Skip discovery (use cached assortment)
./kicksdb-sync.sh --skip-discover

# Limit assortment size
./kicksdb-sync.sh --limit=100
```

**Data flow:**
- KicksDB API → `data/feed-kicksdb.json` → `KicksDbFeedAdapter` → `WcProductBuilder` → `data/feed-kicksdb-wc-latest.json`
- SKU registry (`data/sku-registry.json`) prevents duplicating products already covered by GS

### 3. Shopify CSV Import (One-Time Migration)

**Entry point:** `bin/import-dir`

**Pipeline:** `ShopifyCsvParser → BulkUploader` (self-contained)

```bash
# Preview
bin/import-dir --dir=import/ --dry-run --verbose

# Import first 5 products
bin/import-dir --dir=import/ --limit=5

# Full import (skips products already in WooCommerce)
bin/import-dir --dir=import/

# Skip image upload (use URLs directly)
bin/import-dir --dir=import/ --skip-media
```

**Features:**
- Parses Shopify product export CSV format (multi-row per product, Handle grouping)
- Extracts brands from Tags (with sub-brand detection: Jordan vs Nike)
- Extracts SKUs from HTML body descriptions
- Gallery image support (multiple images per product)
- Auto-detects category from size format (numeric → Sneakers, letter → Abbigliamento)
- `--skip-existing` is enabled by default (skips products already in WooCommerce by SKU)

### 4. Manual Bulk Upload

**Entry point:** `bin/bulk-upload`

**Pipeline:** `BulkUploader` (self-contained)

```bash
# From CSV
bin/bulk-upload --file=data/products.csv --dry-run

# From JSON
bin/bulk-upload --file=data/products.json --verbose

# With options
bin/bulk-upload --file=data/products.csv --skip-media --limit=10
```

**Expected CSV format:** `sku, name, brand, category, image_url, size, price, stock`

## WooCommerce Product Model

See `docs/CURRENT_MODEL.json` for full field reference.

**Parent product:** variable type, `manage_stock: false`, `stock_status: 'instock'`

**Variation:** `{parent_sku}-{size_eu}`, `manage_stock: true`, individual stock quantities

**Attributes:**
- `Marca` (brand) - visible, non-variation, global attribute (`pa_marca`)
- `Taglia` (size) - visible, variation, global attribute (`pa_taglia`)

## Localization (Italian)

All user-facing content is in Italian:
- Category names: Sneakers, Abbigliamento
- Attribute names: Taglia, Marca
- Image SEO: alt text, captions, descriptions
- Product descriptions: short + long with Italian templates

### Image SEO Templates

| Field | Template |
|-------|----------|
| Alt text | `{product_name} - {sku} - Acquista su {store_name}` |
| Caption | `{brand_name} {product_name}` |
| Description | `Acquista {product_name} ({sku}) su {store_name}. Sneakers originali {brand_name}...` |

## Environment Variables

See `.env.example` for full list. Key variables:

```env
# WooCommerce API
WC_URL=https://your-store.com
WC_CONSUMER_KEY=ck_xxxxx
WC_CONSUMER_SECRET=cs_xxxxx

# WordPress (media uploads)
WP_USERNAME=admin
WP_APP_PASSWORD=xxxx_xxxx_xxxx_xxxx

# Golden Sneakers API
GS_API_URL=https://www.goldensneakers.net/api/assortment/
GS_BEARER_TOKEN=your_jwt_token

# Pricing
GS_MARKUP_PERCENTAGE=25
GS_VAT_PERCENTAGE=22

# Store
STORE_NAME=ResellPiacenza
```

## Code Style

- PSR-12 coding standard
- PHPDoc comments on all public methods
- Meaningful variable names
- Logs in English, user-facing content in Italian
- PHP >= 7.4, dependencies via Composer

## Known Gotchas

- **Variation visibility:** Parent products MUST have `manage_stock => false` and `stock_status => 'instock'`, otherwise WooCommerce hides the size dropdown on the frontend.
- **Attribute slugs:** WC accepts both `taglia` and `pa_taglia`. Resolution uses flexible matching (with/without `pa_` prefix, name fallback).
- **Dry-run + images:** Never persist fake media IDs during `--dry-run`. Gallery upload returns `[]` in dry-run mode.
- **image-map.json:** Delete this file to force re-upload of all images. Stale entries (fake IDs from dry-runs) are auto-filtered.

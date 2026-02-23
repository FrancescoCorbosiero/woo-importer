# WOO-IMPORTER

## Project Overview

WooCommerce product importer for ResellPiacenza. Supports five import pipelines:

1. **Unified Sync** - Merges KicksDB catalog + GS pricing at variation level (recommended)
2. **Golden Sneakers (GS)** - Automated supplier sync (cron every 30 min)
3. **KicksDB** - Popular sneakers auto-discovery (cron every 6 hours)
4. **Shopify CSV Import** - One-time migration from Shopify exports
5. **Manual Bulk Upload** - CSV/JSON file import for ad-hoc products

All pipelines produce WooCommerce products (variable or simple) with Italian SEO metadata, size variations (Taglia), brand attributes (Marca), and gallery images.

**Product categories:** Sneakers, Abbigliamento, Accessori — auto-detected from product type or size format.

## Architecture

```
Data Sources           Adapters / Parsers         Core Pipeline              WooCommerce
─────────────         ──────────────────         ──────────────             ───────────
GS + KicksDB      →   FeedMerger             →   WcProductBuilder      →   WooCommerceImporter
GS API            →   GoldenSneakersAdapter  →   WcProductBuilder      →   WooCommerceImporter
KicksDB API       →   KicksDbAdapter         →   WcProductBuilder      →   WooCommerceImporter
Shopify CSV       →   ShopifyCsvParser       →   BulkUploader          →   WooCommerceImporter
CSV/JSON files    →   (BulkUploader inline)  →   BulkUploader          →   WooCommerceImporter
```

### Key classes

| Class | File | Purpose |
|-------|------|---------|
| `Config` | `src/Support/Config.php` | Loads `.env`, provides config array |
| `TaxonomyManager` | `src/Taxonomy/TaxonomyManager.php` | Creates/resolves WC categories, attributes, brands |
| `MediaUploader` | `src/Media/MediaUploader.php` | Uploads images to WordPress media library |
| `WcProductBuilder` | `src/Import/WcProductBuilder.php` | Transforms normalized feed → WC product payload |
| `WooCommerceImporter` | `src/Import/WooCommerceImporter.php` | Batch creates/updates products + variations via WC REST API |
| `FeedMerger` | `src/Import/FeedMerger.php` | Merges KicksDB + GS feeds at variation level |
| `BulkUploader` | `src/Import/BulkUploader.php` | Self-contained pipeline for CSV/JSON/Shopify imports |
| `ShopifyCsvParser` | `src/Import/ShopifyCsvParser.php` | Parses Shopify product export CSVs |
| `GoldenSneakersAdapter` | `src/Import/GoldenSneakersAdapter.php` | Normalizes Golden Sneakers API response |
| `KicksDbAdapter` | `src/Import/KicksDbAdapter.php` | Normalizes KicksDB API response |
| `KicksDb\Client` | `src/KicksDb/Client.php` | KicksDB v3 API client (search, prices, webhooks) |
| `PriceCalculator` | `src/Pricing/PriceCalculator.php` | Tiered margin + floor price + rounding |

## File Structure

```
woo-importer/
├── bin/                          # CLI scripts
│   ├── bulk-upload               # Manual CSV/JSON import
│   ├── gs-transform              # GS feed → WC format
│   ├── import-dir                # Shopify CSV directory import
│   ├── import-kicksdb            # KicksDB standalone import
│   ├── import-wc                 # Generic WC JSON import
│   ├── kicksdb-discover          # KicksDB product discovery (catalog mode)
│   ├── kicksdb-transform         # KicksDB feed → WC format
│   ├── nuke-products             # Delete all WC products (dangerous)
│   ├── prepare-media             # Upload/validate images
│   ├── prepare-taxonomies        # Create categories/attributes/brands
│   ├── pricing-reconcile         # Update stale prices from KicksDB
│   ├── sync-wc                   # Delta sync → WC import
│   └── unified-transform         # Merge KicksDB + GS → WC format
├── src/
│   ├── Import/                   # Import pipeline classes + adapters
│   ├── KicksDb/                  # KicksDB API client
│   ├── Media/                    # Image upload/SEO
│   ├── Nuke/                     # Product deletion
│   ├── Pricing/                  # Margin calculation, price updates
│   ├── Support/                  # Config, Logger
│   └── Taxonomy/                 # Category/attribute/brand management
├── unified-sync.sh               # Unified pipeline (recommended for production)
├── gs-sync.sh                    # GS-only cron pipeline
├── kicksdb-sync.sh               # KicksDB-only cron pipeline
├── data/
│   └── brand-catalog.json        # KicksDB discovery catalog (sections + brands)
├── docs/                         # Technical docs and model references
├── samples/                      # Sample API responses and test data
├── .env.example                  # Environment template
├── config.php                    # Configuration loader
├── crontab.txt                   # Cron schedule reference
└── composer.json                 # PHP dependencies
```

## Import Pipelines

### 1. Unified Sync (Recommended)

**Entry point:** `./unified-sync.sh`

**Pipeline:** `kicksdb-discover → prepare-taxonomies → prepare-media → unified-transform → sync-wc`

KicksDB is the product catalog master (rich metadata, images, gallery). GS is a pricing/stock overlay — when a product exists in both, GS real prices and stock replace KicksDB synthetic data at the variation level.

```bash
./unified-sync.sh                     # Full sync
./unified-sync.sh --dry-run --verbose # Preview
./unified-sync.sh --skip-media        # Skip image upload
./unified-sync.sh --skip-discover     # Use cached assortment
```

### 2. Golden Sneakers (GS) - Standalone Sync

**Entry point:** `./gs-sync.sh`

**Pipeline:** `prepare-taxonomies → prepare-media → gs-transform → sync-wc`

```bash
./gs-sync.sh                          # Full sync (cron runs this)
./gs-sync.sh --dry-run --verbose      # Preview
./gs-sync.sh --skip-media             # Skip image upload
./gs-sync.sh --force-full             # Ignore delta diff
```

### 3. KicksDB - Standalone Sync

**Entry point:** `./kicksdb-sync.sh`

**Pipeline:** `kicksdb-discover → prepare-taxonomies → prepare-media → kicksdb-transform → sync-wc`

```bash
./kicksdb-sync.sh                     # Full sync
./kicksdb-sync.sh --dry-run --verbose # Preview
./kicksdb-sync.sh --skip-discover     # Use cached assortment
./kicksdb-sync.sh --limit=100         # Limit assortment size
```

### 4. Shopify CSV Import (One-Time Migration)

**Entry point:** `bin/import-dir`

```bash
bin/import-dir --dir=import/ --dry-run --verbose  # Preview
bin/import-dir --dir=import/ --limit=5            # Import first 5
bin/import-dir --dir=import/                      # Full import
```

### 5. Manual Bulk Upload

**Entry point:** `bin/bulk-upload`

```bash
bin/bulk-upload --file=data/products.csv --dry-run
bin/bulk-upload --file=data/products.json --verbose
```

**Expected CSV format:** `sku, name, brand, category, image_url, size, price, stock`

## Brand Catalog (`data/brand-catalog.json`)

The KicksDB discovery pipeline uses a JSON catalog to define what to search for. The catalog has **sections** (mapped to WC categories) containing **brands** with search queries.

Each section specifies `product_types` which are passed to the KicksDB API as filters. Brands can override the section's `product_types` for mixed sections (e.g., Accessori with streetwear hats + collectibles toys).

```json
{
  "sections": [
    {
      "name": "Sneakers",
      "slug": "sneakers",
      "product_types": ["sneakers"],
      "brands": [
        { "name": "Nike", "per_label": 30, "products": ["Nike Dunk", "Nike Air Force 1"] }
      ]
    },
    {
      "name": "Accessori",
      "slug": "accessori",
      "product_types": ["streetwear"],
      "brands": [
        { "name": "Beanie", "per_label": 15, "product_types": ["streetwear"], "products": ["beanie"] },
        { "name": "Labubu", "per_label": 15, "product_types": ["collectibles"], "products": ["labubu"] }
      ]
    }
  ]
}
```

## WooCommerce Product Model

See `docs/CURRENT_MODEL.json` for full field reference.

**Parent product:** variable type, `manage_stock: false`, `stock_status: 'instock'`
**Simple product:** used for "One Size" items (collectibles, bags)

**Variation:** `{parent_sku}-{size_eu}`, `manage_stock: true`, individual stock quantities

**Attributes:**
- `Taglia` (size) - visible, variation, global (`pa_taglia`)
- `Marca` (brand) - visible, non-variation, global (`pa_marca`)
- `Colorway` - visible, non-variation, global (`pa_colorway`)
- `Genere` (gender) - visible, non-variation, global (`pa_genere`)
- `Modello` (model) - visible, non-variation, global (`pa_modello`)
- `Data di Rilascio` (release date) - visible, non-variation, global (`pa_data-di-rilascio`)

**Images:** Prefers pre-uploaded media IDs from `image-map.json`. Falls back to `src` URL sideloading when no pre-uploaded media exists.

## Localization (Italian)

All user-facing content is in Italian:
- Category names: Sneakers, Abbigliamento, Accessori
- Attribute names: Taglia, Marca, Genere, Modello, Data di Rilascio
- Image SEO: alt text, captions, descriptions
- Product descriptions: short + long with Italian templates

## Environment Variables

See `.env.example` for the full list. Key variables:

```env
WC_URL=https://your-store.com
WC_CONSUMER_KEY=ck_xxxxx
WC_CONSUMER_SECRET=cs_xxxxx
WP_USERNAME=admin
WP_APP_PASSWORD=xxxx_xxxx_xxxx_xxxx
GS_API_URL=https://www.goldensneakers.net/api/assortment/
GS_BEARER_TOKEN=your_jwt_token
KICKSDB_API_KEY=your_kicksdb_api_key
KICKSDB_BRAND_CATALOG_FILE=data/brand-catalog.json
STORE_NAME=ResellPiacenza
```

## Code Style

- PSR-12 coding standard
- PHPDoc comments on all public methods
- Meaningful variable names
- Logs in English, user-facing content in Italian
- PHP >= 7.4, dependencies via Composer

## Known Gotchas

- **Variation visibility:** Parent products MUST have `manage_stock => false` and `stock_status => 'instock'`, otherwise WooCommerce hides the size dropdown.
- **Attribute slugs:** WC accepts both `taglia` and `pa_taglia`. Resolution uses flexible matching (with/without `pa_` prefix, name fallback).
- **Image fallback:** When `prepare-media` hasn't run, `WcProductBuilder` falls back to sideloading from the source URL. This is slower but ensures products always get images.
- **image-map.json:** Delete this file to force re-upload of all images. Stale entries are auto-filtered by `prepare-media --validate`.
- **Dry-run + images:** Never persist fake media IDs during `--dry-run`. Gallery upload returns `[]` in dry-run mode.
- **KicksDB API filters:** Non-sneaker queries (clothing, accessories, collectibles) require the `filters=product_type=...` parameter. This is set automatically from `product_types` in the brand catalog.

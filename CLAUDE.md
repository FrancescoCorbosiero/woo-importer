# WOO-IMPORTER

## Project Overview

WooCommerce product importer for ResellPiacenza. Architecture: **one master catalog, separate update loops**.

### Primary Pipelines

1. **Catalog Build** (`catalog-build.sh`) - Full catalog refresh: KicksDB discovery + GS ingestion → WC import (daily)
2. **GS Update** (`gs-update.sh`) - Lightweight GS price/stock variation patches (every 30 min)
3. **KicksDB Price Refresh** (`kicksdb-price-refresh.sh`) - Market price updates for non-GS products (daily)

### Secondary Pipelines

4. **Shopify CSV Import** - One-time migration from Shopify exports
5. **Manual Bulk Upload** - CSV/JSON file import for ad-hoc products

### Legacy Pipelines (Deprecated)

6. ~~Unified Sync~~ → replaced by Catalog Build
7. ~~GS Sync~~ → replaced by GS Update
8. ~~KicksDB Sync~~ → replaced by Catalog Build

All pipelines produce WooCommerce products (variable or simple) with Italian SEO metadata, size variations (Taglia), brand attributes (Marca), and gallery images.

**Product categories:** Sneakers, Abbigliamento, Accessori — determined by `wc_category` in brand-catalog.json.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  CATALOG BUILD  (daily)                                      │
│  kicksdb-discover → gs-ingest → taxonomies → media           │
│  → catalog-transform → sync-wc                               │
│                                                               │
│  brand-catalog.json ──→ KicksDB API ──→ assortment            │
│  GS API ──→ KicksDB lookup (enrich) ──→ merged assortment     │
│  FeedMerger (variation-level merge) → WcProductBuilder → WC   │
└─────────────────────────────────────────────────────────────┘
┌────────────────────────────┐  ┌────────────────────────────┐
│  GS UPDATE  (30 min)       │  │  KICKSDB PRICE  (daily)    │
│  GS API → compare cached   │  │  KicksDB price API          │
│  → patch WC variations     │  │  → PriceCalculator          │
│  (price + stock only)      │  │  → patch WC variation prices │
└────────────────────────────┘  └────────────────────────────┘
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
│   ├── catalog-transform         # Merged assortment → WC REST format (NEW)
│   ├── gs-ingest                 # GS SKUs → KicksDB enrich → merged assortment (NEW)
│   ├── gs-variation-update       # Lightweight GS → WC variation patcher (NEW)
│   ├── kicksdb-discover          # KicksDB product discovery (catalog v2)
│   ├── prepare-media             # Upload/validate images
│   ├── prepare-taxonomies        # Create categories/attributes/brands
│   ├── pricing-reconcile         # Update stale prices from KicksDB
│   ├── sync-wc                   # Delta sync → WC import
│   ├── import-wc                 # Generic WC JSON import
│   ├── import-dir                # Shopify CSV directory import
│   ├── bulk-upload               # Manual CSV/JSON import
│   ├── nuke-products             # Delete all WC products (dangerous)
│   ├── gs-transform              # (legacy) GS feed → WC format
│   ├── kicksdb-transform         # (legacy) KicksDB feed → WC format
│   └── unified-transform         # (legacy) Merge KicksDB + GS → WC format
├── src/
│   ├── Import/                   # Import pipeline classes + adapters
│   ├── KicksDb/                  # KicksDB API client
│   ├── Media/                    # Image upload/SEO
│   ├── Nuke/                     # Product deletion
│   ├── Pricing/                  # Margin calculation, price updates
│   ├── Support/                  # Config, Logger
│   └── Taxonomy/                 # Category/attribute/brand management
├── catalog-build.sh              # Full catalog refresh (NEW, recommended)
├── gs-update.sh                  # Lightweight GS price/stock patcher (NEW)
├── kicksdb-price-refresh.sh      # KicksDB market price updates (NEW)
├── unified-sync.sh               # (legacy) Unified pipeline
├── gs-sync.sh                    # (legacy) GS-only cron pipeline
├── kicksdb-sync.sh               # (legacy) KicksDB-only cron pipeline
├── data/
│   ├── brand-catalog.json        # Discovery catalog v2 (sections + discovery modes)
│   ├── kicksdb-assortment.json   # KicksDB discovery output (generated)
│   ├── merged-assortment.json    # KicksDB + GS merged catalog (generated)
│   ├── gs-tracked-skus.json      # GS SKU → variation snapshot (generated)
│   ├── gs-queue.json             # New GS SKUs pending catalog-build (generated)
│   ├── feed-wc-latest.json       # Latest WC feed (generated)
│   ├── feed-wc.json              # Baseline WC feed for delta (generated)
│   ├── taxonomy-map.json         # Category/brand IDs (generated)
│   └── image-map.json            # Media IDs (generated)
├── docs/                         # Technical docs and model references
├── samples/                      # Sample API responses and test data
├── .env.example                  # Environment template
├── config.php                    # Configuration loader
├── crontab.txt                   # Cron schedule reference
└── composer.json                 # PHP dependencies
```

## Import Pipelines

### 1. Catalog Build (Recommended — Daily)

**Entry point:** `./catalog-build.sh`

**Pipeline:** `kicksdb-discover → gs-ingest → prepare-taxonomies → prepare-media → catalog-transform → sync-wc`

KicksDB is the product catalog master (rich metadata, images, gallery). GS provides real pricing/stock overlay — when a product exists in both, GS prices and stock replace KicksDB synthetic data at the variation level. New GS SKUs are enriched via KicksDB lookup; if KicksDB miss, they're imported with GS-only data.

```bash
./catalog-build.sh                     # Full build
./catalog-build.sh --dry-run --verbose # Preview
./catalog-build.sh --skip-media        # Skip image upload
./catalog-build.sh --skip-discover     # Use cached KicksDB assortment
./catalog-build.sh --skip-gs           # Skip GS ingestion
./catalog-build.sh --force-full        # Force full import (ignore delta)
./catalog-build.sh --limit=100         # Limit assortment size
```

### 2. GS Update (Every 30 min)

**Entry point:** `./gs-update.sh`

Lightweight variation-only patcher. Fetches GS feed, compares against cached values in `gs-tracked-skus.json`, and patches only changed WC variations (price + stock). Does NOT rebuild products. New GS SKUs are queued to `gs-queue.json` for the next catalog-build.

```bash
./gs-update.sh                         # Full update
./gs-update.sh --dry-run --verbose     # Preview
```

### 3. KicksDB Price Refresh (Daily)

**Entry point:** `./kicksdb-price-refresh.sh`

Updates WC variation prices from KicksDB market data for products NOT covered by GS. Wraps `bin/pricing-reconcile`.

```bash
./kicksdb-price-refresh.sh                     # Full refresh
./kicksdb-price-refresh.sh --dry-run           # Preview
./kicksdb-price-refresh.sh --limit=50          # First 50 SKUs
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

## Brand Catalog v2 (`data/brand-catalog.json`)

The KicksDB discovery pipeline uses a JSON catalog to define what to search for. The catalog has **sections** with explicit `wc_category` and `discovery` mode.

**Two discovery modes:**
- `"brand"` — Sections with real brands (Sneakers, Abbigliamento). Iterates `brands[].queries[]`. Brand enrichment applies.
- `"query"` — Sections with direct search queries (Accessori). Iterates `items[].queries[]`. No brand enrichment.

Each section specifies `wc_category` (config key: `sneakers`, `clothing`, `accessories`) which directly determines the WC category — no normalization hacks needed.

```json
{
  "sections": [
    {
      "name": "Sneakers",
      "slug": "sneakers",
      "wc_category": "sneakers",
      "discovery": "brand",
      "product_types": ["sneakers"],
      "brands": [
        { "name": "Nike", "per_label": 30, "queries": ["Nike Dunk", "Nike Air Force 1"] }
      ]
    },
    {
      "name": "Accessori",
      "slug": "accessori",
      "wc_category": "accessories",
      "discovery": "query",
      "product_types": ["streetwear"],
      "items": [
        { "label": "Beanie", "per_label": 15, "product_types": ["streetwear"], "queries": ["beanie"] },
        { "label": "Labubu", "per_label": 15, "product_types": ["collectibles"], "queries": ["labubu"] }
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

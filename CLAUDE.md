# WOO-IMPORTER

## Project Overview

WooCommerce product importer for ResellPiacenza. Architecture: **one master catalog, separate update loops**.

### Primary Pipelines

1. **GS Catalog Sync** (`gs-catalog-sync.sh`) - GS-driven import: GS feed → diff vs WC → KicksDB enrich → upsert (every 2-4 hours)
2. **Catalog Build** (`catalog-build.sh`) - Full catalog refresh: KicksDB discovery + GS ingestion → WC import (daily)
3. **GS Update** (`gs-update.sh`) - Lightweight GS price/stock variation patches (every 30 min)
4. **KicksDB Price Refresh** (`kicksdb-price-refresh.sh`) - Market price updates for non-GS products (daily)

### Secondary Pipelines

4. **Shopify CSV Import** - One-time migration from Shopify exports
5. **Manual Bulk Upload** - CSV/JSON file import for ad-hoc products

### Legacy Pipelines (Deprecated)

6. ~~Unified Sync~~ → replaced by Catalog Build
7. ~~GS Sync~~ → replaced by GS Update
8. ~~KicksDB Sync~~ → replaced by Catalog Build

All pipelines produce WooCommerce products (variable or simple) with Italian SEO metadata, size variations (Taglia), brand attributes (Marca), and gallery images.

**Product categories:** Sneakers, Abbigliamento, Accessori — determined by `wc_category` in catalog.json. Hierarchical sub-categories (e.g. Abbigliamento > T-Shirt, Accessori > Beanie) are auto-created from catalog structure.

**Catalog modes:** Two modes for Step 1 of the Catalog Build pipeline:
- **Curated** (default when `data/catalog.json` exists) — Explicit product SKUs fetched from KicksDB. Full control over which products appear and in what order.
- **Discovery** (fallback, or `--discovery` flag) — Query-based auto-discovery from KicksDB via `data/brand-catalog.json`. Useful for populating "New Releases" or exploring available products.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  CATALOG BUILD  (daily)                                      │
│                                                               │
│  Curated mode (default):                                      │
│  catalog.json ──→ catalog-fetch ──→ assortment                │
│                                                               │
│  Discovery mode (--discovery):                                │
│  brand-catalog.json ──→ kicksdb-discover ──→ assortment       │
│                                                               │
│  → gs-ingest → taxonomies → media                             │
│  → catalog-transform → sync-wc                               │
│  GS API ──→ KicksDB lookup (enrich) ──→ merged assortment     │
│  FeedMerger (variation-level merge) → WcProductBuilder → WC   │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│  GS CATALOG SYNC  (every 2-4h)                              │
│                                                               │
│  GS API ──→ diff vs WC ──→ new? KicksDB enrich ──→ create   │
│                          ──→ changed? patch variations        │
│                          ──→ gone? leave alone                │
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
| `GsCatalogSync` | `src/Import/GsCatalogSync.php` | GS-driven pipeline: fetch → diff → enrich → upsert |
| `GoldenSneakersAdapter` | `src/Import/GoldenSneakersAdapter.php` | Normalizes Golden Sneakers API response |
| `KicksDbAdapter` | `src/Import/KicksDbAdapter.php` | Normalizes KicksDB API response |
| `KicksDb\Client` | `src/KicksDb/Client.php` | KicksDB v3 API client (search, prices, webhooks) |
| `catalog-fetch` | `bin/catalog-fetch` | Fetches explicit SKUs from KicksDB (curated catalog mode) |
| `PriceCalculator` | `src/Pricing/PriceCalculator.php` | Tiered margin + floor price + rounding |

### Catalog Provenance & Tagging

Every WC product carries metadata tracing it back to its origin in the catalog:

| Meta key | Value | Set by |
|----------|-------|--------|
| `_catalog_section` | Section slug (sneakers, abbigliamento, accessori) | `catalog-transform` |
| `_catalog_discovery` | Discovery mode (brand, query, gs_enriched, gs_only) | `catalog-transform` |
| `_catalog_wc_category` | Config key (sneakers, clothing, accessories) | `catalog-transform` |
| `_catalog_brand` | Brand name from catalog (brand-mode only) | `catalog-transform` |
| `_catalog_subcategory` | Query label / item label | `catalog-transform` |
| `_gs_catalog` | `"1"` if product is in Golden Sneakers feed | `catalog-transform` |

**Reverse lookup:** `gs-ingest` generates `data/catalog-index.json` mapping sections → brands/items → SKUs for quick provenance queries.

## File Structure

```
woo-importer/
├── bin/                          # CLI scripts
│   ├── catalog-fetch             # Fetch explicit SKUs from KicksDB (curated catalog)
│   ├── catalog-transform         # Merged assortment → WC REST format
│   ├── gs-ingest                 # GS SKUs → KicksDB enrich → merged assortment
│   ├── gs-variation-update       # Lightweight GS → WC variation patcher
│   ├── kicksdb-discover          # KicksDB query-based product discovery
│   ├── prepare-media             # Upload/validate images
│   ├── prepare-taxonomies        # Create categories/attributes/brands
│   ├── pricing-reconcile         # Update stale prices from KicksDB
│   ├── sync-wc                   # Delta sync → WC import
│   ├── import-wc                 # Generic WC JSON import
│   ├── import-dir                # Shopify CSV directory import
│   ├── bulk-upload               # Manual CSV/JSON import
│   ├── gs-catalog-sync           # GS-driven import pipeline (recommended)
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
├── gs-catalog-sync.sh            # GS-driven import pipeline (NEW, recommended)
├── catalog-build.sh              # Full catalog refresh
├── gs-update.sh                  # Lightweight GS price/stock patcher (NEW)
├── kicksdb-price-refresh.sh      # KicksDB market price updates (NEW)
├── unified-sync.sh               # (legacy) Unified pipeline
├── gs-sync.sh                    # (legacy) GS-only cron pipeline
├── kicksdb-sync.sh               # (legacy) KicksDB-only cron pipeline
├── data/
│   ├── catalog.json              # Curated product catalog (sections + explicit SKUs)
│   ├── brand-catalog.json        # Discovery catalog (sections + query-based discovery)
│   ├── kicksdb-assortment.json   # KicksDB discovery output (generated)
│   ├── merged-assortment.json    # KicksDB + GS merged catalog (generated)
│   ├── catalog-index.json        # Reverse lookup: section → brand/item → SKUs (generated)
│   ├── gs-tracked-skus.json      # GS SKU → variation snapshot (generated)
│   ├── gs-queue.json             # New GS SKUs pending catalog-build (generated)
│   ├── feed-wc-latest.json       # Latest WC feed (generated)
│   ├── feed-wc.json              # Baseline WC feed for delta (generated)
│   ├── taxonomy-map.json         # Category/subcategory/brand IDs + keywords (generated)
│   └── image-map.json            # Media IDs + gallery IDs (generated)
├── docs/                         # Technical docs and model references
├── samples/                      # Sample API responses and test data
├── .env.example                  # Environment template
├── config.php                    # Configuration loader
├── crontab.txt                   # Cron schedule reference
└── composer.json                 # PHP dependencies
```

## Import Pipelines

### 0. GS Catalog Sync (Recommended — Every 2-4 hours)

**Entry point:** `./gs-catalog-sync.sh`

**Pipeline:** `GS feed → diff vs WC → KicksDB enrich (new only) → upsert`

Simplest pipeline. GS feed is the source of truth — no catalog.json, no discovery queries. Compares GS feed against current WC inventory:
- **New products:** Enriched from KicksDB (SKU lookup, title fallback) for rich metadata + gallery. Fallback to GS-only data. Created via `WcProductBuilder` → `WooCommerceImporter`.
- **Changed products:** Variation-level price/stock patches via WC REST API batch.
- **Removed from GS:** Left alone in WC (GS removals are temporary).

Subcategories for clothing use keyword matching from `config.php` (no external catalog files needed).

```bash
./gs-catalog-sync.sh                         # Full sync
./gs-catalog-sync.sh --dry-run --verbose     # Preview
./gs-catalog-sync.sh --limit=10              # Test with 10 products
./gs-catalog-sync.sh --skip-kicksdb          # GS-only data (faster)
./gs-catalog-sync.sh --env=environment/clientA.env  # Multi-customer
```

### 1. Catalog Build (Daily)

**Entry point:** `./catalog-build.sh`

**Pipeline:** `catalog-fetch|kicksdb-discover → gs-ingest → prepare-taxonomies → prepare-media → catalog-transform → sync-wc`

KicksDB is the product catalog master (rich metadata, images, gallery). GS provides real pricing/stock overlay — when a product exists in both, GS prices and stock replace KicksDB synthetic data at the variation level. New GS SKUs are enriched via KicksDB lookup; if KicksDB miss, they're imported with GS-only data.

**Two catalog modes (auto-detected):**
- **Curated** (when `data/catalog.json` exists): Fetches explicit SKUs via `bin/catalog-fetch`. Products ordered exactly as listed in catalog file. `menu_order` preserves catalog position.
- **Discovery** (fallback or `--discovery` flag): Query-based auto-discovery via `bin/kicksdb-discover`. Products ordered by release date. Useful for exploring KicksDB or populating new releases.

```bash
./catalog-build.sh                     # Full build (auto-detects curated/discovery)
./catalog-build.sh --dry-run --verbose # Preview
./catalog-build.sh --discovery         # Force query-based discovery mode
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

## Curated Catalog (`data/catalog.json`)

The curated catalog defines exactly which products to import by SKU. It is the **recommended** approach — you control which products appear in your store and in what order. Products are fetched from KicksDB by SKU and ordered by their position in the file.

**Structure rules:**
- Sneakers: `brands[].subcategories[].products[]` — subcategories are product lines (Nike Dunk, Jordan 4)
- Clothing: `brands[].products[]` — subcategories use keyword matching (T-Shirt, Felpe, etc.)
- Accessories: `items[].products[]` — subcategories use keyword matching (Beanie, Cappelli, etc.)
- **Order matters:** `menu_order` is assigned from array position. First product = 0, second = 1, etc.

```json
{
  "sections": [
    {
      "name": "Sneakers",
      "slug": "sneakers",
      "wc_category": "sneakers",
      "brands": [
        {
          "name": "Nike",
          "subcategories": [
            {
              "name": "Nike Dunk",
              "products": ["DD1391-100", "DD1503-101", "FQ6965-700"]
            }
          ]
        }
      ]
    },
    {
      "name": "Abbigliamento",
      "slug": "abbigliamento",
      "wc_category": "clothing",
      "subcategories": [
        {"name": "T-Shirt", "keywords": ["t-shirt", "tee"]},
        {"name": "Felpe", "keywords": ["hoodie", "sweatshirt", "crewneck"]}
      ],
      "brands": [
        {
          "name": "Supreme",
          "products": ["SUP-SKU-1", "SUP-SKU-2"]
        }
      ]
    },
    {
      "name": "Accessori",
      "slug": "accessori",
      "wc_category": "accessories",
      "subcategories": [
        {"name": "Beanie", "keywords": ["beanie"]},
        {"name": "Cappelli", "keywords": ["cap", "hat"]},
        {"name": "Labubu", "keywords": ["labubu"]}
      ],
      "items": [
        {
          "name": "Labubu",
          "product_types": ["collectibles"],
          "products": ["LABUBU-SKU-1"]
        }
      ]
    }
  ]
}
```

**Adding products:** Find SKUs on [explorer.kicks.dev](https://explorer.kicks.dev/) and add them to the appropriate section/subcategory array.

## Brand Catalog (`data/brand-catalog.json`) — Discovery Mode

The KicksDB discovery pipeline uses a JSON catalog to define what to search for. The catalog has **sections** with explicit `wc_category` and `discovery` mode. Used when `data/catalog.json` doesn't exist or when `--discovery` flag is passed.

**Two discovery modes:**
- `"brand"` — Sections with real brands (Sneakers, Abbigliamento). Iterates `brands[].queries[]`. Brand enrichment applies.
- `"query"` — Sections with direct search queries (Accessori). Iterates `items[].queries[]`. No brand enrichment.

Each section specifies `wc_category` (config key: `sneakers`, `clothing`, `accessories`) which directly determines the WC category — no normalization hacks needed.

### Sub-categories

Sections can define how products are auto-categorized into WC sub-categories:

- **Explicit `subcategories`** — keyword-based matching (Abbigliamento, Accessori). Products are classified by matching query/title against English keywords. Sub-categories are garment/item types, NOT brand names.
- **Brand-mode without `subcategories`** — queries become sub-categories directly (Sneakers > Nike Dunk, Sneakers > Jordan 1). These represent product lines, not brands.

**Brand vs Category separation:** Brand names (Nike, Supreme, Sp5der) live exclusively in `pa_marca`. They are never used as product categories. For sections with keyword subcategories, brand enrichment only adds the parent brand — query labels like "Sp5der Pants" are NOT created as sub-brands.

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
      "name": "Abbigliamento",
      "slug": "abbigliamento",
      "wc_category": "clothing",
      "discovery": "brand",
      "product_types": ["streetwear"],
      "subcategories": [
        {"name": "T-Shirt", "keywords": ["t-shirt", "tee"]},
        {"name": "Felpe", "keywords": ["hoodie", "sweatshirt", "crewneck"]},
        {"name": "Giacche", "keywords": ["jacket", "coat", "puffer", "windbreaker"]},
        {"name": "Pantaloni", "keywords": ["pants", "jogger", "trousers", "cargo"]}
      ],
      "brands": [
        { "name": "Supreme", "per_label": 20, "queries": ["Supreme T-Shirt", "Supreme Hoodie"] }
      ]
    },
    {
      "name": "Accessori",
      "slug": "accessori",
      "wc_category": "accessories",
      "discovery": "query",
      "product_types": ["streetwear"],
      "subcategories": [
        {"name": "Beanie", "keywords": ["beanie"]},
        {"name": "Cappelli", "keywords": ["cap", "hat"]},
        {"name": "Labubu", "keywords": ["labubu"]}
      ],
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

**Images:** Uses pre-uploaded media IDs from `image-map.json` only. Sideloading is disabled (causes WC batch timeouts). Products without pre-uploaded images are created imageless; run `prepare-media` + follow-up sync to attach them. Gallery 360: first frame is skipped (duplicate of primary image angle), remaining ~6 frames are evenly sampled.

## Localization (Italian)

All user-facing content is in Italian:
- Category names: Sneakers, Abbigliamento, Accessori
- Attribute names: Taglia, Marca, Genere, Modello, Data di Rilascio
- Image SEO: alt text, captions, descriptions
- Product descriptions: short + long with Italian templates

## GS Sale Pricing (Optional)

When enabled, GS-sourced prices display with a "Sale" badge in WooCommerce:
- `regular_price` = GS price × (1 + markup%) — shown crossed out
- `sale_price` = GS price — shown as the active price

Configured via environment variables (disabled by default):
```env
GS_SALE_PRICING_ENABLED=false
GS_SALE_PRICING_MARKUP=15
```

Applied consistently in both `catalog-transform` (full builds) and `gs-variation-update` (30-min patches).

## Supported Import Sources

| Source | Entry point | Format | Mode |
|--------|-------------|--------|------|
| GS Catalog Sync | `bin/gs-catalog-sync` | JSON API | GS-driven: fetch → diff vs WC → KicksDB enrich → upsert |
| KicksDB REST API (curated) | `bin/catalog-fetch` | JSON API | Fetch explicit SKUs from `catalog.json` |
| KicksDB REST API (discovery) | `bin/kicksdb-discover` | JSON API | Query-based discovery via `brand-catalog.json` |
| Golden Sneakers REST API | `bin/gs-ingest` | JSON API | Automatic feed fetch + KicksDB enrichment |
| GS live price/stock | `bin/gs-variation-update` | JSON API | Lightweight WC variation patches (30-min cron) |
| KicksDB price API | `bin/pricing-reconcile` | JSON API | Market price refresh for non-GS products |
| Shopify CSV export | `bin/import-dir` | CSV | One-time migration from Shopify product exports |
| Manual CSV | `bin/bulk-upload` | CSV | Generic format: `sku, name, brand, category, image_url, size, price, stock` |
| Manual JSON | `bin/bulk-upload` | JSON | Array of product objects, same fields as CSV |
| WC JSON feed | `bin/import-wc` | JSON | Pre-built WC REST format, direct import |

Any source that produces a normalized product structure (SKU, name, brand, sizes, prices, images) can plug into the pipeline. The work is writing an adapter class; everything downstream (taxonomies, media, WC import, delta sync) is reusable.

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
GS_SALE_PRICING_ENABLED=false           # Show GS prices with Sale badge
GS_SALE_PRICING_MARKUP=15               # Markup % for regular_price
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
- **No image sideloading:** `WcProductBuilder` only uses pre-uploaded media IDs. Products without `image-map.json` entries are created imageless. Run `prepare-media` first, then sync.
- **image-map.json:** Delete this file to force re-upload of all images. Stale entries are auto-filtered by `prepare-media --validate`.
- **Dry-run + images:** Never persist fake media IDs during `--dry-run`. Gallery upload returns `[]` in dry-run mode.
- **KicksDB API filters:** Non-sneaker queries (clothing, accessories, collectibles) require the `filters=product_type=...` parameter. This is set automatically from `product_types` in the brand catalog.
- **Sub-category keyword matching:** Keyword search is case-insensitive and matches against both the query label and product title. Order matters — first matching keyword wins. Keep keywords specific to avoid false positives. Keywords must be in English (KicksDB uses English queries/titles).
- **Brand vs category leakage:** For sections with keyword `subcategories`, brand enrichment only adds the parent brand. Query labels (e.g. "Sp5der Pants") are NOT created as sub-brands in `pa_marca`.
- **taxonomy-map.json format:** Subcategory entries can be either plain int IDs (direct slug match, Sneakers only) or `{"id": int, "keywords": [...]}` objects (keyword match, Abbigliamento + Accessori). `catalog-transform` auto-detects the format. Prefer keyword matching — direct slug matching is fragile when query strings don't exactly match item labels.

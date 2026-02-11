# WOO-IMPORTER

Multi-source sneaker product importer for WooCommerce. Imports from Golden Sneakers API, KicksDB (StockX prices), and CSV/JSON bulk files into a WooCommerce store with Italian localization, SEO-optimized images, and dynamic pricing.

## Architecture

```
Golden Sneakers API ─┐
KicksDB API ─────────┼─→ FeedAdapter ─→ WcProductBuilder ─→ WooCommerceImporter ─→ WC Store
Bulk CSV/JSON ───────┘    (normalize)    (WC format)         (REST API push)
```

**Core pipeline**: Source-specific adapters normalize products into a common format. `WcProductBuilder` transforms them into WC REST API payloads. `WooCommerceImporter` pushes via batch endpoints, creates variations, re-saves to trigger sync, and flushes caches.

## Data Flows

### Golden Sneakers (full pipeline via `gs-sync.sh`)
```
prepare-taxonomies --from-gs    → data/taxonomy-map.json
prepare-media --from-gs         → image-map.json
gs-transform                    → data/feed-wc-latest.json
sync-wc --feed=data/feed-wc-latest.json → WooCommerce
```

### KicksDB (single command)
```
import-kicksdb --skus=FILE      → WcProductBuilder → WooCommerceImporter → WooCommerce
```

### Bulk Upload (self-contained)
```
bulk-upload --file=products.json → taxonomy + media + WC import (all-in-one)
```

### Price Reconciliation (cron)
```
pricing-reconcile               → SkuRegistry sync → KicksDB prices → PriceUpdater → WC variations
```

### Webhooks (real-time)
```
KicksDB price change → public/kicksdb-webhook.php → PriceUpdater → WC variations
WC product created   → public/wc-product-listener.php → SkuRegistry → KicksDB webhook
```

## File Structure

```
woo-importer/
├── config.php                          # Central config (loads .env)
├── composer.json                       # Dependencies + script aliases
├── gs-sync.sh                          # GS full pipeline (crontab entrypoint)
├── nuke-products.php                   # Bulk product deletion utility
│
├── bin/                                # CLI entry points
│   ├── gs-transform                    # GS feed → WC format
│   ├── import-kicksdb                  # KicksDB product importer
│   ├── import-wc                       # WC REST API direct importer
│   ├── bulk-upload                     # CSV/JSON bulk importer
│   ├── prepare-media                   # Image upload to WP media library
│   ├── prepare-taxonomies              # Category/attribute/brand setup
│   ├── pricing-reconcile               # KicksDB price sync (polling)
│   └── sync-wc                         # Delta sync orchestrator
│
├── src/
│   ├── Support/
│   │   ├── Config.php                  # Config singleton (dot-notation access)
│   │   ├── LoggerFactory.php           # Monolog wrapper
│   │   ├── Template.php                # Template string parser ({placeholders})
│   │   └── StockEstimator.php          # Price-based virtual stock assignment
│   │
│   ├── Import/
│   │   ├── FeedAdapter.php             # Interface: normalized product contract
│   │   ├── GoldenSneakersAdapter.php   # GS API → normalized format
│   │   ├── KicksDbAdapter.php          # KicksDB API → normalized format
│   │   ├── WcProductBuilder.php        # Normalized → WC REST API format
│   │   ├── WooCommerceImporter.php     # WC REST API batch push
│   │   ├── BulkUploader.php            # CSV/JSON → full pipeline
│   │   └── DeltaSync.php              # Feed diff detection (incremental sync)
│   │
│   ├── Media/
│   │   └── MediaUploader.php           # Image download + WP upload + SEO metadata
│   │
│   ├── Pricing/
│   │   ├── PriceCalculator.php         # Tiered margin calculation
│   │   ├── PriceUpdater.php            # WC variation price sync
│   │   └── SkuRegistry.php             # WC ↔ KicksDB product tracking
│   │
│   ├── Taxonomy/
│   │   └── TaxonomyManager.php         # Category/attribute/brand management
│   │
│   └── KicksDb/
│       ├── Client.php                  # KicksDB v3 API client
│       └── VariantParser.php           # EU size + market price extraction
│
├── public/                             # Webhook endpoints (deploy to web server)
│   ├── kicksdb-webhook.php             # KicksDB price change receiver
│   └── wc-product-listener.php         # WC product event → SKU registry
│
├── samples/                            # Test data and example files
├── docs/                               # Model documentation and examples
├── data/                               # Generated at runtime (gitignored)
└── logs/                               # Log files (gitignored)
```

## Key Patterns

### Normalized Product Format (FeedAdapter contract)
Every adapter yields products in this shape:
```php
[
    'sku' => 'DD1873-102',
    'name' => 'Nike Dunk Low ...',
    'brand' => 'Nike',
    'category_type' => 'sneakers',  // or 'clothing'
    'image_url' => 'https://...',
    'gallery_urls' => [...],
    'variations' => [
        ['size_eu' => '36', 'price' => 99.00, 'stock_quantity' => 50, 'stock_status' => 'instock']
    ]
]
```

### Taxonomy Map (`data/taxonomy-map.json`)
Pre-resolved WooCommerce IDs for categories, attributes, and brands. Created by `prepare-taxonomies`, consumed by `WcProductBuilder`. Avoids slug-based lookups during import.

### Image Map (`image-map.json`)
Pre-uploaded WordPress media IDs keyed by SKU. Created by `prepare-media`, consumed by `WcProductBuilder`. Maps `{sku: {media_id, url, gallery_ids}}`.

### PUT Re-save After Variations
WC REST API does NOT call `WC_Product_Variable::sync()` when creating variations via batch endpoint. The importer does a PUT re-save (`['status' => 'publish']`) on each parent product after variations are created, which triggers the sync hooks that update `_price` meta, stock status, and attribute lookups.

### Cache Flush After Import
Runs `clear_transients`, `regenerate_product_lookup_tables`, and `regenerate_product_attributes_lookup_table` via `system_status/tools` REST API endpoint.

### Tiered Pricing (PriceCalculator)
Market price → selling price with configurable tiers:
- 0-100: +35%, 100-200: +28%, 200-500: +22%, 500+: +18%
- Floor price (default 59), rounding (whole/half/none)

### Virtual Stock (StockEstimator)
Neither source has real stock data. Stock assigned inversely by price:
- < 140 → 80 units, < 240 → 50, < 340 → 30, 340+ → 13

### Italian Localization
Template strings in `config.php` for image metadata, product descriptions, category/attribute names. Parsed by `Support\Template::parse()`.

## CLI Commands

```bash
# Golden Sneakers full pipeline
./gs-sync.sh                                    # Full sync
./gs-sync.sh --dry-run                          # Preview
./gs-sync.sh --skip-media --force-full          # Skip images, force full import

# Individual GS steps
bin/prepare-taxonomies --from-gs                # Setup categories/attributes/brands
bin/prepare-media --from-gs                     # Upload images to WP
bin/gs-transform                                # Transform feed to WC format
bin/sync-wc --feed=data/feed-wc-latest.json     # Delta sync + import

# KicksDB
bin/import-kicksdb --skus=samples/kicksdb-skus.csv
bin/import-kicksdb --skus=samples/kicksdb-skus.json --dry-run --limit=5

# Bulk upload
bin/bulk-upload --file=products.json
bin/bulk-upload --file=products.csv --dry-run

# Direct WC import (pre-formatted JSON)
bin/import-wc --feed=data/feed-wc-latest.json
cat products.json | bin/import-wc

# Price reconciliation
bin/pricing-reconcile                           # Full reconciliation
bin/pricing-reconcile --sku=DD1873-102          # Single product
bin/pricing-reconcile --dry-run --limit=10      # Preview first 10

# Utilities
php nuke-products.php --confirm                 # Delete all products
php nuke-products.php --confirm --keep=SKU1,SKU2
```

## Cron Setup

```bash
# GS sync every 30 minutes
*/30 * * * * cd /path/to/woo-importer && ./gs-sync.sh >> logs/cron.log 2>&1

# Price reconciliation every 6 hours
0 */6 * * * cd /path/to/woo-importer && bin/pricing-reconcile >> logs/cron.log 2>&1
```

## Environment Variables

See `.env.example` for the full list. Key groups:

- `GS_*` — Golden Sneakers API (base_url, bearer_token, markup/VAT)
- `WC_*` — WooCommerce REST API (url, consumer_key, consumer_secret)
- `WP_*` — WordPress media upload (username, app_password)
- `STORE_*` — Store identity (name, locale)
- `ATTRIBUTE_*` — WC attributes (size/brand name and slug)
- `KICKSDB_*` — KicksDB API (api_key, base_url, market, webhook)
- `PRICING_*` — Pricing engine (flat_margin, tiers, floor_price, rounding)

## Dependencies

```json
{
    "php": ">=7.4",
    "automattic/woocommerce": "^3.0",
    "monolog/monolog": "^2.0",
    "vlucas/phpdotenv": "^5.0"
}
```

PSR-4 autoloading: `ResellPiacenza\` → `src/`

## Known Constraints

- WC REST API batch limit: 100 items per request
- KicksDB rate limit: 200ms between requests (enforced in adapters)
- Image sideloading is slow; always prefer `prepare-media` pre-upload
- Brands taxonomy requires a WooCommerce brands plugin (e.g. Perfect Brands)
- DeltaSync requires a pre-built `--feed` file; does not fetch from API directly
- Variable products: never set `stock_status` on the parent — let WC derive it from variations via the PUT re-save

## Code Style

- PSR-12
- PHPDoc on public methods
- Logs in English, user-facing text in Italian
- All template parsing through `Support\Template::parse()`
- All stock estimation through `Support\StockEstimator::forPrice()`
- All KicksDB variant parsing through `KicksDb\VariantParser`

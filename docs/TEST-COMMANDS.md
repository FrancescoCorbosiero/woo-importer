# Test Commands Reference

All commands run from the project root (`~/resellpiacenza/woo-importer`).

---

## Setup

```bash
# Install PHP dependencies
composer install

# Configure credentials
cp .env.example .env
nano .env   # Fill in WC_URL, WC_CONSUMER_KEY, WC_CONSUMER_SECRET, WP_USERNAME, WP_APP_PASSWORD, GS_BEARER_TOKEN
```

---

## 1. Golden Sneakers (GS) Pipeline

### Full Pipeline (what cron runs)

```bash
# Dry run - preview everything, no API writes
./gs-sync.sh --dry-run --verbose

# Real sync
./gs-sync.sh --verbose

# Skip image uploads (faster testing)
./gs-sync.sh --skip-media --verbose

# Force full import (ignore delta, re-import all)
./gs-sync.sh --force-full --verbose
```

### Individual Steps

```bash
# Step 1: Create/resolve categories, attributes, brands
php bin/prepare-taxonomies --from-gs --dry-run --verbose
php bin/prepare-taxonomies --from-gs --verbose

# Step 2: Upload images to WordPress media library
php bin/prepare-media --from-gs --dry-run --verbose
php bin/prepare-media --from-gs --verbose

# Step 2.5: Validate image-map.json (remove stale references)
php bin/prepare-media --validate --verbose

# Step 3: Transform GS feed → WooCommerce format
php bin/gs-transform --verbose

# Step 4: Delta sync + import to WooCommerce
php bin/sync-wc --feed=data/feed-wc-latest.json --dry-run --verbose
php bin/sync-wc --feed=data/feed-wc-latest.json --verbose
php bin/sync-wc --feed=data/feed-wc-latest.json --force-full --verbose
```

---

## 2. KicksDB Pipeline

### Full Pipeline (what cron runs)

```bash
# Dry run
./kicksdb-sync.sh --dry-run --verbose

# Real sync
./kicksdb-sync.sh --verbose

# Skip discovery (use cached assortment from previous run)
./kicksdb-sync.sh --skip-discover --verbose

# Limit to 100 products (faster testing)
./kicksdb-sync.sh --limit=100 --verbose

# Skip media + limit (fastest test)
./kicksdb-sync.sh --skip-media --skip-discover --limit=50 --verbose
```

### Individual Steps

```bash
# Step 1: Discover popular products from KicksDB API
php bin/kicksdb-discover --verbose
php bin/kicksdb-discover --limit=50 --verbose
php bin/kicksdb-discover --dry-run --verbose

# Step 2: Taxonomies (from KicksDB feed)
php bin/prepare-taxonomies --from-kicksdb --dry-run --verbose
php bin/prepare-taxonomies --from-kicksdb --verbose

# Step 3: Media (from KicksDB feed)
php bin/prepare-media --from-kicksdb --dry-run --verbose
php bin/prepare-media --from-kicksdb --verbose

# Step 4: Transform KicksDB → WC format
php bin/kicksdb-transform --verbose
php bin/kicksdb-transform --limit=50 --verbose

# Step 5: Delta sync + import
php bin/sync-wc --feed=data/feed-kicksdb-wc-latest.json --baseline=data/feed-kicksdb-wc.json --diff=data/diff-kicksdb-wc.json --dry-run --verbose
php bin/sync-wc --feed=data/feed-kicksdb-wc-latest.json --baseline=data/feed-kicksdb-wc.json --diff=data/diff-kicksdb-wc.json --verbose
```

### Price Reconciliation

```bash
# Preview price updates
php bin/pricing-reconcile --dry-run --verbose

# Real price update
php bin/pricing-reconcile --verbose

# Limit to N products
php bin/pricing-reconcile --limit=20 --verbose
```

---

## 3. Shopify CSV Import

```bash
# Preview: parse CSVs and show what would be imported
bin/import-dir --dir=import/ --dry-run --verbose

# Import first 3 products (quick test)
bin/import-dir --dir=import/ --limit=3 --verbose

# Import first 5 without uploading images
bin/import-dir --dir=import/ --limit=5 --skip-media --verbose

# Full import (skips products already in WooCommerce)
bin/import-dir --dir=import/ --verbose

# Import from a different directory
bin/import-dir --dir=/path/to/shopify-exports/ --verbose
```

**Notes:**
- `--skip-existing` is always on: products with matching SKUs in WooCommerce are skipped
- Parsed intermediate feed is saved to `data/shopify-import-feed.json`
- Delete `image-map.json` to force re-upload of all images

---

## 4. Manual Bulk Upload

```bash
# From CSV file
bin/bulk-upload --file=data/products.csv --dry-run --verbose
bin/bulk-upload --file=data/products.csv --verbose

# From JSON file
bin/bulk-upload --file=data/products.json --dry-run --verbose
bin/bulk-upload --file=data/products.json --verbose

# With limit
bin/bulk-upload --file=data/products.csv --limit=5 --verbose

# Skip media uploads
bin/bulk-upload --file=data/products.csv --skip-media --verbose
```

**CSV format:** `sku, name, brand, category, image_url, size, price, stock`

See `docs/bulk-upload-example.csv` for a sample file.

---

## 5. Standalone Import (from pre-built JSON)

```bash
# Import a pre-built WC-format JSON feed directly
php bin/import-wc --file=data/feed-wc-latest.json --dry-run --verbose
php bin/import-wc --file=data/feed-wc-latest.json --verbose
php bin/import-wc --file=data/feed-wc-latest.json --limit=10 --verbose
```

---

## 6. Utility Commands

```bash
# Delete ALL WooCommerce products (use with extreme caution!)
php bin/nuke-products --dry-run --verbose
php bin/nuke-products --verbose
# Requires confirmation prompt

# Validate image-map.json (remove references to deleted media)
php bin/prepare-media --validate --verbose
```

---

## Verification Checklist

After any import, verify in WooCommerce:

1. **Products > All Products** - new products appear with correct names
2. **Product > Edit** - check:
   - Short description (Italian text)
   - Long description (Italian HTML)
   - Attributes: "Marca" and "Taglia" are set
   - Variations: sizes show with correct prices and stock
   - Images: featured image + gallery images
3. **Frontend > Product page** - size dropdown is visible and functional
4. **Media Library** - images have Italian alt text, captions, descriptions
5. **Products > Attributes** - "Taglia" and "Marca" exist as global attributes

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Sizes not showing on frontend | Ensure parent has `manage_stock: false` + `stock_status: instock` |
| "Attribute slug already in use" | Normal - resolved automatically via flexible matching |
| Images all skipped | Delete `image-map.json` and re-run |
| Duplicate products created | Use `--skip-existing` (default on for `import-dir`) |
| `Undefined array key "media_id"` | Delete `image-map.json` (stale dry-run data) |

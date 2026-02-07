# Test Samples & Flow Commands

All samples use the `TEST-` SKU prefix for easy identification and cleanup.

---

## Sample Files

| File | Used by | Description |
|------|---------|-------------|
| `brands.json` | `bin/prepare-taxonomies` | Simple brand list |
| `image-urls.json` | `bin/prepare-media` | SKU → image URL mapping |
| `gs-api-response.json` | `bin/gs-transform` | Simulated GS API response |
| `bulk-upload-products.json` | `bin/bulk-upload` | JSON format (6 products) |
| `bulk-upload-products.csv` | `bin/bulk-upload` | CSV format (4 products) |
| `wc-formatted-feed.json` | `bin/import-wc`, `bin/sync-wc` | Pre-resolved WC REST format |
| `kicksdb-skus.json` | `bin/import-kicksdb` | JSON array of 5 real SKUs |
| `kicksdb-skus.txt` | `bin/import-kicksdb` | Plain text, one SKU per line |
| `kicksdb-skus.csv` | `bin/import-kicksdb` | CSV with SKU + name + notes |
| `kicksdb-api-response.json` | Reference | Mock KicksDB StockX product response |
| `kicksdb-variants-response.json` | Reference | Mock KicksDB variants (11 sizes + prices) |
| `kicksdb-webhook-payload.json` | Reference | Mock price_change webhook payload |
| `wc-feed-from-kicksdb.json` | `bin/import-wc` | Expected WC output from KicksDB transform |

---

## Flow 1: Bulk Upload (JSON) — Full One-Shot

The simplest path. One command does everything: creates brands, uploads
images, transforms to WC format, imports.

```bash
# Dry run first — preview what would happen
php bin/bulk-upload --file=samples/bulk-upload-products.json --dry-run --verbose

# Real import
php bin/bulk-upload --file=samples/bulk-upload-products.json --verbose

# Skip image upload (WooCommerce will sideload from URLs)
php bin/bulk-upload --file=samples/bulk-upload-products.json --skip-media --verbose
```

---

## Flow 2: Bulk Upload (CSV)

Same as Flow 1 but from CSV. Rows with the same SKU are grouped into
one product with multiple size variations.

```bash
# Dry run
php bin/bulk-upload --file=samples/bulk-upload-products.csv --dry-run --verbose

# Real import
php bin/bulk-upload --file=samples/bulk-upload-products.csv --verbose
```

---

## Flow 3: Step-by-Step (Generic Pipeline)

Run each tool individually. Useful when you want control over each step.

```bash
# Step 1: Ensure taxonomies (categories + attributes + brands from file)
php bin/prepare-taxonomies --brands-file=samples/brands.json --dry-run --verbose
php bin/prepare-taxonomies --brands-file=samples/brands.json --verbose

# Step 2: Upload images from URL list
php bin/prepare-media --urls-file=samples/image-urls.json --dry-run --verbose
php bin/prepare-media --urls-file=samples/image-urls.json --verbose

# Step 3: Check the generated maps
cat data/taxonomy-map.json
cat image-map.json

# Step 4: Direct WC import (feed already in WC format)
php bin/import-wc --feed=samples/wc-formatted-feed.json --dry-run
php bin/import-wc --feed=samples/wc-formatted-feed.json

# Step 5: Delta sync from a local file
php bin/sync-wc --feed=samples/wc-formatted-feed.json --dry-run --verbose
php bin/sync-wc --feed=samples/wc-formatted-feed.json --verbose
```

---

## Flow 4: Step-by-Step (GS Pipeline — requires live API)

Full Golden Sneakers pipeline. Requires valid `.env` credentials.

```bash
# Step 1: Discover brands from GS feed and create in WC
php bin/prepare-taxonomies --from-gs --dry-run --verbose
php bin/prepare-taxonomies --from-gs --verbose

# Step 2: Upload images from GS feed
php bin/prepare-media --from-gs --dry-run --verbose
php bin/prepare-media --from-gs --limit=5 --verbose    # first 5 only

# Step 3: Transform GS feed → WC format
php bin/gs-transform --verbose

# Step 4: Delta sync + import
php bin/sync-wc --feed=data/feed-wc-latest.json --dry-run --verbose
php bin/sync-wc --feed=data/feed-wc-latest.json --verbose

# Or run the full pipeline in one shot:
./gs-sync.sh --dry-run --verbose
./gs-sync.sh --verbose
./gs-sync.sh --skip-media --verbose    # stock/price updates only
```

---

## Flow 5: Manual Brand Management

```bash
# From a comma-separated list
php bin/prepare-taxonomies --brands=Nike,Adidas,Puma,Jordan --dry-run --verbose
php bin/prepare-taxonomies --brands=Nike,Adidas,Puma,Jordan --verbose

# From a JSON file
php bin/prepare-taxonomies --brands-file=samples/brands.json --verbose

# Just categories + attributes (no brands)
php bin/prepare-taxonomies --verbose
```

---

## Flow 6: Direct WC Import (Pre-formatted Data)

When you already have data in WooCommerce REST format with resolved IDs.

```bash
# Preview
php bin/import-wc --feed=samples/wc-formatted-feed.json --dry-run

# Import first 1 product
php bin/import-wc --feed=samples/wc-formatted-feed.json --limit=1

# Full import
php bin/import-wc --feed=samples/wc-formatted-feed.json
```

---

## Flow 7: KicksDB Import (from SKU list)

Import sneakers by SKU from KicksDB (StockX market data).
Requires `KICKSDB_API_KEY` in `.env`.

```bash
# From CLI argument (dry run)
php bin/import-kicksdb --skus=DD1873-102,CW2288-111 --dry-run --verbose

# From JSON file
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.json --dry-run

# From plain text file
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.txt --dry-run

# From CSV file
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.csv --dry-run

# Real import (limit to first 2)
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.json --limit=2

# Full import
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.json

# Transform only (generate WC feed without importing)
php bin/import-kicksdb --skus-file=samples/kicksdb-skus.json --transform-only --save-feed
# Then import manually:
php bin/import-wc --feed=data/feed-kicksdb.json

# Pipe a single SKU
echo "DD1873-102" | php bin/import-kicksdb --dry-run
```

---

## Flow 8: KicksDB Price Reconciliation

Update existing WC product prices from current StockX market data.

```bash
# Full reconciliation (all tracked SKUs)
php bin/pricing-reconcile --dry-run --verbose
php bin/pricing-reconcile --verbose

# Single product
php bin/pricing-reconcile --sku=DD1873-102 --verbose

# First 5 products only
php bin/pricing-reconcile --limit=5 --dry-run --verbose
```

---

## Flow 9: Pre-formatted KicksDB WC Feed

Import the sample WC feed that simulates KicksDB transform output.
Useful for testing `bin/import-wc` without a live KicksDB API key.

```bash
# Preview
php bin/import-wc --feed=samples/wc-feed-from-kicksdb.json --dry-run

# Import
php bin/import-wc --feed=samples/wc-feed-from-kicksdb.json
```

---

## Cleanup

Delete all test products after testing:

```bash
# Preview what would be deleted (looks for TEST- prefix)
# Use nuke-products.php or WooCommerce admin

# Nuclear option (deletes ALL products — be careful)
php nuke-products.php --dry-run
```

---

## What Each Sample Contains

### bulk-upload-products.json / .csv (6 products)
| SKU | Name | Brand | Category | Sizes |
|-----|------|-------|----------|-------|
| TEST-AF1-001 | Nike Air Force 1 '07 Low White | Nike | sneakers | 38-44 (8 sizes) |
| TEST-DUNK-002 | Nike Dunk Low Retro Panda | Nike | sneakers | 36-40 (6 sizes) |
| TEST-SAMBA-003 | Adidas Samba OG White Black | Adidas | sneakers | 40-44 (6 sizes) |
| TEST-NB550-004 | New Balance 550 White Green | New Balance | sneakers | 40-43 (4 sizes) |
| TEST-HOODIE-005 | Nike Tech Fleece Hoodie Black | Nike | clothing | S-XL (4 sizes) |
| TEST-JOGGER-006 | Adidas 3-Stripes Jogger Navy | Adidas | clothing | S-XXL (5 sizes) |

### gs-api-response.json (5 products)
Same as above minus TEST-JOGGER-006, in Golden Sneakers API format with
`presented_price`, `offer_price`, `available_quantity`, `barcode`, etc.

### kicksdb-skus.json / .txt / .csv
5 real sneaker SKUs for testing KicksDB import:

| SKU | Name |
|-----|------|
| DD1873-102 | Nike Dunk Low Panda (W) |
| CW2288-111 | Nike Air Force 1 '07 White |
| CT8527-100 | Air Jordan 1 Retro High OG Chicago |
| DV0833-105 | Nike Dunk Low Grey Fog |
| FQ8249-100 | Nike Air Max 1 '86 Big Bubble |

### kicksdb-api-response.json
Simulated `GET /v3/stockx/products/DD1873-102` response with all fields:
id, sku, slug, title, brand, model, gender, colorway, description, image, etc.

### kicksdb-variants-response.json
Simulated `GET /v3/stockx/products/{id}/variants?market=US` response with
11 women's sizes (EU 35.5-42), each with: lowest_ask, highest_bid, last_sale,
barcode, size_us, size_eu, size_uk.

### kicksdb-webhook-payload.json
Simulated `price_change` webhook payload with 3 variant price updates.

### wc-feed-from-kicksdb.json
Expected output of KicksDbTransformer for DD1873-102. Shows tiered
margin pricing applied to StockX asks:
- $82 (size 35.5) → +35% tier → €111
- $105 (size 40) → +28% tier → €135
- $125 (size 42) → +28% tier → €160

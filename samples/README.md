# Test Samples & Flow Commands

All samples use the `TEST-` SKU prefix for easy identification and cleanup.

---

## Sample Files

| File | Used by | Description |
|------|---------|-------------|
| `brands.json` | `prepare-taxonomies.php` | Simple brand list |
| `image-urls.json` | `prepare-media.php` | SKU → image URL mapping |
| `gs-api-response.json` | `gs-transform.php` | Simulated GS API response |
| `bulk-upload-products.json` | `bulk-upload.php` | JSON format (6 products) |
| `bulk-upload-products.csv` | `bulk-upload.php` | CSV format (4 products) |
| `wc-formatted-feed.json` | `import-wc.php`, `sync-wc.php` | Pre-resolved WC REST format |

---

## Flow 1: Bulk Upload (JSON) — Full One-Shot

The simplest path. One command does everything: creates brands, uploads
images, transforms to WC format, imports.

```bash
# Dry run first — preview what would happen
php bulk-upload.php --file=samples/bulk-upload-products.json --dry-run --verbose

# Real import
php bulk-upload.php --file=samples/bulk-upload-products.json --verbose

# Skip image upload (WooCommerce will sideload from URLs)
php bulk-upload.php --file=samples/bulk-upload-products.json --skip-media --verbose
```

---

## Flow 2: Bulk Upload (CSV)

Same as Flow 1 but from CSV. Rows with the same SKU are grouped into
one product with multiple size variations.

```bash
# Dry run
php bulk-upload.php --file=samples/bulk-upload-products.csv --dry-run --verbose

# Real import
php bulk-upload.php --file=samples/bulk-upload-products.csv --verbose
```

---

## Flow 3: Step-by-Step (Generic Pipeline)

Run each tool individually. Useful when you want control over each step.

```bash
# Step 1: Ensure taxonomies (categories + attributes + brands from file)
php prepare-taxonomies.php --brands-file=samples/brands.json --dry-run --verbose
php prepare-taxonomies.php --brands-file=samples/brands.json --verbose

# Step 2: Upload images from URL list
php prepare-media.php --urls-file=samples/image-urls.json --dry-run --verbose
php prepare-media.php --urls-file=samples/image-urls.json --verbose

# Step 3: Check the generated maps
cat data/taxonomy-map.json
cat image-map.json

# Step 4: Direct WC import (feed already in WC format)
php import-wc.php --feed=samples/wc-formatted-feed.json --dry-run
php import-wc.php --feed=samples/wc-formatted-feed.json

# Step 5: Delta sync from a local file
php sync-wc.php --feed=samples/wc-formatted-feed.json --dry-run --verbose
php sync-wc.php --feed=samples/wc-formatted-feed.json --verbose
```

---

## Flow 4: Step-by-Step (GS Pipeline — requires live API)

Full Golden Sneakers pipeline. Requires valid `.env` credentials.

```bash
# Step 1: Discover brands from GS feed and create in WC
php prepare-taxonomies.php --from-gs --dry-run --verbose
php prepare-taxonomies.php --from-gs --verbose

# Step 2: Upload images from GS feed
php prepare-media.php --from-gs --dry-run --verbose
php prepare-media.php --from-gs --limit=5 --verbose    # first 5 only

# Step 3: Transform GS feed → WC format
php gs-transform.php --verbose

# Step 4: Delta sync + import
php sync-wc.php --feed=data/feed-wc-latest.json --dry-run --verbose
php sync-wc.php --feed=data/feed-wc-latest.json --verbose

# Or run the full pipeline in one shot:
./gs-sync.sh --dry-run --verbose
./gs-sync.sh --verbose
./gs-sync.sh --skip-media --verbose    # stock/price updates only
```

---

## Flow 5: Manual Brand Management

```bash
# From a comma-separated list
php prepare-taxonomies.php --brands=Nike,Adidas,Puma,Jordan --dry-run --verbose
php prepare-taxonomies.php --brands=Nike,Adidas,Puma,Jordan --verbose

# From a JSON file
php prepare-taxonomies.php --brands-file=samples/brands.json --verbose

# Just categories + attributes (no brands)
php prepare-taxonomies.php --verbose
```

---

## Flow 6: Direct WC Import (Pre-formatted Data)

When you already have data in WooCommerce REST format with resolved IDs.

```bash
# Preview
php import-wc.php --feed=samples/wc-formatted-feed.json --dry-run

# Import first 1 product
php import-wc.php --feed=samples/wc-formatted-feed.json --limit=1

# Full import
php import-wc.php --feed=samples/wc-formatted-feed.json
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

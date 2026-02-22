# Unified Sync Pipeline — Implementation Plan

## Goal

Create a new sync flow where KicksDB is the product catalog master and GS is a pricing/stock overlay. Merge happens at the **variation level**: each size independently gets GS data when available, KicksDB data otherwise.

## New Files (3 files only)

### 1. `src/Import/FeedMerger.php`

Merges KicksDB + GS normalized products into a single feed.

**Input:** Two iterables of normalized products (same format `FeedAdapter` produces).

**Merge rules:**

- **Products in both KicksDB and GS** (matched by SKU):
  - Product-level: all metadata from KicksDB (name, brand, model, gender, colorway, release_date, retail_price, description, gallery, category_type)
  - `_source` meta set to `"unified:kicksdb+gs"` for traceability
  - Variation-level merge by `size_eu`:
    - Size in both → GS `price` + GS `stock_quantity` + GS `stock_status`, merge meta_data from both (GS meta takes precedence on conflicts)
    - Size only in KicksDB → keep KicksDB price/stock as-is
    - Size only in GS → add it with GS price/stock (real inventory, don't lose it)

- **Products only in KicksDB**: pass through unchanged (KicksDB pricing)

- **Products only in GS**: pass through unchanged (real supplier inventory that KicksDB doesn't know about — use GS's minimal metadata)

**Stats tracked:**
- `products_merged` (both sources)
- `products_kicksdb_only`
- `products_gs_only`
- `variations_gs_overlay` (GS price/stock applied)
- `variations_kicksdb_only`
- `variations_gs_only` (size only in GS, added)

### 2. `bin/unified-transform`

CLI script that orchestrates the merge + WC build.

**Steps:**
1. Load KicksDB assortment (`data/kicksdb-assortment.json`) → extract SKU list
2. Create `KicksDbAdapter` with those SKUs → produces normalized KicksDB products
3. Create `GoldenSneakersAdapter` → produces normalized GS products
4. Pass both to `FeedMerger::merge()` → yields merged normalized products
5. Pass merged feed to `WcProductBuilder::buildAll()` → WC format
6. Enrich with catalog brand hierarchy (same logic as `kicksdb-transform`)
7. Sort by release_date for menu_order (same logic as `kicksdb-transform`)
8. Write `data/feed-unified-wc-latest.json`
9. Write `data/unified-sku-cache.json` (for any future dedup needs)

**CLI options:** `--limit=N`, `--verbose`, `--help`, `--skip-gs` (KicksDB only, useful for debugging)

### 3. `unified-sync.sh`

Shell wrapper mirroring `kicksdb-sync.sh` structure.

**Pipeline:**
1. `bin/kicksdb-discover` (unchanged) — discover products from KicksDB
2. `bin/prepare-taxonomies --from-catalog` (unchanged) — create brands + categories
3. `bin/prepare-media --from-kicksdb` (unchanged) — upload images
4. **`bin/unified-transform`** (NEW) — merge KicksDB + GS → WC format
5. `bin/sync-wc` (unchanged) — delta sync + import to WooCommerce

**CLI options:** same as `kicksdb-sync.sh` (`--dry-run`, `--verbose`, `--skip-media`, `--skip-discover`, `--force-full`, `--limit=N`, `--env=FILE`)

Feed/baseline/diff files:
- `data/feed-unified-wc-latest.json` (current run)
- `data/feed-unified-wc.json` (baseline, managed by sync-wc)
- `data/diff-unified-wc.json` (delta)

## Existing Files — NOT Modified

- `gs-sync.sh` — untouched
- `kicksdb-sync.sh` — untouched
- `bin/gs-transform` — untouched
- `bin/kicksdb-transform` — untouched
- `bin/kicksdb-discover` — untouched (reused as step 1)
- `bin/sync-wc` — untouched (reused as step 5, just different file paths)
- `src/Import/GoldenSneakersAdapter.php` — untouched (reused)
- `src/Import/KicksDbAdapter.php` — untouched (reused)
- `src/Import/WcProductBuilder.php` — untouched (reused)
- `src/Import/WooCommerceImporter.php` — untouched

## Variation-Level Merge Example

```
KicksDB product "DV0833-101" (15 sizes, synthetic stock, market prices):
  EU 38 → €189, stock 3 (synthetic)
  EU 39 → €175, stock 5 (synthetic)
  EU 40 → €165, stock 5 (synthetic)
  ...
  EU 46 → €210, stock 2 (synthetic)

GS product "DV0833-101" (8 sizes, real inventory, supplier prices):
  EU 39 → €145, stock 2 (real)
  EU 40 → €140, stock 4 (real)
  EU 41 → €138, stock 1 (real)
  EU 42 → €142, stock 3 (real)
  EU 43 → €148, stock 2 (real)
  EU 44 → €155, stock 1 (real)
  EU 45 → €162, stock 1 (real)
  EU 47 → €180, stock 1 (real GS-only size, not in KicksDB)

Merged result (16 sizes):
  EU 38 → €189, stock 3  ← KicksDB only
  EU 39 → €145, stock 2  ← GS overlay (real price + real stock)
  EU 40 → €140, stock 4  ← GS overlay
  EU 41 → €138, stock 1  ← GS overlay
  EU 42 → €142, stock 3  ← GS overlay
  EU 43 → €148, stock 2  ← GS overlay
  EU 44 → €155, stock 1  ← GS overlay
  EU 45 → €162, stock 1  ← GS overlay
  EU 46 → €210, stock 2  ← KicksDB only
  EU 47 → €180, stock 1  ← GS-only size (added)
  ...
```

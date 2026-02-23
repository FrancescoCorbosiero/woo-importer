# Quick Reference

## Import Pipelines

| Pipeline | Entry Point | Schedule | Source |
|----------|-------------|----------|--------|
| Unified Sync | `./unified-sync.sh` | Recommended | KicksDB + GS merged |
| Golden Sneakers | `./gs-sync.sh` | Every 30 min | GS API |
| KicksDB | `./kicksdb-sync.sh` | Every 6 hours | KicksDB API |
| Shopify CSV | `bin/import-dir` | Manual (one-time) | CSV files |
| Bulk Upload | `bin/bulk-upload` | Manual | CSV/JSON files |

## Quick Test Commands

```bash
# Unified sync - dry run
./unified-sync.sh --dry-run --verbose

# GS - dry run
./gs-sync.sh --dry-run --verbose

# KicksDB - dry run with limit
./kicksdb-sync.sh --dry-run --limit=50 --verbose

# Shopify CSV - preview
bin/import-dir --dir=import/ --dry-run --verbose

# Bulk upload - dry run
bin/bulk-upload --file=data/products.csv --dry-run --verbose

# Price reconciliation - preview
php bin/pricing-reconcile --dry-run --verbose
```

## Common Flags

| Flag | Description |
|------|-------------|
| `--dry-run` | Preview without making API calls |
| `--verbose` / `-v` | Detailed output |
| `--skip-media` | Skip image uploads |
| `--skip-discover` | Use cached KicksDB assortment |
| `--limit=N` | Process only first N products |
| `--force-full` | Ignore delta, re-import everything |
| `--env=FILE` | Multi-customer mode (use alternate .env) |

## Verification After Import

1. Products appear in WooCommerce with correct names
2. Size dropdown visible on product frontend page
3. Images have Italian SEO metadata (alt, caption, description)
4. Attributes: Taglia, Marca, Colorway, Genere, Modello are set
5. Categories: Sneakers, Abbigliamento, or Accessori assigned correctly

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Sizes not showing | Parent product needs `manage_stock: false` + `stock_status: instock` |
| Images all skipped | Delete `image-map.json` and re-run with `--from-gs` or `--from-kicksdb` |
| No images on product | Check `prepare-media` ran; fallback to URL sideloading is automatic |
| Accessori empty | Verify `brand-catalog.json` has correct `product_types` per brand |
| Duplicate products | `import-dir` skips existing by default; use `bin/nuke-products` to clean up |

See `docs/TEST-COMMANDS.md` for comprehensive command reference.

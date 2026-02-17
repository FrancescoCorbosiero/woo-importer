# Quick Reference

## Import Pipelines

| Pipeline | Entry Point | Schedule | Source |
|----------|-------------|----------|--------|
| Golden Sneakers | `./gs-sync.sh` | Every 30 min | GS API |
| KicksDB | `./kicksdb-sync.sh` | Every 6 hours | KicksDB API |
| Shopify CSV | `bin/import-dir` | Manual (one-time) | CSV files |
| Bulk Upload | `bin/bulk-upload` | Manual | CSV/JSON files |

## Quick Test Commands

```bash
# GS - dry run
./gs-sync.sh --dry-run --verbose

# KicksDB - dry run with limit
./kicksdb-sync.sh --dry-run --limit=50 --verbose

# Shopify CSV - preview
bin/import-dir --dir=import/ --dry-run --verbose

# Shopify CSV - import first 5
bin/import-dir --dir=import/ --limit=5 --verbose

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
| `--limit=N` | Process only first N products |
| `--force-full` | Ignore delta, re-import everything |

## Verification After Import

1. Products appear in WooCommerce with correct names
2. Size dropdown visible on product frontend page
3. Images have Italian SEO metadata (alt, caption, description)
4. Attributes: "Taglia" (size) and "Marca" (brand) are set

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Sizes not showing | Parent product needs `manage_stock: false` + `stock_status: instock` |
| Images all skipped | Delete `image-map.json` and re-run |
| Duplicate products | `import-dir` skips existing by default; use `bin/nuke-products` to clean up |

See `docs/TEST-COMMANDS.md` for comprehensive command reference.

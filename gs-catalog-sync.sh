#!/bin/bash
# =============================================================================
# GS Catalog Sync — GS-driven product import pipeline
# =============================================================================
#
# Simple pipeline: GS feed → diff vs WC → enrich from KicksDB → upsert to WC.
# GS feed is the source of truth. No catalog.json, no discovery queries.
#
# Designed to run as a cron job (e.g. every 2-4 hours).
#
# Usage:
#   ./gs-catalog-sync.sh                         # Full sync
#   ./gs-catalog-sync.sh --dry-run               # Preview changes
#   ./gs-catalog-sync.sh --verbose               # Detailed output
#   ./gs-catalog-sync.sh --limit=10              # Test with 10 products
#   ./gs-catalog-sync.sh --skip-kicksdb          # Skip KicksDB enrichment
#   ./gs-catalog-sync.sh --env=environment/clientA.env  # Multi-customer
#
# Crontab (every 4 hours):
#   0 */4 * * * cd /path/to/woo-importer && ./gs-catalog-sync.sh >> logs/gs-catalog-sync.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

mkdir -p logs

echo ""
echo "========================================"
echo "  GS Catalog Sync"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

php bin/gs-catalog-sync "$@"

echo ""
echo "========================================"
echo "  GS Catalog Sync complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

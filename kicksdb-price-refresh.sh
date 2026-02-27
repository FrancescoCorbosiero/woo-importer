#!/bin/bash
# =============================================================================
# KicksDB Price Refresh — Market price updates for non-GS products
# =============================================================================
#
# Updates WooCommerce variation prices from KicksDB market data for products
# NOT covered by the GS feed. GS-tracked SKUs are handled by gs-update.sh.
#
# Wraps the existing pricing-reconcile script with catalog awareness.
#
# Usage:
#   ./kicksdb-price-refresh.sh                     # Full refresh
#   ./kicksdb-price-refresh.sh --dry-run           # Preview changes
#   ./kicksdb-price-refresh.sh --verbose           # Detailed output
#   ./kicksdb-price-refresh.sh --limit=50          # Process first 50 SKUs
#   ./kicksdb-price-refresh.sh --env=environment/clientA.env  # Multi-customer mode
#
# Crontab (daily at 6am, after catalog-build):
#   0 6 * * * cd /path/to/woo-importer && ./kicksdb-price-refresh.sh >> logs/kicksdb-price.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
LIMIT_ARG=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)     DRY_RUN="--dry-run" ;;
        --verbose|-v)  VERBOSE="--verbose" ;;
        --limit=*)     LIMIT_ARG="$arg" ;;
        --env=*)       ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./kicksdb-price-refresh.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run       Preview all changes without writing"
            echo "  --verbose, -v   Detailed output"
            echo "  --limit=N       Process first N SKUs"
            echo "  --env=FILE      Use alternate .env file"
            echo "  --help, -h      Show this help"
            exit 0
            ;;
    esac
done

mkdir -p logs

echo ""
echo "========================================"
echo "  KicksDB Price Refresh"
echo "  Market prices for non-GS products"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# Run pricing reconciliation
# The pricing-reconcile script already handles:
# - SKU registry sync (WC inventory ↔ KicksDB tracking)
# - Fetching current market prices from KicksDB
# - Applying margin via PriceCalculator
# - Patching WC variation prices
php bin/pricing-reconcile $DRY_RUN $VERBOSE $LIMIT_ARG $ENV_ARG

echo ""
echo "========================================"
echo "  Price refresh complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

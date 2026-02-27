#!/bin/bash
# =============================================================================
# GS Update Pipeline — Lightweight price/stock patcher
# =============================================================================
#
# Fetches the GS feed and patches WooCommerce variation prices and stock
# for known GS-tracked SKUs. Fast and lightweight — no product rebuilds.
#
# New GS SKUs are queued for the next catalog-build.
#
# Designed to run every 30 minutes as a cron job.
#
# Usage:
#   ./gs-update.sh                     # Full update
#   ./gs-update.sh --dry-run           # Preview changes
#   ./gs-update.sh --verbose           # Detailed output
#   ./gs-update.sh --env=environment/clientA.env  # Multi-customer mode
#
# Crontab (every 30 min):
#   */30 * * * * cd /path/to/woo-importer && ./gs-update.sh >> logs/gs-update.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)     DRY_RUN="--dry-run" ;;
        --verbose|-v)  VERBOSE="--verbose" ;;
        --env=*)       ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./gs-update.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run       Preview all changes without writing"
            echo "  --verbose, -v   Detailed output"
            echo "  --env=FILE      Use alternate .env file"
            echo "  --help, -h      Show this help"
            exit 0
            ;;
    esac
done

mkdir -p logs

echo ""
echo "========================================"
echo "  GS Variation Update"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

php bin/gs-variation-update $DRY_RUN $VERBOSE $ENV_ARG

echo ""
echo "========================================"
echo "  GS update complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

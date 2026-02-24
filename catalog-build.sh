#!/bin/bash
# =============================================================================
# Catalog Build Pipeline — Full catalog refresh
# =============================================================================
#
# One master catalog, built from KicksDB discovery + GS feed ingestion.
# Runs daily (or on-demand). Produces the complete WC product feed.
#
# Pipeline:
#   1. kicksdb-discover    → KicksDB assortment (brand-catalog.json driven)
#   2. gs-ingest           → Merge GS SKUs (KicksDB enrich + fallback)
#   3. prepare-taxonomies  → WC categories, attributes, brands
#   4. prepare-media       → Upload images to WP media library
#   5. catalog-transform   → Merged assortment → WC REST format
#   6. sync-wc             → Delta sync to WooCommerce
#
# Usage:
#   ./catalog-build.sh                     # Full build
#   ./catalog-build.sh --dry-run           # Preview everything
#   ./catalog-build.sh --skip-media        # Skip image upload
#   ./catalog-build.sh --skip-discover     # Use cached KicksDB assortment
#   ./catalog-build.sh --skip-gs           # Skip GS ingestion
#   ./catalog-build.sh --force-full        # Force full import (ignore delta)
#   ./catalog-build.sh --verbose           # Detailed output
#   ./catalog-build.sh --limit=100         # Limit assortment size
#   ./catalog-build.sh --env=environment/clientA.env  # Multi-customer mode
#
# Crontab (daily at 2am):
#   0 2 * * * cd /path/to/woo-importer && ./catalog-build.sh >> logs/catalog-build.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
SKIP_MEDIA=""
SKIP_DISCOVER=""
SKIP_GS=""
FORCE_FULL=""
LIMIT_ARG=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)         DRY_RUN="--dry-run" ;;
        --verbose|-v)      VERBOSE="--verbose" ;;
        --skip-media)      SKIP_MEDIA="1" ;;
        --skip-discover)   SKIP_DISCOVER="1" ;;
        --skip-gs)         SKIP_GS="1" ;;
        --force-full)      FORCE_FULL="--force-full" ;;
        --limit=*)         LIMIT_ARG="$arg" ;;
        --env=*)           ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./catalog-build.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run           Preview all changes without writing"
            echo "  --skip-media        Skip image upload step"
            echo "  --skip-discover     Skip KicksDB discovery (use cached assortment)"
            echo "  --skip-gs           Skip GS feed ingestion"
            echo "  --force-full        Force full import (ignore delta)"
            echo "  --limit=N           Limit assortment size"
            echo "  --verbose, -v       Detailed output from all steps"
            echo "  --env=FILE          Use alternate .env file"
            echo "  --help, -h          Show this help"
            exit 0
            ;;
    esac
done

mkdir -p logs

echo ""
echo "========================================"
echo "  Catalog Build Pipeline"
echo "  KicksDB + GS → WooCommerce"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# Step 1: Discover products from KicksDB (brand-catalog.json driven)
if [ -z "$SKIP_DISCOVER" ]; then
    echo "[Step 1/6] Discovering KicksDB assortment..."
    php bin/kicksdb-discover --skip-dedup $VERBOSE $LIMIT_ARG $DRY_RUN $ENV_ARG
    echo ""
else
    echo "[Step 1/6] Skipping discovery (--skip-discover)"
    echo ""
fi

# Step 2: Ingest GS feed (enrich via KicksDB, merge into assortment)
if [ -z "$SKIP_GS" ]; then
    echo "[Step 2/6] Ingesting GS feed..."
    php bin/gs-ingest $VERBOSE $DRY_RUN $ENV_ARG
    echo ""
else
    echo "[Step 2/6] Skipping GS ingestion (--skip-gs)"
    echo ""
fi

# Step 3: Ensure taxonomies exist
echo "[Step 3/6] Preparing taxonomies..."
php bin/prepare-taxonomies --from-catalog $DRY_RUN $VERBOSE $ENV_ARG
echo ""

# Step 4: Upload images to WordPress media library
if [ -z "$SKIP_MEDIA" ]; then
    echo "[Step 4/6] Preparing media..."
    php bin/prepare-media --from-kicksdb $DRY_RUN $VERBOSE $ENV_ARG
    echo ""

    echo "[Step 4.5] Validating image map..."
    php bin/prepare-media --validate $VERBOSE $ENV_ARG
    echo ""
else
    echo "[Step 4/6] Skipping media (--skip-media)"
    echo ""
fi

# Step 5: Transform merged assortment → WC REST format
echo "[Step 5/6] Transforming catalog..."
SKIP_GS_FLAG=""
if [ -n "$SKIP_GS" ]; then
    SKIP_GS_FLAG="--skip-gs"
fi
php bin/catalog-transform $VERBOSE $LIMIT_ARG $SKIP_GS_FLAG $ENV_ARG
echo ""

# Step 6: Delta sync to WooCommerce
echo "[Step 6/6] Running delta sync..."
php bin/sync-wc --feed-latest $DRY_RUN $VERBOSE $FORCE_FULL $ENV_ARG

echo ""
echo "========================================"
echo "  Catalog build complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

#!/bin/bash
# =============================================================================
# Catalog Build Pipeline — Thin wrapper over php bin/catalog-build
# =============================================================================
#
# Full catalog refresh: KicksDB discovery + GS feed → WooCommerce.
# All logic is in bin/catalog-build (PHP). This script forwards CLI flags.
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

mkdir -p logs

php bin/catalog-build "$@"

<?php

namespace ResellPiacenza\Import;

/**
 * Feed Merger - Combines KicksDB + GoldenSneakers normalized products
 *
 * KicksDB is the product catalog master (rich metadata, gallery, descriptions).
 * GoldenSneakers is a pricing/stock overlay (real supplier inventory).
 *
 * Merge happens at the VARIATION level: each size independently gets
 * GS data when available, KicksDB data otherwise.
 *
 * Rules:
 * - Product in both: KicksDB metadata + variation-level merge by size_eu
 *   - Size in both: GS price + GS stock (real inventory wins)
 *   - Size only in KicksDB: KicksDB price + stock (synthetic)
 *   - Size only in GS: added with GS price + stock (real inventory)
 * - Product only in KicksDB: pass through unchanged
 * - Product only in GS: pass through unchanged (minimal metadata)
 *
 * @package ResellPiacenza\Import
 */
class FeedMerger
{
    private $logger;

    private array $stats = [
        'products_merged' => 0,
        'products_kicksdb_only' => 0,
        'products_gs_only' => 0,
        'variations_gs_overlay' => 0,
        'variations_kicksdb_only' => 0,
        'variations_gs_only' => 0,
    ];

    public function __construct($logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Merge KicksDB and GS normalized feeds
     *
     * @param iterable $kicksdbProducts Normalized products from KicksDbAdapter
     * @param iterable $gsProducts Normalized products from GoldenSneakersAdapter
     * @return array Merged normalized products
     */
    public function merge(iterable $kicksdbProducts, iterable $gsProducts): array
    {
        // Index GS products by SKU for O(1) lookup
        $gsIndex = [];
        foreach ($gsProducts as $gsProduct) {
            $sku = $gsProduct['sku'] ?? null;
            if ($sku) {
                $gsIndex[$sku] = $gsProduct;
            }
        }

        $this->log('info', "GS index: " . count($gsIndex) . " products");

        $merged = [];

        // Process KicksDB products (catalog master)
        foreach ($kicksdbProducts as $kdbProduct) {
            $sku = $kdbProduct['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            if (isset($gsIndex[$sku])) {
                // Product exists in both sources → merge at variation level
                $merged[] = $this->mergeProduct($kdbProduct, $gsIndex[$sku]);
                $this->stats['products_merged']++;
                unset($gsIndex[$sku]); // Remove from GS index so we know what's GS-only
            } else {
                // KicksDB only
                $merged[] = $kdbProduct;
                $this->stats['products_kicksdb_only']++;
            }
        }

        // Remaining GS products (not in KicksDB catalog)
        foreach ($gsIndex as $gsProduct) {
            $merged[] = $gsProduct;
            $this->stats['products_gs_only']++;
        }

        $this->log('info', sprintf(
            "Merge complete: %d merged, %d KicksDB-only, %d GS-only → %d total",
            $this->stats['products_merged'],
            $this->stats['products_kicksdb_only'],
            $this->stats['products_gs_only'],
            count($merged)
        ));

        return $merged;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    // =========================================================================
    // Product-Level Merge
    // =========================================================================

    /**
     * Merge a single product that exists in both KicksDB and GS
     *
     * KicksDB provides the product shell (metadata, images, descriptions).
     * GS overlays real pricing and stock at the variation level.
     */
    private function mergeProduct(array $kdb, array $gs): array
    {
        $sku = $kdb['sku'];

        // Start from KicksDB product (rich metadata)
        $merged = $kdb;

        // Update _source meta to reflect unified origin
        $merged['meta_data'] = $this->mergeSourceMeta($kdb['meta_data'] ?? []);

        // Merge variations by size_eu
        $merged['variations'] = $this->mergeVariations(
            $kdb['variations'] ?? [],
            $gs['variations'] ?? [],
            $sku
        );

        return $merged;
    }

    /**
     * Replace _source meta with unified source tag
     */
    private function mergeSourceMeta(array $meta): array
    {
        $filtered = array_filter($meta, fn($m) => ($m['key'] ?? '') !== '_source');
        $filtered[] = ['key' => '_source', 'value' => 'unified:kicksdb+gs'];
        return array_values($filtered);
    }

    // =========================================================================
    // Variation-Level Merge
    // =========================================================================

    /**
     * Merge variations from KicksDB and GS by size_eu
     *
     * For each size:
     * - In both: GS price + stock, merged metadata (GS takes precedence)
     * - KicksDB only: keep as-is (synthetic pricing)
     * - GS only: add with GS data (real inventory not in KicksDB catalog)
     */
    private function mergeVariations(array $kdbVars, array $gsVars, string $sku): array
    {
        // Index GS variations by size_eu
        $gsVarIndex = [];
        foreach ($gsVars as $gsVar) {
            $size = $gsVar['size_eu'] ?? null;
            if ($size !== null) {
                $gsVarIndex[(string) $size] = $gsVar;
            }
        }

        $merged = [];

        // Process KicksDB variations
        foreach ($kdbVars as $kdbVar) {
            $size = (string) ($kdbVar['size_eu'] ?? '');
            if ($size === '') {
                continue;
            }

            if (isset($gsVarIndex[$size])) {
                // Size exists in both → GS overlay
                $merged[] = $this->mergeVariation($kdbVar, $gsVarIndex[$size], $sku, $size);
                $this->stats['variations_gs_overlay']++;
                unset($gsVarIndex[$size]);
            } else {
                // KicksDB only
                $merged[] = $kdbVar;
                $this->stats['variations_kicksdb_only']++;
            }
        }

        // Remaining GS-only sizes
        foreach ($gsVarIndex as $gsVar) {
            $size = $gsVar['size_eu'] ?? '?';
            $this->log('debug', "  {$sku} EU {$size}: GS-only size added (real inventory)");
            $merged[] = $gsVar;
            $this->stats['variations_gs_only']++;
        }

        return $merged;
    }

    /**
     * Merge a single variation that exists in both sources
     *
     * GS provides: price, stock_quantity, stock_status (real supplier data)
     * KicksDB provides: rich per-variation metadata
     * Merged metadata: both, with GS keys taking precedence on conflicts
     */
    private function mergeVariation(array $kdbVar, array $gsVar, string $sku, string $size): array
    {
        $this->log('debug', "  {$sku} EU {$size}: GS overlay (price {$gsVar['price']}, stock {$gsVar['stock_quantity']})");

        return [
            'size_eu' => $kdbVar['size_eu'],
            'price' => $gsVar['price'],                 // GS real price
            'stock_quantity' => $gsVar['stock_quantity'], // GS real stock
            'stock_status' => $gsVar['stock_status'],     // GS real status
            'meta_data' => $this->mergeVariationMeta(
                $kdbVar['meta_data'] ?? [],
                $gsVar['meta_data'] ?? []
            ),
        ];
    }

    /**
     * Merge variation metadata arrays
     *
     * KicksDB meta is the base, GS meta overwrites on key conflicts.
     * Adds _gs_overlay flag for traceability.
     */
    private function mergeVariationMeta(array $kdbMeta, array $gsMeta): array
    {
        // Build a key → entry map from KicksDB (base)
        $map = [];
        foreach ($kdbMeta as $entry) {
            $map[$entry['key']] = $entry;
        }

        // Overlay GS entries (overwrite on conflict)
        foreach ($gsMeta as $entry) {
            $map[$entry['key']] = $entry;
        }

        // Add overlay flag
        $map['_gs_overlay'] = ['key' => '_gs_overlay', 'value' => '1'];

        return array_values($map);
    }

    // =========================================================================
    // Logging
    // =========================================================================

    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

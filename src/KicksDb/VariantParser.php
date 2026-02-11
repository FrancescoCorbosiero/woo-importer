<?php

namespace ResellPiacenza\KicksDb;

/**
 * KicksDB variant data extraction helpers.
 *
 * Shared between KicksDbAdapter (import) and PriceUpdater (reconciliation)
 * to parse EU sizes and market prices from KicksDB variant structures.
 *
 * @package ResellPiacenza\KicksDb
 */
class VariantParser
{
    /**
     * Extract EU size from a KicksDB variant.
     *
     * Checks (in order):
     * 1. sizes[] sub-array with type=eu
     * 2. Direct size_eu field
     * 3. Title parsing ("Men's US 10 / EU 44")
     *
     * Does NOT fall back to $variant['size'] (that's US size).
     *
     * @param array $variant KicksDB variant data
     * @return string|null EU size (e.g. "35.5") or null
     */
    public static function extractEuSize(array $variant): ?string
    {
        // Primary: parse from sizes[] sub-array
        $eu_size = self::extractSizeByType($variant, 'eu');
        if ($eu_size !== null) {
            return preg_replace('/^EU\s*/i', '', trim($eu_size));
        }

        // Fallback: direct size_eu field (some response formats)
        if (!empty($variant['size_eu'])) {
            return preg_replace('/^EU\s*/i', '', trim($variant['size_eu']));
        }

        // Fallback: parse from title like "Men's US 10 / EU 44"
        if (!empty($variant['title']) && preg_match('/EU\s+([\d.]+)/i', $variant['title'], $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract the standard market price from a KicksDB variant.
     *
     * Variants can have multiple prices with different types:
     * - "standard": regular shipping price (what we want)
     * - "express_standard": faster shipping, higher price
     * - "express_expedited": fastest, highest price
     *
     * If prices[] exists, filters for "standard" type.
     * Otherwise falls back to lowest_ask / price / amount fields.
     *
     * @param array $variant KicksDB variant data
     * @return float Market price in EUR (0.0 if not found)
     */
    public static function extractStandardPrice(array $variant): float
    {
        if (!empty($variant['prices']) && is_array($variant['prices'])) {
            foreach ($variant['prices'] as $price_entry) {
                if (($price_entry['type'] ?? '') === 'standard') {
                    return (float) ($price_entry['price'] ?? 0);
                }
            }
            // No standard found — take lowest non-zero price
            $prices = array_filter(
                array_column($variant['prices'], 'price'),
                fn($p) => $p > 0
            );
            return !empty($prices) ? (float) min($prices) : 0.0;
        }

        // Direct fields (simulated responses, variants endpoint)
        return (float) ($variant['lowest_ask']
            ?? $variant['price']
            ?? $variant['amount']
            ?? 0);
    }

    /**
     * Extract a specific size type from the sizes[] sub-array.
     *
     * @param array $variant Variant data
     * @param string $type Size type key (eu, us m, us w, uk, cm, kr)
     * @return string|null The size value or null
     */
    public static function extractSizeByType(array $variant, string $type): ?string
    {
        foreach ($variant['sizes'] ?? [] as $size_entry) {
            if (($size_entry['type'] ?? '') === $type) {
                return $size_entry['size'] ?? null;
            }
        }
        return null;
    }
}

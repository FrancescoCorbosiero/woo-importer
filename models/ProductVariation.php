<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * ProductVariation model - represents size/stock variations
 */
class ProductVariation extends BaseModel
{
    protected static string $table = 'product_variations';

    /**
     * Find a variation by SKU
     */
    public function findBySku(string $sku): ?array
    {
        return $this->findBy('sku', $sku);
    }

    /**
     * Find a variation by WooCommerce variation ID
     */
    public function findByWcId(int $wcVariationId): ?array
    {
        $sql = '
            SELECT pv.*, wvm.wc_variation_id, wvm.wc_parent_id
            FROM product_variations pv
            INNER JOIN wc_variation_map wvm ON pv.id = wvm.variation_id
            WHERE wvm.wc_variation_id = ?
            LIMIT 1
        ';
        return $this->db->fetchOne($sql, [$wcVariationId]);
    }

    /**
     * Get all variations for a product
     */
    public function getByProductId(int $productId): array
    {
        $sql = "
            SELECT * FROM product_variations
            WHERE product_id = ? AND status = 'active'
            ORDER BY CAST(size_eu AS DECIMAL(10,1)) ASC
        ";
        return $this->db->fetchAll($sql, [$productId]);
    }

    /**
     * Get variations with WooCommerce mapping
     */
    public function getByProductIdWithMapping(int $productId): array
    {
        $sql = "
            SELECT pv.*, wvm.wc_variation_id, wvm.wc_parent_id
            FROM product_variations pv
            LEFT JOIN wc_variation_map wvm ON pv.id = wvm.variation_id
            WHERE pv.product_id = ? AND pv.status = 'active'
            ORDER BY CAST(pv.size_eu AS DECIMAL(10,1)) ASC
        ";
        return $this->db->fetchAll($sql, [$productId]);
    }

    /**
     * Create or update a variation from feed data
     */
    public function upsertFromFeed(int $productId, string $parentSku, array $sizeData, float $retailPrice): int
    {
        $variationSku = $parentSku . '-' . ($sizeData['size_eu'] ?? $sizeData['size_us'] ?? 'OS');

        $data = [
            'product_id' => $productId,
            'sku' => $variationSku,
            'size_us' => $sizeData['size_us'] ?? null,
            'size_eu' => $sizeData['size_eu'] ?? null,
            'size_uk' => $sizeData['size_uk'] ?? null,
            'offer_price' => $sizeData['offer_price'] ?? 0,
            'retail_price' => $retailPrice,
            'stock_quantity' => $sizeData['available_quantity'] ?? 0,
            'barcode' => $sizeData['barcode'] ?? null,
            'status' => 'active',
        ];

        $existing = $this->findBySku($variationSku);

        if ($existing) {
            $this->update($existing['id'], $data);
            return $existing['id'];
        } else {
            return $this->create($data);
        }
    }

    /**
     * Update stock quantity for a variation
     */
    public function updateStock(int $variationId, int $quantity): int
    {
        return $this->update($variationId, [
            'stock_quantity' => $quantity,
        ]);
    }

    /**
     * Update stock by SKU
     */
    public function updateStockBySku(string $sku, int $quantity): int
    {
        $sql = 'UPDATE product_variations SET stock_quantity = ?, updated_at = NOW() WHERE sku = ?';
        return $this->db->execute($sql, [$quantity, $sku]);
    }

    /**
     * Update price for a variation
     */
    public function updatePrice(int $variationId, float $offerPrice, float $retailPrice): int
    {
        return $this->update($variationId, [
            'offer_price' => $offerPrice,
            'retail_price' => $retailPrice,
        ]);
    }

    /**
     * Get total stock for a product
     */
    public function getTotalStock(int $productId): int
    {
        $sql = "
            SELECT COALESCE(SUM(stock_quantity), 0)
            FROM product_variations
            WHERE product_id = ? AND status = 'active'
        ";
        return (int) $this->db->fetchColumn($sql, [$productId]);
    }

    /**
     * Get in-stock variations for a product
     */
    public function getInStock(int $productId): array
    {
        $sql = "
            SELECT * FROM product_variations
            WHERE product_id = ? AND status = 'active' AND stock_quantity > 0
            ORDER BY CAST(size_eu AS DECIMAL(10,1)) ASC
        ";
        return $this->db->fetchAll($sql, [$productId]);
    }

    /**
     * Mark all variations for a product as out of stock
     */
    public function markOutOfStock(int $productId): int
    {
        $sql = "UPDATE product_variations SET stock_quantity = 0, updated_at = NOW() WHERE product_id = ?";
        return $this->db->execute($sql, [$productId]);
    }

    /**
     * Mark variations as inactive (soft delete)
     */
    public function deactivateForProduct(int $productId): int
    {
        $sql = "UPDATE product_variations SET status = 'inactive', updated_at = NOW() WHERE product_id = ?";
        return $this->db->execute($sql, [$productId]);
    }

    /**
     * Get variations that have changed stock
     */
    public function getStockChanges(int $productId, array $newSizes): array
    {
        $existing = $this->getByProductId($productId);
        $existingBySku = [];
        foreach ($existing as $var) {
            $existingBySku[$var['sku']] = $var;
        }

        $changes = [
            'new' => [],
            'updated' => [],
            'removed' => [],
        ];

        $newSkus = [];
        foreach ($newSizes as $size) {
            $sku = $this->buildVariationSku($size);
            $newSkus[] = $sku;

            if (!isset($existingBySku[$sku])) {
                $changes['new'][] = $size;
            } elseif ($existingBySku[$sku]['stock_quantity'] != ($size['available_quantity'] ?? 0)) {
                $changes['updated'][] = [
                    'size' => $size,
                    'old_stock' => $existingBySku[$sku]['stock_quantity'],
                    'new_stock' => $size['available_quantity'] ?? 0,
                ];
            }
        }

        foreach ($existing as $var) {
            if (!in_array($var['sku'], $newSkus)) {
                $changes['removed'][] = $var;
            }
        }

        return $changes;
    }

    /**
     * Build variation SKU from size data
     */
    private function buildVariationSku(array $size): string
    {
        return $size['size_eu'] ?? $size['size_us'] ?? 'OS';
    }

    /**
     * Bulk update variations for a product from feed
     */
    public function syncFromFeed(int $productId, string $parentSku, array $sizes, callable $priceCalculator): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        // Get existing variations
        $existing = $this->getByProductId($productId);
        $existingBySize = [];
        foreach ($existing as $var) {
            $existingBySize[$var['size_eu'] ?? $var['size_us']] = $var;
        }

        $processedSizes = [];

        foreach ($sizes as $size) {
            $sizeKey = $size['size_eu'] ?? $size['size_us'] ?? 'OS';
            $processedSizes[] = $sizeKey;
            $retailPrice = $priceCalculator($size['offer_price'] ?? 0);

            if (isset($existingBySize[$sizeKey])) {
                // Update existing
                $varId = $existingBySize[$sizeKey]['id'];
                $this->update($varId, [
                    'offer_price' => $size['offer_price'] ?? 0,
                    'retail_price' => $retailPrice,
                    'stock_quantity' => $size['available_quantity'] ?? 0,
                    'barcode' => $size['barcode'] ?? null,
                    'status' => 'active',
                ]);
                $stats['updated']++;
            } else {
                // Create new
                $this->upsertFromFeed($productId, $parentSku, $size, $retailPrice);
                $stats['created']++;
            }
        }

        // Deactivate sizes not in feed
        foreach ($existing as $var) {
            $sizeKey = $var['size_eu'] ?? $var['size_us'];
            if (!in_array($sizeKey, $processedSizes)) {
                $this->update($var['id'], ['status' => 'inactive', 'stock_quantity' => 0]);
                $stats['deactivated']++;
            }
        }

        return $stats;
    }

    /**
     * Get all variations indexed by SKU
     */
    public function getAllIndexedBySku(): array
    {
        $sql = "SELECT * FROM product_variations WHERE status = 'active'";
        $variations = $this->db->fetchAll($sql);
        $indexed = [];
        foreach ($variations as $var) {
            $indexed[$var['sku']] = $var;
        }
        return $indexed;
    }
}

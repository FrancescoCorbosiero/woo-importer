<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * WcProductMap model - mapping between local products and WooCommerce
 */
class WcProductMap extends BaseModel
{
    protected static string $table = 'wc_product_map';

    /**
     * Find mapping by local product ID
     */
    public function findByProductId(int $productId): ?array
    {
        return $this->findBy('product_id', $productId);
    }

    /**
     * Find mapping by WooCommerce product ID
     */
    public function findByWcProductId(int $wcProductId): ?array
    {
        return $this->findBy('wc_product_id', $wcProductId);
    }

    /**
     * Create or update mapping
     */
    public function createMapping(int $productId, int $wcProductId, string $wcProductType = 'variable'): int
    {
        $existing = $this->findByProductId($productId);

        if ($existing) {
            $this->update($existing['id'], [
                'wc_product_id' => $wcProductId,
                'wc_product_type' => $wcProductType,
            ]);
            return $existing['id'];
        }

        return $this->create([
            'product_id' => $productId,
            'wc_product_id' => $wcProductId,
            'wc_product_type' => $wcProductType,
        ]);
    }

    /**
     * Delete mapping by local product ID
     */
    public function deleteByProductId(int $productId): int
    {
        $sql = 'DELETE FROM wc_product_map WHERE product_id = ?';
        return $this->db->execute($sql, [$productId]);
    }

    /**
     * Delete mapping by WooCommerce product ID
     */
    public function deleteByWcProductId(int $wcProductId): int
    {
        $sql = 'DELETE FROM wc_product_map WHERE wc_product_id = ?';
        return $this->db->execute($sql, [$wcProductId]);
    }

    /**
     * Get all mappings indexed by local product ID
     */
    public function getAllIndexedByProductId(): array
    {
        $mappings = $this->all();
        $indexed = [];
        foreach ($mappings as $map) {
            $indexed[$map['product_id']] = $map;
        }
        return $indexed;
    }

    /**
     * Get all mappings indexed by WooCommerce product ID
     */
    public function getAllIndexedByWcId(): array
    {
        $mappings = $this->all();
        $indexed = [];
        foreach ($mappings as $map) {
            $indexed[$map['wc_product_id']] = $map;
        }
        return $indexed;
    }

    /**
     * Check if a WooCommerce product is mapped
     */
    public function isMapped(int $wcProductId): bool
    {
        return $this->exists('wc_product_id', $wcProductId);
    }

    /**
     * Get unmapped local products
     */
    public function getUnmappedProducts(): array
    {
        $sql = "
            SELECT p.*
            FROM products p
            LEFT JOIN wc_product_map wpm ON p.id = wpm.product_id
            WHERE wpm.id IS NULL AND p.status = 'active'
        ";
        return $this->db->fetchAll($sql);
    }
}

<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * Product model - represents products in the source of truth database
 */
class Product extends BaseModel
{
    protected static string $table = 'products';

    /**
     * Find a product by SKU
     */
    public function findBySku(string $sku): ?array
    {
        return $this->findBy('sku', $sku);
    }

    /**
     * Find a product by WooCommerce ID
     */
    public function findByWcId(int $wcProductId): ?array
    {
        $sql = '
            SELECT p.*, wpm.wc_product_id, wpm.wc_product_type
            FROM products p
            INNER JOIN wc_product_map wpm ON p.id = wpm.product_id
            WHERE wpm.wc_product_id = ?
            LIMIT 1
        ';
        return $this->db->fetchOne($sql, [$wcProductId]);
    }

    /**
     * Get all active products
     */
    public function getActive(?int $limit = null, int $offset = 0): array
    {
        $sql = "SELECT * FROM products WHERE status = 'active'";
        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        return $this->db->fetchAll($sql);
    }

    /**
     * Get products pending WooCommerce sync
     */
    public function getPendingWooSync(?int $limit = null): array
    {
        $sql = "
            SELECT p.*, wpm.wc_product_id, wpm.wc_product_type,
                   CASE WHEN wpm.wc_product_id IS NULL THEN 'new' ELSE 'update' END AS sync_action
            FROM products p
            LEFT JOIN wc_product_map wpm ON p.id = wpm.product_id
            WHERE p.status = 'active'
              AND (p.last_woo_sync IS NULL OR p.updated_at > p.last_woo_sync)
            ORDER BY p.updated_at ASC
        ";
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }
        return $this->db->fetchAll($sql);
    }

    /**
     * Get products with their variations
     */
    public function getWithVariations(int $productId): ?array
    {
        $product = $this->find($productId);
        if ($product === null) {
            return null;
        }

        $sql = "SELECT * FROM product_variations WHERE product_id = ? AND status = 'active' ORDER BY size_eu ASC";
        $product['variations'] = $this->db->fetchAll($sql, [$productId]);

        return $product;
    }

    /**
     * Get product with all related data (variations, WC mapping)
     */
    public function getFullProduct(int $productId): ?array
    {
        $product = $this->getWithVariations($productId);
        if ($product === null) {
            return null;
        }

        // Get WooCommerce mapping
        $sql = 'SELECT * FROM wc_product_map WHERE product_id = ? LIMIT 1';
        $product['wc_map'] = $this->db->fetchOne($sql, [$productId]);

        // Get variation mappings
        if (!empty($product['variations'])) {
            $variationIds = array_column($product['variations'], 'id');
            $placeholders = implode(',', array_fill(0, count($variationIds), '?'));
            $sql = "SELECT * FROM wc_variation_map WHERE variation_id IN ({$placeholders})";
            $wcVariations = $this->db->fetchAll($sql, $variationIds);

            $wcVariationMap = [];
            foreach ($wcVariations as $wcVar) {
                $wcVariationMap[$wcVar['variation_id']] = $wcVar;
            }

            foreach ($product['variations'] as &$variation) {
                $variation['wc_map'] = $wcVariationMap[$variation['id']] ?? null;
            }
        }

        return $product;
    }

    /**
     * Create or update a product from feed data
     */
    public function upsertFromFeed(array $feedProduct): int
    {
        $sku = $feedProduct['sku'];
        $signature = $this->calculateSignature($feedProduct);

        $data = [
            'sku' => $sku,
            'name' => $feedProduct['name'],
            'brand_name' => $feedProduct['brand_name'] ?? null,
            'image_url' => $feedProduct['image_full_url'] ?? null,
            'size_mapper_name' => $feedProduct['size_mapper_name'] ?? null,
            'feed_id' => $feedProduct['id'] ?? null,
            'feed_signature' => $signature,
            'source' => 'feed',
            'status' => 'active',
            'last_feed_sync' => date('Y-m-d H:i:s'),
        ];

        $existing = $this->findBySku($sku);

        if ($existing) {
            // Update existing product
            $this->update($existing['id'], $data);
            return $existing['id'];
        } else {
            // Create new product
            return $this->create($data);
        }
    }

    /**
     * Calculate a signature hash for change detection
     */
    public function calculateSignature(array $product): string
    {
        $significantFields = [
            'name' => $product['name'] ?? '',
            'brand_name' => $product['brand_name'] ?? '',
            'image_full_url' => $product['image_full_url'] ?? '',
            'sizes' => [],
        ];

        if (!empty($product['sizes'])) {
            foreach ($product['sizes'] as $size) {
                $significantFields['sizes'][] = [
                    'size_eu' => $size['size_eu'] ?? '',
                    'offer_price' => $size['offer_price'] ?? 0,
                    'available_quantity' => $size['available_quantity'] ?? 0,
                ];
            }
        }

        return md5(json_encode($significantFields));
    }

    /**
     * Check if product has changed based on signature
     */
    public function hasChanged(array $feedProduct): bool
    {
        $existing = $this->findBySku($feedProduct['sku']);
        if ($existing === null) {
            return true; // New product
        }

        $newSignature = $this->calculateSignature($feedProduct);
        return $existing['feed_signature'] !== $newSignature;
    }

    /**
     * Mark product as synced to WooCommerce
     */
    public function markWooSynced(int $productId): int
    {
        return $this->update($productId, [
            'last_woo_sync' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get products by brand
     */
    public function getByBrand(string $brandName): array
    {
        return $this->findAllBy('brand_name', $brandName);
    }

    /**
     * Get all unique brands
     */
    public function getBrands(): array
    {
        $sql = "SELECT DISTINCT brand_name FROM products WHERE brand_name IS NOT NULL AND status = 'active' ORDER BY brand_name";
        return array_column($this->db->fetchAll($sql), 'brand_name');
    }

    /**
     * Get stock summary for a product
     */
    public function getStockSummary(int $productId): array
    {
        $sql = "
            SELECT
                COUNT(*) as variation_count,
                SUM(stock_quantity) as total_stock,
                SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock_count,
                MIN(retail_price) as min_price,
                MAX(retail_price) as max_price
            FROM product_variations
            WHERE product_id = ? AND status = 'active'
        ";
        return $this->db->fetchOne($sql, [$productId]) ?? [
            'variation_count' => 0,
            'total_stock' => 0,
            'in_stock_count' => 0,
            'min_price' => null,
            'max_price' => null,
        ];
    }

    /**
     * Mark products as removed (not in feed anymore)
     */
    public function markRemovedProducts(array $currentSkus): int
    {
        if (empty($currentSkus)) {
            // Mark all as inactive
            $sql = "UPDATE products SET status = 'inactive' WHERE status = 'active' AND source = 'feed'";
            return $this->db->execute($sql);
        }

        $placeholders = implode(',', array_fill(0, count($currentSkus), '?'));
        $sql = "
            UPDATE products
            SET status = 'inactive'
            WHERE status = 'active'
              AND source = 'feed'
              AND sku NOT IN ({$placeholders})
        ";
        return $this->db->execute($sql, $currentSkus);
    }

    /**
     * Get products that were in DB but are not in the current feed
     */
    public function getRemovedProducts(array $currentSkus): array
    {
        if (empty($currentSkus)) {
            return $this->findWhere("status = 'active' AND source = 'feed'");
        }

        $placeholders = implode(',', array_fill(0, count($currentSkus), '?'));
        $sql = "
            SELECT * FROM products
            WHERE status = 'active'
              AND source = 'feed'
              AND sku NOT IN ({$placeholders})
        ";
        return $this->db->fetchAll($sql, $currentSkus);
    }

    /**
     * Get products indexed by SKU
     */
    public function getAllIndexedBySku(): array
    {
        $products = $this->getActive();
        $indexed = [];
        foreach ($products as $product) {
            $indexed[$product['sku']] = $product;
        }
        return $indexed;
    }
}

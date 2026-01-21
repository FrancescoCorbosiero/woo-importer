<?php

declare(strict_types=1);

namespace WooImporter\Models;

/**
 * WcVariationMap model - mapping between local variations and WooCommerce variations
 */
class WcVariationMap extends BaseModel
{
    protected static string $table = 'wc_variation_map';

    /**
     * Find mapping by local variation ID
     */
    public function findByVariationId(int $variationId): ?array
    {
        return $this->findBy('variation_id', $variationId);
    }

    /**
     * Find mapping by WooCommerce variation ID
     */
    public function findByWcVariationId(int $wcVariationId): ?array
    {
        return $this->findBy('wc_variation_id', $wcVariationId);
    }

    /**
     * Get all mappings for a WooCommerce parent product
     */
    public function getByWcParentId(int $wcParentId): array
    {
        return $this->findAllBy('wc_parent_id', $wcParentId);
    }

    /**
     * Create or update mapping
     */
    public function createMapping(int $variationId, int $wcVariationId, int $wcParentId): int
    {
        $existing = $this->findByVariationId($variationId);

        if ($existing) {
            $this->update($existing['id'], [
                'wc_variation_id' => $wcVariationId,
                'wc_parent_id' => $wcParentId,
            ]);
            return $existing['id'];
        }

        return $this->create([
            'variation_id' => $variationId,
            'wc_variation_id' => $wcVariationId,
            'wc_parent_id' => $wcParentId,
        ]);
    }

    /**
     * Delete mapping by local variation ID
     */
    public function deleteByVariationId(int $variationId): int
    {
        $sql = 'DELETE FROM wc_variation_map WHERE variation_id = ?';
        return $this->db->execute($sql, [$variationId]);
    }

    /**
     * Delete mapping by WooCommerce variation ID
     */
    public function deleteByWcVariationId(int $wcVariationId): int
    {
        $sql = 'DELETE FROM wc_variation_map WHERE wc_variation_id = ?';
        return $this->db->execute($sql, [$wcVariationId]);
    }

    /**
     * Delete all mappings for a WooCommerce parent product
     */
    public function deleteByWcParentId(int $wcParentId): int
    {
        $sql = 'DELETE FROM wc_variation_map WHERE wc_parent_id = ?';
        return $this->db->execute($sql, [$wcParentId]);
    }

    /**
     * Get all mappings indexed by local variation ID
     */
    public function getAllIndexedByVariationId(): array
    {
        $mappings = $this->all();
        $indexed = [];
        foreach ($mappings as $map) {
            $indexed[$map['variation_id']] = $map;
        }
        return $indexed;
    }

    /**
     * Get all mappings indexed by WooCommerce variation ID
     */
    public function getAllIndexedByWcId(): array
    {
        $mappings = $this->all();
        $indexed = [];
        foreach ($mappings as $map) {
            $indexed[$map['wc_variation_id']] = $map;
        }
        return $indexed;
    }

    /**
     * Get local variation IDs for a set of WooCommerce variation IDs
     */
    public function getLocalIdsForWcIds(array $wcVariationIds): array
    {
        if (empty($wcVariationIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($wcVariationIds), '?'));
        $sql = "SELECT variation_id, wc_variation_id FROM wc_variation_map WHERE wc_variation_id IN ({$placeholders})";
        $results = $this->db->fetchAll($sql, $wcVariationIds);

        $mapping = [];
        foreach ($results as $row) {
            $mapping[$row['wc_variation_id']] = $row['variation_id'];
        }
        return $mapping;
    }
}

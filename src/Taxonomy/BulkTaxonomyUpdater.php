<?php

namespace ResellPiacenza\Taxonomy;

use Automattic\WooCommerce\Client;

/**
 * Bulk Taxonomy Updater
 *
 * Manipulates WooCommerce taxonomies (categories, brands, attributes) for
 * groups of products identified by SKU. Supports set/add/remove operations
 * on product_cat, brands, and pa_* attributes.
 *
 * Uses WC REST API batch endpoint for efficient bulk updates.
 *
 * @package ResellPiacenza\Taxonomy
 */
class BulkTaxonomyUpdater
{
    private Client $wc_client;
    private $logger;
    private bool $dry_run;
    private int $batch_size;

    private array $stats = [
        'products_found' => 0,
        'products_updated' => 0,
        'products_skipped' => 0,
        'errors' => 0,
        'batch_requests' => 0,
    ];

    /**
     * @param Client $wc_client WooCommerce REST API client
     * @param object|null $logger Monolog logger instance
     * @param bool $dry_run Preview mode — no changes applied
     * @param int $batch_size Products per batch request (max 100)
     */
    public function __construct(Client $wc_client, $logger = null, bool $dry_run = false, int $batch_size = 25)
    {
        $this->wc_client = $wc_client;
        $this->logger = $logger;
        $this->dry_run = $dry_run;
        $this->batch_size = min($batch_size, 100);
    }

    /**
     * Set/add/remove categories on products by SKU
     *
     * @param array $skus List of product SKUs to target
     * @param string $operation 'set' (replace all), 'add', or 'remove'
     * @param array $category_refs Category references — each can be:
     *   - int: category ID
     *   - string: category slug (resolved via API)
     *   - array: ['slug' => '...'] or ['name' => '...', 'parent' => '...']
     * @return array Stats summary
     */
    public function updateCategories(array $skus, string $operation, array $category_refs): array
    {
        $this->resetStats();
        $category_ids = $this->resolveCategoryRefs($category_refs);

        if (empty($category_ids)) {
            $this->log('error', 'No valid category IDs resolved');
            return $this->stats;
        }

        $this->log('info', "Categories: " . implode(', ', $category_ids) . " (operation: {$operation})");

        $products = $this->fetchProductsBySkus($skus);
        if (empty($products)) {
            $this->log('warning', 'No matching products found in WooCommerce');
            return $this->stats;
        }

        $updates = [];
        foreach ($products as $product) {
            $existing_ids = array_map(fn($c) => $c->id, $product->categories ?? []);
            $new_ids = $this->applyOperation($existing_ids, $category_ids, $operation);

            if ($this->arraysEqual($existing_ids, $new_ids)) {
                $this->stats['products_skipped']++;
                $this->log('debug', "  [{$product->sku}] No change needed");
                continue;
            }

            $updates[] = [
                'id' => $product->id,
                'categories' => array_map(fn($id) => ['id' => $id], $new_ids),
            ];

            $this->log('info', "  [{$product->sku}] categories: [" . implode(',', $existing_ids) . "] → [" . implode(',', $new_ids) . "]");
        }

        $this->executeBatchUpdates($updates);
        return $this->stats;
    }

    /**
     * Set/add/remove brands on products by SKU
     *
     * Uses the WooCommerce brands taxonomy (/products/brands endpoint).
     *
     * @param array $skus List of product SKUs to target
     * @param string $operation 'set' (replace all), 'add', or 'remove'
     * @param array $brand_refs Brand references — each can be:
     *   - int: brand ID
     *   - string: brand slug or name (resolved via API)
     * @return array Stats summary
     */
    public function updateBrands(array $skus, string $operation, array $brand_refs): array
    {
        $this->resetStats();
        $brand_ids = $this->resolveBrandRefs($brand_refs);

        if (empty($brand_ids)) {
            $this->log('error', 'No valid brand IDs resolved');
            return $this->stats;
        }

        $this->log('info', "Brands: " . implode(', ', $brand_ids) . " (operation: {$operation})");

        $products = $this->fetchProductsBySkus($skus);
        if (empty($products)) {
            $this->log('warning', 'No matching products found in WooCommerce');
            return $this->stats;
        }

        $updates = [];
        foreach ($products as $product) {
            $existing_ids = array_map(fn($b) => $b->id, $product->brands ?? []);
            $new_ids = $this->applyOperation($existing_ids, $brand_ids, $operation);

            if ($this->arraysEqual($existing_ids, $new_ids)) {
                $this->stats['products_skipped']++;
                $this->log('debug', "  [{$product->sku}] No change needed");
                continue;
            }

            $updates[] = [
                'id' => $product->id,
                'brands' => array_map(fn($id) => ['id' => $id], $new_ids),
            ];

            $this->log('info', "  [{$product->sku}] brands: [" . implode(',', $existing_ids) . "] → [" . implode(',', $new_ids) . "]");
        }

        $this->executeBatchUpdates($updates);
        return $this->stats;
    }

    /**
     * Set/add/remove a product attribute on products by SKU
     *
     * Operates on non-variation attributes (pa_marca, pa_genere, pa_modello,
     * pa_colorway, pa_data-di-rilascio). For variation attributes (pa_taglia),
     * use updateVariationAttributes() instead.
     *
     * @param array $skus List of product SKUs to target
     * @param string $operation 'set' (replace options for this attr), 'add', or 'remove'
     * @param string $attribute Attribute slug or name (e.g. 'pa_marca', 'marca', 'Marca')
     * @param array $values Attribute option values (e.g. ['Nike'], ['Uomo', 'Donna'])
     * @return array Stats summary
     */
    public function updateAttribute(array $skus, string $operation, string $attribute, array $values): array
    {
        $this->resetStats();
        $attr_info = $this->resolveAttribute($attribute);

        if (!$attr_info) {
            $this->log('error', "Attribute not found: {$attribute}");
            return $this->stats;
        }

        $this->log('info', "Attribute: {$attr_info['name']} (ID {$attr_info['id']}, slug: {$attr_info['slug']})");
        $this->log('info', "Values: " . implode(', ', $values) . " (operation: {$operation})");

        $products = $this->fetchProductsBySkus($skus);
        if (empty($products)) {
            $this->log('warning', 'No matching products found in WooCommerce');
            return $this->stats;
        }

        $updates = [];
        foreach ($products as $product) {
            $existing_attrs = (array) ($product->attributes ?? []);
            $new_attrs = $this->applyAttributeOperation($existing_attrs, $attr_info, $values, $operation);

            if ($new_attrs === null) {
                $this->stats['products_skipped']++;
                $this->log('debug', "  [{$product->sku}] No change needed");
                continue;
            }

            $updates[] = [
                'id' => $product->id,
                'attributes' => $new_attrs,
            ];

            $this->log('info', "  [{$product->sku}] {$attr_info['name']}: {$operation} " . implode(', ', $values));
        }

        $this->executeBatchUpdates($updates);
        return $this->stats;
    }

    /**
     * Set product status (publish/draft/pending/private) by SKU
     *
     * Convenience method — not strictly taxonomy, but commonly needed
     * alongside taxonomy bulk operations.
     *
     * @param array $skus List of product SKUs to target
     * @param string $status Target status (publish, draft, pending, private)
     * @return array Stats summary
     */
    public function updateStatus(array $skus, string $status): array
    {
        $this->resetStats();
        $valid = ['publish', 'draft', 'pending', 'private'];
        if (!in_array($status, $valid)) {
            $this->log('error', "Invalid status '{$status}'. Must be one of: " . implode(', ', $valid));
            return $this->stats;
        }

        $this->log('info', "Status: {$status}");

        $products = $this->fetchProductsBySkus($skus);
        if (empty($products)) {
            $this->log('warning', 'No matching products found in WooCommerce');
            return $this->stats;
        }

        $updates = [];
        foreach ($products as $product) {
            if (($product->status ?? '') === $status) {
                $this->stats['products_skipped']++;
                continue;
            }

            $updates[] = [
                'id' => $product->id,
                'status' => $status,
            ];

            $this->log('info', "  [{$product->sku}] {$product->status} → {$status}");
        }

        $this->executeBatchUpdates($updates);
        return $this->stats;
    }

    // =========================================================================
    // Product Lookup
    // =========================================================================

    /**
     * Fetch WC products by a list of SKUs
     *
     * Paginated fetch of all products, filtered to matching SKUs.
     * Uses status=any to include draft/private products.
     *
     * @param array $skus SKUs to find
     * @return array WC product objects (keyed by SKU for dedup)
     */
    private function fetchProductsBySkus(array $skus): array
    {
        $sku_set = array_flip(array_map('strtoupper', $skus));
        $found = [];

        // For small SKU lists (≤5), use per-SKU lookup (fewer API calls)
        if (count($skus) <= 5) {
            foreach ($skus as $sku) {
                try {
                    $products = $this->wc_client->get('products', [
                        'sku' => $sku,
                        'status' => 'any',
                        'per_page' => 1,
                    ]);
                    if (!empty($products)) {
                        $found[$sku] = $products[0];
                        $this->stats['products_found']++;
                    } else {
                        $this->log('warning', "  SKU not found: {$sku}");
                    }
                } catch (\Exception $e) {
                    $this->log('error', "  SKU lookup failed for {$sku}: " . $e->getMessage());
                }
            }
            return array_values($found);
        }

        // For larger lists, paginate through all products
        $this->log('info', 'Fetching WC products...');
        $page = 1;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($products as $product) {
                    $sku = $product->sku ?? '';
                    if ($sku && isset($sku_set[strtoupper($sku)])) {
                        $found[$sku] = $product;
                        $this->stats['products_found']++;
                    }
                }

                // Early exit: found all SKUs
                if (count($found) >= count($skus)) {
                    break;
                }

                $page++;
            } catch (\Exception $e) {
                $this->log('error', "  Error fetching products page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products ?? []) === 100);

        // Report missing SKUs
        foreach ($skus as $sku) {
            if (!isset($found[$sku]) && !isset($found[strtoupper($sku)])) {
                $this->log('warning', "  SKU not found: {$sku}");
            }
        }

        $this->log('info', "  Found {$this->stats['products_found']}/" . count($skus) . " products");
        return array_values($found);
    }

    // =========================================================================
    // Taxonomy Resolution
    // =========================================================================

    /**
     * Resolve category references to IDs
     *
     * @param array $refs Mixed references (IDs, slugs, arrays)
     * @return array Resolved category IDs
     */
    private function resolveCategoryRefs(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (is_int($ref)) {
                $ids[] = $ref;
            } elseif (is_string($ref)) {
                $id = $this->lookupCategoryBySlug($ref);
                if ($id) {
                    $ids[] = $id;
                } else {
                    $this->log('warning', "  Category not found: {$ref}");
                }
            } elseif (is_array($ref) && isset($ref['slug'])) {
                $id = $this->lookupCategoryBySlug($ref['slug']);
                if ($id) {
                    $ids[] = $id;
                }
            }
        }
        return array_unique($ids);
    }

    /**
     * Look up a WC category by slug
     *
     * @param string $slug Category slug
     * @return int|null Category ID
     */
    private function lookupCategoryBySlug(string $slug): ?int
    {
        try {
            $categories = $this->wc_client->get('products/categories', [
                'slug' => $this->sanitizeSlug($slug),
                'per_page' => 1,
            ]);
            return !empty($categories) ? $categories[0]->id : null;
        } catch (\Exception $e) {
            $this->log('error', "  Category lookup error for '{$slug}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve brand references to IDs
     *
     * @param array $refs Mixed references (IDs, slugs/names)
     * @return array Resolved brand IDs
     */
    private function resolveBrandRefs(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (is_int($ref)) {
                $ids[] = $ref;
            } elseif (is_string($ref)) {
                $id = $this->lookupBrandBySlug($ref);
                if ($id) {
                    $ids[] = $id;
                } else {
                    $this->log('warning', "  Brand not found: {$ref}");
                }
            }
        }
        return array_unique($ids);
    }

    /**
     * Look up a WC brand by slug or name
     *
     * @param string $ref Brand slug or name
     * @return int|null Brand ID
     */
    private function lookupBrandBySlug(string $ref): ?int
    {
        $slug = $this->sanitizeSlug($ref);
        try {
            $brands = $this->wc_client->get('products/brands', [
                'slug' => $slug,
                'per_page' => 1,
            ]);
            if (!empty($brands)) {
                return $brands[0]->id;
            }

            // Fallback: search by name (case-insensitive via API)
            $brands = $this->wc_client->get('products/brands', [
                'search' => $ref,
                'per_page' => 10,
            ]);
            foreach ($brands as $brand) {
                if (strcasecmp($brand->name, $ref) === 0) {
                    return $brand->id;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->log('error', "  Brand lookup error for '{$ref}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve an attribute slug/name to its WC attribute info
     *
     * Handles flexible matching: 'marca', 'pa_marca', 'Marca' all resolve
     * to the same global attribute.
     *
     * @param string $attribute Attribute slug, pa_slug, or display name
     * @return array|null ['id' => int, 'slug' => string, 'name' => string] or null
     */
    private function resolveAttribute(string $attribute): ?array
    {
        try {
            $attributes = $this->wc_client->get('products/attributes');
            $needle = strtolower(preg_replace('/^pa_/', '', $attribute));

            foreach ($attributes as $attr) {
                $attr_slug = strtolower($attr->slug ?? '');
                $attr_slug_bare = preg_replace('/^pa_/', '', $attr_slug);
                $attr_name = strtolower($attr->name ?? '');

                if ($attr_slug_bare === $needle || $attr_slug === $needle || $attr_name === $needle) {
                    return [
                        'id' => $attr->id,
                        'slug' => 'pa_' . $attr_slug_bare,
                        'name' => $attr->name,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "  Attribute lookup error: " . $e->getMessage());
        }

        return null;
    }

    // =========================================================================
    // Operation Logic
    // =========================================================================

    /**
     * Apply set/add/remove operation on a list of IDs
     *
     * @param array $existing Current IDs
     * @param array $target IDs to set/add/remove
     * @param string $operation 'set', 'add', or 'remove'
     * @return array Resulting IDs
     */
    private function applyOperation(array $existing, array $target, string $operation): array
    {
        switch ($operation) {
            case 'set':
                return $target;

            case 'add':
                return array_values(array_unique(array_merge($existing, $target)));

            case 'remove':
                return array_values(array_diff($existing, $target));

            default:
                $this->log('error', "Invalid operation: {$operation}");
                return $existing;
        }
    }

    /**
     * Apply attribute operation on a product's attributes array
     *
     * Preserves all other attributes, only modifies the targeted one.
     *
     * @param array $existing_attrs Current product attributes (from WC API)
     * @param array $attr_info Resolved attribute ['id', 'slug', 'name']
     * @param array $values Option values to set/add/remove
     * @param string $operation 'set', 'add', or 'remove'
     * @return array|null New attributes array, or null if no change needed
     */
    private function applyAttributeOperation(array $existing_attrs, array $attr_info, array $values, string $operation): ?array
    {
        $result = [];
        $found = false;

        foreach ($existing_attrs as $attr) {
            $attr = (array) $attr;
            $attr_slug = strtolower($attr['slug'] ?? $attr['name'] ?? '');
            $attr_slug_bare = preg_replace('/^pa_/', '', $attr_slug);
            $target_bare = preg_replace('/^pa_/', '', strtolower($attr_info['slug']));

            if ($attr_slug_bare === $target_bare) {
                $found = true;
                $existing_options = $attr['options'] ?? [];

                switch ($operation) {
                    case 'set':
                        $new_options = $values;
                        break;
                    case 'add':
                        $new_options = array_values(array_unique(array_merge($existing_options, $values)));
                        break;
                    case 'remove':
                        $new_options = array_values(array_diff($existing_options, $values));
                        break;
                    default:
                        $new_options = $existing_options;
                }

                // No change
                if ($this->arraysEqual($existing_options, $new_options)) {
                    $result[] = $attr;
                    continue;
                }

                // If remove empties the attribute, skip it entirely
                if ($operation === 'remove' && empty($new_options)) {
                    continue;
                }

                $attr['options'] = $new_options;
                $result[] = $attr;
            } else {
                $result[] = $attr;
            }
        }

        // Attribute not on product yet — add it (for 'set' or 'add')
        if (!$found && in_array($operation, ['set', 'add']) && !empty($values)) {
            $result[] = [
                'id' => $attr_info['id'],
                'name' => $attr_info['name'],
                'slug' => $attr_info['slug'],
                'visible' => true,
                'variation' => false,
                'options' => $values,
            ];
        } elseif (!$found) {
            return null; // Nothing to remove from non-existent attribute
        }

        // Check if anything actually changed
        if (count($result) === count($existing_attrs)) {
            $changed = false;
            foreach ($result as $i => $attr) {
                $orig = (array) ($existing_attrs[$i] ?? []);
                if (($attr['options'] ?? []) !== ($orig['options'] ?? [])) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed && $found) {
                return null;
            }
        }

        return $result;
    }

    // =========================================================================
    // Batch Execution
    // =========================================================================

    /**
     * Execute batch product updates via WC REST API
     *
     * @param array $updates Array of product update payloads [{id, ...}, ...]
     */
    private function executeBatchUpdates(array $updates): void
    {
        if (empty($updates)) {
            $this->log('info', 'No products need updating');
            return;
        }

        $this->log('info', "Updating " . count($updates) . " products...");
        $chunks = array_chunk($updates, $this->batch_size);

        foreach ($chunks as $ci => $chunk) {
            $batch_label = ($ci + 1) . '/' . count($chunks);

            if ($this->dry_run) {
                $this->stats['products_updated'] += count($chunk);
                $this->log('info', "  [DRY RUN] Batch {$batch_label}: would update " . count($chunk) . " products");
                continue;
            }

            try {
                $result = $this->wc_client->post('products/batch', ['update' => $chunk]);
                $this->stats['batch_requests']++;

                $ok = 0;
                $err = 0;

                foreach ($result->update ?? [] as $item) {
                    if (isset($item->error)) {
                        $err++;
                        $this->stats['errors']++;
                        $this->log('error', "  Product {$item->id}: " . ($item->error->message ?? 'Unknown error'));
                    } else {
                        $ok++;
                        $this->stats['products_updated']++;
                    }
                }

                $this->log('info', "  Batch {$batch_label}: updated {$ok}" . ($err > 0 ? ", {$err} errors" : ''));
            } catch (\Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->log('error', "  Batch {$batch_label} failed: " . $e->getMessage());
            }

            usleep(200000); // 200ms between batches
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Compare two arrays for equality (order-independent)
     */
    private function arraysEqual(array $a, array $b): bool
    {
        $a = array_values($a);
        $b = array_values($b);
        sort($a);
        sort($b);
        return $a === $b;
    }

    /**
     * Sanitize a string to a URL-safe slug
     */
    private function sanitizeSlug(string $str): string
    {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    /**
     * Reset stats counters
     */
    private function resetStats(): void
    {
        $this->stats = array_map(fn() => 0, $this->stats);
    }

    /**
     * Get current stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

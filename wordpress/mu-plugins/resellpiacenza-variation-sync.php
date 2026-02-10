<?php
/**
 * ResellPiacenza — Variation Sync Endpoint
 *
 * Must-use plugin that exposes a REST endpoint to trigger
 * WC_Product_Variable::sync() after programmatic product imports.
 *
 * The WooCommerce REST API does NOT call sync() when creating
 * variations, so _product_attributes meta, price lookups, and
 * transient caches remain stale until a manual re-save in the
 * WP editor. This endpoint replicates that editor re-save.
 *
 * Install: copy to wp-content/mu-plugins/
 * Auth:    WordPress Application Passwords (Basic Auth)
 *
 * Endpoints:
 *   POST /wp-json/resellpiacenza/v1/sync-variations
 *   Body: { "product_ids": [123, 456, ...] }
 *
 * @package ResellPiacenza
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('resellpiacenza/v1', '/sync-variations', [
        'methods'             => 'POST',
        'callback'            => 'resellpiacenza_sync_variations',
        'permission_callback' => function () {
            return current_user_can('edit_products');
        },
        'args' => [
            'product_ids' => [
                'required'          => true,
                'type'              => 'array',
                'items'             => ['type' => 'integer'],
                'sanitize_callback' => function ($ids) {
                    return array_map('absint', (array) $ids);
                },
            ],
        ],
    ]);
});

/**
 * Sync variable products — replicates what the WP editor "Update" button does.
 *
 * For each product ID:
 * 1. Loads the WC_Product_Variable instance
 * 2. Calls WC_Product_Variable::sync() — rebuilds parent price meta,
 *    stock status, and attribute lookups from child variations
 * 3. Deletes product transients (cached variation prices, children IDs)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function resellpiacenza_sync_variations(WP_REST_Request $request): WP_REST_Response
{
    $product_ids = $request->get_param('product_ids');
    $results     = [];

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            $results[] = [
                'id'     => $product_id,
                'status' => 'error',
                'reason' => 'Product not found',
            ];
            continue;
        }

        if (!$product->is_type('variable')) {
            $results[] = [
                'id'     => $product_id,
                'status' => 'skipped',
                'reason' => 'Not a variable product',
            ];
            continue;
        }

        // Core sync — rebuilds _price meta, stock status, attribute lookups
        WC_Product_Variable::sync($product_id);

        // Clear cached variation prices and children IDs
        wc_delete_product_transients($product_id);

        $results[] = [
            'id'     => $product_id,
            'status' => 'synced',
        ];
    }

    return new WP_REST_Response([
        'synced'  => count(array_filter($results, fn($r) => $r['status'] === 'synced')),
        'total'   => count($product_ids),
        'results' => $results,
    ], 200);
}

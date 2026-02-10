<?php
/**
 * Plugin Name: ResellPiacenza Product Sync
 * Description: REST endpoint to trigger WC_Product_Variable::sync() after REST API imports.
 * Version: 1.0.0
 *
 * Drop this file into wp-content/mu-plugins/ on your WordPress server.
 *
 * Usage:
 *   POST /wp-json/resellpiacenza/v1/sync-products
 *   Body: {"ids": [123, 456]}     — sync specific products
 *   Body: {}                       — sync ALL variable products
 *
 * Auth: WordPress Application Password (Basic Auth)
 */

add_action('rest_api_init', function () {
    register_rest_route('resellpiacenza/v1', '/sync-products', [
        'methods' => 'POST',
        'callback' => function ($request) {
            $ids = $request->get_param('ids');
            $synced = [];

            if (!empty($ids) && is_array($ids)) {
                foreach ($ids as $id) {
                    WC_Product_Variable::sync((int) $id);
                    $synced[] = (int) $id;
                }
            } else {
                $products = wc_get_products([
                    'type' => 'variable',
                    'limit' => -1,
                    'return' => 'ids',
                ]);
                foreach ($products as $id) {
                    WC_Product_Variable::sync($id);
                    $synced[] = $id;
                }
            }

            return ['synced' => count($synced)];
        },
        'permission_callback' => function () {
            return current_user_can('manage_woocommerce');
        },
    ]);
});

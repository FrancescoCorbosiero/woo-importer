<?php
/* RUN THIS: docker exec wordpress wp db query "DELETE FROM wp_wc_product_meta_lookup WHERE product_id NOT IN (SELECT ID FROM wp_posts WHERE post_type IN ('product', 'product_variation'));" --path=/opt/bitnami/wordpress --allow-root
 */
/**
 * WooCommerce Lookup Table Cleanup Script
 *
 * Upload this file to your WordPress root directory and access it via browser:
 * https://yoursite.com/cleanup-lookup-table.php?confirm=yes
 *
 * DELETE THIS FILE AFTER USE!
 */

// Load WordPress
require_once __DIR__ . '/wp-load.php';

// Security check - only admins can run this
if (!current_user_can('manage_options')) {
    // Try to auto-login if accessed from CLI or direct
    if (php_sapi_name() === 'cli' || !is_user_logged_in()) {
        // Allow with confirmation parameter for non-logged-in access
        if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
            die('Access denied. Add ?confirm=yes to the URL to proceed, or login as admin.');
        }
    } else {
        die('Access denied. You must be an administrator.');
    }
}

global $wpdb;

echo "<html><head><title>WooCommerce Lookup Table Cleanup</title></head><body>";
echo "<h1>WooCommerce Lookup Table Cleanup</h1>";

// Count orphaned entries before cleanup
$orphaned_count = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup
    WHERE product_id NOT IN (
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type IN ('product', 'product_variation')
    )
");

echo "<p>Found <strong>{$orphaned_count}</strong> orphaned entries in the lookup table.</p>";

if ($orphaned_count > 0) {
    // Delete orphaned entries
    $deleted = $wpdb->query("
        DELETE FROM {$wpdb->prefix}wc_product_meta_lookup
        WHERE product_id NOT IN (
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type IN ('product', 'product_variation')
        )
    ");

    echo "<p style='color: green;'>✓ Deleted <strong>{$deleted}</strong> orphaned entries.</p>";

    // Also clean up orphaned term relationships
    $orphaned_terms = $wpdb->query("
        DELETE FROM {$wpdb->term_relationships}
        WHERE object_id NOT IN (SELECT ID FROM {$wpdb->posts})
    ");

    echo "<p style='color: green;'>✓ Cleaned up <strong>{$orphaned_terms}</strong> orphaned term relationships.</p>";

    // Clear WooCommerce transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_%'");

    echo "<p style='color: green;'>✓ Cleared WooCommerce transients.</p>";

} else {
    echo "<p style='color: blue;'>No orphaned entries found. Lookup table is clean.</p>";
}

// Show current state
$total_lookup = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup");
$total_products = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'");
$total_variations = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'");

echo "<h2>Current State:</h2>";
echo "<ul>";
echo "<li>Lookup table entries: <strong>{$total_lookup}</strong></li>";
echo "<li>Products in database: <strong>{$total_products}</strong></li>";
echo "<li>Variations in database: <strong>{$total_variations}</strong></li>";
echo "</ul>";

echo "<p style='color: red; font-weight: bold;'>⚠️ DELETE THIS FILE NOW: cleanup-lookup-table.php</p>";
echo "</body></html>";

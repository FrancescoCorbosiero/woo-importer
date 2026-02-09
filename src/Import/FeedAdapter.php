<?php

namespace ResellPiacenza\Import;

/**
 * Feed Adapter Interface
 *
 * Defines the contract for any external product feed source.
 * Each adapter fetches from its source and normalizes to a common format,
 * which WcProductBuilder then converts to WooCommerce REST API payloads.
 *
 * Normalized product format:
 * [
 *     'sku'           => string,       // Product SKU / style code
 *     'name'          => string,       // Product title
 *     'brand'         => string,       // Brand name
 *     'model'         => string,       // Model name (e.g. "Dunk Low", "Jordan 1 Retro High OG")
 *     'gender'        => string,       // Gender: "men", "women", "unisex", "youth", etc.
 *     'colorway'      => string,       // Colorway string (e.g. "White/Black-White")
 *     'release_date'  => string,       // Release date (ISO or YYYY-MM-DD)
 *     'retail_price'  => string,       // Original retail price
 *     'description'   => string,       // Rich product description (HTML or plain text)
 *     'category_type' => string,       // 'sneakers' or 'clothing'
 *     'image_url'     => ?string,      // Primary image URL
 *     'gallery_urls'  => array,        // Additional gallery image URLs
 *     'meta_data'     => array,        // Extra WC meta_data entries
 *     'variations'    => [
 *         [
 *             'size_eu'        => string,  // EU size
 *             'price'          => float,   // Final selling price (after margin)
 *             'stock_quantity' => int,
 *             'stock_status'   => string,  // 'instock' or 'outofstock'
 *             'meta_data'      => array,   // Per-variation meta
 *         ],
 *     ],
 * ]
 *
 * @package ResellPiacenza\Import
 */
interface FeedAdapter
{
    /**
     * Source name for logging and identification
     */
    public function getSourceName(): string;

    /**
     * Fetch and normalize products from the source
     *
     * @return iterable<array> Yields normalized product arrays
     */
    public function fetchProducts(): iterable;

    /**
     * Get adapter-specific stats after fetch
     */
    public function getStats(): array;
}

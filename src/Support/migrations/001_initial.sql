-- Migration 001: Initial schema
-- Creates all tables for the woo-importer SQLite storage layer.

-- Product catalog (canonical normalized records)
CREATE TABLE IF NOT EXISTS products (
    sku TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    brand TEXT,
    category TEXT,
    source TEXT NOT NULL,
    normalized_json TEXT NOT NULL,
    wc_payload_json TEXT,
    wc_product_id INTEGER,
    signature TEXT,
    first_seen_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    synced_at TEXT
);

-- Per-variation data (queryable size/price/stock)
CREATE TABLE IF NOT EXISTS variations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL REFERENCES products(sku) ON DELETE CASCADE,
    size_eu TEXT NOT NULL,
    price REAL,
    regular_price REAL,
    sale_price REAL,
    stock_quantity INTEGER DEFAULT 0,
    stock_status TEXT DEFAULT 'outofstock',
    source TEXT NOT NULL,
    wc_variation_id INTEGER,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(sku, size_eu, source)
);

-- API response cache (replaces kicksdb-product-cache.json)
CREATE TABLE IF NOT EXISTS api_cache (
    cache_key TEXT PRIMARY KEY,
    source TEXT NOT NULL,
    response_json TEXT NOT NULL,
    fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Media map (replaces image-map.json)
CREATE TABLE IF NOT EXISTS media_map (
    url_hash TEXT PRIMARY KEY,
    source_url TEXT NOT NULL,
    wp_media_id INTEGER NOT NULL,
    wp_url TEXT,
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Taxonomy map (replaces taxonomy-map.json)
CREATE TABLE IF NOT EXISTS taxonomy_map (
    slug TEXT NOT NULL,
    taxonomy TEXT NOT NULL,
    wc_term_id INTEGER NOT NULL,
    name TEXT,
    parent_id INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY(slug, taxonomy)
);

-- Sync log (audit trail)
CREATE TABLE IF NOT EXISTS sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    action TEXT NOT NULL,
    details_json TEXT,
    synced_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_products_source ON products(source);
CREATE INDEX IF NOT EXISTS idx_products_brand ON products(brand);
CREATE INDEX IF NOT EXISTS idx_products_synced ON products(synced_at);
CREATE INDEX IF NOT EXISTS idx_variations_sku ON variations(sku);
CREATE INDEX IF NOT EXISTS idx_api_cache_source ON api_cache(source);
CREATE INDEX IF NOT EXISTS idx_api_cache_fetched ON api_cache(fetched_at);
CREATE INDEX IF NOT EXISTS idx_sync_log_sku ON sync_log(sku);
CREATE INDEX IF NOT EXISTS idx_sync_log_synced ON sync_log(synced_at);

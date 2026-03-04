-- Migration 002: Media upload queue
-- Async media processing: products are created imageless, images uploaded in background.

CREATE TABLE IF NOT EXISTS media_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    source_url TEXT NOT NULL,
    product_name TEXT DEFAULT '',
    brand_name TEXT DEFAULT '',
    type TEXT NOT NULL DEFAULT 'primary',
    gallery_index INTEGER DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    wp_media_id INTEGER,
    attempts INTEGER DEFAULT 0,
    error TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(sku, source_url)
);

CREATE INDEX IF NOT EXISTS idx_media_queue_status ON media_queue(status);
CREATE INDEX IF NOT EXISTS idx_media_queue_sku ON media_queue(sku);
CREATE INDEX IF NOT EXISTS idx_media_queue_created ON media_queue(created_at);

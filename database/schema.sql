-- WooCommerce Importer Database Schema
-- Source of Truth for inventory management
--
-- Run this script to initialize the database:
-- mysql -u username -p database_name < schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Table: products
-- Master product table - source of truth
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NOT NULL,
    `name` VARCHAR(500) NOT NULL,
    `brand_name` VARCHAR(200) NULL,
    `image_url` VARCHAR(1000) NULL,
    `size_mapper_name` VARCHAR(100) NULL,
    `feed_id` INT UNSIGNED NULL COMMENT 'Original ID from Golden Sneakers feed',
    `short_description` TEXT NULL,
    `long_description` TEXT NULL,
    `status` ENUM('active', 'inactive', 'deleted') NOT NULL DEFAULT 'active',
    `source` ENUM('feed', 'woocommerce', 'manual') NOT NULL DEFAULT 'feed',
    `feed_signature` VARCHAR(64) NULL COMMENT 'MD5 hash for change detection',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_feed_sync` DATETIME NULL COMMENT 'Last time synced from feed',
    `last_woo_sync` DATETIME NULL COMMENT 'Last time synced to/from WooCommerce',
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_sku` (`sku`),
    INDEX `idx_brand` (`brand_name`),
    INDEX `idx_status` (`status`),
    INDEX `idx_feed_id` (`feed_id`),
    INDEX `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: product_variations
-- Size/stock variations for each product
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `product_variations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(120) NOT NULL COMMENT 'Format: PARENT_SKU-SIZE',
    `size_us` VARCHAR(20) NULL,
    `size_eu` VARCHAR(20) NULL,
    `size_uk` VARCHAR(20) NULL,
    `offer_price` DECIMAL(10,2) NOT NULL COMMENT 'Cost price from supplier',
    `retail_price` DECIMAL(10,2) NOT NULL COMMENT 'Calculated selling price',
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `barcode` VARCHAR(50) NULL,
    `status` ENUM('active', 'inactive', 'deleted') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_sku` (`sku`),
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_stock` (`stock_quantity`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_variation_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: wc_product_map
-- Mapping between local DB and WooCommerce IDs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wc_product_map` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `wc_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'WooCommerce product ID',
    `wc_product_type` ENUM('simple', 'variable') NOT NULL DEFAULT 'variable',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_product_id` (`product_id`),
    UNIQUE INDEX `idx_wc_product_id` (`wc_product_id`),
    CONSTRAINT `fk_map_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: wc_variation_map
-- Mapping between local variations and WooCommerce variation IDs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wc_variation_map` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `variation_id` INT UNSIGNED NOT NULL,
    `wc_variation_id` BIGINT UNSIGNED NOT NULL COMMENT 'WooCommerce variation ID',
    `wc_parent_id` BIGINT UNSIGNED NOT NULL COMMENT 'WooCommerce parent product ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_variation_id` (`variation_id`),
    UNIQUE INDEX `idx_wc_variation_id` (`wc_variation_id`),
    INDEX `idx_wc_parent_id` (`wc_parent_id`),
    CONSTRAINT `fk_map_variation`
        FOREIGN KEY (`variation_id`) REFERENCES `product_variations` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: sync_log
-- Audit trail for all sync operations
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sync_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sync_type` ENUM('feed_to_db', 'db_to_woo', 'woo_to_db', 'webhook') NOT NULL,
    `entity_type` ENUM('product', 'variation', 'batch') NOT NULL,
    `entity_id` INT UNSIGNED NULL COMMENT 'Local product/variation ID',
    `wc_entity_id` BIGINT UNSIGNED NULL COMMENT 'WooCommerce ID if applicable',
    `sku` VARCHAR(120) NULL,
    `action` ENUM('create', 'update', 'delete', 'skip', 'error') NOT NULL,
    `changes` JSON NULL COMMENT 'JSON object with field changes',
    `source` VARCHAR(50) NULL COMMENT 'Source identifier (feed, webhook, manual)',
    `message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sync_type` (`sync_type`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_sku` (`sku`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: webhook_queue
-- Queue for incoming webhooks (for reliability)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `webhook_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webhook_id` VARCHAR(100) NULL COMMENT 'WooCommerce webhook delivery ID',
    `topic` VARCHAR(100) NOT NULL COMMENT 'e.g., product.updated',
    `resource` VARCHAR(50) NOT NULL COMMENT 'e.g., product',
    `resource_id` BIGINT UNSIGNED NOT NULL,
    `payload` JSON NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `error_message` TEXT NULL,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_topic` (`topic`),
    INDEX `idx_resource` (`resource`, `resource_id`),
    INDEX `idx_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table: feed_snapshots
-- Store feed snapshots for diff comparison
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `feed_snapshots` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snapshot_hash` VARCHAR(64) NOT NULL COMMENT 'MD5 of full feed',
    `product_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- Views for common queries
-- -----------------------------------------------------

-- Products with stock summary
CREATE OR REPLACE VIEW `v_products_stock` AS
SELECT
    p.id,
    p.sku,
    p.name,
    p.brand_name,
    p.status,
    COUNT(pv.id) AS variation_count,
    SUM(pv.stock_quantity) AS total_stock,
    SUM(CASE WHEN pv.stock_quantity > 0 THEN 1 ELSE 0 END) AS in_stock_variations,
    MIN(pv.retail_price) AS min_price,
    MAX(pv.retail_price) AS max_price,
    wpm.wc_product_id
FROM products p
LEFT JOIN product_variations pv ON p.id = pv.product_id AND pv.status = 'active'
LEFT JOIN wc_product_map wpm ON p.id = wpm.product_id
WHERE p.status = 'active'
GROUP BY p.id;

-- Pending sync items (changed since last WooCommerce sync)
CREATE OR REPLACE VIEW `v_pending_woo_sync` AS
SELECT
    p.id,
    p.sku,
    p.name,
    p.updated_at,
    p.last_woo_sync,
    wpm.wc_product_id,
    CASE WHEN wpm.wc_product_id IS NULL THEN 'new' ELSE 'update' END AS sync_action
FROM products p
LEFT JOIN wc_product_map wpm ON p.id = wpm.product_id
WHERE p.status = 'active'
  AND (p.last_woo_sync IS NULL OR p.updated_at > p.last_woo_sync);

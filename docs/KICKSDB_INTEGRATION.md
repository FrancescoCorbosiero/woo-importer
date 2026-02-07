# KicksDB Integration Guide

Complete guide to importing products and maintaining real-time prices from
KicksDB (StockX market data) into WooCommerce.

---

## Architecture Overview

```
                         KicksDB API (api.kicks.dev/v3)
                        /                              \
                       /                                \
          ┌───────────────────┐              ┌──────────────────────┐
          │  PRODUCT IMPORT   │              │  PRICE MAINTENANCE   │
          │  (one-time/batch) │              │  (continuous loop)   │
          └────────┬──────────┘              └──────────┬───────────┘
                   │                                    │
    import-kicksdb.php                      ┌───────────┴──────────┐
          │                                 │                      │
    kicksdb-transform.php           Webhook (real-time)    Cron (fallback)
          │                                 │                      │
    import-wc.php                  webhook-receiver.php   reconcile.php
          │                                 │                      │
          └────────────┬────────────────────┘──────────────────────┘
                       │
                       ▼
                  WooCommerce
              (REST API: products,
               variations, batch)
```

Two separate flows:
1. **Product Import** — Creates products in WC from a list of SKUs
2. **Price Maintenance** — Keeps variant prices updated via webhooks + polling

---

## Prerequisites

### 1. KicksDB PRO Account
- Sign up at [kicks.dev](https://kicks.dev)
- PRO plan required for webhook support
- Note your API key

### 2. WooCommerce Setup
- WC REST API credentials (`ck_` / `cs_` keys)
- Global attributes created: `pa_taglia` (Size), `pa_marca` (Brand)
- Category created: `Sneakers`
- Run `php prepare-taxonomies.php` to set up all taxonomies

### 3. Environment Variables
Copy `.env.example` to `.env` and fill in:

```env
# Required for KicksDB
KICKSDB_API_KEY=your_api_key_here
KICKSDB_MARKET=US

# Required for WooCommerce
WC_URL=https://your-store.com
WC_CONSUMER_KEY=ck_xxx
WC_CONSUMER_SECRET=cs_xxx

# Required for webhooks (production)
KICKSDB_WEBHOOK_CALLBACK_URL=https://your-store.com/api/kicksdb-webhook
KICKSDB_WEBHOOK_SECRET=a_random_secret_string
WC_PRODUCT_WEBHOOK_SECRET=another_random_secret
```

---

## Flow 1: Product Import from KicksDB

### Quick Start

```bash
# 1. Ensure taxonomies exist
php prepare-taxonomies.php --brands=Nike,Adidas,Jordan --verbose

# 2. Import 3 products (dry run first)
php import-kicksdb.php --skus=DD1873-102,CW2288-111,CT8527-100 --dry-run

# 3. Real import
php import-kicksdb.php --skus=DD1873-102,CW2288-111,CT8527-100
```

### Input Formats

**CLI argument:**
```bash
php import-kicksdb.php --skus=DD1873-102,CW2288-111
```

**JSON file:**
```bash
php import-kicksdb.php --skus-file=samples/kicksdb-skus.json
```

**Plain text file (one SKU per line):**
```bash
php import-kicksdb.php --skus-file=samples/kicksdb-skus.txt
```

**CSV file (first column = SKU):**
```bash
php import-kicksdb.php --skus-file=samples/kicksdb-skus.csv
```

**Pipe from stdin:**
```bash
echo "DD1873-102" | php import-kicksdb.php
cat my-skus.txt | php import-kicksdb.php --dry-run
```

### What Happens

For each SKU, the importer:

1. **Fetches** product from KicksDB: `GET /v3/stockx/products/{sku}`
2. **Fetches** variants: `GET /v3/stockx/products/{id}/variants?market=US`
3. **Transforms** to WooCommerce format:
   - Title, brand, description from KicksDB
   - Italian localized descriptions from templates
   - Image sideloaded from StockX CDN
   - EU sizes extracted from variant data
   - Prices calculated: `StockX lowest_ask * margin → selling price`
4. **Imports** via `import-wc.php` (batch create/update)

### Transform-Only Mode

Generate the WC feed without importing (useful for review):

```bash
php import-kicksdb.php --skus-file=skus.json --transform-only --save-feed
# Output: data/feed-kicksdb.json

# Review the feed, then import manually:
php import-wc.php --feed=data/feed-kicksdb.json
```

### CLI Options

| Option | Description |
|--------|-------------|
| `--skus=A,B,C` | Comma-separated SKU list |
| `--skus-file=FILE` | SKUs from file (JSON/TXT/CSV) |
| `--dry-run` | Preview without WC changes |
| `--transform-only` | Fetch + transform only, don't import |
| `--save-feed` | Save WC feed to `data/feed-kicksdb.json` |
| `--limit=N` | Process first N SKUs only |
| `--market=CODE` | KicksDB market (default: US) |
| `--verbose` | Detailed output |

---

## Flow 2: Real-Time Price Updates (Webhooks)

### Setup

#### Step 1: Deploy Webhook Receiver

Point your web server to `pricing/webhook-receiver.php`:

**Nginx:**
```nginx
location /api/kicksdb-webhook {
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /path/to/woo-importer/pricing/webhook-receiver.php;
    include fastcgi_params;
}
```

**Apache (.htaccess):**
```apache
RewriteRule ^api/kicksdb-webhook$ pricing/webhook-receiver.php [L]
```

#### Step 2: Register Initial SKUs

```bash
# Run reconciliation — this syncs your WC inventory with KicksDB
# and creates the webhook automatically on first run
php pricing/reconcile.php --verbose
```

#### Step 3: Verify

```bash
# Check the registry
cat data/sku-registry.json
```

### How It Works

```
KicksDB detects StockX price change
        │
        ▼ POST /api/kicksdb-webhook
┌──────────────────────┐
│  webhook-receiver.php │
│  1. Validate signature│
│  2. Respond 200       │  ← KicksDB gets instant ACK
│  3. Process async     │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  price-updater.php   │
│  1. Find WC product  │
│  2. Match sizes      │
│  3. Calculate margin  │
│  4. Batch PATCH      │
│  5. Alert if anomaly │
└──────────────────────┘
```

---

## Flow 3: Auto-Register New Products

When you import a new product to WooCommerce, automatically register
its SKU with KicksDB for price tracking.

### Setup

1. Go to **WP Admin > WooCommerce > Settings > Advanced > Webhooks**
2. Add webhook:
   - **Name:** KicksDB SKU Registry
   - **Status:** Active
   - **Topic:** Product created
   - **Delivery URL:** `https://your-store.com/api/wc-product-listener`
   - **Secret:** Same as `WC_PRODUCT_WEBHOOK_SECRET` in `.env`
3. Add another webhook for **Product deleted** (same URL)

Deploy `pricing/wc-product-listener.php`:

```nginx
location /api/wc-product-listener {
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /path/to/woo-importer/pricing/wc-product-listener.php;
    include fastcgi_params;
}
```

### What Happens

```
You import a product (via import-kicksdb.php or WC admin)
        │
        ▼ WC fires product.created webhook
┌──────────────────────────┐
│  wc-product-listener.php │
│  1. Extract SKU          │
│  2. Lookup in KicksDB    │
│  3. Add to webhook       │
└──────────────────────────┘
        │
        ▼ KicksDB now tracks this SKU
        → Price changes flow via webhook-receiver.php
```

---

## Flow 4: Reconciliation (Cron Fallback)

Periodic job to catch missed webhooks and verify data integrity.

```bash
# Full reconciliation
php pricing/reconcile.php

# Dry run
php pricing/reconcile.php --dry-run --verbose

# Single product
php pricing/reconcile.php --sku=DD1873-102

# First 10 only
php pricing/reconcile.php --limit=10 --verbose
```

**Recommended cron schedule:**
```cron
# Every 6 hours
0 */6 * * * cd /path/to/woo-importer && php pricing/reconcile.php >> logs/cron.log 2>&1
```

### What It Does

1. **Syncs SKU registry** — compares WC inventory vs KicksDB tracked list
2. **Fetches current prices** — `GET /v3/stockx/products/{sku}/variants` for each SKU
3. **Updates stale prices** — calculates margin, patches WC variations where price differs
4. **Reports anomalies** — sends email alerts for large price swings

---

## KicksDB API Reference

### Endpoints Used

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/v3/stockx/products/{sku}` | GET | Product metadata (title, brand, image) |
| `/v3/stockx/products/{id}/variants` | GET | Size variants with StockX prices |
| `/v3/webhooks` | POST | Register webhook |
| `/v3/webhooks/{id}` | GET/PUT/DELETE | Manage webhook |
| `/v3/webhooks/{id}/products` | POST/DELETE | Add/remove tracked products |

### Product Response Fields

| Field | Type | Example | Used For |
|-------|------|---------|----------|
| `id` | UUID | `50b8dfb8-...` | Webhook registration |
| `sku` | string | `DD1873-102` | WC product SKU |
| `title` | string | `Nike Dunk Low...` | WC product name |
| `brand` | string | `Nike` | `pa_marca` attribute + brand taxonomy |
| `model` | string | `Dunk Low` | Metadata |
| `gender` | string | `women` | Metadata |
| `colorway` | string | `White/Black-White` | Metadata |
| `image` | URL | StockX CDN | WC product image (sideloaded) |
| `description` | string | Product desc | Fallback (Italian template preferred) |

### Variant Response Fields

| Field | Type | Example | Used For |
|-------|------|---------|----------|
| `size_eu` | string | `38` | WC variation `pa_taglia` option |
| `size_us` | string | `7W` | Metadata `_size_us` |
| `size_uk` | string | `4.5` | Metadata `_size_uk` |
| `lowest_ask` | number | `95` | **Base price for margin calculation** |
| `highest_bid` | number | `82` | Not used (informational) |
| `last_sale` | number | `90` | Not used (informational) |
| `barcode` | string | `0196152912549` | Metadata `_barcode` |

---

## Sample Files

| File | Description |
|------|-------------|
| `samples/kicksdb-skus.json` | JSON array of 5 real SKUs |
| `samples/kicksdb-skus.txt` | Plain text, one SKU per line |
| `samples/kicksdb-skus.csv` | CSV with SKU + name + notes |
| `samples/kicksdb-api-response.json` | Mock product response |
| `samples/kicksdb-variants-response.json` | Mock variants response (11 sizes) |
| `samples/kicksdb-webhook-payload.json` | Mock price_change webhook payload |
| `samples/wc-feed-from-kicksdb.json` | Expected WC output with margin pricing |

---

## Troubleshooting

### Product not found in KicksDB
- Verify the SKU is a valid StockX style code (e.g. `DD1873-102`)
- Try searching: KicksDB uses style codes, not custom/internal SKUs
- Check your API key has access to StockX endpoints

### Prices not updating
1. Check `logs/webhook-receiver.log` for incoming events
2. Check `logs/reconcile.log` for cron output
3. Verify webhook is registered: check `data/sku-registry.json`
4. Run manual reconciliation: `php pricing/reconcile.php --sku=DD1873-102 --verbose`

### Webhook signature errors
- Ensure `KICKSDB_WEBHOOK_SECRET` matches what KicksDB has on file
- Check `X-KicksDB-Signature` header is being passed through your reverse proxy

### Rate limiting
- KicksDB has request quotas per plan
- The client has built-in retry with exponential backoff
- Sequential lookups have 200ms delays between requests
- Batch reconciliation processes are the heaviest — schedule during off-hours

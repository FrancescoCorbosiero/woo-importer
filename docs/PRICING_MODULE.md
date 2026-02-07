# Pricing Module Reference

Technical reference for the variant pricing system.

**Namespace:** `ResellPiacenza\Pricing\*`, `ResellPiacenza\KicksDb\Client`

---

## Module Architecture

```
src/
├── KicksDb/
│   └── Client.php             # KicksDB v3 API wrapper
├── Pricing/
│   ├── PriceCalculator.php    # Margin engine
│   ├── PriceUpdater.php       # WC variation price patcher
│   └── SkuRegistry.php        # WC SKU ↔ KicksDB tracking sync
└── Support/
    ├── Config.php             # Configuration singleton
    └── LoggerFactory.php      # Logger factory

bin/
└── pricing-reconcile          # CLI: Cron job (polling fallback)

public/
├── kicksdb-webhook.php        # HTTP endpoint (KicksDB → price update)
└── wc-product-listener.php    # HTTP endpoint (WC → auto-register SKU)
```

---

## Price Calculation

### Formula

```
selling_price = round_up( max( market_price * (1 + margin%), floor_price ) )
```

### Margin Strategies

Three strategies stack together:

#### 1. Flat Margin (default)

```env
PRICING_FLAT_MARGIN=25
```

Every product gets +25% on top of StockX lowest ask.

#### 2. Tiered Margins

Different margins for different price ranges. Configure via `.env`:

```env
PRICING_TIERS=[{"min":0,"max":100,"margin":35},{"min":100,"max":200,"margin":28},{"min":200,"max":500,"margin":22},{"min":500,"max":null,"margin":18}]
```

Or leave empty to use flat margin for all:
```env
PRICING_TIERS=
```

**Default tiers (when PRICING_TIERS is empty):**

| Market Price | Margin | Example: $80 → | Example: $150 → | Example: $300 → |
|---|---|---|---|---|
| $0 – $100 | +35% | $108 | — | — |
| $100 – $200 | +28% | — | $192 | — |
| $200 – $500 | +22% | — | — | $366 |
| $500+ | +18% | — | — | — |

**Logic:** tiers take precedence when configured. If the price falls in a tier range, that tier's margin is used. If no tier matches, flat margin is used as fallback.

#### 3. Floor Price

```env
PRICING_FLOOR_PRICE=59
```

Absolute minimum selling price. If the calculated price (after margin) is below this, the floor price is used instead. Set to `0` to disable.

### Rounding

```env
PRICING_ROUNDING=whole
```

| Strategy | Behavior | Example: $108.25 |
|---|---|---|
| `whole` | `ceil()` to integer | $109 |
| `half` | `ceil()` to nearest .50 | $108.50 |
| `none` | Round to 2 decimals | $108.25 |

### Worked Examples

Given default tiers and floor price of $59:

| StockX Ask | Tier | Margin | Raw Price | Floor? | Rounded | Final |
|---|---|---|---|---|---|---|
| $40 | 0-100 | +35% | $54.00 | YES | — | **$59** |
| $82 | 0-100 | +35% | $110.70 | No | ceil | **$111** |
| $95 | 0-100 | +35% | $128.25 | No | ceil | **$129** |
| $105 | 100-200 | +28% | $134.40 | No | ceil | **$135** |
| $150 | 100-200 | +28% | $192.00 | No | ceil | **$192** |
| $250 | 200-500 | +22% | $305.00 | No | ceil | **$305** |
| $600 | 500+ | +18% | $708.00 | No | ceil | **$708** |

---

## Price Updater

### Idempotent Updates

The updater compares calculated price vs current WC price. If the
difference is < $0.01, the update is skipped. This prevents unnecessary
API calls and WC revision history bloat.

### Size Matching

Variants are matched by EU size:

```
KicksDB variant.size_eu = "38"  →  WC variation attribute pa_taglia = "38"
```

Fallback: extracts size from variation SKU pattern `{base}-{size}`.

### Batch Updates

Updates are batched via WC REST API:

```
POST /wp-json/wc/v3/products/{id}/variations/batch
{ "update": [{"id": 123, "regular_price": "129"}, ...] }
```

Max 100 variations per batch request (configurable via `PRICING_BATCH_SIZE`).

---

## Email Alerts

### Configuration

```env
PRICING_ALERT_THRESHOLD=30
PRICING_ALERT_EMAIL=your@email.com
```

### When Alerts Fire

An alert is sent when a price change exceeds the threshold percentage:

```
|new_price - old_price| / old_price * 100 >= threshold
```

Example with 30% threshold:
- Old price: $150, New price: $100 → 33% drop → ALERT
- Old price: $150, New price: $120 → 20% drop → no alert
- Old price: $100, New price: $140 → 40% increase → ALERT

### Alert Email Content

```
Subject: [ResellPiacenza] Price DROP: DD1873-102 size 38 (33.3%)

Product: Nike Dunk Low Next Nature White Black Panda (Women's)
SKU: DD1873-102
Size: 38

Price Change: DROP (33.3%)
  Old Price: €150.00
  New Price: €100.00

Breakdown:
  Market Price (StockX): €74.07
  Margin Applied: 35% (tier_0)
  Floor Price Applied: No
```

### Disabling Alerts

Set threshold to 0 or leave email empty:
```env
PRICING_ALERT_THRESHOLD=0
# or
PRICING_ALERT_EMAIL=
```

---

## SKU Registry

### Storage

The registry is persisted at `data/sku-registry.json`:

```json
{
  "skus": {
    "DD1873-102": {"id": 456, "name": "Nike Dunk Low..."},
    "CW2288-111": {"id": 457, "name": "Nike Air Force 1..."}
  },
  "webhook_id": "wh_abc123",
  "last_sync": "2026-02-07T10:00:00+00:00"
}
```

### Sync Flow

```
fetchWcSkus()        →  GET /wc/v3/products (all variable, published)
loadRegistry()       →  Read data/sku-registry.json
diff()               →  New SKUs = in WC but not registry
                        Removed = in registry but not WC
registerSkus()       →  POST /v3/webhooks/{id}/products (add new)
unregisterSkus()     →  DELETE /v3/webhooks/{id}/products (remove old)
saveRegistry()       →  Write data/sku-registry.json
```

### First Run

On first run (no `webhook_id` exists), the registry creates a new
KicksDB webhook automatically and saves the ID.

---

## Reconciliation Cron

### Purpose

1. **Catch missed webhooks** — KicksDB may fail to deliver
2. **Initial backfill** — New products need prices set
3. **Data integrity** — Verify webhook data matches reality
4. **SKU registry sync** — Ensure tracking list is up to date

### Usage

```bash
# Full reconciliation (recommended: every 6h)
php bin/pricing-reconcile

# Dry run
php bin/pricing-reconcile --dry-run --verbose

# Single product debug
php bin/pricing-reconcile --sku=DD1873-102 --verbose

# Batch with limit
php bin/pricing-reconcile --limit=50

# Skip registry sync (prices only)
php bin/pricing-reconcile --skip-registry
```

### Cron Setup

```cron
# Reconcile every 6 hours
0 */6 * * * cd /path/to/woo-importer && php bin/pricing-reconcile >> logs/cron.log 2>&1

# Or every 12 hours if API quota is limited
0 0,12 * * * cd /path/to/woo-importer && php bin/pricing-reconcile >> logs/cron.log 2>&1
```

### API Usage

Each reconciliation run makes:
- 1 paginated fetch for WC products (registry sync)
- 2 KicksDB API calls per SKU (product + variants)
- 1 WC batch call per product with price changes

For 100 products: ~200 KicksDB API calls + ~100 WC API calls.

---

## Log Files

| File | Source | Retention |
|------|--------|-----------|
| `logs/webhook-receiver.log` | KicksDB webhook events | 14 days |
| `logs/wc-product-listener.log` | WC product webhooks | 7 days |
| `logs/reconcile.log` | Cron reconciliation runs | 7 days |
| `logs/import-kicksdb.log` | Product imports | 7 days |

All logs use Monolog `RotatingFileHandler` with automatic cleanup.

---

## Environment Variables Reference

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `KICKSDB_API_KEY` | Yes | — | KicksDB API key |
| `KICKSDB_BASE_URL` | No | `https://api.kicks.dev/v3` | API base URL |
| `KICKSDB_MARKET` | No | `US` | Market for variant prices |
| `KICKSDB_WEBHOOK_ID` | No | auto | Existing webhook ID |
| `KICKSDB_WEBHOOK_CALLBACK_URL` | For webhooks | — | Your webhook endpoint URL |
| `KICKSDB_WEBHOOK_SECRET` | For webhooks | — | HMAC signature secret |
| `WC_PRODUCT_WEBHOOK_SECRET` | For auto-reg | — | WC webhook signature secret |
| `PRICING_FLAT_MARGIN` | No | `25` | Default margin % |
| `PRICING_TIERS` | No | see config | JSON tiered margins |
| `PRICING_FLOOR_PRICE` | No | `59` | Minimum selling price |
| `PRICING_ROUNDING` | No | `whole` | Rounding strategy |
| `PRICING_ALERT_THRESHOLD` | No | `30` | Alert threshold % |
| `PRICING_ALERT_EMAIL` | No | — | Alert email address |
| `PRICING_BATCH_SIZE` | No | `100` | WC batch size |

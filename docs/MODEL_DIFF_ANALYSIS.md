# Model Comparison: Current Import vs WooCommerce Full Schema

## Summary

| Metric | Current Model | WooCommerce Full | Coverage |
|--------|---------------|------------------|----------|
| **Product Fields (writable)** | 8 | 40 | 20% |
| **Variation Fields (writable)** | 7 | 26 | 27% |
| **Media Fields** | 4 | 4 | 100% |

---

## 🟢 Currently Used Fields (19 total)

### Product Level (8 fields)
| Field | Source | Value |
|-------|--------|-------|
| `name` | API | Product name |
| `type` | Hard-coded | "variable" |
| `sku` | API | Product SKU |
| `status` | Hard-coded | "publish" |
| `catalog_visibility` | Hard-coded | "visible" |
| `short_description` | Template | Italian generic text |
| `description` | Template | Italian generic HTML |
| `categories` | Auto-detected | Sneakers/Abbigliamento |
| `attributes` | API + Config | Marca, Taglia |
| `images` | Pre-uploaded | Media ID reference |

### Variation Level (7 fields)
| Field | Source | Value |
|-------|--------|-------|
| `sku` | Constructed | `{parent_sku}-{size_eu}` |
| `regular_price` | API + Markup | Calculated price |
| `manage_stock` | Hard-coded | true |
| `stock_quantity` | API | Available quantity |
| `stock_status` | Calculated | instock/outofstock |
| `attributes` | API | Taglia value |
| `meta_data` | API | `_size_us`, `_barcode` |

### Media/Images (4 fields)
| Field | Source | Status |
|-------|--------|--------|
| `title` | API | ✅ Complete |
| `alt_text` | Template | ✅ SEO optimized |
| `caption` | Template | ✅ Complete |
| `description` | Template | ✅ SEO optimized |

---

## 🔑 Identifier Strategy

| Identifier | Role | Availability | Use Case |
|------------|------|--------------|----------|
| **SKU** | **Primary** | Always available | Order management, product lookup, variation naming |
| **GTIN/Barcode** | Secondary | Often missing | Google Shopping, Merchant Center (when available) |

**Variation SKU format:** `{parent_sku}-{size_eu}` → `DD1873-102-38`

---

## 🔴 Missing Fields - HIGH SEO IMPACT

These fields significantly affect search rankings and rich snippets:

### 1. `tags`
**Impact:** ⭐⭐⭐⭐ Keyword association & internal linking
```json
{
  "tags": [
    {"id": 1, "name": "Nike", "slug": "nike"},
    {"id": 2, "name": "Dunk Low", "slug": "dunk-low"},
    {"id": 3, "name": "Donna", "slug": "donna"},
    {"id": 4, "name": "Bianco", "slug": "bianco"},
    {"id": 5, "name": "Nero", "slug": "nero"}
  ]
}
```
**Recommendation:** Parse product name to extract: brand, model, color, gender

### 3. `slug`
**Impact:** ⭐⭐⭐⭐ URL structure for SEO
```json
{
  "slug": "nike-dunk-low-next-nature-white-black-panda-donna-dd1873-102"
}
```
**Note:** Currently auto-generated - customize for better URLs

### 4. `global_unique_id` (GTIN/Barcode) - OPTIONAL
**Impact:** ⭐⭐⭐ Bonus for Google Shopping (when available)
```json
{
  "global_unique_id": "0196152912548"
}
```
**Note:** Only set when `barcode` is provided by feed. SKU remains primary identifier.

---

## 🟡 Missing Fields - MEDIUM SEO IMPACT

### 5. `weight`
**Impact:** ⭐⭐⭐ Shipping schema & product info
```json
{
  "weight": "0.8"  // in kg typically
}
```
**Note:** Could estimate based on shoe type/size

### 6. `reviews_allowed`
**Impact:** ⭐⭐⭐ User-generated content signals
```json
{
  "reviews_allowed": true
}
```
**Note:** Currently using WooCommerce default (true)

### 7. More Categories (hierarchical)
**Impact:** ⭐⭐⭐ Site structure & breadcrumbs
```json
{
  "categories": [
    {"id": 10},  // Sneakers
    {"id": 15},  // Nike
    {"id": 20}   // Donna
  ]
}
```
**Recommendation:** Create category hierarchy: Brand > Gender > Model line

### 8. `upsell_ids` / `cross_sell_ids`
**Impact:** ⭐⭐⭐ Internal linking & user engagement
```json
{
  "upsell_ids": [123, 124, 125],
  "cross_sell_ids": [200, 201]
}
```
**Note:** Could auto-link same brand/similar styles

---

## 🟠 Missing Fields - LOW SEO IMPACT (but professional)

### 9. `dimensions` (length, width, height)
**Impact:** ⭐⭐ Shipping info completeness
```json
{
  "dimensions": {
    "length": "35",
    "width": "22",
    "height": "14"
  }
}
```

### 10. `featured`
**Impact:** ⭐⭐ Internal catalog management
```json
{
  "featured": true  // for bestsellers
}
```

### 11. `menu_order`
**Impact:** ⭐ Custom sorting
```json
{
  "menu_order": 0
}
```

### 12. `purchase_note`
**Impact:** ⭐ Post-purchase experience
```json
{
  "purchase_note": "Grazie per l'acquisto! Conserva la scatola originale per eventuali resi."
}
```

### 13. `default_attributes`
**Impact:** ⭐ UX - pre-selects most popular size
```json
{
  "default_attributes": [
    {"name": "Taglia", "option": "42"}
  ]
}
```

### 14. `backorders`
**Impact:** ⭐ Inventory policy
```json
{
  "backorders": "notify"
}
```

### 15. `low_stock_amount`
**Impact:** ⭐ Inventory alerts
```json
{
  "low_stock_amount": 2
}
```

---

## 🔵 Theme-Specific vs WooCommerce-Only

You asked about Shoptimizer theme fields. Here's the distinction:

### WooCommerce Core (via REST API)
All fields listed above are **WooCommerce core** - they work regardless of theme.

### Theme-Specific Fields (Shoptimizer)
These require `meta_data` or theme-specific APIs:

| Feature | Type | Likely Meta Key |
|---------|------|-----------------|
| Trust badges | meta_data | `_shoptimizer_trust_badge` |
| Countdown timer | meta_data | `_sale_countdown` |
| Video gallery | meta_data | `_product_video_url` |
| Size guide popup | meta_data | `_size_guide_id` |
| Sticky add-to-cart | theme setting | N/A |
| Quick view | theme setting | N/A |
| FOMO badges | theme setting | N/A |

**To discover Shoptimizer-specific meta keys:**
1. Create a test product in WooCommerce admin
2. Fill all Shoptimizer product options
3. Query via REST API: `GET /wp-json/wc/v3/products/{id}`
4. Check the `meta_data` array for theme-specific keys

---

## 📊 Priority Implementation Roadmap

### Phase 1: Critical SEO (Native WooCommerce Fields)
1. Generate smart `tags` from product name parsing (brand, model, color, gender)
2. Custom `slug` generation: `{product-name-slugified}-{sku}`
3. Add `pa_colore` and `pa_genere` attributes (parsed from name)

### Phase 2: Enhanced Structure
4. Category hierarchy (Brand > Gender > Type)
5. Related products linking (upsell_ids, cross_sell_ids)
6. Add estimated `weight` and `dimensions`

### Phase 3: Professional Polish
7. `purchase_note` in Italian
8. `default_attributes` (most common size)
9. `backorders` and `low_stock_amount` policies

### Phase 4: Optional Enhancements
10. Map `barcode` → `global_unique_id` (only when available from feed)
11. Research Shoptimizer theme meta keys if needed

---

## 🚫 SEO Plugin Agnostic Approach

**Do NOT use plugin-specific meta keys like:**
- `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`
- `rank_math_title`, `rank_math_description`

**Instead, rely on native WooCommerce fields:**
| SEO Element | Native WC Source |
|-------------|------------------|
| Title Tag | `product.name` (plugins auto-generate) |
| Meta Description | `product.short_description` |
| H1 | `product.name` (theme renders) |
| URL | `product.slug` |
| Image SEO | `images[].alt`, `images[].name` |
| Structured Data | `sku`, `price`, `stock_status`, `categories` |
| Breadcrumbs | `categories` (hierarchical) |

SEO plugins will auto-generate from these native fields.

---

## 📁 Files Created

| File | Description |
|------|-------------|
| `docs/CURRENT_MODEL.json` | Full JSON schema of current import |
| `docs/CURRENT_MODEL.csv` | CSV with all fields and status |
| `docs/WOOCOMMERCE_COMPLETE_MODEL.json` | Complete WC REST API schema |
| `docs/WOOCOMMERCE_COMPLETE_MODEL.csv` | CSV with all WC fields + SEO impact |
| `docs/MODEL_DIFF_ANALYSIS.md` | This analysis document |

---

## Sources

- [WooCommerce REST API Documentation](https://woocommerce.github.io/woocommerce-rest-api-docs/)
- [WooCommerce Developer Docs](https://developer.woocommerce.com/docs/apis/rest-api/)
- [Product Data Schema (GitHub Wiki)](https://github.com/woocommerce/woocommerce/wiki/Product-Data-Schema)

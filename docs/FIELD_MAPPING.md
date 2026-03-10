# Field Mapping — Truth Table

Complete mapping of how data flows across all pipeline stages.

## Pipeline Overview

```
CSV ──→ catalog.json ──→ catalog-fetch ──→ assortment.json ──→ catalog-build ──→ WC Product
                          (curated)

brand-catalog.json ──→ kicksdb-discover ──→ assortment.json ──→ catalog-build ──→ WC Product
                        (discovery)
```

## 1. CSV Columns → catalog.json Structure

Only 4 columns matter for the importer. The rest are reference-only.

| CSV Column | Used by importer | Purpose |
|-----------|-----------------|---------|
| Sezione | Yes | Determines section (Sneakers/Abbigliamento/Accessori) |
| Marca | Yes | Brand name. Empty for Accessori |
| Sottocategoria | Yes | Subcategory/item name. Empty for Abbigliamento |
| SKU | Yes | Product identifier. Empty rows define structure only |
| Titolo | No | Product title from KicksDB (reference) |
| Query | No | KicksDB search query used to find product (reference) |
| Taglie | No | Sizes (reference) |
| Prezzo | No | Price (reference) |
| Prezzo Scontato | No | Sale price (reference) |

### Per-section CSV → JSON mapping

| Section | CSV Marca | CSV Sottocategoria | JSON structure |
|---------|-----------|-------------------|----------------|
| Sneakers | Nike | Nike Dunk | `sections[].brands[].subcategories[].products[]` |
| Abbigliamento | Supreme | _(empty)_ | `sections[].brands[].products[]` |
| Accessori | _(empty)_ | Beanie | `sections[].items[].products[]` |

### Detection logic in csv-import

```
if (empty Marca && non-empty Sottocategoria) → Accessori-style (items[])
if (non-empty Marca && non-empty Sottocategoria) → Sneakers-style (brands[].subcategories[])
if (non-empty Marca && empty Sottocategoria) → Abbigliamento-style (brands[].products[])
```

## 2. catalog.json → Assortment Metadata (catalog-fetch)

| catalog.json source | Assortment field | Sneakers | Abbigliamento | Accessori |
|---------------------|-----------------|----------|---------------|-----------|
| section.slug | catalog_section | `sneakers` | `abbigliamento` | `accessori` |
| section.wc_category | catalog_wc_category | `sneakers` | `clothing` | `accessories` |
| brand.name | catalog_brand | `Nike` | `Supreme` | _(empty)_ |
| subcategory.name | catalog_subcategory | `Nike Dunk` | brand.name (`Supreme`) | item.name (`Beanie`) |
| _(walk order)_ | _catalog_position | 0, 1, 2... | N, N+1... | M, M+1... |
| _(hardcoded)_ | catalog_discovery | `curated` | `curated` | `curated` |

## 3. brand-catalog.json → Assortment Metadata (kicksdb-discover)

| brand-catalog.json source | Assortment field | Sneakers (brand mode) | Abbigliamento (brand mode) | Accessori (query mode) |
|--------------------------|-----------------|----------------------|---------------------------|----------------------|
| section.slug | catalog_section | `sneakers` | `abbigliamento` | `accessori` |
| section.wc_category | catalog_wc_category | `sneakers` | `clothing` | `accessories` |
| section.discovery | catalog_discovery | `brand` | `brand` | `query` |
| brand.name | catalog_brand | `Nike` | `Supreme` | _(empty)_ |
| query label | catalog_subcategory | `Nike Dunk` | `Supreme Hoodie` | `beanie` |
| _(not set)_ | _catalog_position | _(absent)_ | _(absent)_ | _(absent)_ |

## 4. Assortment → WC Product (postBuild callback)

### Catalog provenance meta_data

| Assortment field | WC meta_data key | Example value |
|-----------------|------------------|---------------|
| catalog_section | `_catalog_section` | `sneakers` |
| catalog_discovery | `_catalog_discovery` | `curated`, `brand`, `query`, `gs_enriched`, `gs_only` |
| catalog_wc_category | `_catalog_wc_category` | `sneakers`, `clothing`, `accessories` |
| catalog_brand | `_catalog_brand` | `Nike`, `Supreme`, _(empty)_ |
| catalog_subcategory | `_catalog_subcategory` | `Nike Dunk`, `Supreme`, `Beanie` |
| gs_tracked / discovery type | `_gs_catalog` | `1` (only if GS product) |

### Sub-category assignment (WC categories[])

| Section | Method | Input | Match against | Result |
|---------|--------|-------|---------------|--------|
| Sneakers | Direct slug | `catalog_subcategory` = "Nike Dunk" | slugify → `nike-dunk` → taxonomy-map lookup | `categories[] += {id: subcategory_id}` |
| Abbigliamento | Keyword match | `catalog_subcategory + product name` | subcategories[].keywords[] | `categories[] += {id: matched_keyword_subcat_id}` |
| Accessori | Direct slug | `catalog_subcategory` = "Beanie" | slugify → `beanie` → taxonomy-map lookup | `categories[] += {id: subcategory_id}` |

### Brand enrichment (WC brands[])

Applies when: `catalog_brand` is non-empty AND `catalog_discovery` is `brand` or `curated`

| Section | Has keyword subcats? | Parent brand | Sub-brand |
|---------|---------------------|-------------|-----------|
| Sneakers | No | Nike (parent) | Nike Dunk (sub-brand under Nike) |
| Abbigliamento | Yes | Supreme (parent) | _(skipped — keyword subcats prevent sub-brand creation)_ |
| Accessori | N/A | _(skipped — no catalog_brand)_ | _(skipped)_ |

### menu_order assignment

| Mode | Sort method | Source |
|------|------------|--------|
| Curated | `_catalog_position` ascending | catalog.json walk order (= CSV row order) |
| Curated fallback (GS-only) | `release_date` descending | KicksDB API |
| Discovery | `release_date` descending | KicksDB API |

## 5. Complete Per-Section Truth Table

### Sneakers

| Stage | Field | Value |
|-------|-------|-------|
| CSV | Sezione | `Sneakers` |
| CSV | Marca | `Nike` |
| CSV | Sottocategoria | `Nike Dunk` |
| CSV | SKU | `DD1391-100` |
| catalog.json | structure | `sections[].brands[].subcategories[].products[]` |
| assortment | catalog_brand | `Nike` |
| assortment | catalog_subcategory | `Nike Dunk` |
| assortment | catalog_section | `sneakers` |
| assortment | catalog_wc_category | `sneakers` |
| assortment | catalog_discovery | `curated` (or `brand` in discovery) |
| assortment | _catalog_position | `0` (curated only) |
| WC | categories[] | Sneakers (parent) + Nike Dunk (sub, direct slug) |
| WC | brands[] | Nike (parent) + Nike Dunk (sub-brand) |
| WC | menu_order | `0` (from _catalog_position) |

### Abbigliamento

| Stage | Field | Value |
|-------|-------|-------|
| CSV | Sezione | `Abbigliamento` |
| CSV | Marca | `Supreme` |
| CSV | Sottocategoria | _(empty)_ |
| CSV | SKU | `SUP-SKU-1` |
| catalog.json | structure | `sections[].brands[].products[]` |
| catalog.json | subcategories[] | keyword-based (T-Shirt, Felpe, Giacche, Pantaloni) |
| assortment | catalog_brand | `Supreme` |
| assortment | catalog_subcategory | `Supreme` (= brand name, for keyword matching) |
| assortment | catalog_section | `abbigliamento` |
| assortment | catalog_wc_category | `clothing` |
| assortment | catalog_discovery | `curated` (or `brand` in discovery) |
| WC | categories[] | Abbigliamento (parent) + T-Shirt/Felpe/etc (keyword match on product title) |
| WC | brands[] | Supreme (parent only, NO sub-brand due to keyword subcats) |
| WC | menu_order | from _catalog_position |

### Accessori

| Stage | Field | Value |
|-------|-------|-------|
| CSV | Sezione | `Accessori` |
| CSV | Marca | _(empty)_ |
| CSV | Sottocategoria | `Beanie` |
| CSV | SKU | `BEANIE-SKU-1` |
| catalog.json | structure | `sections[].items[].products[]` |
| assortment | catalog_brand | _(empty)_ |
| assortment | catalog_subcategory | `Beanie` |
| assortment | catalog_section | `accessori` |
| assortment | catalog_wc_category | `accessories` |
| assortment | catalog_discovery | `curated` (or `query` in discovery) |
| WC | categories[] | Accessori (parent) + Beanie (sub, direct slug) |
| WC | brands[] | _(none — no catalog_brand)_ |
| WC | menu_order | from _catalog_position |

## 6. Curated vs Discovery Mode Differences

| Aspect | Curated (catalog.json) | Discovery (brand-catalog.json) |
|--------|----------------------|-------------------------------|
| Step 1 script | `bin/catalog-fetch` | `bin/kicksdb-discover` |
| Product selection | Explicit SKUs | Query-based search results |
| catalog_discovery | `curated` | `brand` or `query` |
| _catalog_position | Set (walk order) | Not set |
| menu_order source | Catalog position (CSV row order) | Release date (newest first) |
| Product churn | None (all explicit) | Possible (rank fluctuation, _missed_discoveries) |
| API calls | 1 per SKU (getStockXProduct) | Paginated search (browseProducts) |
| Filtering | None (trust the catalog) | Type filter + relevance filter + GS dedup |
| Sort in KicksDB API | N/A (per-SKU lookup) | `KICKSDB_DISCOVERY_SORT` (default: rank) |

<?php

namespace ResellPiacenza\Import;

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use ResellPiacenza\KicksDb\Client as KicksDbClient;
use ResellPiacenza\Pricing\PriceCalculator;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * GS Catalog Sync Pipeline
 *
 * Simple, self-contained pipeline:
 *   1. Fetch GS feed (source of truth)
 *   2. Fetch current WC products
 *   3. Diff (new / changed / gone)
 *   4. New products → enrich from KicksDB, fallback to GS-only → create in WC
 *   5. Changed products → patch WC variations (price + stock)
 *   6. Gone products → leave alone (GS removals are not our concern)
 *
 * No catalog.json, no discovery, no complicated merging.
 * GS feed IS the catalog.
 *
 * @package ResellPiacenza\Import
 */
class GsCatalogSync
{
    private array $config;
    private Logger $logger;
    private Client $wc_client;

    private bool $dry_run;
    private bool $verbose;
    private ?int $limit;
    private bool $skip_kicksdb;

    // GS sale pricing
    private bool $gs_sale_enabled;
    private float $gs_sale_markup;

    private array $stats = [
        'gs_products'         => 0,
        'wc_products'         => 0,
        'new_products'        => 0,
        'changed_products'    => 0,
        'unchanged_products'  => 0,
        'kicksdb_enriched'    => 0,
        'kicksdb_missed'      => 0,
        'products_created'    => 0,
        'variations_patched'  => 0,
        'errors'              => 0,
    ];

    /**
     * @param array $config Full config from config.php
     * @param array $options CLI options: dry_run, verbose, limit, skip_kicksdb
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->skip_kicksdb = $options['skip_kicksdb'] ?? false;

        $gs_sale = $config['gs_sale_pricing'] ?? [];
        $this->gs_sale_enabled = !empty($gs_sale['enabled']);
        $this->gs_sale_markup = (float) ($gs_sale['markup_percentage'] ?? 15.0);

        $this->logger = LoggerFactory::create('GsCatalogSync', [
            'file' => Config::projectRoot() . '/logs/gs-catalog-sync.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);

        $this->setupWooCommerceClient();
    }

    /**
     * Run the full pipeline
     */
    public function run(): bool
    {
        $start = microtime(true);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  GS Catalog Sync');
        $this->logger->info('========================================');
        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }
        $this->logger->info('');

        try {
            // Step 1: Fetch GS feed
            $gs_products = $this->fetchGsFeed();
            if (empty($gs_products)) {
                $this->logger->error('GS feed is empty, aborting.');
                return false;
            }

            // Step 2: Fetch current WC products
            $wc_products = $this->fetchWcProducts();

            // Step 3: Diff
            [$new_skus, $changed, $unchanged] = $this->diff($gs_products, $wc_products);

            $this->stats['new_products'] = count($new_skus);
            $this->stats['changed_products'] = count($changed);
            $this->stats['unchanged_products'] = $unchanged;

            $this->logger->info('');
            $this->logger->info("Diff: " . count($new_skus) . " new, " . count($changed) . " changed, {$unchanged} unchanged");

            if (empty($new_skus) && empty($changed)) {
                $this->logger->info('Nothing to do.');
                $this->printSummary($start);
                return true;
            }

            // Step 4: Create new products (KicksDB enrich → WcProductBuilder → WooCommerceImporter)
            if (!empty($new_skus)) {
                $this->createNewProducts($new_skus, $gs_products);
            }

            // Step 5: Patch changed variations
            if (!empty($changed)) {
                $this->patchChangedVariations($changed, $wc_products);
            }

            // Step 6: Update GS tracking snapshot
            $this->updateTrackingSnapshot($gs_products);

            $this->printSummary($start);
            return $this->stats['errors'] === 0;

        } catch (\Exception $e) {
            $this->logger->error('Pipeline error: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Step 1: Fetch GS Feed
    // =========================================================================

    /**
     * Fetch and normalize the GS feed
     *
     * @return array SKU => normalized product
     */
    private function fetchGsFeed(): array
    {
        $this->logger->info('Step 1: Fetching GS feed...');

        $adapter = new GoldenSneakersAdapter($this->config, $this->logger, $this->limit);
        $products = [];

        foreach ($adapter->fetchProducts() as $p) {
            $products[$p['sku']] = $p;
        }

        $this->stats['gs_products'] = count($products);
        $this->logger->info("  {$this->stats['gs_products']} products from GS");

        return $products;
    }

    // =========================================================================
    // Step 2: Fetch WC Products (with variation snapshots)
    // =========================================================================

    /**
     * Fetch all current WC products with their variation price/stock data
     *
     * @return array SKU => {id, variations: {size => {price, stock_quantity, stock_status, variation_id}}}
     */
    private function fetchWcProducts(): array
    {
        $this->logger->info('');
        $this->logger->info('Step 2: Fetching current WC products...');

        $products = [];
        $page = 1;
        $retries = 0;

        do {
            try {
                $batch = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($batch as $product) {
                    $sku = $product->sku ?? '';
                    if (empty($sku)) {
                        continue;
                    }
                    $products[$sku] = [
                        'id' => $product->id,
                        'type' => $product->type ?? 'simple',
                        'variations' => [], // populated lazily during diff
                    ];
                }

                $page++;
                $retries = 0;
            } catch (\Exception $e) {
                $retries++;
                if ($retries >= 3) {
                    $this->logger->error("  Error fetching products page {$page} after 3 retries: " . $e->getMessage());
                    $page++;
                    $retries = 0;
                } else {
                    $this->logger->warning("  Retrying page {$page} ({$retries}/3)...");
                    usleep(500000);
                    continue;
                }
            }
        } while (count($batch ?? []) === 100);

        $this->stats['wc_products'] = count($products);
        $this->logger->info("  {$this->stats['wc_products']} products in WooCommerce");

        return $products;
    }

    // =========================================================================
    // Step 3: Diff
    // =========================================================================

    /**
     * Compare GS feed against WC inventory
     *
     * @return array [new_skus[], changed[sku => var_changes], unchanged_count]
     */
    private function diff(array $gs_products, array &$wc_products): array
    {
        $this->logger->info('');
        $this->logger->info('Step 3: Computing diff...');

        $new_skus = [];
        $changed = [];
        $unchanged = 0;

        foreach ($gs_products as $sku => $gs_product) {
            if (!isset($wc_products[$sku])) {
                $new_skus[] = $sku;
                continue;
            }

            // Product exists in WC — check for price/stock changes
            $wc = &$wc_products[$sku];
            $product_id = $wc['id'];

            // Fetch WC variations for this product (lazy load)
            $wc_vars = $this->fetchWcVariations($product_id, $wc['type']);
            $wc['variations'] = $wc_vars;

            $var_changes = [];
            foreach ($gs_product['variations'] ?? [] as $gs_var) {
                $size = $gs_var['size_eu'] ?? '';
                if (empty($size)) {
                    continue;
                }

                $gs_price = (float) ($gs_var['price'] ?? 0);
                $gs_stock = (int) ($gs_var['stock_quantity'] ?? 0);
                $gs_status = $gs_var['stock_status'] ?? 'outofstock';

                $wc_var = $wc_vars[$size] ?? null;
                if ($wc_var === null) {
                    // New size — will be handled by variation patching
                    $var_changes[$size] = [
                        'price' => $gs_price,
                        'stock_quantity' => $gs_stock,
                        'stock_status' => $gs_status,
                        'reason' => 'new_size',
                    ];
                    continue;
                }

                $price_changed = abs(($wc_var['price'] ?? 0) - $gs_price) > 0.01;
                $stock_changed = ($wc_var['stock_quantity'] ?? 0) !== $gs_stock;

                if ($price_changed || $stock_changed) {
                    $var_changes[$size] = [
                        'price' => $gs_price,
                        'stock_quantity' => $gs_stock,
                        'stock_status' => $gs_status,
                        'reason' => $price_changed && $stock_changed ? 'price+stock'
                            : ($price_changed ? 'price' : 'stock'),
                    ];
                }
            }

            if (!empty($var_changes)) {
                $changed[$sku] = $var_changes;
            } else {
                $unchanged++;
            }
        }

        return [$new_skus, $changed, $unchanged];
    }

    /**
     * Fetch WC variation price/stock snapshot for a product
     *
     * @return array size => {price, stock_quantity, stock_status, variation_id}
     */
    private function fetchWcVariations(int $product_id, string $type): array
    {
        // Simple products: read price/stock from the product itself
        if ($type === 'simple') {
            try {
                $product = $this->wc_client->get("products/{$product_id}");
                return [
                    'One Size' => [
                        'price' => (float) ($product->regular_price ?? $product->price ?? 0),
                        'stock_quantity' => (int) ($product->stock_quantity ?? 0),
                        'stock_status' => $product->stock_status ?? 'outofstock',
                        'variation_id' => null, // simple product, patch the product itself
                    ],
                ];
            } catch (\Exception $e) {
                $this->logger->debug("  Failed to fetch simple product {$product_id}: " . $e->getMessage());
                return [];
            }
        }

        try {
            $wc_variations = $this->wc_client->get("products/{$product_id}/variations", ['per_page' => 100]);
        } catch (\Exception $e) {
            $this->logger->debug("  Failed to fetch variations for product {$product_id}: " . $e->getMessage());
            return [];
        }

        $map = [];
        foreach ($wc_variations as $wc_var) {
            foreach ($wc_var->attributes ?? [] as $attr) {
                $attr_slug = $attr->slug ?? $attr->name ?? '';
                if (stripos($attr_slug, 'taglia') !== false || stripos($attr_slug, 'size') !== false) {
                    $map[$attr->option] = [
                        'price' => (float) ($wc_var->regular_price ?? $wc_var->price ?? 0),
                        'stock_quantity' => (int) ($wc_var->stock_quantity ?? 0),
                        'stock_status' => $wc_var->stock_status ?? 'outofstock',
                        'variation_id' => $wc_var->id,
                    ];
                    break;
                }
            }
        }

        return $map;
    }

    // =========================================================================
    // Step 4: Create New Products
    // =========================================================================

    /**
     * Enrich new GS products via KicksDB, build WC payloads, and import
     *
     * @param array $new_skus SKUs to create
     * @param array $gs_products Full GS feed (SKU => normalized product)
     */
    private function createNewProducts(array $new_skus, array $gs_products): void
    {
        $this->logger->info('');
        $this->logger->info('Step 4: Creating ' . count($new_skus) . ' new products...');

        // Enrich via KicksDB where possible
        $enriched = $this->enrichFromKicksDb($new_skus, $gs_products);

        // Build WC payloads
        $builder = new WcProductBuilder($this->config, $this->logger);
        $wc_products = $builder->buildAll($enriched);

        // Assign subcategories by keyword matching (post-build, adds to categories[])
        $this->assignSubcategories($wc_products, $enriched);

        $this->logger->info("  Built " . count($wc_products) . " WC product payloads");

        if (empty($wc_products)) {
            return;
        }

        // Import via WooCommerceImporter
        $importer = new WooCommerceImporter($this->config, [
            'dry_run' => $this->dry_run,
        ]);

        $importer->import($wc_products);
        $importer_stats = $importer->getStats();

        $this->stats['products_created'] = ($importer_stats['products_created'] ?? 0) + ($importer_stats['products_updated'] ?? 0);
        $this->stats['errors'] += $importer_stats['errors'] ?? 0;

        $this->logger->info("  Created: {$importer_stats['products_created']}, Updated: {$importer_stats['products_updated']}, Errors: {$importer_stats['errors']}");
    }

    /**
     * Enrich GS products from KicksDB (SKU lookup, title search fallback)
     *
     * Products found in KicksDB get rich metadata (brand, model, colorway, gallery, etc.)
     * Products not found keep GS-only data (name, brand, price, stock — less metadata).
     *
     * @param array $skus SKUs to enrich
     * @param array $gs_products Full GS feed
     * @return array Enriched normalized products
     */
    private function enrichFromKicksDb(array $skus, array $gs_products): array
    {
        if ($this->skip_kicksdb) {
            $this->logger->info('  Skipping KicksDB enrichment (--skip-kicksdb)');
            $this->stats['kicksdb_missed'] = count($skus);
            return array_map(fn($sku) => $gs_products[$sku], $skus);
        }

        $pricing = $this->config['pricing'] ?? [];
        $api_key = $pricing['kicksdb_api_key'] ?? '';

        if (empty($api_key)) {
            $this->logger->warning('  No KICKSDB_API_KEY configured, using GS-only data');
            $this->stats['kicksdb_missed'] = count($skus);
            return array_map(fn($sku) => $gs_products[$sku], $skus);
        }

        $kicksdb = new KicksDbClient(
            $api_key,
            ['base_url' => $pricing['kicksdb_base_url'] ?? 'https://api.kicks.dev/v3'],
            $this->logger
        );
        $market = $pricing['kicksdb_market'] ?? 'IT';

        $enriched = [];
        $total = count($skus);

        foreach ($skus as $idx => $sku) {
            $gs = $gs_products[$sku];
            $progress = $idx + 1;

            $this->logger->debug("[{$progress}/{$total}] Enriching {$sku}...");

            try {
                $kdb_product = $kicksdb->getStockXProduct($sku, $market);
            } catch (\Exception $e) {
                $this->logger->debug("  KicksDB error for {$sku}: " . $e->getMessage());
                $kdb_product = null;
            }

            if ($kdb_product !== null) {
                // KicksDB found: use rich metadata, but keep GS pricing + stock
                $kdb_data = $kdb_product['data'] ?? $kdb_product;
                $enriched_product = $this->mergeKicksDbIntoGs($gs, $kdb_data);
                $enriched_product['meta_data'][] = ['key' => '_source', 'value' => 'gs_kicksdb_enriched'];
                $enriched[] = $enriched_product;
                $this->stats['kicksdb_enriched']++;
                $this->logger->debug("  Enriched from KicksDB");
            } else {
                // KicksDB miss: use GS-only data
                $enriched[] = $gs;
                $this->stats['kicksdb_missed']++;
                $this->logger->debug("  KicksDB miss, using GS-only data");
            }

            usleep(200000); // 200ms rate limit
        }

        $this->logger->info("  KicksDB: {$this->stats['kicksdb_enriched']} enriched, {$this->stats['kicksdb_missed']} GS-only");
        return $enriched;
    }

    /**
     * Merge KicksDB rich metadata into a GS product
     *
     * KicksDB provides: name, brand, model, colorway, gender, release_date,
     * description, image_url, gallery_urls, category_type.
     * GS provides: variations (price + stock) — these are kept as-is.
     *
     * @param array $gs GS normalized product
     * @param array $kdb Raw KicksDB product data
     * @return array Merged normalized product
     */
    private function mergeKicksDbIntoGs(array $gs, array $kdb): array
    {
        // Extract traits from KicksDB
        $traits = [];
        foreach ($kdb['traits'] ?? [] as $trait) {
            $traits[$trait['name'] ?? ''] = $trait['value'] ?? '';
        }

        // Build gallery URLs from KicksDB
        $gallery_urls = [];
        foreach ($kdb['gallery'] ?? [] as $url) {
            if (!empty($url)) {
                $gallery_urls[] = $url;
            }
        }
        // Sample 360 images if available (skip first frame, pick ~6 evenly spaced)
        $gallery_360 = $kdb['gallery_360'] ?? [];
        if (count($gallery_360) > 1) {
            $frames = array_slice($gallery_360, 1); // skip first (duplicate of primary angle)
            $count = count($frames);
            $sample_count = min(6, $count);
            if ($sample_count > 0 && $count > $sample_count) {
                $step = $count / $sample_count;
                $sampled = [];
                for ($i = 0; $i < $sample_count; $i++) {
                    $sampled[] = $frames[(int) ($i * $step)];
                }
                $gallery_urls = array_merge($gallery_urls, $sampled);
            } else {
                $gallery_urls = array_merge($gallery_urls, $frames);
            }
        }

        // Determine category_type from KicksDB product_type
        $kdb_type = strtolower($kdb['product_type'] ?? '');
        $category_type = $gs['category_type']; // default to GS detection
        if (in_array($kdb_type, ['sneakers', 'shoes', 'footwear'])) {
            $category_type = 'sneakers';
        } elseif (in_array($kdb_type, ['streetwear', 'apparel', 'clothing'])) {
            $category_type = 'clothing';
        } elseif (in_array($kdb_type, ['accessories', 'collectibles'])) {
            $category_type = 'accessories';
        }

        return [
            'sku' => $gs['sku'],
            'name' => $kdb['name'] ?? $gs['name'],
            'brand' => $kdb['brand'] ?? $gs['brand'],
            'model' => $traits['Style'] ?? $traits['Model'] ?? '',
            'gender' => $traits['Gender'] ?? '',
            'colorway' => $traits['Colorway'] ?? $traits['Color'] ?? '',
            'release_date' => $traits['Release Date'] ?? '',
            'retail_price' => $traits['Retail Price'] ?? '',
            'description' => $kdb['description'] ?? '',
            'category_type' => $category_type,
            'image_url' => $kdb['image_url'] ?? $gs['image_url'],
            'gallery_urls' => $gallery_urls,
            'variations' => $gs['variations'], // GS pricing + stock preserved
            'meta_data' => $gs['meta_data'] ?? [],
        ];
    }

    /**
     * Assign subcategories to built WC products by keyword matching
     *
     * Matches product name against subcategory keywords defined in config.php.
     * Adds the matched subcategory ID to the product's categories[] array.
     *
     * @param array &$wc_products Built WC product payloads (modified in place)
     * @param array $normalized Original normalized products (for category_type lookup)
     */
    private function assignSubcategories(array &$wc_products, array $normalized): void
    {
        // Load taxonomy map for subcategory IDs
        $tax_file = Config::dataDir() . '/taxonomy-map.json';
        if (!file_exists($tax_file)) {
            return;
        }

        $tax_map = json_decode(file_get_contents($tax_file), true) ?: [];
        $subcategories = $tax_map['subcategories'] ?? [];

        // Build keyword → subcategory ID lookup per parent category slug
        $keyword_map = [];
        foreach ($this->config['categories'] as $cat_key => $cat_config) {
            $parent_slug = $cat_config['slug'] ?? '';
            $subcat_defs = $cat_config['subcategories'] ?? [];
            $subcat_ids = $subcategories[$parent_slug] ?? [];

            foreach ($subcat_defs as $def) {
                $name = $def['name'] ?? '';
                $keywords = $def['keywords'] ?? [];
                $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $name));
                $slug = preg_replace('/-+/', '-', trim($slug, '-'));

                // Resolve subcategory ID from taxonomy map
                $entry = $subcat_ids[$slug] ?? null;
                $subcat_id = null;
                if (is_array($entry)) {
                    $subcat_id = $entry['id'] ?? null;
                } elseif (is_int($entry)) {
                    $subcat_id = $entry;
                }

                if ($subcat_id && !empty($keywords)) {
                    foreach ($keywords as $kw) {
                        $keyword_map[$parent_slug][] = [
                            'keyword' => strtolower($kw),
                            'id' => $subcat_id,
                        ];
                    }
                }
            }
        }

        if (empty($keyword_map)) {
            return;
        }

        // Build SKU → category_type index from normalized products
        $sku_category = [];
        foreach ($normalized as $p) {
            $sku_category[$p['sku'] ?? ''] = $p['category_type'] ?? 'sneakers';
        }

        // Map category_type → parent slug
        $type_to_slug = [];
        foreach ($this->config['categories'] as $cat_key => $cat_config) {
            $type_to_slug[$cat_key] = $cat_config['slug'] ?? '';
        }

        $matched = 0;
        foreach ($wc_products as &$wc) {
            $sku = $wc['sku'] ?? '';
            $cat_type = $sku_category[$sku] ?? 'sneakers';
            $parent_slug = $type_to_slug[$cat_type] ?? '';

            $rules = $keyword_map[$parent_slug] ?? [];
            if (empty($rules)) {
                continue;
            }

            $name_lower = strtolower($wc['name'] ?? '');
            foreach ($rules as $rule) {
                if (strpos($name_lower, $rule['keyword']) !== false) {
                    $wc['categories'][] = ['id' => $rule['id']];
                    $matched++;
                    break; // first match wins
                }
            }
        }
        unset($wc);

        if ($matched > 0) {
            $this->logger->info("  Subcategories: {$matched} products matched by keyword");
        }
    }

    // =========================================================================
    // Step 5: Patch Changed Variations
    // =========================================================================

    /**
     * Patch WC variations with new prices/stock from GS
     *
     * @param array $changed SKU => {size => {price, stock_quantity, stock_status, reason}}
     * @param array $wc_products SKU => {id, type, variations}
     */
    private function patchChangedVariations(array $changed, array $wc_products): void
    {
        $this->logger->info('');
        $this->logger->info('Step 5: Patching ' . count($changed) . ' changed products...');

        $batch_size = min((int) ($this->config['import']['batch_size'] ?? 25), 100);

        foreach ($changed as $sku => $var_changes) {
            $wc = $wc_products[$sku] ?? null;
            if (!$wc) {
                continue;
            }

            $product_id = $wc['id'];
            $wc_vars = $wc['variations'];

            $to_update = [];
            foreach ($var_changes as $size => $change) {
                $wc_var = $wc_vars[$size] ?? null;
                $variation_id = $wc_var['variation_id'] ?? null;

                if ($variation_id === null) {
                    // Simple product: patch the product itself
                    if ($wc['type'] === 'simple') {
                        $this->patchSimpleProduct($product_id, $sku, $change);
                        continue;
                    }
                    // New size on a variable product — skip (would need full variation creation)
                    $this->logger->debug("  [{$sku}] Size {$size}: no WC variation found, skipping");
                    continue;
                }

                $update = ['id' => $variation_id];

                $price = (float) $change['price'];
                if ($this->gs_sale_enabled && $price > 0) {
                    $update['regular_price'] = (string) ceil($price * (1 + $this->gs_sale_markup / 100));
                    $update['sale_price'] = (string) $price;
                } else {
                    $update['regular_price'] = (string) $price;
                }

                $update['stock_quantity'] = (int) $change['stock_quantity'];
                $update['stock_status'] = $change['stock_status'] ?? 'instock';
                $update['manage_stock'] = true;

                $to_update[] = $update;

                $this->logger->debug("  [{$sku}] EU {$size}: {$change['reason']}");
            }

            if (empty($to_update)) {
                continue;
            }

            if ($this->dry_run) {
                $this->stats['variations_patched'] += count($to_update);
                $this->logger->info("  [DRY RUN] [{$sku}] Would patch " . count($to_update) . " variations");
                continue;
            }

            // Batch update variations
            $chunks = array_chunk($to_update, $batch_size);
            foreach ($chunks as $chunk) {
                try {
                    $result = $this->wc_client->post(
                        "products/{$product_id}/variations/batch",
                        ['update' => $chunk]
                    );

                    foreach ($result->update ?? [] as $item) {
                        if (isset($item->error)) {
                            $this->stats['errors']++;
                            $this->logger->error("  [{$sku}] Variation {$item->id}: " . ($item->error->message ?? 'Unknown'));
                        } else {
                            $this->stats['variations_patched']++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->stats['errors'] += count($chunk);
                    $this->logger->error("  [{$sku}] Batch update failed: " . $e->getMessage());
                }
            }

            usleep(100000); // 100ms between products
        }
    }

    /**
     * Patch a simple (One Size) product's price and stock directly
     */
    private function patchSimpleProduct(int $product_id, string $sku, array $change): void
    {
        $price = (float) $change['price'];
        $payload = [
            'stock_quantity' => (int) $change['stock_quantity'],
            'stock_status' => $change['stock_status'] ?? 'instock',
            'manage_stock' => true,
        ];

        if ($this->gs_sale_enabled && $price > 0) {
            $payload['regular_price'] = (string) ceil($price * (1 + $this->gs_sale_markup / 100));
            $payload['sale_price'] = (string) $price;
        } else {
            $payload['regular_price'] = (string) $price;
        }

        if ($this->dry_run) {
            $this->stats['variations_patched']++;
            $this->logger->info("  [DRY RUN] [{$sku}] Would patch simple product price/stock");
            return;
        }

        try {
            $this->wc_client->put("products/{$product_id}", $payload);
            $this->stats['variations_patched']++;
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("  [{$sku}] Simple product patch failed: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Step 6: Update Tracking Snapshot
    // =========================================================================

    /**
     * Update the GS tracking snapshot for future runs
     */
    private function updateTrackingSnapshot(array $gs_products): void
    {
        $tracked_skus = [];
        foreach ($gs_products as $sku => $product) {
            $var_snapshot = [];
            foreach ($product['variations'] ?? [] as $var) {
                $size = $var['size_eu'] ?? '';
                if ($size) {
                    $var_snapshot[$size] = [
                        'price' => $var['price'] ?? 0,
                        'stock_quantity' => $var['stock_quantity'] ?? 0,
                        'stock_status' => $var['stock_status'] ?? 'outofstock',
                    ];
                }
            }
            $tracked_skus[$sku] = $var_snapshot;
        }

        $tracked_file = Config::dataDir() . '/gs-tracked-skus.json';

        if (!$this->dry_run) {
            file_put_contents(
                $tracked_file,
                json_encode([
                    'updated_at' => date('c'),
                    'total' => count($tracked_skus),
                    'source' => 'gs-catalog-sync',
                    'skus' => $tracked_skus,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $this->logger->info("  Updated gs-tracked-skus.json (" . count($tracked_skus) . " SKUs)");
        }
    }

    // =========================================================================
    // Summary
    // =========================================================================

    private function printSummary(float $start): void
    {
        $duration = round(microtime(true) - $start, 1);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  GS CATALOG SYNC SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("  GS feed:              {$this->stats['gs_products']} products");
        $this->logger->info("  WC existing:          {$this->stats['wc_products']} products");
        $this->logger->info("  New products:         {$this->stats['new_products']}");
        $this->logger->info("  Changed products:     {$this->stats['changed_products']}");
        $this->logger->info("  Unchanged:            {$this->stats['unchanged_products']}");
        $this->logger->info("  KicksDB enriched:     {$this->stats['kicksdb_enriched']}");
        $this->logger->info("  GS-only (no KicksDB): {$this->stats['kicksdb_missed']}");
        $this->logger->info("  Products created:     {$this->stats['products_created']}");
        $this->logger->info("  Variations patched:   {$this->stats['variations_patched']}");
        $this->logger->info("  Errors:               {$this->stats['errors']}");
        $this->logger->info("  Duration:             {$duration}s");
        $this->logger->info('========================================');
    }

    // =========================================================================
    // Setup
    // =========================================================================

    private function setupWooCommerceClient(): void
    {
        $url = rtrim(trim($this->config['woocommerce']['url'] ?? ''), ':');

        if (empty($url)) {
            throw new \RuntimeException(
                'WC_URL is not configured. Set it in your .env file.'
            );
        }

        $this->wc_client = new Client(
            $url,
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'] ?? 'wc/v3',
                'timeout' => (int) ($this->config['import']['api_timeout'] ?? 120),
            ]
        );
    }

    /**
     * Get pipeline stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}

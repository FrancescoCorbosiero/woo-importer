<?php

namespace ResellPiacenza\Taxonomy;

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * Taxonomy Manager (Source-Agnostic)
 *
 * Ensures all required WooCommerce taxonomies exist before import:
 * - Categories (from config: Sneakers, Abbigliamento)
 * - Global attributes (from config: Taglia, Marca)
 * - Brands (from Golden Sneakers API, a JSON file, or CLI list)
 *
 * Outputs: data/taxonomy-map.json
 *
 * @package ResellPiacenza\Taxonomy
 */
class TaxonomyManager
{
    private $config;
    private $wc_client;
    private $logger;

    private $dry_run = false;
    private $verbose = false;

    // Brand source
    private $brand_source = null; // 'gs', 'file', 'list', or null
    private $brands_file = null;
    private $brands_list = [];

    private $map = [
        'categories' => [],
        'subcategories' => [],
        'attributes' => [],
        'brands' => [],
    ];

    private $stats = [
        'categories_created' => 0,
        'categories_existing' => 0,
        'attributes_created' => 0,
        'attributes_existing' => 0,
        'brands_created' => 0,
        'brands_existing' => 0,
    ];

    /**
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->brand_source = $options['brand_source'] ?? null;
        $this->brands_file = $options['brands_file'] ?? null;
        $this->brands_list = $options['brands_list'] ?? [];

        $this->setupLogger();
        $this->setupWooCommerceClient();
    }

    private function setupLogger(): void
    {
        $this->logger = LoggerFactory::create('TaxPrep', [
            'file' => Config::projectRoot() . '/logs/prepare-taxonomies.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
     * Setup WooCommerce client with URL validation
     *
     * @throws \RuntimeException If WC_URL is empty or malformed
     */
    private function setupWooCommerceClient(): void
    {
        $url = trim($this->config['woocommerce']['url'] ?? '');

        if (empty($url)) {
            throw new \RuntimeException(
                'WC_URL is not configured. Set it in your .env file (e.g. WC_URL=https://your-store.com).'
            );
        }

        // Strip trailing colon (common typo: "https://store.com:")
        // which causes cURL "Port number was not a decimal number" error
        $url = rtrim($url, ':');

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \RuntimeException(
                "WC_URL is malformed: '{$url}'. Expected format: https://your-store.com"
            );
        }

        $this->wc_client = new Client(
            $url,
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 60]
        );
    }

    /**
     * Ensure a category exists in WooCommerce
     *
     * @param string $name Category display name
     * @param string $slug Category URL slug
     * @param int|null $parent Parent category ID for hierarchical categories
     */
    private function ensureCategory(string $name, string $slug, ?int $parent = null): ?int
    {
        try {
            $categories = $this->wc_client->get('products/categories', ['slug' => $slug]);

            if (!empty($categories)) {
                $id = $categories[0]->id;
                $this->stats['categories_existing']++;
                $this->logger->debug("  Exists: {$name} (ID: {$id})");
                return $id;
            }

            if ($this->dry_run) {
                $this->stats['categories_created']++;
                $this->logger->info("  [DRY RUN] Would create: {$name}" . ($parent ? " (parent: {$parent})" : ''));
                return 99999 - $this->stats['categories_created'];
            }

            $payload = [
                'name' => $name,
                'slug' => $slug,
            ];
            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            $result = $this->wc_client->post('products/categories', $payload);

            $this->stats['categories_created']++;
            $this->logger->info("  Created: {$name} (ID: {$result->id})" . ($parent ? " under parent {$parent}" : ''));
            return $result->id;

        } catch (\Exception $e) {
            $this->logger->error("  Category error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure a global attribute exists in WooCommerce
     */
    private function ensureAttribute(string $name, string $slug, array $extra = []): ?int
    {
        try {
            $existing_id = $this->findExistingAttribute($slug);
            if ($existing_id !== null) {
                $this->stats['attributes_existing']++;
                $this->logger->debug("  Exists: {$name} (ID: {$existing_id})");
                return $existing_id;
            }

            if ($this->dry_run) {
                $this->stats['attributes_created']++;
                $this->logger->info("  [DRY RUN] Would create: {$name}");
                return 99998;
            }

            $payload = array_merge([
                'name' => $name,
                'slug' => $slug,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => true,
            ], $extra);

            $result = $this->wc_client->post('products/attributes', $payload);

            $this->stats['attributes_created']++;
            $this->logger->info("  Created: {$name} (ID: {$result->id})");
            return $result->id;

        } catch (\Exception $e) {
            // "slug already in use" — attribute exists but lookup missed it
            if (strpos($e->getMessage(), 'woocommerce_rest_cannot_create') !== false) {
                $this->logger->debug("  Attribute exists (create conflict), retrying lookup for {$slug}...");
                $existing_id = $this->findExistingAttribute($slug);
                if ($existing_id !== null) {
                    $this->stats['attributes_existing']++;
                    $this->logger->info("  Found existing: {$name} (ID: {$existing_id})");
                    return $existing_id;
                }
            }
            $this->logger->error("  Attribute error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find an existing attribute by slug (handles pa_ prefix mismatch)
     */
    private function findExistingAttribute(string $slug): ?int
    {
        try {
            $attributes = $this->wc_client->get('products/attributes');
            foreach ($attributes as $attr) {
                $attr_slug = $attr->slug ?? '';
                // Match with or without pa_ prefix
                if ($attr_slug === $slug || $attr_slug === 'pa_' . $slug || 'pa_' . $attr_slug === $slug) {
                    return $attr->id;
                }
                // Also match by name (case-insensitive)
                if (isset($attr->name) && strtolower($attr->name) === strtolower($slug)) {
                    return $attr->id;
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug("  Attribute lookup error: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Ensure a brand exists in WooCommerce brands taxonomy
     *
     * @param string $name Brand name
     * @param int|null $parent Parent brand ID for hierarchical brands
     */
    private function ensureBrand(string $name, ?int $parent = null): ?int
    {
        $slug = $this->sanitizeSlug($name);

        if (isset($this->map['brands'][$slug])) {
            return $this->map['brands'][$slug];
        }

        try {
            $brands = $this->wc_client->get('products/brands', ['slug' => $slug]);

            if (!empty($brands)) {
                $id = $brands[0]->id;
                $this->stats['brands_existing']++;
                $this->logger->debug("  Exists: {$name} (ID: {$id})");
                return $id;
            }

            if ($this->dry_run) {
                $this->stats['brands_created']++;
                $this->logger->info("  [DRY RUN] Would create: {$name}" . ($parent ? " (parent: {$parent})" : ''));
                return 99997 - $this->stats['brands_created'];
            }

            $payload = [
                'name' => $name,
                'slug' => $slug,
            ];
            if ($parent !== null) {
                $payload['parent'] = $parent;
            }

            $result = $this->wc_client->post('products/brands', $payload);

            $this->stats['brands_created']++;
            $this->logger->info("  Created: {$name} (ID: {$result->id})" . ($parent ? " under parent {$parent}" : ''));
            return $result->id;

        } catch (\Exception $e) {
            $this->logger->debug("  Brand error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    private function sanitizeSlug(string $str): string
    {
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9-]/', '-', $str);
        $str = preg_replace('/-+/', '-', $str);
        return trim($str, '-');
    }

    /**
     * Resolve brand names from the configured source
     *
     * @return array List of brand names
     */
    private function resolveBrandNames(): array
    {
        switch ($this->brand_source) {
            case 'gs':
                return $this->fetchBrandNamesFromGS();

            case 'kicksdb':
                return $this->fetchBrandNamesFromKicksDB();

            case 'catalog':
                return $this->fetchBrandNamesFromCatalog();

            case 'file':
                return $this->loadBrandNamesFromFile($this->brands_file);

            case 'list':
                $this->logger->info("  " . count($this->brands_list) . " brands from CLI");
                return $this->brands_list;

            default:
                $this->logger->info('  No brand source specified (use --from-gs, --from-catalog, --brands-file, or --brands)');
                return [];
        }
    }

    /**
     * Fetch brand names from Golden Sneakers API
     */
    private function fetchBrandNamesFromGS(): array
    {
        $this->logger->info('Fetching GS feed for brand discovery...');

        $params = http_build_query($this->config['api']['params']);
        $url = $this->config['api']['base_url'] . '?' . $params;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api']['bearer_token'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $this->logger->error('CURL Error: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }

        curl_close($ch);

        if ($http_code !== 200) {
            $this->logger->error("API returned HTTP {$http_code}");
            return [];
        }

        $products = json_decode($response, true);
        if (!is_array($products)) {
            return [];
        }

        $brands = [];
        foreach ($products as $product) {
            $brand = $product['brand_name'] ?? '';
            if ($brand && !in_array($brand, $brands)) {
                $brands[] = $brand;
            }
        }

        sort($brands);
        $this->logger->info("  Found " . count($brands) . " unique brands in " . count($products) . " products");

        return $brands;
    }

    /**
     * Fetch brand names from KicksDB assortment file
     */
    private function fetchBrandNamesFromKicksDB(): array
    {
        $assortment_file = Config::dataDir() . '/kicksdb-assortment.json';

        if (!file_exists($assortment_file)) {
            $this->logger->error("KicksDB assortment not found: {$assortment_file}");
            $this->logger->error("Run bin/kicksdb-discover first");
            return [];
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $products = $data['products'] ?? [];

        $brands = [];
        foreach ($products as $product) {
            $brand = $product['brand'] ?? '';
            if ($brand && !in_array($brand, $brands)) {
                $brands[] = $brand;
            }
        }

        sort($brands);
        $this->logger->info("  Found " . count($brands) . " unique brands in " . count($products) . " KicksDB products");

        return $brands;
    }

    /**
     * Fetch brand names from the catalog embedded in the KicksDB assortment
     *
     * Supports both catalog formats:
     * - Sections-based: {"sections": [{"brands": [...]}, ...]}
     * - Legacy flat: {"brands": [...]}
     */
    private function fetchBrandNamesFromCatalog(): array
    {
        $assortment_file = Config::dataDir() . '/kicksdb-assortment.json';

        if (!file_exists($assortment_file)) {
            $this->logger->error("KicksDB assortment not found: {$assortment_file}");
            $this->logger->error("Run bin/kicksdb-discover first");
            return [];
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $catalog = $data['catalog'] ?? null;

        if (!$catalog) {
            $this->logger->error("No catalog structure found in assortment. Was discovery run with a catalog file?");
            return [];
        }

        // Collect brand entries from all sections (new format) or top-level (legacy)
        $all_brand_entries = $this->extractCatalogBrandEntries($catalog);

        if (empty($all_brand_entries)) {
            $this->logger->error("No brands found in catalog structure.");
            return [];
        }

        $brands = [];
        foreach ($all_brand_entries as $brand_entry) {
            $name = $brand_entry['name'] ?? '';
            if ($name && !in_array($name, $brands)) {
                $brands[] = $name;
            }
        }

        sort($brands);
        $this->logger->info("  Found " . count($brands) . " brands from catalog");

        return $brands;
    }

    /**
     * Create hierarchical brands from the catalog structure
     *
     * Creates: Brand (e.g., Nike) > Sub-brand (e.g., Nike Dunk)
     * Uses the WooCommerce brands taxonomy (same as GS pipeline).
     * All brand IDs are stored in the taxonomy map for downstream use.
     *
     * Supports both catalog formats:
     * - Sections-based: {"sections": [{"brands": [...]}, ...]}
     * - Legacy flat: {"brands": [...]}
     */
    private function ensureCatalogBrands(): void
    {
        $assortment_file = Config::dataDir() . '/kicksdb-assortment.json';

        if (!file_exists($assortment_file)) {
            $this->logger->error("KicksDB assortment not found: {$assortment_file}");
            return;
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $catalog = $data['catalog'] ?? null;

        if (!$catalog) {
            $this->logger->error("No catalog structure found in assortment.");
            return;
        }

        $all_brand_entries = $this->extractCatalogBrandEntries($catalog);

        if (empty($all_brand_entries)) {
            $this->logger->error("No brands found in catalog structure.");
            return;
        }

        foreach ($all_brand_entries as $brand_entry) {
            $brand_name = $brand_entry['name'] ?? '';
            if (empty($brand_name)) {
                continue;
            }

            $brand_slug = $this->sanitizeSlug($brand_name);

            // Create parent brand
            $brand_id = $this->ensureBrand($brand_name);
            if ($brand_id) {
                $this->map['brands'][$brand_slug] = $brand_id;
            }

            $subcategories = $brand_entry['products'] ?? [];
            if (empty($subcategories)) {
                continue;
            }

            // Create sub-brands under parent brand
            foreach ($subcategories as $subcat_name) {
                $subcat_slug = $this->sanitizeSlug($subcat_name);
                $subcat_id = $this->ensureBrand($subcat_name, $brand_id);
                if ($subcat_id) {
                    $this->map['brands'][$subcat_slug] = $subcat_id;
                }
            }
        }
    }

    /**
     * Create hierarchical product sub-categories from the catalog structure
     *
     * For brand-mode sections: each brand name becomes a sub-category under the parent
     *   e.g. Sneakers > Nike, Sneakers > Jordan, Abbigliamento > Supreme
     *
     * For query-mode sections: each item label becomes a sub-category under the parent
     *   e.g. Accessori > Beanie, Accessori > Labubu
     *
     * Sub-category IDs are stored in taxonomy map under 'subcategories' keyed by
     * parent category slug, for downstream use by catalog-transform.
     */
    private function ensureCatalogSubcategories(): void
    {
        $assortment_file = Config::dataDir() . '/kicksdb-assortment.json';

        if (!file_exists($assortment_file)) {
            $this->logger->error("KicksDB assortment not found: {$assortment_file}");
            return;
        }

        $data = json_decode(file_get_contents($assortment_file), true);
        $catalog = $data['catalog'] ?? null;

        if (!$catalog || empty($catalog['sections'])) {
            $this->logger->error("No catalog sections found in assortment.");
            return;
        }

        $total_created = 0;

        foreach ($catalog['sections'] as $section) {
            $section_name = $section['name'] ?? '';
            $wc_category_key = $section['wc_category'] ?? '';
            $discovery_mode = $section['discovery'] ?? '';

            if (empty($wc_category_key)) {
                continue;
            }

            // Resolve parent category config and ID
            $parent_config = $this->config['categories'][$wc_category_key] ?? null;
            if (!$parent_config) {
                $this->logger->warning("  No category config for '{$wc_category_key}', skipping section '{$section_name}'");
                continue;
            }

            $parent_slug = $parent_config['slug'];
            $parent_id = $this->map['categories'][$parent_slug] ?? null;
            if (!$parent_id) {
                $this->logger->warning("  Parent category '{$parent_slug}' not in taxonomy map, skipping section '{$section_name}'");
                continue;
            }

            // Initialize subcategory map for this parent
            if (!isset($this->map['subcategories'][$parent_slug])) {
                $this->map['subcategories'][$parent_slug] = [];
            }

            $this->logger->info("  {$section_name} ({$parent_slug}, {$discovery_mode} mode):");

            // Collect sub-category names based on discovery mode
            $subcat_names = [];

            if ($discovery_mode === 'brand') {
                // Brand-mode: each brand name is a sub-category
                foreach ($section['brands'] ?? [] as $brand_entry) {
                    $name = $brand_entry['name'] ?? '';
                    if ($name && !in_array($name, $subcat_names)) {
                        $subcat_names[] = $name;
                    }
                }
            } elseif ($discovery_mode === 'query') {
                // Query-mode: each item label is a sub-category
                foreach ($section['items'] ?? [] as $item) {
                    $name = $item['label'] ?? '';
                    if ($name && !in_array($name, $subcat_names)) {
                        $subcat_names[] = $name;
                    }
                }
            }

            // Create sub-categories under parent
            foreach ($subcat_names as $subcat_name) {
                $subcat_slug = $this->sanitizeSlug($subcat_name);
                $subcat_id = $this->ensureCategory($subcat_name, $subcat_slug, $parent_id);

                if ($subcat_id) {
                    $this->map['subcategories'][$parent_slug][$subcat_slug] = $subcat_id;
                    $total_created++;
                }
            }
        }

        $this->logger->info("  Total sub-categories: {$total_created}");
    }

    /**
     * Extract brand entries from catalog, supporting both formats
     *
     * @param array $catalog Catalog structure from assortment
     * @return array Flat list of brand entries (deduplicated by name)
     */
    private function extractCatalogBrandEntries(array $catalog): array
    {
        $all_brands = [];
        $seen = [];

        if (!empty($catalog['sections'])) {
            // Sections-based format: {"sections": [{"brands": [...]}, ...]}
            foreach ($catalog['sections'] as $section) {
                foreach ($section['brands'] ?? [] as $brand_entry) {
                    $name = $brand_entry['name'] ?? '';
                    if ($name && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $all_brands[] = $brand_entry;
                    }
                }
            }
        } elseif (!empty($catalog['brands'])) {
            // Legacy flat format: {"brands": [...]}
            $all_brands = $catalog['brands'];
        }

        return $all_brands;
    }

    /**
     * Load brand names from a JSON file
     * Accepts: ["Nike", "Adidas", ...] or [{"brand": "Nike"}, ...]
     */
    private function loadBrandNamesFromFile(string $path): array
    {
        if (!file_exists($path)) {
            $this->logger->error("Brands file not found: {$path}");
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            $this->logger->error("Invalid JSON in brands file");
            return [];
        }

        $brands = [];
        foreach ($data as $item) {
            $name = is_string($item) ? $item : ($item['brand'] ?? $item['brand_name'] ?? $item['name'] ?? null);
            if ($name && !in_array($name, $brands)) {
                $brands[] = $name;
            }
        }

        sort($brands);
        $this->logger->info("  Loaded " . count($brands) . " brands from {$path}");

        return $brands;
    }

    /**
     * Main entry point
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Taxonomy Preparation');
        $this->logger->info('================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }

        $this->logger->info('');

        try {
            // Load existing map to preserve entries from other sources
            $map_file = Config::dataDir() . '/taxonomy-map.json';
            if (file_exists($map_file)) {
                $existing = json_decode(file_get_contents($map_file), true) ?: [];
                $this->map['categories'] = $existing['categories'] ?? [];
                $this->map['subcategories'] = $existing['subcategories'] ?? [];
                $this->map['attributes'] = $existing['attributes'] ?? [];
                $this->map['brands'] = $existing['brands'] ?? [];
                $this->logger->debug("Loaded existing map: " .
                    count($this->map['categories']) . " categories, " .
                    count($this->map['brands']) . " brands"
                );
            }

            // Categories
            $this->logger->info('Ensuring categories...');
            foreach ($this->config['categories'] as $type => $cat_config) {
                $id = $this->ensureCategory($cat_config['name'], $cat_config['slug']);
                if ($id) {
                    $this->map['categories'][$cat_config['slug']] = $id;
                }
            }

            // Global attributes
            $this->logger->info('');
            $this->logger->info('Ensuring attributes...');
            foreach ($this->config['attributes'] as $key => $attr_config) {
                $id = $this->ensureAttribute(
                    $attr_config['name'],
                    $attr_config['slug'],
                    [
                        'type' => $attr_config['type'] ?? 'select',
                        'order_by' => $attr_config['order_by'] ?? 'menu_order',
                        'has_archives' => $attr_config['has_archives'] ?? true,
                    ]
                );
                if ($id) {
                    $this->map['attributes'][$attr_config['slug']] = $id;
                }
            }

            // Catalog hierarchical brands (Brand > Sub-brand in brands taxonomy)
            if ($this->brand_source === 'catalog') {
                $this->logger->info('');
                $this->logger->info('Creating catalog brand hierarchy...');
                $this->ensureCatalogBrands();
            }

            // Catalog sub-categories (Parent Category > Sub-category)
            if ($this->brand_source === 'catalog') {
                $this->logger->info('');
                $this->logger->info('Creating catalog sub-categories...');
                $this->ensureCatalogSubcategories();
            }

            // Brands (WC brands taxonomy)
            if ($this->config['brands']['enabled'] ?? true) {
                $this->logger->info('');
                $this->logger->info('Resolving brands...');
                $brand_names = $this->resolveBrandNames();

                if (!empty($brand_names)) {
                    $this->logger->info('Ensuring brands...');
                    foreach ($brand_names as $name) {
                        $id = $this->ensureBrand($name);
                        if ($id) {
                            $this->map['brands'][$this->sanitizeSlug($name)] = $id;
                        }
                    }
                }
            }

            // Save taxonomy map
            $this->map['updated_at'] = date('Y-m-d H:i:s');

            file_put_contents(
                $map_file,
                json_encode($this->map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            // Summary
            $duration = round(microtime(true) - $start_time, 1);
            $this->logger->info('');
            $this->logger->info('================================');
            $this->logger->info('  TAXONOMY SUMMARY');
            $this->logger->info('================================');
            $this->logger->info("  Categories: {$this->stats['categories_existing']} existing, {$this->stats['categories_created']} created");
            $this->logger->info("  Attributes: {$this->stats['attributes_existing']} existing, {$this->stats['attributes_created']} created");
            $this->logger->info("  Brands:     {$this->stats['brands_existing']} existing, {$this->stats['brands_created']} created");
            $subcat_total = 0;
            foreach ($this->map['subcategories'] as $entries) {
                $subcat_total += count($entries);
            }
            $this->logger->info("  Sub-cats:   {$subcat_total} across " . count($this->map['subcategories']) . " parents");
            $this->logger->info("  Total in map: " . count($this->map['brands']) . " brands");
            $this->logger->info("  Duration:   {$duration}s");
            $this->logger->info("  Saved to:   {$map_file}");
            $this->logger->info('================================');

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

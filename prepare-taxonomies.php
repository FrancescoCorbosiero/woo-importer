<?php
/**
 * Taxonomy Preparation
 *
 * Ensures all required WooCommerce taxonomies exist before import:
 * - Categories (Sneakers, Abbigliamento - auto-detected by size format)
 * - Global attributes (Taglia, Marca)
 * - Brands (extracted from Golden Sneakers feed)
 *
 * Outputs: data/taxonomy-map.json
 *
 * Usage:
 *   php prepare-taxonomies.php                # Ensure all taxonomies
 *   php prepare-taxonomies.php --dry-run      # Preview without creating
 *   php prepare-taxonomies.php --verbose      # Detailed output
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class TaxonomyPreparer
{
    private $config;
    private $wc_client;
    private $logger;

    private $dry_run = false;
    private $verbose = false;

    private $map = [
        'categories' => [],
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

        $this->setupLogger();
        $this->setupWooCommerceClient();
    }

    private function setupLogger(): void
    {
        $this->logger = new Logger('TaxPrep');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/prepare-taxonomies.log', 7, Logger::DEBUG)
        );

        $level = $this->verbose ? Logger::DEBUG : Logger::INFO;
        $this->logger->pushHandler(new StreamHandler('php://stdout', $level));
    }

    private function setupWooCommerceClient(): void
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 60]
        );
    }

    /**
     * Ensure a category exists in WooCommerce
     *
     * @param string $name Display name
     * @param string $slug URL slug
     * @return int|null Category ID
     */
    private function ensureCategory(string $name, string $slug): ?int
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
                $this->logger->info("  [DRY RUN] Would create: {$name}");
                return 99999;
            }

            $result = $this->wc_client->post('products/categories', [
                'name' => $name,
                'slug' => $slug,
            ]);

            $this->stats['categories_created']++;
            $this->logger->info("  Created: {$name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
            $this->logger->error("  Category error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure a global attribute exists in WooCommerce
     *
     * @param string $name Display name
     * @param string $slug Attribute slug
     * @param array $extra Extra attribute properties
     * @return int|null Attribute ID
     */
    private function ensureAttribute(string $name, string $slug, array $extra = []): ?int
    {
        try {
            $attributes = $this->wc_client->get('products/attributes');

            foreach ($attributes as $attr) {
                if ($attr->slug === $slug) {
                    $this->stats['attributes_existing']++;
                    $this->logger->debug("  Exists: {$name} (ID: {$attr->id})");
                    return $attr->id;
                }
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

        } catch (Exception $e) {
            $this->logger->error("  Attribute error ({$name}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure a brand exists in WooCommerce brands taxonomy
     *
     * @param string $name Brand name
     * @return int|null Brand term ID
     */
    private function ensureBrand(string $name): ?int
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
                $this->logger->info("  [DRY RUN] Would create: {$name}");
                return 99997;
            }

            $result = $this->wc_client->post('products/brands', [
                'name' => $name,
                'slug' => $slug,
            ]);

            $this->stats['brands_created']++;
            $this->logger->info("  Created: {$name} (ID: {$result->id})");
            return $result->id;

        } catch (Exception $e) {
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
     * Fetch Golden Sneakers feed and extract unique brand names
     *
     * @return array List of brand names
     */
    private function fetchBrandNames(): array
    {
        $this->logger->info('Fetching feed for brand discovery...');

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
     * Main entry point
     *
     * @return bool Success
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

            // Brands (from feed)
            if ($this->config['brands']['enabled'] ?? true) {
                $this->logger->info('');
                $brand_names = $this->fetchBrandNames();

                $this->logger->info('Ensuring brands...');
                foreach ($brand_names as $name) {
                    $id = $this->ensureBrand($name);
                    if ($id) {
                        $this->map['brands'][$this->sanitizeSlug($name)] = $id;
                    }
                }
            }

            // Save taxonomy map
            $this->map['updated_at'] = date('Y-m-d H:i:s');

            $data_dir = __DIR__ . '/data';
            if (!is_dir($data_dir)) {
                mkdir($data_dir, 0755, true);
            }

            $map_file = $data_dir . '/taxonomy-map.json';
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
            $this->logger->info("  Duration:   {$duration}s");
            $this->logger->info("  Saved to:   {$map_file}");
            $this->logger->info('================================');

            return true;

        } catch (Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// CLI
// ============================================================================

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
Taxonomy Preparation
Ensures all WooCommerce taxonomies exist before import.

Usage:
  php prepare-taxonomies.php [options]

Options:
  --dry-run         Preview without creating anything
  --verbose, -v     Detailed output
  --help, -h        Show help

Output:
  data/taxonomy-map.json - Category, attribute, and brand ID mappings

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
];

$config = require __DIR__ . '/config.php';
$preparer = new TaxonomyPreparer($config, $options);
exit($preparer->run() ? 0 : 1);

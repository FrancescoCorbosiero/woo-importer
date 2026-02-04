<?php
/**
 * WooCommerce Pass-Through Importer
 *
 * Simplified importer that receives WooCommerce-ready data from FeedProxy.
 * No transformation logic - just batch operations against WooCommerce API.
 *
 * Usage:
 *   php import-wc.php                      # Full import via FeedProxy
 *   php import-wc.php --feed=diff.json     # Import from pre-transformed file
 *   php import-wc.php --dry-run            # Preview without changes
 *   php import-wc.php --limit=50           # Limit products
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/FeedProxy.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class WooCommerceImporter
{
    private $config;
    private $wc_client;
    private $logger;
    private $proxy;

    // Options
    private $dry_run = false;
    private $limit = null;
    private $feed_file = null;
    private $batch_size = 100;

    // Stats
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
        'skipped' => 0,
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration from config.php
     * @param array $options CLI options
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->feed_file = $options['feed_file'] ?? null;
        $this->batch_size = min($config['import']['batch_size'] ?? 100, 100);

        $this->setupLogger();
        $this->setupWooCommerceClient();
        $this->proxy = new FeedProxy($config, [
            'dry_run' => $this->dry_run,
            'verbose' => $options['verbose'] ?? false,
        ]);
    }

    /**
     * Setup logger
     */
    private function setupLogger(): void
    {
        $this->logger = new Logger('WooImporter');

        $log_dir = __DIR__ . '/logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $this->logger->pushHandler(
            new RotatingFileHandler($log_dir . '/import-wc.log', 7, Logger::DEBUG)
        );
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
    }

    /**
     * Setup WooCommerce client
     */
    private function setupWooCommerceClient(): void
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            ['version' => $this->config['woocommerce']['version'], 'timeout' => 120]
        );
    }

    /**
     * Fetch existing products from WooCommerce (SKU => ID mapping)
     *
     * @return array SKU => product data
     */
    private function fetchExistingProducts(): array
    {
        $existing = [];
        $page = 1;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => 100,
                    'page' => $page,
                    'status' => 'any',
                ]);

                foreach ($products as $product) {
                    if (!empty($product->sku)) {
                        $existing[$product->sku] = [
                            'id' => $product->id,
                            'name' => $product->name,
                        ];
                    }
                }
                $page++;
            } catch (Exception $e) {
                $this->logger->error("Error fetching products page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === 100);

        return $existing;
    }

    /**
     * Fetch existing variations for a product
     *
     * @param int $product_id Product ID
     * @return array SKU => variation data
     */
    private function fetchExistingVariations(int $product_id): array
    {
        $existing = [];
        $page = 1;

        do {
            try {
                $variations = $this->wc_client->get("products/{$product_id}/variations", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                foreach ($variations as $var) {
                    if (!empty($var->sku)) {
                        $existing[$var->sku] = ['id' => $var->id];
                    }
                }
                $page++;
            } catch (Exception $e) {
                break;
            }
        } while (count($variations) === 100);

        return $existing;
    }

    /**
     * Process products in batch
     *
     * Products come pre-formatted from FeedProxy - just send to WC API.
     *
     * @param array $wc_products WooCommerce-formatted products
     * @param array $existing_products Existing products (SKU => data)
     * @return array Product map (SKU => [id, variations])
     */
    private function batchProcessProducts(array $wc_products, array $existing_products): array
    {
        $to_create = [];
        $to_update = [];
        $product_map = [];

        foreach ($wc_products as $product) {
            $sku = $product['sku'];
            $variations = $product['_variations'] ?? [];

            // Remove internal keys before sending to API
            $api_payload = $product;
            unset($api_payload['_variations'], $api_payload['_sync_action'], $api_payload['_product_type']);

            if (isset($existing_products[$sku])) {
                // Update: add ID to payload
                $api_payload['id'] = $existing_products[$sku]['id'];
                $to_update[] = $api_payload;
                $product_map[$sku] = [
                    'id' => $existing_products[$sku]['id'],
                    'variations' => $variations,
                ];
            } else {
                // Create: full payload
                $to_create[] = $api_payload;
                $product_map[$sku] = [
                    'id' => null,
                    'variations' => $variations,
                    'pending' => true,
                ];
            }
        }

        $this->logger->info("  To create: " . count($to_create) . ", to update: " . count($to_update));

        // Execute batches
        if (!empty($to_create)) {
            $this->executeBatch('create', $to_create, $product_map);
        }
        if (!empty($to_update)) {
            $this->executeBatch('update', $to_update, $product_map);
        }

        return $product_map;
    }

    /**
     * Execute batch operation
     *
     * @param string $operation 'create' or 'update'
     * @param array $items Product payloads
     * @param array &$product_map Reference to update with new IDs
     */
    private function executeBatch(string $operation, array $items, array &$product_map): void
    {
        $chunks = array_chunk($items, $this->batch_size);

        foreach ($chunks as $chunk_idx => $chunk) {
            if ($this->dry_run) {
                $this->logger->info("  [DRY RUN] Would {$operation} " . count($chunk) . " products");
                if ($operation === 'create') {
                    foreach ($chunk as $item) {
                        if (isset($product_map[$item['sku']])) {
                            $product_map[$item['sku']]['id'] = 99990000 + $this->stats['products_created'];
                            unset($product_map[$item['sku']]['pending']);
                            $this->stats['products_created']++;
                        }
                    }
                } else {
                    $this->stats['products_updated'] += count($chunk);
                }
                continue;
            }

            try {
                $result = $this->wc_client->post('products/batch', [$operation => $chunk]);
                $this->stats['batch_requests']++;

                $result_key = $operation;
                foreach ($result->$result_key ?? [] as $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                        $this->logger->error("  Error: " . ($item->error->message ?? 'Unknown'));
                    } else {
                        if ($operation === 'create') {
                            $this->stats['products_created']++;
                            if (!empty($item->sku) && isset($product_map[$item->sku])) {
                                $product_map[$item->sku]['id'] = $item->id;
                                unset($product_map[$item->sku]['pending']);
                            }
                        } else {
                            $this->stats['products_updated']++;
                        }
                    }
                }

                $this->logger->info("  Batch " . ($chunk_idx + 1) . ": {$operation}d " . count($chunk) . " products");

            } catch (Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  Batch failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Process variations for a product
     *
     * Variations come pre-formatted from FeedProxy.
     *
     * @param int $product_id Product ID
     * @param array $variations WooCommerce-formatted variations
     */
    private function processVariations(int $product_id, array $variations): void
    {
        $existing = $this->fetchExistingVariations($product_id);

        $to_create = [];
        $to_update = [];

        foreach ($variations as $var) {
            $sku = $var['sku'];

            if (isset($existing[$sku])) {
                $var['id'] = $existing[$sku]['id'];
                $to_update[] = $var;
            } else {
                $to_create[] = $var;
            }
        }

        // Execute variation batches
        if (!empty($to_create)) {
            $this->executeVariationBatch($product_id, 'create', $to_create);
        }
        if (!empty($to_update)) {
            $this->executeVariationBatch($product_id, 'update', $to_update);
        }
    }

    /**
     * Execute variation batch
     *
     * @param int $product_id Product ID
     * @param string $operation 'create' or 'update'
     * @param array $items Variation payloads
     */
    private function executeVariationBatch(int $product_id, string $operation, array $items): void
    {
        $chunks = array_chunk($items, $this->batch_size);

        foreach ($chunks as $chunk) {
            if ($this->dry_run) {
                if ($operation === 'create') {
                    $this->stats['variations_created'] += count($chunk);
                } else {
                    $this->stats['variations_updated'] += count($chunk);
                }
                continue;
            }

            try {
                $result = $this->wc_client->post(
                    "products/{$product_id}/variations/batch",
                    [$operation => $chunk]
                );
                $this->stats['batch_requests']++;

                $result_key = $operation;
                foreach ($result->$result_key ?? [] as $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                    } else {
                        if ($operation === 'create') {
                            $this->stats['variations_created']++;
                        } else {
                            $this->stats['variations_updated']++;
                        }
                    }
                }

            } catch (Exception $e) {
                $this->stats['errors'] += count($chunk);
            }
        }
    }

    /**
     * Main import runner
     *
     * @return bool Success
     */
    public function run(): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  WooCommerce Pass-Through Importer');
        $this->logger->info('========================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }

        try {
            // Phase 1: Get existing products
            $this->logger->info('');
            $this->logger->info('Phase 1: Fetching existing WooCommerce products...');
            $existing_products = $this->fetchExistingProducts();
            $this->logger->info("  Found " . count($existing_products) . " existing products");

            // Phase 2: Get WooCommerce-ready data from proxy
            $this->logger->info('');
            $this->logger->info('Phase 2: Loading WooCommerce-formatted data...');

            if ($this->feed_file) {
                // From file (diff import)
                $wc_products = $this->proxy->transformFromFile($this->feed_file, $existing_products);
            } else {
                // From API
                $wc_products = $this->proxy->fetchAndTransform($existing_products);
            }

            if (empty($wc_products)) {
                $this->logger->warning('No products to import');
                return true;
            }

            if ($this->limit) {
                $wc_products = array_slice($wc_products, 0, $this->limit);
                $this->logger->info("  Limited to {$this->limit} products");
            }

            // Phase 3: Batch import products
            $this->logger->info('');
            $this->logger->info('Phase 3: Batch importing products...');
            $product_map = $this->batchProcessProducts($wc_products, $existing_products);

            // Phase 4: Process variations
            $this->logger->info('');
            $this->logger->info('Phase 4: Processing variations...');

            $total = count($product_map);
            $current = 0;

            foreach ($product_map as $sku => $data) {
                $current++;

                if (empty($data['id']) || empty($data['variations'])) {
                    continue;
                }

                echo "\r  Processing: {$current}/{$total}          ";
                $this->processVariations($data['id'], $data['variations']);
            }
            echo "\n";

            // Summary
            $this->printSummary($start_time);
            return true;

        } catch (Exception $e) {
            $this->logger->error('Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Print summary
     *
     * @param float $start_time Start microtime
     */
    private function printSummary(float $start_time): void
    {
        $duration = round(microtime(true) - $start_time, 1);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  IMPORT SUMMARY');
        $this->logger->info('========================================');
        $this->logger->info("  Products Created:    {$this->stats['products_created']}");
        $this->logger->info("  Products Updated:    {$this->stats['products_updated']}");
        $this->logger->info("  Variations Created:  {$this->stats['variations_created']}");
        $this->logger->info("  Variations Updated:  {$this->stats['variations_updated']}");
        $this->logger->info("  Batch Requests:      {$this->stats['batch_requests']}");
        $this->logger->info("  Errors:              {$this->stats['errors']}");
        $this->logger->info("  Duration:            {$duration}s");
        $this->logger->info('========================================');
    }
}

// ============================================================================
// CLI
// ============================================================================

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo <<<HELP
WooCommerce Pass-Through Importer
Uses FeedProxy for transformation - zero mapping in importer.

Usage:
  php import-wc.php [options]

Options:
  --dry-run         Preview without changes
  --limit=N         Limit to N products
  --feed=FILE       Import from pre-transformed JSON file
  --verbose, -v     Verbose output
  --help, -h        Show this help

Examples:
  php import-wc.php --dry-run --limit=10
  php import-wc.php --feed=data/diff.json

HELP;
    exit(0);
}

$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'limit' => null,
    'feed_file' => null,
    'verbose' => in_array('--verbose', $argv) || in_array('-v', $argv),
];

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) str_replace('--limit=', '', $arg);
    }
    if (strpos($arg, '--feed=') === 0) {
        $options['feed_file'] = str_replace('--feed=', '', $arg);
    }
}

$config = require __DIR__ . '/config.php';
$importer = new WooCommerceImporter($config, $options);
exit($importer->run() ? 0 : 1);

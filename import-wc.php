<?php
/**
 * WooCommerce Direct Importer
 *
 * Accepts WooCommerce REST API formatted JSON and pushes directly to WooCommerce.
 * NO transformation - expects data already in WC format.
 *
 * Input format (each product):
 * {
 *   "name": "Product Name",
 *   "sku": "SKU-123",
 *   "type": "variable",
 *   "status": "publish",
 *   "short_description": "...",
 *   "description": "...",
 *   "categories": [{"id": 123}],
 *   "brands": [{"id": 456}],
 *   "images": [{"id": 789}],
 *   "attributes": [...],
 *   "_variations": [
 *     {
 *       "sku": "SKU-123-36",
 *       "regular_price": "99.00",
 *       "stock_quantity": 5,
 *       "stock_status": "instock",
 *       "attributes": [{"id": 1, "option": "36"}]
 *     }
 *   ]
 * }
 *
 * Usage:
 *   php import-wc.php --feed=products.json     # Import from file
 *   cat products.json | php import-wc.php      # Import from stdin
 *   php import-wc.php --dry-run --feed=x.json  # Preview
 *
 * @package ResellPiacenza\WooImport
 */

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

class WooCommerceDirectImporter
{
    private $config;
    private $wc_client;
    private $logger;

    // Options
    private $dry_run = false;
    private $limit = null;
    private $batch_size = 100;

    // Stats
    private $stats = [
        'products_created' => 0,
        'products_updated' => 0,
        'variations_created' => 0,
        'variations_updated' => 0,
        'batch_requests' => 0,
        'errors' => 0,
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
        $this->batch_size = min($config['import']['batch_size'] ?? 100, 100);

        $this->setupLogger();
        $this->setupWooCommerceClient();
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
     * Fetch existing products from WooCommerce
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
                        $existing[$product->sku] = ['id' => $product->id];
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
     * @param array $wc_products WooCommerce-formatted products
     * @param array $existing_products Existing products
     * @return array Product map (SKU => [id, variations])
     */
    private function batchProcessProducts(array $wc_products, array $existing_products): array
    {
        $to_create = [];
        $to_update = [];
        $product_map = [];

        foreach ($wc_products as $product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            $variations = $product['_variations'] ?? [];

            // Clean internal keys before API call
            $api_payload = $product;
            unset($api_payload['_variations'], $api_payload['_sync_action'], $api_payload['_product_type']);

            if (isset($existing_products[$sku])) {
                $api_payload['id'] = $existing_products[$sku]['id'];
                $to_update[] = $api_payload;
                $product_map[$sku] = [
                    'id' => $existing_products[$sku]['id'],
                    'variations' => $variations,
                ];
            } else {
                $to_create[] = $api_payload;
                $product_map[$sku] = [
                    'id' => null,
                    'variations' => $variations,
                    'pending' => true,
                ];
            }
        }

        $this->logger->info("  To create: " . count($to_create) . ", to update: " . count($to_update));

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
                        $sku = $item['sku'] ?? null;
                        if ($sku && isset($product_map[$sku])) {
                            $product_map[$sku]['id'] = 99990000 + $this->stats['products_created'];
                            unset($product_map[$sku]['pending']);
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

                foreach ($result->$operation ?? [] as $item) {
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

                $this->logger->info("  Batch " . ($chunk_idx + 1) . ": {$operation}d " . count($chunk));

            } catch (Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->logger->error("  Batch failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Process variations for a product
     *
     * @param int $product_id Product ID
     * @param array $variations Variations (WC format)
     */
    private function processVariations(int $product_id, array $variations): void
    {
        $existing = $this->fetchExistingVariations($product_id);

        $to_create = [];
        $to_update = [];

        foreach ($variations as $var) {
            $sku = $var['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            if (isset($existing[$sku])) {
                $var['id'] = $existing[$sku]['id'];
                $to_update[] = $var;
            } else {
                $to_create[] = $var;
            }
        }

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

                foreach ($result->$operation ?? [] as $item) {
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
     * Import from WooCommerce-formatted array
     *
     * @param array $wc_products Array of WC-formatted products
     * @return bool Success
     */
    public function import(array $wc_products): bool
    {
        $start_time = microtime(true);

        $this->logger->info('');
        $this->logger->info('========================================');
        $this->logger->info('  WooCommerce Direct Importer');
        $this->logger->info('========================================');

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN MODE');
        }

        try {
            // Apply limit
            if ($this->limit) {
                $wc_products = array_slice($wc_products, 0, $this->limit);
            }

            $this->logger->info("  Products to import: " . count($wc_products));

            if (empty($wc_products)) {
                $this->logger->warning('  No products to import');
                return true;
            }

            // Fetch existing
            $this->logger->info('');
            $this->logger->info('Fetching existing WooCommerce products...');
            $existing = $this->fetchExistingProducts();
            $this->logger->info("  Found " . count($existing) . " existing");

            // Batch products
            $this->logger->info('');
            $this->logger->info('Importing products...');
            $product_map = $this->batchProcessProducts($wc_products, $existing);

            // Variations
            $this->logger->info('');
            $this->logger->info('Processing variations...');

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

            $this->printSummary($start_time);
            return true;

        } catch (Exception $e) {
            $this->logger->error('Fatal error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Print summary
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
WooCommerce Direct Importer
Accepts WooCommerce REST API formatted JSON - NO transformation.

Usage:
  php import-wc.php --feed=FILE         # Import from JSON file
  cat products.json | php import-wc.php # Import from stdin
  php import-wc.php --dry-run --feed=x  # Preview mode

Options:
  --feed=FILE       JSON file with WC-formatted products
  --dry-run         Preview without making changes
  --limit=N         Limit to N products
  --help, -h        Show this help

Expected JSON format:
  [
    {
      "name": "Product Name",
      "sku": "SKU-123",
      "type": "variable",
      "categories": [{"id": 123}],
      "attributes": [...],
      "_variations": [
        {
          "sku": "SKU-123-36",
          "regular_price": "99.00",
          "stock_quantity": 5,
          "stock_status": "instock",
          "attributes": [{"id": 1, "option": "36"}]
        }
      ]
    }
  ]

Note: _variations is the only non-standard key (nested for convenience).
      All other fields map 1:1 to WooCommerce REST API.

HELP;
    exit(0);
}

// Parse options
$options = [
    'dry_run' => in_array('--dry-run', $argv),
    'limit' => null,
];

$feed_file = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int) str_replace('--limit=', '', $arg);
    }
    if (strpos($arg, '--feed=') === 0) {
        $feed_file = str_replace('--feed=', '', $arg);
    }
}

// Load data from file or stdin
$wc_products = null;

if ($feed_file) {
    if (!file_exists($feed_file)) {
        fwrite(STDERR, "Error: File not found: {$feed_file}\n");
        exit(1);
    }
    $wc_products = json_decode(file_get_contents($feed_file), true);
} elseif (!posix_isatty(STDIN)) {
    // Read from stdin
    $input = stream_get_contents(STDIN);
    $wc_products = json_decode($input, true);
} else {
    fwrite(STDERR, "Error: No input. Use --feed=FILE or pipe JSON to stdin.\n");
    fwrite(STDERR, "Run with --help for usage.\n");
    exit(1);
}

if ($wc_products === null || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Error: Invalid JSON - " . json_last_error_msg() . "\n");
    exit(1);
}

// Run importer
$config = require __DIR__ . '/config.php';
$importer = new WooCommerceDirectImporter($config, $options);
exit($importer->import($wc_products) ? 0 : 1);

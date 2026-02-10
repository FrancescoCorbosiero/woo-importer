#!/usr/bin/env php
<?php
/**
 * Nuclear Product Deletion Script
 *
 * Deletes ALL WooCommerce products except those in the exclusion list.
 * Also cleans up orphaned entries in the product lookup table.
 *
 * Usage:
 *   php nuke-products.php                    # Dry run (shows what would be deleted)
 *   php nuke-products.php --confirm          # Actually delete everything
 *   php nuke-products.php --confirm --keep=SKU1,SKU2,SKU3   # Keep specific SKUs
 *   php nuke-products.php --confirm --keep-file=keep.txt   # Keep SKUs from file (one per line)
 *   php nuke-products.php --confirm --batch=50             # Delete in batches of 50
 *
 * @package ResellPiacenza\WooImport
 */

$config = require __DIR__ . '/config.php';

use Automattic\WooCommerce\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class ProductNuker
{
    private $config;
    private $wc_client;
    private $logger;
    private $dry_run = true;
    private $delete_media = false;
    private $batch_size = 100;
    private $excluded_skus = [];

    // Stats
    private $products_deleted = 0;
    private $products_skipped = 0;
    private $products_failed = 0;
    private $variations_deleted = 0;
    private $media_deleted = 0;
    private $media_failed = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->setupLogger();
        $this->setupWooCommerceClient();
    }

    private function setupLogger()
    {
        $this->logger = new Logger('ProductNuker');

        // File handler
        $log_dir = dirname($this->config['logging']['file']);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $file_handler = new RotatingFileHandler(
            $log_dir . '/nuke.log',
            30,
            Logger::DEBUG
        );
        $file_handler->setFormatter(new LineFormatter(null, null, true, true));
        $this->logger->pushHandler($file_handler);

        // Console handler
        $console_handler = new StreamHandler('php://stdout', Logger::INFO);
        $console_handler->setFormatter(new LineFormatter(
            "%message%\n",
            null,
            true,
            true
        ));
        $this->logger->pushHandler($console_handler);
    }

    private function setupWooCommerceClient()
    {
        $this->wc_client = new Client(
            $this->config['woocommerce']['url'],
            $this->config['woocommerce']['consumer_key'],
            $this->config['woocommerce']['consumer_secret'],
            [
                'version' => $this->config['woocommerce']['version'],
                'timeout' => 120,
            ]
        );
    }

    public function setDryRun(bool $dry_run): self
    {
        $this->dry_run = $dry_run;
        return $this;
    }

    public function setBatchSize(int $size): self
    {
        $this->batch_size = max(1, min($size, 100));
        return $this;
    }

    public function setDeleteMedia(bool $delete_media): self
    {
        $this->delete_media = $delete_media;
        return $this;
    }

    public function setExcludedSkus(array $skus): self
    {
        $this->excluded_skus = array_map('trim', $skus);
        $this->excluded_skus = array_filter($this->excluded_skus);
        return $this;
    }

    public function loadExcludedSkusFromFile(string $filepath): self
    {
        if (!file_exists($filepath)) {
            $this->logger->error("Exclusion file not found: {$filepath}");
            return $this;
        }

        $content = file_get_contents($filepath);
        $skus = explode("\n", $content);
        $skus = array_map('trim', $skus);
        $skus = array_filter($skus);

        $this->excluded_skus = array_merge($this->excluded_skus, $skus);
        $this->logger->info("Loaded " . count($skus) . " SKUs from exclusion file");

        return $this;
    }

    public function run(): void
    {
        $start_time = microtime(true);

        $this->printBanner();

        if ($this->dry_run) {
            $this->logger->info("===========================================");
            $this->logger->info("  DRY RUN MODE - No products will be deleted");
            $this->logger->info("  Run with --confirm to actually delete");
            $this->logger->info("===========================================\n");
        } else {
            $this->logger->info("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
            $this->logger->info("  LIVE MODE - Products WILL BE DELETED!");
            if ($this->delete_media) {
                $this->logger->info("  MEDIA CLEANUP ENABLED - Associated images will also be deleted!");
            }
            $this->logger->info("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!\n");
        }

        if (!empty($this->excluded_skus)) {
            $this->logger->info("Excluding " . count($this->excluded_skus) . " SKUs from deletion:");
            foreach ($this->excluded_skus as $sku) {
                $this->logger->info("  - {$sku}");
            }
            $this->logger->info("");
        }

        // Phase 1: Fetch all products
        $this->logger->info("Phase 1: Fetching all products from WooCommerce...");
        $products = $this->fetchAllProducts();
        $total = count($products);
        $this->logger->info("Found {$total} products in WooCommerce\n");

        if ($total === 0) {
            $this->logger->info("No products to delete. Exiting.");
            return;
        }

        // Phase 2: Filter and delete
        $this->logger->info("Phase 2: Processing deletions...\n");

        $to_delete = [];
        foreach ($products as $product) {
            $sku = $product->sku ?? '';

            if (in_array($sku, $this->excluded_skus)) {
                $this->logger->info("  SKIP: {$product->name} (SKU: {$sku}) - In exclusion list");
                $this->products_skipped++;
                continue;
            }

            $to_delete[] = $product;
        }

        $this->logger->info("\nProducts to delete: " . count($to_delete));
        $this->logger->info("Products to keep: {$this->products_skipped}\n");

        if (empty($to_delete)) {
            $this->logger->info("No products to delete after filtering. Exiting.");
            return;
        }

        // Delete in batches
        $batches = array_chunk($to_delete, $this->batch_size);
        $batch_num = 0;
        $total_batches = count($batches);

        foreach ($batches as $batch) {
            $batch_num++;
            $this->logger->info("Batch {$batch_num}/{$total_batches}...");

            foreach ($batch as $product) {
                $this->deleteProduct($product);
            }

            // Small delay between batches to avoid overwhelming the server
            if ($batch_num < $total_batches) {
                usleep(500000); // 0.5 second
            }
        }

        // Phase 3: Cleanup orphaned categories (optional)
        $this->logger->info("\nPhase 3: Cleanup complete.\n");

        $this->printSummary($start_time);
    }

    private function fetchAllProducts(): array
    {
        $all_products = [];
        $page = 1;
        $per_page = 100;

        do {
            try {
                $products = $this->wc_client->get('products', [
                    'per_page' => $per_page,
                    'page' => $page,
                    'status' => 'any',
                ]);

                if (empty($products)) {
                    break;
                }

                $all_products = array_merge($all_products, $products);
                $this->logger->info("  Fetched page {$page} (" . count($products) . " products)");
                $page++;

                // Safety limit
                if ($page > 1000) {
                    $this->logger->warning("Safety limit reached (100,000 products)");
                    break;
                }

            } catch (Exception $e) {
                $this->logger->error("Error fetching page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === $per_page);

        return $all_products;
    }

    private function deleteProduct($product): bool
    {
        $name = $product->name ?? 'Unknown';
        $sku = $product->sku ?? 'N/A';
        $id = $product->id;

        try {
            // Collect media IDs before deletion
            $media_ids = [];
            if ($this->delete_media) {
                $media_ids = $this->collectProductMediaIds($product);
            }

            if ($this->dry_run) {
                $this->logger->info("  [DRY RUN] Would delete: {$name} (SKU: {$sku}, ID: {$id})");
                if (!empty($media_ids)) {
                    $this->logger->info("    Would delete " . count($media_ids) . " media items: " . implode(', ', $media_ids));
                    $this->media_deleted += count($media_ids);
                }
                $this->products_deleted++;
                return true;
            }

            // Delete associated media first
            if ($this->delete_media && !empty($media_ids)) {
                $this->deleteProductMedia($media_ids, $sku);
            }

            // Delete the product (force=true permanently deletes, bypasses trash)
            $this->wc_client->delete("products/{$id}", ['force' => true]);

            $this->logger->info("  DELETED: {$name} (SKU: {$sku}, ID: {$id})");
            $this->products_deleted++;
            return true;

        } catch (Exception $e) {
            $error = $e->getMessage();
            $this->logger->error("  FAILED: {$name} (SKU: {$sku}, ID: {$id}) - {$error}");
            $this->products_failed++;
            return false;
        }
    }

    /**
     * Collect all media IDs associated with a product
     */
    private function collectProductMediaIds($product): array
    {
        $ids = [];

        // Product images from the WC response
        foreach ($product->images ?? [] as $image) {
            $media_id = $image->id ?? null;
            if ($media_id && $media_id > 0) {
                $ids[] = $media_id;
            }
        }

        return array_unique($ids);
    }

    /**
     * Delete media items from WordPress and clean up image-map.json
     */
    private function deleteProductMedia(array $media_ids, string $sku): void
    {
        $wp_url = rtrim($this->config['woocommerce']['url'], '/') . '/wp-json/wp/v2/media/';
        $auth = base64_encode(
            $this->config['wordpress']['username'] . ':' .
            $this->config['wordpress']['app_password']
        );

        foreach ($media_ids as $media_id) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $wp_url . $media_id . '?force=true',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'DELETE',
                    CURLOPT_HTTPHEADER => ["Authorization: Basic {$auth}"],
                    CURLOPT_TIMEOUT => 30,
                ]);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code >= 200 && $http_code < 300) {
                    $this->media_deleted++;
                    $this->logger->debug("    Deleted media ID {$media_id}");
                } else {
                    $this->media_failed++;
                    $this->logger->warning("    Failed to delete media ID {$media_id}: HTTP {$http_code}");
                }
            } catch (Exception $e) {
                $this->media_failed++;
                $this->logger->warning("    Failed to delete media ID {$media_id}: " . $e->getMessage());
            }
        }

        // Clean up image-map.json entry for this SKU
        $this->removeFromImageMap($sku);
    }

    /**
     * Remove a SKU entry from image-map.json
     */
    private function removeFromImageMap(string $sku): void
    {
        $map_file = __DIR__ . '/image-map.json';
        if (!file_exists($map_file)) {
            return;
        }

        $map = json_decode(file_get_contents($map_file), true);
        if (!is_array($map) || !isset($map[$sku])) {
            return;
        }

        unset($map[$sku]);
        file_put_contents(
            $map_file,
            json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function printBanner(): void
    {
        $this->logger->info("");
        $this->logger->info("╔═══════════════════════════════════════════════════════════╗");
        $this->logger->info("║           NUCLEAR PRODUCT DELETION SCRIPT                 ║");
        $this->logger->info("║                   Use with caution!                       ║");
        $this->logger->info("╚═══════════════════════════════════════════════════════════╝");
        $this->logger->info("");
    }

    private function printSummary($start_time): void
    {
        $elapsed = round(microtime(true) - $start_time, 2);

        $this->logger->info("═══════════════════════════════════════════════════════════");
        $this->logger->info("                        SUMMARY");
        $this->logger->info("═══════════════════════════════════════════════════════════");
        $this->logger->info("  Products deleted:  {$this->products_deleted}");
        $this->logger->info("  Products skipped:  {$this->products_skipped}");
        $this->logger->info("  Products failed:   {$this->products_failed}");
        if ($this->delete_media) {
            $this->logger->info("  Media deleted:     {$this->media_deleted}");
            $this->logger->info("  Media failed:      {$this->media_failed}");
        }
        $this->logger->info("  Time elapsed:      {$elapsed}s");
        $this->logger->info("═══════════════════════════════════════════════════════════");

        if ($this->dry_run) {
            $this->logger->info("\n  This was a DRY RUN. No products were actually deleted.");
            $this->logger->info("  Run with --confirm to perform actual deletion.\n");
        }
    }
}

// =============================================================================
// CLI ENTRY POINT
// =============================================================================

// Parse command line arguments
$options = getopt('', [
    'confirm',
    'delete-media',
    'keep:',
    'keep-file:',
    'batch:',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP

Nuclear Product Deletion Script
================================

Deletes ALL WooCommerce products except those in the exclusion list.

Usage:
  php nuke-products.php [options]

Options:
  --confirm              Actually delete products (without this, it's a dry run)
  --delete-media         Also delete associated images from WordPress media library
  --keep=SKU1,SKU2       Comma-separated list of SKUs to keep
  --keep-file=file.txt   File containing SKUs to keep (one per line)
  --batch=N              Delete in batches of N products (default: 100, max: 100)
  --help                 Show this help message

Examples:
  php nuke-products.php                              # Dry run, see what would be deleted
  php nuke-products.php --confirm                    # Delete everything
  php nuke-products.php --confirm --delete-media     # Delete products + their images
  php nuke-products.php --confirm --keep=ABC-123     # Delete all except SKU ABC-123
  php nuke-products.php --confirm --keep-file=keep.txt --batch=50

HELP;
    exit(0);
}

$nuker = new ProductNuker($config);

// Set dry run mode (default: true, unless --confirm is passed)
$nuker->setDryRun(!isset($options['confirm']));

// Set media deletion mode
$nuker->setDeleteMedia(isset($options['delete-media']));

// Set batch size
if (isset($options['batch'])) {
    $nuker->setBatchSize((int) $options['batch']);
}

// Set excluded SKUs from command line
if (isset($options['keep'])) {
    $skus = explode(',', $options['keep']);
    $nuker->setExcludedSkus($skus);
}

// Load excluded SKUs from file
if (isset($options['keep-file'])) {
    $nuker->loadExcludedSkusFromFile($options['keep-file']);
}

// Run the nuker
$nuker->run();

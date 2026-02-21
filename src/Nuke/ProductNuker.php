<?php

namespace ResellPiacenza\Nuke;

use Automattic\WooCommerce\Client;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;

/**
 * Nuclear Product Deletion
 *
 * Deletes ALL WooCommerce products except those in an exclusion list.
 * Also cleans up associated media from WordPress and orphaned entries
 * in image-map.json.
 *
 * @package ResellPiacenza\Nuke
 */
class ProductNuker
{
    private array $config;
    private $wc_client;
    private $logger;
    private bool $dry_run = true;
    private int $batch_size = 100;
    private array $excluded_skus = [];
    private bool $keep_media = false;

    // WordPress media API
    private string $wp_url;
    private string $wp_auth;

    // Stats
    private int $products_deleted = 0;
    private int $products_skipped = 0;
    private int $products_failed = 0;
    private int $variations_deleted = 0;
    private int $media_deleted = 0;
    private int $media_failed = 0;
    private int $image_map_cleaned = 0;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->logger = LoggerFactory::create('ProductNuker', [
            'file' => Config::projectRoot() . '/logs/nuke.log',
        ]);

        $url = trim($config['woocommerce']['url'] ?? '');
        if (empty($url)) {
            throw new \RuntimeException(
                'WC_URL is not configured. Set it in your .env file (e.g. WC_URL=https://your-store.com).'
            );
        }
        $url = rtrim($url, ':');

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \RuntimeException(
                "WC_URL is malformed: '{$url}'. Expected format: https://your-store.com"
            );
        }

        $this->wc_client = new Client(
            $url,
            $config['woocommerce']['consumer_key'],
            $config['woocommerce']['consumer_secret'],
            [
                'version' => $config['woocommerce']['version'],
                'timeout' => 120,
            ]
        );

        // WordPress REST API for media deletion
        $this->wp_url = rtrim($url, '/');
        $this->wp_auth = base64_encode(
            ($config['wordpress']['username'] ?? '') . ':' .
            ($config['wordpress']['app_password'] ?? '')
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

    public function setKeepMedia(bool $keep): self
    {
        $this->keep_media = $keep;
        return $this;
    }

    public function setExcludedSkus(array $skus): self
    {
        $this->excluded_skus = array_filter(array_map('trim', $skus));
        return $this;
    }

    public function loadExcludedSkusFromFile(string $filepath): self
    {
        if (!file_exists($filepath)) {
            $this->logger->error("Exclusion file not found: {$filepath}");
            return $this;
        }

        $content = file_get_contents($filepath);
        $skus = array_filter(array_map('trim', explode("\n", $content)));

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

        // Phase 2a: Delete media in parallel (before products, while references exist)
        if (!$this->keep_media) {
            $this->logger->info("Phase 2a: Deleting media in parallel...\n");
            $this->deleteMediaBatch($to_delete);
        }

        // Phase 2b: Delete products using WC batch API (100 at a time)
        $this->logger->info("\nPhase 2b: Batch-deleting products...\n");
        $this->deleteProductsBatch($to_delete);

        // Phase 3: Clean up image-map.json
        $this->logger->info("\nPhase 3: Cleaning up image-map.json...");
        $this->cleanImageMap($to_delete);

        $this->logger->info("");
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

                if ($page > 1000) {
                    $this->logger->warning("Safety limit reached (100,000 products)");
                    break;
                }

            } catch (\Exception $e) {
                $this->logger->error("Error fetching page {$page}: " . $e->getMessage());
                break;
            }
        } while (count($products) === $per_page);

        return $all_products;
    }

    /**
     * Delete products using the WC batch API (up to 100 per request)
     *
     * Much faster than per-product DELETE calls.
     * WC batch delete also removes all associated variations automatically.
     */
    private function deleteProductsBatch(array $products): void
    {
        $ids = array_map(fn($p) => $p->id, $products);
        $chunks = array_chunk($ids, $this->batch_size);
        $total_batches = count($chunks);

        foreach ($chunks as $batch_num => $chunk) {
            $this->logger->info("  Batch " . ($batch_num + 1) . "/{$total_batches}: " . count($chunk) . " products...");

            if ($this->dry_run) {
                $this->products_deleted += count($chunk);
                $this->logger->info("    [DRY RUN] Would delete " . count($chunk) . " products");
                continue;
            }

            try {
                $result = $this->wc_client->post('products/batch', [
                    'delete' => $chunk,
                ]);

                foreach ($result->delete ?? [] as $item) {
                    if (isset($item->error)) {
                        $this->products_failed++;
                        $this->logger->error("    FAILED: ID {$item->id} - " . ($item->error->message ?? 'Unknown'));
                    } else {
                        $this->products_deleted++;
                    }
                }

                $this->logger->info("    Deleted " . count($result->delete ?? []) . " products");

            } catch (\Exception $e) {
                $this->products_failed += count($chunk);
                $this->logger->error("    Batch failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Delete media items in parallel using curl_multi
     *
     * Collects all media IDs from the product list and deletes them
     * concurrently (10 at a time) via the WordPress REST API.
     */
    private function deleteMediaBatch(array $products): void
    {
        // Collect all unique media IDs
        $media_ids = [];
        foreach ($products as $product) {
            foreach ($product->images ?? [] as $img) {
                if (!empty($img->id)) {
                    $media_ids[$img->id] = true;
                }
            }
        }

        $media_ids = array_keys($media_ids);
        $total = count($media_ids);

        if ($total === 0) {
            $this->logger->info("  No media to delete");
            return;
        }

        if ($this->dry_run) {
            $this->media_deleted = $total;
            $this->logger->info("  [DRY RUN] Would delete {$total} media items");
            return;
        }

        $this->logger->info("  Deleting {$total} media items (concurrency: 10)...");

        // Process in groups of 10 concurrent requests
        $chunks = array_chunk($media_ids, 10);
        $processed = 0;

        foreach ($chunks as $chunk) {
            $handles = [];
            $mh = curl_multi_init();

            foreach ($chunk as $media_id) {
                $url = $this->wp_url . "/wp-json/wp/v2/media/{$media_id}?force=true";
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'DELETE',
                    CURLOPT_HTTPHEADER => ["Authorization: Basic {$this->wp_auth}"],
                    CURLOPT_TIMEOUT => 30,
                ]);
                $handles[$media_id] = $ch;
                curl_multi_add_handle($mh, $ch);
            }

            // Execute concurrently
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // Process results
            foreach ($handles as $media_id => $ch) {
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($http_code === 200) {
                    $this->media_deleted++;
                } else {
                    $this->media_failed++;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
            $processed += count($chunk);
            echo "\r  Media: {$processed}/{$total}          ";
        }
        echo "\n";
    }

    /**
     * Clean image-map.json entries for deleted products
     */
    private function cleanImageMap(array $deleted_products): void
    {
        $map_file = Config::imageMapFile();
        if (!file_exists($map_file)) {
            $this->logger->info("  No image-map.json found");
            return;
        }

        $image_map = json_decode(file_get_contents($map_file), true) ?: [];
        $deleted_skus = [];
        foreach ($deleted_products as $p) {
            $s = $p->sku ?? '';
            if (!empty($s)) {
                $deleted_skus[] = $s;
            }
        }

        foreach ($deleted_skus as $dsku) {
            if (isset($image_map[$dsku])) {
                unset($image_map[$dsku]);
                $this->image_map_cleaned++;
            }
        }

        if ($this->image_map_cleaned > 0 && !$this->dry_run) {
            file_put_contents(
                $map_file,
                json_encode($image_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            $this->logger->info("  Removed {$this->image_map_cleaned} entries from image-map.json");
        } elseif ($this->image_map_cleaned > 0) {
            $this->logger->info("  [DRY RUN] Would remove {$this->image_map_cleaned} entries from image-map.json");
        } else {
            $this->logger->info("  No entries to clean");
        }
    }

    private function printBanner(): void
    {
        $this->logger->info("");
        $this->logger->info("===============================================");
        $this->logger->info("  NUCLEAR PRODUCT DELETION");
        $this->logger->info("  Use with caution!");
        $this->logger->info("===============================================");
        $this->logger->info("");
    }

    private function printSummary(float $start_time): void
    {
        $elapsed = round(microtime(true) - $start_time, 2);

        $this->logger->info("===============================================");
        $this->logger->info("  SUMMARY");
        $this->logger->info("===============================================");
        $this->logger->info("  Products deleted:  {$this->products_deleted}");
        $this->logger->info("  Products skipped:  {$this->products_skipped}");
        $this->logger->info("  Products failed:   {$this->products_failed}");
        $this->logger->info("  Media deleted:     {$this->media_deleted}");
        $this->logger->info("  Media failed:      {$this->media_failed}");
        $this->logger->info("  Image-map cleaned: {$this->image_map_cleaned}");
        $this->logger->info("  Time elapsed:      {$elapsed}s");
        $this->logger->info("===============================================");

        if ($this->dry_run) {
            $this->logger->info("\n  This was a DRY RUN. No products were actually deleted.");
            $this->logger->info("  Run with --confirm to perform actual deletion.\n");
        }
    }
}

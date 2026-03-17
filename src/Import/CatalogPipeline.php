<?php

namespace ResellPiacenza\Import;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;
use ResellPiacenza\Support\Storage;
use ResellPiacenza\Taxonomy\TaxonomyManager;
use ResellPiacenza\Media\MediaUploader;

/**
 * Unified Catalog Pipeline Orchestrator
 *
 * Accepts any FeedAdapter(s) and runs the canonical import pipeline:
 *   Adapter::fetchProducts() → [merge] → [taxonomies] → [media] → WcProductBuilder → DeltaSync
 *
 * Replaces ad-hoc pipeline wiring in individual bin/ scripts with a single
 * reusable orchestrator. All import sources (KicksDB, GS, Shopify, CSV, JSON,
 * pre-built WC) flow through the same downstream stages.
 *
 * @package ResellPiacenza\Import
 */
class CatalogPipeline
{
    private array $config;
    private $logger;

    // Pipeline options
    private bool $dry_run;
    private bool $verbose;
    private bool $skip_taxonomies;
    private bool $skip_media;
    private bool $skip_build;
    private bool $force_full;
    private ?int $limit;

    // Optional external stage instances (caller-configured)
    private ?TaxonomyManager $taxonomyManager;
    private ?MediaUploader $mediaUploader;

    /** @var callable|null Post-build callback: fn(array $wcProducts): array */
    private $postBuildCallback;

    /** @var bool Write feed-wc-latest.json for debugging/backup */
    private bool $writeFeed;

    /** @var string|null Custom feed output path */
    private ?string $feedOutputPath;

    /** @var string|null Custom baseline file for DeltaSync (default: feed-wc.json) */
    private ?string $baselineFile;

    /** @var Storage|null SQLite storage for product + sync persistence */
    private ?Storage $storage;

    /**
     * @param array $config Full config from config.php
     * @param object|null $logger PSR-3 compatible logger
     * @param array $options Pipeline options:
     *   - dry_run: bool (default false)
     *   - verbose: bool (default false)
     *   - skip_taxonomies: bool (default false)
     *   - skip_media: bool (default false)
     *   - skip_build: bool (default false) — for pre-built WC JSON passthrough
     *   - force_full: bool (default false) — force full import, ignore delta
     *   - limit: int|null (default null) — limit number of products
     *   - taxonomy_manager: TaxonomyManager|null — pre-configured instance
     *   - media_uploader: MediaUploader|null — pre-configured instance
     *   - post_build: callable|null — fn(array $wcProducts): array
     *   - write_feed: bool (default true) — write feed-wc-latest.json
     *   - feed_output_path: string|null — custom feed output path
     *   - storage: Storage|null — SQLite storage instance
     */
    public function __construct(array $config, $logger = null, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->skip_taxonomies = $options['skip_taxonomies'] ?? false;
        $this->skip_media = $options['skip_media'] ?? false;
        $this->skip_build = $options['skip_build'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->limit = $options['limit'] ?? null;
        $this->taxonomyManager = $options['taxonomy_manager'] ?? null;
        $this->mediaUploader = $options['media_uploader'] ?? null;
        $this->postBuildCallback = $options['post_build'] ?? null;
        $this->writeFeed = $options['write_feed'] ?? true;
        $this->feedOutputPath = $options['feed_output_path'] ?? null;
        $this->baselineFile = $options['baseline_file'] ?? null;
        // Initialize Storage: use provided instance or auto-create from Config
        if (isset($options['storage'])) {
            $this->storage = $options['storage'];
        } else {
            try {
                $dbPath = Config::databasePath();
                $this->storage = Storage::getInstance($dbPath);
            } catch (\Throwable $e) {
                // SQLite not available — continue without storage, but log the reason
                $this->storage = null;
                if ($logger) {
                    $logger->warning('SQLite storage unavailable: ' . $e->getMessage());
                } else {
                    error_log('[CatalogPipeline] SQLite storage unavailable: ' . $e->getMessage());
                }
            }
        }

        if ($logger) {
            $this->logger = $logger;
        } else {
            $this->logger = LoggerFactory::create('CatalogPipeline', [
                'file' => Config::projectRoot() . '/logs/catalog-pipeline.log',
                'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
            ]);
        }
    }

    /**
     * Run the pipeline with one or more adapters
     *
     * When multiple adapters are given, products are merged via FeedMerger
     * (variation-level merge: first adapter is master, second is overlay).
     * When a single adapter is given, no merge step.
     *
     * @param FeedAdapter ...$adapters One or more feed adapters
     * @return PipelineResult Stats from each stage
     */
    public function run(FeedAdapter ...$adapters): PipelineResult
    {
        $start_time = microtime(true);
        $result = new PipelineResult();

        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Catalog Pipeline');
        $this->logger->info('================================');

        $sources = array_map(fn($a) => $a->getSourceName(), $adapters);
        $this->logger->info('  Sources: ' . implode(' + ', $sources));

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }
        if ($this->skip_taxonomies) {
            $this->logger->info('  Taxonomies: SKIP');
        }
        if ($this->skip_media) {
            $this->logger->info('  Media: SKIP');
        }
        if ($this->skip_build) {
            $this->logger->info('  Build: SKIP (pre-built WC JSON)');
        }
        if ($this->force_full) {
            $this->logger->warning('  FORCE FULL (ignore delta)');
        }
        if ($this->limit) {
            $this->logger->info("  Limit: {$this->limit}");
        }

        $this->logger->info('');

        try {
            // ================================================================
            // Stage 1: Fetch products from adapters
            // ================================================================

            $this->logger->info('Stage 1: Fetching products...');

            $adapterProducts = [];
            foreach ($adapters as $adapter) {
                $name = $adapter->getSourceName();
                $this->logger->info("  Fetching from {$name}...");

                $products = [];
                foreach ($adapter->fetchProducts() as $product) {
                    $products[] = $product;
                }

                $this->logger->info("  {$name}: " . count($products) . " products");
                $adapterProducts[$name] = $products;
                $result = $result->withAdapterStats($name, $adapter->getStats());
            }

            // ================================================================
            // Stage 2: Merge (if multiple adapters)
            // ================================================================

            if (count($adapterProducts) > 1) {
                $this->logger->info('');
                $this->logger->info('Stage 2: Merging feeds (variation-level)...');

                $feeds = array_values($adapterProducts);
                $merger = new FeedMerger($this->logger);
                $normalizedProducts = $merger->merge($feeds[0], $feeds[1]);
                $result = $result->withMergerStats($merger->getStats());

                $this->logger->info("  Merged: " . count($normalizedProducts) . " products");
            } else {
                $normalizedProducts = reset($adapterProducts) ?: [];
            }

            // Apply limit
            if ($this->limit && count($normalizedProducts) > $this->limit) {
                $normalizedProducts = array_slice($normalizedProducts, 0, $this->limit);
                $this->logger->info("  Limited to {$this->limit} products");
            }

            $result = $result->withProductsNormalized(count($normalizedProducts));

            // Store normalized products in SQLite
            if ($this->storage !== null && !$this->skip_build) {
                $this->storage->transaction(function (Storage $s) use ($normalizedProducts, $sources) {
                    $source = implode('+', $sources);
                    foreach ($normalizedProducts as $product) {
                        $sku = $product['sku'] ?? '';
                        if ($sku) {
                            $s->upsertProduct($sku, $product, $source);
                            foreach ($product['variations'] ?? [] as $var) {
                                $sizeEu = $var['size_eu'] ?? '';
                                if ($sizeEu) {
                                    $s->upsertVariation($sku, $sizeEu, $var, $source);
                                }
                            }
                        }
                    }
                });
                $this->logger->info("  Stored " . count($normalizedProducts) . " products in SQLite");
            }

            if (empty($normalizedProducts)) {
                $this->logger->warning('No products to process');
                $duration = round(microtime(true) - $start_time, 1);
                return $result->withDuration($duration);
            }

            // ================================================================
            // Stage 3: Taxonomies (optional)
            // ================================================================

            if (!$this->skip_taxonomies && $this->taxonomyManager !== null) {
                $this->logger->info('');
                $this->logger->info('Stage 3: Ensuring taxonomies...');
                $this->taxonomyManager->run();
            } else {
                $this->logger->info('');
                $this->logger->info('Stage 3: Taxonomies — skipped');
            }

            // ================================================================
            // Stage 4: Media (optional)
            // ================================================================

            if (!$this->skip_media && $this->mediaUploader !== null) {
                $this->logger->info('');
                $this->logger->info('Stage 4: Preparing media...');
                $this->mediaUploader->run();
            } else {
                $this->logger->info('');
                $this->logger->info('Stage 4: Media — skipped');
            }

            // ================================================================
            // Stage 5: Build WC payloads (or passthrough for pre-built)
            // ================================================================

            if (!$this->skip_build) {
                $this->logger->info('');
                $this->logger->info('Stage 5: Building WooCommerce payloads...');

                $builder = new WcProductBuilder($this->config, $this->logger);
                $wcProducts = $builder->buildAll($normalizedProducts);

                $result = $result->withBuilderStats($builder->getStats());
                $this->logger->info("  Built: " . count($wcProducts) . " WC products");

                // Store WC payloads + signatures in SQLite
                if ($this->storage !== null) {
                    $this->storage->transaction(function (Storage $s) use ($wcProducts) {
                        foreach ($wcProducts as $product) {
                            $sku = $product['sku'] ?? '';
                            if ($sku) {
                                $sigData = [
                                    'name' => $product['name'] ?? '',
                                    'variations' => [],
                                ];
                                foreach ($product['_variations'] ?? [] as $var) {
                                    $option = $var['attributes'][0]['option'] ?? '';
                                    $sigData['variations'][] = implode(':', [
                                        $option,
                                        $var['regular_price'] ?? '0',
                                        $var['stock_quantity'] ?? 0,
                                    ]);
                                }
                                sort($sigData['variations']);
                                $signature = md5(json_encode($sigData));

                                $s->setWcPayload($sku, $product, $signature);
                            }
                        }
                    });
                }

                // Post-build callback (catalog provenance, sorting, etc.)
                if ($this->postBuildCallback !== null) {
                    $this->logger->info('  Running post-build enrichment...');
                    $wcProducts = ($this->postBuildCallback)($wcProducts);
                }
            } else {
                // Pre-built WC JSON passthrough
                $wcProducts = $normalizedProducts;
                $this->logger->info('');
                $this->logger->info('Stage 5: Build — skipped (pre-built)');
            }

            $result = $result->withProductsBuilt(count($wcProducts));

            // ================================================================
            // Stage 6: Write feed + Delta sync
            // ================================================================

            $this->logger->info('');
            $this->logger->info('Stage 6: Delta sync...');

            if (empty($wcProducts)) {
                $this->logger->warning('No WC products to sync');
                $duration = round(microtime(true) - $start_time, 1);
                return $result->withDuration($duration);
            }

            // Write feed JSON (for DeltaSync and debugging)
            $feedFile = $this->feedOutputPath ?? Config::dataDir() . '/feed-wc-latest.json';
            if ($this->writeFeed) {
                file_put_contents(
                    $feedFile,
                    json_encode($wcProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                $this->logger->info("  Wrote feed: {$feedFile} (" . count($wcProducts) . " products)");
            }

            // Run DeltaSync
            $syncOptions = [
                'dry_run' => $this->dry_run,
                'force_full' => $this->force_full,
                'verbose' => $this->verbose,
                'feed_file' => $feedFile,
            ];
            if ($this->baselineFile !== null) {
                $syncOptions['baseline_file'] = $this->baselineFile;
            }
            $sync = new DeltaSync($this->config, $syncOptions, $this->storage);

            $syncSuccess = $sync->run();

            $result = $result->withSyncStats([
                'success' => $syncSuccess,
            ]);

            if (!$syncSuccess) {
                $result = $result->withError('Delta sync failed');
            }

            // ================================================================
            // Summary
            // ================================================================

            $duration = round(microtime(true) - $start_time, 1);
            $result = $result->withDuration($duration);

            $this->logger->info('');
            $this->logger->info('================================');
            $this->logger->info('  PIPELINE SUMMARY');
            $this->logger->info('================================');
            $this->logger->info("  Sources:          " . implode(' + ', $sources));
            $this->logger->info("  Products fetched: {$result->getProductsNormalized()}");
            $this->logger->info("  Products built:   {$result->getProductsBuilt()}");
            $this->logger->info("  Sync:             " . ($syncSuccess ? 'OK' : 'FAILED'));
            $this->logger->info("  Duration:         {$duration}s");
            $this->logger->info('================================');

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Pipeline error: ' . $e->getMessage());
            $duration = round(microtime(true) - $start_time, 1);
            return $result->withError($e->getMessage())->withDuration($duration);
        }
    }
}

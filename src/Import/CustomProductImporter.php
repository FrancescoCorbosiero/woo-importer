<?php

namespace ResellPiacenza\Import;

use Monolog\Logger;
use ResellPiacenza\Support\Config;
use ResellPiacenza\Support\LoggerFactory;
use ResellPiacenza\Taxonomy\TaxonomyManager;
use ResellPiacenza\Media\MediaUploader;

/**
 * Custom Product Importer
 *
 * Self-contained pipeline for importing custom-built products into WooCommerce.
 * Handles the full lifecycle: taxonomy creation, image sideloading from CDN URLs,
 * WC payload building, and delta sync.
 *
 * Reuses existing layers: TaxonomyManager, MediaUploader, WcProductBuilder,
 * CatalogPipeline (via inline FeedAdapter).
 *
 * Usage (single product):
 *   $importer = new CustomProductImporter($config, ['dry_run' => true]);
 *   $result = $importer->importProduct([
 *       'sku' => 'MY-001',
 *       'name' => 'Nike Dunk Low',
 *       'brand' => 'Nike',
 *       'category' => 'sneakers',
 *       'image_url' => 'https://cdn.example.com/image.jpg',
 *       'gallery_urls' => ['https://cdn.example.com/img2.jpg'],
 *       'sizes' => [
 *           ['size' => '40', 'price' => 149.00, 'stock' => 3],
 *           ['size' => '41', 'price' => 149.00, 'stock' => 5],
 *       ],
 *   ]);
 *
 * Usage (multiple products):
 *   $result = $importer->importProducts([$product1, $product2, ...]);
 *
 * @package ResellPiacenza\Import
 */
class CustomProductImporter
{
    private array $config;
    private $logger;

    private bool $dry_run;
    private bool $verbose;
    private bool $skip_media;
    private bool $skip_taxonomies;
    private bool $force_full;
    private ?int $limit;

    /**
     * @param array $config Full config from config.php
     * @param array $options Pipeline options:
     *   - dry_run: bool (default false)
     *   - verbose: bool (default false)
     *   - skip_media: bool (default false) — skip image sideloading
     *   - skip_taxonomies: bool (default false)
     *   - force_full: bool (default false) — force full import, ignore delta
     *   - limit: int|null (default null)
     */
    public function __construct(array $config, array $options = [])
    {
        $this->config = $config;
        $this->dry_run = $options['dry_run'] ?? false;
        $this->verbose = $options['verbose'] ?? false;
        $this->skip_media = $options['skip_media'] ?? false;
        $this->skip_taxonomies = $options['skip_taxonomies'] ?? false;
        $this->force_full = $options['force_full'] ?? false;
        $this->limit = $options['limit'] ?? null;

        $this->logger = LoggerFactory::create('CustomImport', [
            'file' => Config::projectRoot() . '/logs/custom-import.log',
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
     * Import a single product
     *
     * @param array $product Product definition
     * @return PipelineResult
     */
    public function importProduct(array $product): PipelineResult
    {
        return $this->importProducts([$product]);
    }

    /**
     * Import multiple products
     *
     * Product format:
     *   sku          (string, required) Product SKU
     *   name         (string, required) Product title
     *   brand        (string) Brand name
     *   category     (string) 'sneakers', 'clothing', or 'accessories' (default: 'sneakers')
     *   description  (string) Long description (HTML ok)
     *   short_description (string) Short description
     *   image_url    (string) Primary image CDN URL (sideloaded to WP media)
     *   gallery_urls (array)  Gallery image CDN URLs
     *   sizes/variations (array) Size entries:
     *     size/size_eu  (string) EU size or label (e.g. '42', 'M', 'One Size')
     *     price         (float)  Regular price
     *     sale_price    (float)  Optional sale price
     *     stock         (int)    Stock quantity
     *
     * @param array $products Array of product definitions
     * @return PipelineResult
     */
    public function importProducts(array $products): PipelineResult
    {
        $this->logger->info('');
        $this->logger->info('================================');
        $this->logger->info('  Custom Product Import');
        $this->logger->info('================================');
        $this->logger->info('  Products: ' . count($products));

        if ($this->dry_run) {
            $this->logger->warning('  DRY RUN');
        }

        // Extract unique brands for taxonomy creation
        $brands = $this->extractBrands($products);
        if (!empty($brands)) {
            $this->logger->info('  Brands: ' . implode(', ', $brands));
        }

        // --- Taxonomy Manager ---
        $taxonomyManager = null;
        if (!$this->skip_taxonomies && !empty($brands)) {
            $taxonomyManager = new TaxonomyManager($this->config, [
                'dry_run' => $this->dry_run,
                'verbose' => $this->verbose,
                'brand_source' => 'list',
                'brands_list' => $brands,
            ]);
        }

        // --- Media Uploader (image sideloading from CDN URLs) ---
        $mediaUploader = null;
        if (!$this->skip_media) {
            $urlsFile = $this->writeImageUrlsFile($products);
            if ($urlsFile) {
                $mediaUploader = new MediaUploader($this->config, [
                    'dry_run' => $this->dry_run,
                    'verbose' => $this->verbose,
                    'image_source' => 'file',
                    'urls_file' => $urlsFile,
                ]);
            }
        }

        // --- Inline FeedAdapter wrapping the products ---
        $adapter = new InlineProductAdapter($products);

        // --- Run CatalogPipeline ---
        // Use isolated feed + baseline files so custom-import never pollutes
        // the shared feed-wc.json used by catalog-build's DeltaSync.
        $pipeline = new CatalogPipeline($this->config, $this->logger, [
            'dry_run' => $this->dry_run,
            'verbose' => $this->verbose,
            'skip_media' => $this->skip_media || $mediaUploader === null,
            'skip_taxonomies' => $this->skip_taxonomies || $taxonomyManager === null,
            'force_full' => $this->force_full,
            'limit' => $this->limit,
            'taxonomy_manager' => $taxonomyManager,
            'media_uploader' => $mediaUploader,
            'feed_output_path' => Config::dataDir() . '/custom-import-feed-wc.json',
            'baseline_file' => Config::dataDir() . '/custom-import-baseline.json',
            'append_baseline' => true,
        ]);

        $result = $pipeline->run($adapter);

        // Clean up temp file
        if (isset($urlsFile) && $urlsFile && file_exists($urlsFile)) {
            @unlink($urlsFile);
        }

        return $result;
    }

    /**
     * Extract unique brand names from product list
     *
     * @param array $products Product definitions
     * @return array Unique brand names
     */
    private function extractBrands(array $products): array
    {
        $brands = [];
        foreach ($products as $p) {
            $brand = trim($p['brand'] ?? '');
            if ($brand !== '' && !in_array($brand, $brands, true)) {
                $brands[] = $brand;
            }
        }
        return $brands;
    }

    /**
     * Write a temporary JSON file with image URLs for MediaUploader
     *
     * Extracts image_url and gallery_urls from products and writes them
     * in the format expected by MediaUploader's 'file' source.
     *
     * @param array $products Product definitions
     * @return string|null Temp file path, or null if no images
     */
    private function writeImageUrlsFile(array $products): ?string
    {
        $images = [];
        foreach ($products as $p) {
            $sku = $p['sku'] ?? null;
            $url = $p['image_url'] ?? $p['image'] ?? null;
            if (!$sku || !$url) {
                continue;
            }
            $images[] = [
                'sku' => $sku,
                'url' => $url,
                'gallery_urls' => $p['gallery_urls'] ?? [],
                'product_name' => $p['name'] ?? $sku,
                'brand_name' => $p['brand'] ?? '',
            ];
        }

        if (empty($images)) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'custom_import_urls_') . '.json';
        file_put_contents($tmpFile, json_encode($images, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->logger->info("  Image URLs: {$tmpFile} (" . count($images) . " products)");

        return $tmpFile;
    }
}

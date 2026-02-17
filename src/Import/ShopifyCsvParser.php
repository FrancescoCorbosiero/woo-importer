<?php

namespace ResellPiacenza\Import;

use Monolog\Logger;
use ResellPiacenza\Support\LoggerFactory;

/**
 * Shopify CSV Product Export Parser
 *
 * Parses Shopify product export CSV files and converts them into
 * the normalized product format used by BulkUploader.
 *
 * Handles:
 * - Multi-row grouping by Handle (variants + gallery images)
 * - Brand extraction from Tags (with Title fallback)
 * - SKU extraction from HTML description
 * - Category auto-detection from size format (numeric → sneakers, letter → clothing)
 * - Gallery image collection ordered by Image Position
 * - Edge cases: single-row multi-size, "Default Title" simple products
 *
 * @package ResellPiacenza\Import
 */
class ShopifyCsvParser
{
    /**
     * Known brand names for Tag-based extraction (case-insensitive match)
     */
    private const DEFAULT_KNOWN_BRANDS = [
        'Nike', 'Jordan', 'Adidas', 'New Balance', 'Puma', 'Asics',
        'Reebok', 'Vans', 'Converse', 'Yeezy', 'Pop Mart', 'Saucony',
        'On Running', 'Hoka', 'Salomon', 'Diadora', 'Fila', 'Lacoste',
    ];

    /**
     * Option1 Name values that represent a size attribute
     */
    private const SIZE_OPTION_NAMES = [
        'taglie', 'taglia', 'numero di scarpa', 'size', 'sizes',
    ];

    private $logger;
    private $known_brands;
    private $verbose;

    private $stats = [
        'files_parsed' => 0,
        'rows_read' => 0,
        'products_parsed' => 0,
        'products_skipped' => 0,
        'skus_from_description' => 0,
        'skus_from_variant' => 0,
        'skus_generated' => 0,
        'brands_detected' => [],
    ];

    /**
     * @param array $options Parser options
     *   - 'known_brands' => string[] Override default known brands list
     *   - 'verbose' => bool Detailed logging
     */
    public function __construct(array $options = [])
    {
        $this->known_brands = $options['known_brands'] ?? self::DEFAULT_KNOWN_BRANDS;
        $this->verbose = $options['verbose'] ?? false;
        $this->setupLogger();
    }

    private function setupLogger(): void
    {
        $log_file = defined('PROJECT_ROOT')
            ? PROJECT_ROOT . '/logs/shopify-parser.log'
            : __DIR__ . '/../../logs/shopify-parser.log';

        $this->logger = LoggerFactory::create('ShopifyCSV', [
            'file' => $log_file,
            'console_level' => $this->verbose ? Logger::DEBUG : Logger::INFO,
        ]);
    }

    /**
     * Parse a single Shopify CSV file
     *
     * @param string $path Path to the CSV file
     * @return array Normalized products in BulkUploader format
     */
    public function parseFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \Exception("File not found: {$path}");
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \Exception("Cannot open file: {$path}");
        }

        // Read and map header columns
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \Exception("Empty CSV file: {$path}");
        }

        $cols = $this->mapColumns($header);
        $this->logger->debug("Parsed header: " . count($header) . " columns");

        // Group all rows by Handle
        $groups = [];
        $row_count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row_count++;
            $h = trim($row[$cols['handle']] ?? '');
            if (!$h) {
                continue;
            }

            if (!isset($groups[$h])) {
                $groups[$h] = [];
            }
            $groups[$h][] = $row;
        }

        fclose($handle);

        $this->stats['files_parsed']++;
        $this->stats['rows_read'] += $row_count;
        $this->logger->info("  Read {$row_count} rows, " . count($groups) . " product groups from " . basename($path));

        // Convert each group to a normalized product
        $products = [];
        foreach ($groups as $handle_slug => $rows) {
            $product = $this->parseProductGroup($handle_slug, $rows, $cols);
            if ($product) {
                $products[] = $product;
                $this->stats['products_parsed']++;
            } else {
                $this->stats['products_skipped']++;
            }
        }

        return $products;
    }

    /**
     * Parse all CSV files in a directory
     *
     * @param string $dir Directory path
     * @return array All normalized products
     */
    public function parseDirectory(string $dir): array
    {
        $dir = rtrim($dir, '/');

        if (!is_dir($dir)) {
            throw new \Exception("Directory not found: {$dir}");
        }

        $csv_files = glob($dir . '/*.csv');
        if (empty($csv_files)) {
            $this->logger->warning("No CSV files found in {$dir}");
            return [];
        }

        $this->logger->info("Found " . count($csv_files) . " CSV file(s) in {$dir}");

        $all_products = [];
        foreach ($csv_files as $file) {
            $this->logger->info("Parsing: " . basename($file));
            $products = $this->parseFile($file);
            $all_products = array_merge($all_products, $products);
        }

        return $all_products;
    }

    /**
     * Get parser stats
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    // =========================================================================
    // Column Mapping
    // =========================================================================

    /**
     * Map CSV header to column indexes
     */
    private function mapColumns(array $header): array
    {
        $header_lower = array_map(function ($h) {
            return strtolower(trim($h));
        }, $header);

        $find = function (string ...$names) use ($header_lower): ?int {
            foreach ($names as $name) {
                $idx = array_search(strtolower($name), $header_lower);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return null;
        };

        return [
            'handle'         => $find('Handle') ?? 0,
            'title'          => $find('Title'),
            'body'           => $find('Body (HTML)'),
            'vendor'         => $find('Vendor'),
            'type'           => $find('Type'),
            'tags'           => $find('Tags'),
            'published'      => $find('Published'),
            'option1_name'   => $find('Option1 Name'),
            'option1_value'  => $find('Option1 Value'),
            'variant_sku'    => $find('Variant SKU'),
            'variant_price'  => $find('Variant Price'),
            'compare_price'  => $find('Variant Compare At Price'),
            'cost'           => $find('Cost per item'),
            'image_src'      => $find('Image Src'),
            'image_position' => $find('Image Position'),
            'image_alt'      => $find('Image Alt Text'),
            'seo_title'      => $find('SEO Title'),
            'seo_description' => $find('SEO Description'),
            'status'         => $find('Status'),
            'variant_image'  => $find('Variant Image'),
        ];
    }

    // =========================================================================
    // Product Group Parsing
    // =========================================================================

    /**
     * Convert a group of rows (same Handle) into a normalized product
     *
     * @param string $handle_slug The Shopify handle/slug
     * @param array $rows All CSV rows for this product
     * @param array $cols Column index mapping
     * @return array|null Normalized product or null if skipped
     */
    private function parseProductGroup(string $handle_slug, array $rows, array $cols): ?array
    {
        // First row contains the main product data
        $main_row = $rows[0];

        // Check status — only import active products
        $status = $this->getCol($main_row, $cols['status']);
        if ($status && strtolower($status) !== 'active') {
            $this->logger->debug("  Skipped {$handle_slug}: status={$status}");
            return null;
        }

        $name = $this->getCol($main_row, $cols['title']) ?: $handle_slug;
        $body_html = $this->getCol($main_row, $cols['body']);
        $tags = $this->getCol($main_row, $cols['tags']);
        $type = $this->getCol($main_row, $cols['type']);
        $option1_name = $this->getCol($main_row, $cols['option1_name']);
        $option1_value = $this->getCol($main_row, $cols['option1_value']);

        // Extract brand from Tags (with Title fallback)
        $brand = $this->extractBrand($tags, $name);

        // Extract SKU
        $sku = $this->extractSku($rows, $cols, $body_html, $handle_slug);

        // Collect images (ordered by position)
        $images = $this->collectImages($rows, $cols);
        $primary_image = $images[0] ?? null;
        $gallery_images = array_slice($images, 1);

        // Collect size variants
        $sizes = $this->collectVariants($rows, $cols, $option1_name, $option1_value);

        // Auto-detect category from size format
        $category = $this->detectCategory($sizes, $type);

        // Track brand stats
        if ($brand && !in_array($brand, $this->stats['brands_detected'])) {
            $this->stats['brands_detected'][] = $brand;
        }

        $this->logger->debug("  {$handle_slug} → SKU={$sku}, brand={$brand}, cat={$category}, " .
            count($sizes) . " sizes, " . count($images) . " images");

        return [
            'sku' => $sku,
            'name' => $name,
            'brand' => $brand ?: '',
            'category' => $category,
            'image_url' => $primary_image,
            'gallery_urls' => $gallery_images,
            'short_description' => null, // Let BulkUploader use Italian template
            'description' => $body_html,
            'sizes' => $sizes,
        ];
    }

    // =========================================================================
    // Brand Extraction
    // =========================================================================

    /**
     * Extract brand name from Tags column, with Title fallback
     *
     * @param string|null $tags Comma-separated tags string
     * @param string $title Product title
     * @return string|null Detected brand name
     */
    private function extractBrand(?string $tags, string $title): ?string
    {
        // Strategy 1: Search Tags for known brands
        // Collect ALL matched brands, then pick the most specific one
        if ($tags) {
            $tag_list = array_map(function ($t) {
                return trim($t, " \t\n\r\0\x0B\"'");
            }, explode(',', $tags));

            $matched_brands = [];
            foreach ($this->known_brands as $brand) {
                foreach ($tag_list as $tag) {
                    if (strcasecmp($tag, $brand) === 0) {
                        $matched_brands[] = $brand;
                        break;
                    }
                }
            }

            if (!empty($matched_brands)) {
                // Before returning a tag-based match, check if the title starts with
                // a more specific sub-brand (e.g., Tags=Nike but Title="Jordan 4...")
                $title_upper = strtoupper($title);
                $parent_brands = ['Nike', 'Adidas'];

                // Check if title starts with a known brand different from tag matches
                foreach ($this->known_brands as $brand) {
                    $brand_upper = strtoupper($brand);
                    if (strpos($title_upper, $brand_upper) === 0 && !in_array($brand, $matched_brands)) {
                        // Title brand is not in tags — it's likely a sub-brand (e.g., Jordan)
                        return $brand;
                    }
                }

                // Single match — use it
                if (count($matched_brands) === 1) {
                    return $matched_brands[0];
                }

                // Multiple brands: prefer the one that appears in the title
                foreach ($matched_brands as $brand) {
                    if (strpos($title_upper, strtoupper($brand)) !== false) {
                        return $brand;
                    }
                }

                // Prefer sub-brands over parent brands (Jordan > Nike, Yeezy > Adidas)
                foreach ($matched_brands as $brand) {
                    if (!in_array($brand, $parent_brands)) {
                        return $brand;
                    }
                }

                return $matched_brands[0];
            }
        }

        // Strategy 2: Match from Title
        $title_upper = strtoupper($title);
        foreach ($this->known_brands as $brand) {
            $brand_upper = strtoupper($brand);
            if (strpos($title_upper, $brand_upper) === 0) {
                return $brand;
            }
            if (preg_match('/\b' . preg_quote($brand_upper, '/') . '\b/', $title_upper)) {
                return $brand;
            }
        }

        return null;
    }

    // =========================================================================
    // SKU Extraction
    // =========================================================================

    /**
     * Extract SKU from various sources
     *
     * Priority: Variant SKU column → HTML description patterns → Handle fallback
     */
    private function extractSku(array $rows, array $cols, ?string $body_html, string $handle_slug): string
    {
        // 1. Check Variant SKU column (any row)
        foreach ($rows as $row) {
            $variant_sku = trim($this->getCol($row, $cols['variant_sku']) ?? '');
            if ($variant_sku) {
                $this->stats['skus_from_variant']++;
                return $variant_sku;
            }
        }

        // 2. Extract from HTML description
        if ($body_html) {
            $sku = $this->extractSkuFromHtml($body_html);
            if ($sku) {
                $this->stats['skus_from_description']++;
                return $sku;
            }
        }

        // 3. Fallback: generate from Handle
        $this->stats['skus_generated']++;
        return 'SHOP-' . strtoupper(str_replace('-', '', $handle_slug));
    }

    /**
     * Extract SKU from HTML description using regex patterns
     *
     * Matches patterns like:
     *   SKU: DZ7293-100
     *   <strong>SKU:</strong> DZ7293-100
     *   <strong>SKU</strong>: DZ7293-100
     *   Style Code: FQ8232-100
     */
    private function extractSkuFromHtml(string $html): ?string
    {
        // Replace block-level tags with spaces before stripping to prevent word merging
        $text = preg_replace('/<br\s*\/?>|<\/?(p|div|li|td|tr|h\d)[^>]*>/i', ' ', $html);
        $text = strip_tags($text);

        // Pattern 1: SKU followed by a product code with hyphen (e.g., DZ7293-100, 310864-01)
        if (preg_match('/\bSKU\s*[:\s]\s*([A-Z0-9][A-Z0-9]+-[A-Z0-9]+)/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Pattern 2: SKU followed by alphanumeric code without hyphen (e.g., JR9633)
        if (preg_match('/\bSKU\s*[:\s]\s*([A-Z]{1,3}[0-9]{3,}[A-Z0-9]*)/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Pattern 3: Style Code
        if (preg_match('/Style\s*Code\s*[:\s]\s*([A-Z0-9][A-Z0-9]+(?:-[A-Z0-9]+)?)/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        // Pattern 4: Codice Stile / Articolo (Italian)
        if (preg_match('/Codice\s*(?:Stile|Articolo)\s*[:\s]\s*([A-Z0-9][A-Z0-9]+(?:-[A-Z0-9]+)?)/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }

        return null;
    }

    // =========================================================================
    // Image Collection
    // =========================================================================

    /**
     * Collect all images from rows, ordered by Image Position
     *
     * @return string[] Image URLs ordered by position
     */
    private function collectImages(array $rows, array $cols): array
    {
        $images = [];

        foreach ($rows as $row) {
            $src = trim($this->getCol($row, $cols['image_src']) ?? '');
            $pos = (int) ($this->getCol($row, $cols['image_position']) ?? 0);

            if ($src) {
                $images[] = ['url' => $src, 'position' => $pos ?: (count($images) + 1)];
            }
        }

        // Sort by position
        usort($images, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($images as $img) {
            if (!isset($seen[$img['url']])) {
                $seen[$img['url']] = true;
                $unique[] = $img['url'];
            }
        }

        return $unique;
    }

    // =========================================================================
    // Variant Collection
    // =========================================================================

    /**
     * Collect size variants from rows
     *
     * Handles:
     * - Normal: one row per size (Option1 Value = single size)
     * - Expanded: single row with all sizes dash-separated (e.g. "35-5-36-36-5-37...")
     * - Simple: "Default Title" = single-variant product
     */
    private function collectVariants(array $rows, array $cols, ?string $option1_name, ?string $first_option_value): array
    {
        $is_size_attr = $this->isSizeAttribute($option1_name);

        // Check if first row has a "multi-size" value (all sizes in one field)
        if ($first_option_value && $is_size_attr && $this->isMultiSizeValue($first_option_value)) {
            return $this->expandMultiSizeValue($first_option_value, $rows[0], $cols);
        }

        $sizes = [];
        $seen_sizes = [];

        foreach ($rows as $row) {
            $opt_value = trim($this->getCol($row, $cols['option1_value']) ?? '');
            $price = trim($this->getCol($row, $cols['variant_price']) ?? '');

            // Skip rows without option value (image-only rows)
            if (!$opt_value) {
                continue;
            }

            // Handle "Default Title" — simple product
            if (strtolower($opt_value) === 'default title') {
                $sizes[] = [
                    'size' => 'Unica',
                    'price' => $price ?: '0',
                    'stock' => 1,
                ];
                continue;
            }

            // Normal size variant
            if ($is_size_attr || $this->looksLikeSize($opt_value)) {
                $size = $this->normalizeSize($opt_value);
                if ($size && !isset($seen_sizes[$size])) {
                    $seen_sizes[$size] = true;
                    $sizes[] = [
                        'size' => $size,
                        'price' => $price ?: '0',
                        'stock' => 1,
                    ];
                }
            }
        }

        return $sizes;
    }

    /**
     * Check if Option1 Name represents a size attribute
     */
    private function isSizeAttribute(?string $option_name): bool
    {
        if (!$option_name) {
            return false;
        }
        return in_array(strtolower(trim($option_name)), self::SIZE_OPTION_NAMES);
    }

    /**
     * Check if a value looks like it contains multiple sizes (dash-separated)
     *
     * e.g. "35-5-36-36-5-37-37-5-38-38-5-39-40-40-5-41-42-42-5-43-44-44-5-45-45-5-46-46-5-47"
     */
    private function isMultiSizeValue(string $value): bool
    {
        // If it has more than 3 dashes and contains numeric values, it's likely multi-size
        $dash_count = substr_count($value, '-');
        return $dash_count > 5 && preg_match('/^\d/', $value);
    }

    /**
     * Expand a dash-separated multi-size value into individual size entries
     *
     * "35-5-36-36-5-37" → ["35.5", "36", "36.5", "37"]
     * Logic: a number followed by "-5" means it's a .5 size
     */
    private function expandMultiSizeValue(string $value, array $row, array $cols): array
    {
        $price = trim($this->getCol($row, $cols['variant_price']) ?? '0');
        $parts = explode('-', $value);
        $sizes = [];
        $i = 0;

        while ($i < count($parts)) {
            $current = $parts[$i];

            if (!is_numeric($current)) {
                $i++;
                continue;
            }

            // Check if next part is "5" (meaning .5)
            if (isset($parts[$i + 1]) && $parts[$i + 1] === '5') {
                $sizes[] = [
                    'size' => $current . '.5',
                    'price' => $price,
                    'stock' => 1,
                ];
                $i += 2;
            } else {
                $sizes[] = [
                    'size' => $current,
                    'price' => $price,
                    'stock' => 1,
                ];
                $i++;
            }
        }

        return $sizes;
    }

    /**
     * Check if a value looks like a shoe/clothing size
     */
    private function looksLikeSize(string $value): bool
    {
        // Numeric sizes: 35, 36.5, 42, etc.
        if (preg_match('/^\d{1,2}(\.\d)?$/', $value)) {
            return true;
        }
        // Letter sizes: S, M, L, XL, XXL, XS, etc.
        if (preg_match('/^(X{0,2}[SML]|XXL)$/i', $value)) {
            return true;
        }
        // "One Size", "Unica", etc.
        if (preg_match('/^(one\s*size|unica|taglia\s*unica)$/i', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Normalize a size value
     */
    private function normalizeSize(string $value): string
    {
        $value = trim($value);
        // Already normalized (36, 37.5, S, M, L, etc.)
        return $value;
    }

    // =========================================================================
    // Category Detection
    // =========================================================================

    /**
     * Auto-detect category from size format
     *
     * Same logic as WcProductBuilder::normalizeCategoryType()
     */
    private function detectCategory(array $sizes, ?string $shopify_type): string
    {
        if (empty($sizes)) {
            // Use Shopify Type if available
            if ($shopify_type) {
                $lower = strtolower($shopify_type);
                if (in_array($lower, ['shoes', 'sneakers', 'footwear'])) {
                    return 'sneakers';
                }
                if (in_array($lower, ['clothing', 'apparel', 'abbigliamento'])) {
                    return 'clothing';
                }
            }
            return 'sneakers'; // default
        }

        // Check first non-"Unica" size
        foreach ($sizes as $size) {
            $s = $size['size'];
            if (strtolower($s) === 'unica') {
                continue;
            }
            // Letter size → clothing
            if (preg_match('/^(X{0,2}[SML]|XXL)$/i', $s)) {
                return 'clothing';
            }
            // Numeric → sneakers
            if (is_numeric($s)) {
                return 'sneakers';
            }
        }

        return 'sneakers';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Safely get a column value from a row
     */
    private function getCol(array $row, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }
        $val = $row[$index] ?? null;
        return $val !== null ? (string) $val : null;
    }
}

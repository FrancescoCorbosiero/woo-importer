<?php

namespace ResellPiacenza\Import;

/**
 * Bulk Upload Feed Adapter
 *
 * Wraps CSV or JSON file parsing into the FeedAdapter interface.
 * Normalizes the simple bulk upload format (sku, name, brand, category,
 * image_url, sizes) into the canonical FeedAdapter product structure.
 *
 * Also handles Shopify CSV directories via ShopifyCsvParser.
 *
 * @package ResellPiacenza\Import
 */
class BulkUploadAdapter implements FeedAdapter
{
    private string $filePath;
    private string $format;
    private ?int $limit;
    private $logger;
    private bool $verbose;

    private array $stats = [
        'total' => 0,
        'fetched' => 0,
        'format' => '',
    ];

    /**
     * @param string $filePath Path to CSV, JSON, or Shopify CSV directory
     * @param string $format One of: 'csv', 'json', 'shopify-dir', 'auto'
     * @param object|null $logger PSR-3 compatible logger
     * @param int|null $limit Max products to return
     * @param bool $verbose Verbose logging for ShopifyCsvParser
     */
    public function __construct(
        string $filePath,
        string $format = 'auto',
        $logger = null,
        ?int $limit = null,
        bool $verbose = false
    ) {
        $this->filePath = $filePath;
        $this->format = $format;
        $this->logger = $logger;
        $this->limit = $limit;
        $this->verbose = $verbose;

        if ($this->format === 'auto') {
            $this->format = $this->detectFormat($filePath);
        }

        $this->stats['format'] = $this->format;
    }

    public function getSourceName(): string
    {
        return 'BulkUpload';
    }

    /**
     * Parse the file and yield normalized products
     *
     * @return iterable Normalized products in FeedAdapter format
     */
    public function fetchProducts(): iterable
    {
        $rawProducts = $this->parseFile();

        $this->stats['total'] = count($rawProducts);

        if ($this->limit && count($rawProducts) > $this->limit) {
            $rawProducts = array_slice($rawProducts, 0, $this->limit);
        }

        foreach ($rawProducts as $raw) {
            $normalized = $this->normalizeProduct($raw);
            if ($normalized) {
                $this->stats['fetched']++;
                yield $normalized;
            }
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Detect format from file extension or directory
     */
    private function detectFormat(string $path): string
    {
        if (is_dir($path)) {
            return 'shopify-dir';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => 'json',
            'csv' => 'csv',
            default => throw new \RuntimeException("Cannot auto-detect format for: {$path}"),
        };
    }

    /**
     * Parse file into raw product arrays (BulkUploader format)
     */
    private function parseFile(): array
    {
        return match ($this->format) {
            'json' => $this->parseJson($this->filePath),
            'csv' => $this->parseCsv($this->filePath),
            'shopify-dir' => $this->parseShopifyDir($this->filePath),
            default => throw new \RuntimeException("Unsupported format: {$this->format}"),
        };
    }

    /**
     * Parse JSON file
     */
    private function parseJson(string $path): array
    {
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        return $data;
    }

    /**
     * Parse CSV file (one row per variation, grouped by SKU)
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$path}");
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new \RuntimeException('Empty CSV file');
        }

        $header = array_map('trim', array_map('strtolower', $header));

        $col = function (string ...$names) use ($header): ?int {
            foreach ($names as $name) {
                $idx = array_search($name, $header);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return null;
        };

        $i_sku = $col('sku');
        $i_name = $col('name', 'product_name');
        $i_brand = $col('brand', 'brand_name');
        $i_cat = $col('category', 'cat');
        $i_image = $col('image_url', 'image', 'img');
        $i_size = $col('size', 'size_eu', 'taglia');
        $i_price = $col('price', 'regular_price');
        $i_sale = $col('sale_price', 'sale', 'prezzo_in_offerta');
        $i_stock = $col('stock', 'stock_quantity', 'qty');

        if ($i_sku === null) {
            fclose($handle);
            throw new \RuntimeException('CSV must have a "sku" column');
        }

        $grouped = [];
        while (($row = fgetcsv($handle)) !== false) {
            $sku = trim($row[$i_sku] ?? '');
            if (!$sku) {
                continue;
            }

            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'name' => $i_name !== null ? trim($row[$i_name]) : $sku,
                    'brand' => $i_brand !== null ? trim($row[$i_brand]) : '',
                    'category' => $i_cat !== null ? trim($row[$i_cat]) : 'sneakers',
                    'image_url' => $i_image !== null ? trim($row[$i_image]) : null,
                    'sizes' => [],
                ];
            }

            if ($i_size !== null) {
                $size_entry = [
                    'size' => trim($row[$i_size] ?? ''),
                    'price' => (string) ($i_price !== null ? trim($row[$i_price]) : '0'),
                    'stock' => (int) ($i_stock !== null ? trim($row[$i_stock]) : 0),
                ];
                if ($i_sale !== null && trim($row[$i_sale] ?? '') !== '') {
                    $size_entry['sale_price'] = (string) trim($row[$i_sale]);
                }
                $grouped[$sku]['sizes'][] = $size_entry;
            }
        }

        fclose($handle);
        return array_values($grouped);
    }

    /**
     * Parse Shopify CSV directory via ShopifyCsvParser
     */
    private function parseShopifyDir(string $dir): array
    {
        $parser = new ShopifyCsvParser(['verbose' => $this->verbose]);
        return $parser->parseDirectory($dir);
    }

    /**
     * Normalize a raw product (BulkUploader/Shopify format) to FeedAdapter format
     */
    private function normalizeProduct(array $raw): ?array
    {
        $sku = $raw['sku'] ?? null;
        if (!$sku) {
            return null;
        }

        $variations = [];
        $sizes = $raw['sizes'] ?? $raw['variations'] ?? [];
        foreach ($sizes as $size) {
            $sizeEu = (string) ($size['size'] ?? $size['size_eu'] ?? '');
            $price = (float) ($size['price'] ?? $size['regular_price'] ?? 0);
            $stock = (int) ($size['stock'] ?? $size['stock_quantity'] ?? 0);
            $salePrice = $size['sale_price'] ?? null;

            $var = [
                'size_eu' => $sizeEu,
                'price' => $price,
                'stock_quantity' => $stock,
                'stock_status' => $stock > 0 ? 'instock' : 'outofstock',
            ];

            if ($salePrice !== null && $salePrice !== '') {
                $var['sale_price'] = (float) $salePrice;
            }

            $variations[] = $var;
        }

        return [
            'sku' => $sku,
            'name' => $raw['name'] ?? $sku,
            'brand' => $raw['brand'] ?? $raw['brand_name'] ?? '',
            'model' => '',
            'gender' => '',
            'colorway' => '',
            'release_date' => '',
            'retail_price' => '',
            'description' => $raw['description'] ?? $raw['short_description'] ?? '',
            'category_type' => $raw['category'] ?? 'sneakers',
            'image_url' => $raw['image_url'] ?? $raw['image'] ?? null,
            'gallery_urls' => $raw['gallery_urls'] ?? [],
            'meta_data' => [
                ['key' => '_source', 'value' => 'bulk_upload'],
            ],
            'variations' => $variations,
        ];
    }
}

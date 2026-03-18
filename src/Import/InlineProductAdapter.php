<?php

namespace ResellPiacenza\Import;

/**
 * Inline Product Feed Adapter
 *
 * Wraps an in-memory array of product definitions into the FeedAdapter interface.
 * Uses the same normalization logic as BulkUploadAdapter (simple product format
 * with sku, name, brand, category, sizes).
 *
 * @package ResellPiacenza\Import
 */
class InlineProductAdapter implements FeedAdapter
{
    private array $products;
    private array $stats = [
        'total' => 0,
        'fetched' => 0,
    ];

    /**
     * @param array $products Array of product definitions
     */
    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function getSourceName(): string
    {
        return 'CustomProduct';
    }

    /**
     * Normalize and yield products in FeedAdapter format
     *
     * @return iterable Normalized products
     */
    public function fetchProducts(): iterable
    {
        $this->stats['total'] = count($this->products);

        foreach ($this->products as $raw) {
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
     * Normalize a raw product to FeedAdapter format
     *
     * @param array $raw Raw product definition
     * @return array|null Normalized product or null if invalid
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
            'brand' => $raw['brand'] ?? '',
            'model' => $raw['model'] ?? '',
            'gender' => $raw['gender'] ?? '',
            'colorway' => $raw['colorway'] ?? '',
            'release_date' => $raw['release_date'] ?? '',
            'retail_price' => $raw['retail_price'] ?? '',
            'description' => $raw['description'] ?? $raw['short_description'] ?? '',
            'category_type' => $raw['category'] ?? 'sneakers',
            'image_url' => $raw['image_url'] ?? $raw['image'] ?? null,
            'gallery_urls' => $raw['gallery_urls'] ?? [],
            'meta_data' => [
                ['key' => '_source', 'value' => 'custom_import'],
            ],
            'variations' => $variations,
        ];
    }
}

<?php

namespace ResellPiacenza\Import;

/**
 * WooCommerce JSON Passthrough Adapter
 *
 * Passes pre-built WooCommerce REST API formatted products through the pipeline
 * without transformation. Used with CatalogPipeline in skip-build mode.
 *
 * Products are already in final WC format (name, sku, type, categories,
 * attributes, _variations, etc.) — they skip taxonomy, media, and build stages
 * and go directly to delta sync.
 *
 * @package ResellPiacenza\Import
 */
class WcJsonAdapter implements FeedAdapter
{
    private string $filePath;
    private ?int $limit;
    private array $wcProducts = [];

    private array $stats = [
        'total' => 0,
        'fetched' => 0,
    ];

    /**
     * @param string $filePath Path to WC-formatted JSON file
     * @param int|null $limit Max products to return
     */
    public function __construct(string $filePath, ?int $limit = null)
    {
        $this->filePath = $filePath;
        $this->limit = $limit;
    }

    public function getSourceName(): string
    {
        return 'WcJson';
    }

    /**
     * Load and yield WC-formatted products as-is
     *
     * These products are NOT in normalized FeedAdapter format — they are
     * already WC REST API payloads. The CatalogPipeline should be configured
     * with skip_build=true when using this adapter.
     *
     * @return iterable WC-formatted product arrays
     */
    public function fetchProducts(): iterable
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException("WC JSON file not found: {$this->filePath}");
        }

        $data = json_decode(file_get_contents($this->filePath), true);

        if ($data === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Expected JSON array of products');
        }

        $this->stats['total'] = count($data);

        if ($this->limit && count($data) > $this->limit) {
            $data = array_slice($data, 0, $this->limit);
        }

        foreach ($data as $product) {
            $this->stats['fetched']++;
            yield $product;
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}

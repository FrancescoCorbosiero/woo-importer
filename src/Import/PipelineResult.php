<?php

namespace ResellPiacenza\Import;

/**
 * Pipeline Result Value Object
 *
 * Captures stats from each stage of the CatalogPipeline run.
 * Immutable after construction — stages append their stats via with*() methods.
 *
 * @package ResellPiacenza\Import
 */
class PipelineResult
{
    private bool $success;
    private float $duration = 0.0;
    private array $adapterStats = [];
    private array $mergerStats = [];
    private array $taxonomyStats = [];
    private array $mediaStats = [];
    private array $builderStats = [];
    private array $syncStats = [];
    private array $errors = [];
    private int $productsNormalized = 0;
    private int $productsBuilt = 0;

    public function __construct(bool $success = true)
    {
        $this->success = $success;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function withSuccess(bool $success): self
    {
        $clone = clone $this;
        $clone->success = $success;
        return $clone;
    }

    public function withDuration(float $duration): self
    {
        $clone = clone $this;
        $clone->duration = $duration;
        return $clone;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function withAdapterStats(string $name, array $stats): self
    {
        $clone = clone $this;
        $clone->adapterStats[$name] = $stats;
        return $clone;
    }

    public function getAdapterStats(): array
    {
        return $this->adapterStats;
    }

    public function withMergerStats(array $stats): self
    {
        $clone = clone $this;
        $clone->mergerStats = $stats;
        return $clone;
    }

    public function getMergerStats(): array
    {
        return $this->mergerStats;
    }

    public function withTaxonomyStats(array $stats): self
    {
        $clone = clone $this;
        $clone->taxonomyStats = $stats;
        return $clone;
    }

    public function getTaxonomyStats(): array
    {
        return $this->taxonomyStats;
    }

    public function withMediaStats(array $stats): self
    {
        $clone = clone $this;
        $clone->mediaStats = $stats;
        return $clone;
    }

    public function getMediaStats(): array
    {
        return $this->mediaStats;
    }

    public function withBuilderStats(array $stats): self
    {
        $clone = clone $this;
        $clone->builderStats = $stats;
        return $clone;
    }

    public function getBuilderStats(): array
    {
        return $this->builderStats;
    }

    public function withSyncStats(array $stats): self
    {
        $clone = clone $this;
        $clone->syncStats = $stats;
        return $clone;
    }

    public function getSyncStats(): array
    {
        return $this->syncStats;
    }

    public function withProductsNormalized(int $count): self
    {
        $clone = clone $this;
        $clone->productsNormalized = $count;
        return $clone;
    }

    public function getProductsNormalized(): int
    {
        return $this->productsNormalized;
    }

    public function withProductsBuilt(int $count): self
    {
        $clone = clone $this;
        $clone->productsBuilt = $count;
        return $clone;
    }

    public function getProductsBuilt(): int
    {
        return $this->productsBuilt;
    }

    public function withError(string $error): self
    {
        $clone = clone $this;
        $clone->errors[] = $error;
        $clone->success = false;
        return $clone;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get a summary array suitable for logging
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'duration' => round($this->duration, 1),
            'products_normalized' => $this->productsNormalized,
            'products_built' => $this->productsBuilt,
            'adapter_stats' => $this->adapterStats,
            'merger_stats' => $this->mergerStats,
            'taxonomy_stats' => $this->taxonomyStats,
            'media_stats' => $this->mediaStats,
            'builder_stats' => $this->builderStats,
            'sync_stats' => $this->syncStats,
            'errors' => $this->errors,
        ];
    }
}

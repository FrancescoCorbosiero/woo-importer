<?php

namespace ResellPiacenza\Pricing;

use ResellPiacenza\KicksDb\VariantParser;

/**
 * WooCommerce Price Updater
 *
 * Takes market price data (from KicksDB webhook or reconciliation),
 * applies margin via PriceCalculator, and patches WooCommerce variations.
 *
 * @package ResellPiacenza\Pricing
 */
class PriceUpdater
{
    private $wc_client;
    private PriceCalculator $calculator;
    private $logger;
    private array $config;

    private int $batch_size;
    private float $alert_threshold;
    private ?string $alert_email;
    private string $store_name;
    private bool $dry_run;

    private array $stats = [
        'variations_checked' => 0,
        'variations_updated' => 0,
        'variations_skipped' => 0,
        'alerts_sent' => 0,
        'errors' => 0,
        'batch_requests' => 0,
    ];

    public function __construct($wc_client, array $pricing_config, $logger = null, bool $dry_run = false)
    {
        $this->wc_client = $wc_client;
        $this->config = $pricing_config;
        $this->logger = $logger;
        $this->dry_run = $dry_run;

        $this->calculator = new PriceCalculator($pricing_config['margin'] ?? []);
        $this->batch_size = min((int) ($pricing_config['batch_size'] ?? 100), 100);
        $this->alert_threshold = (float) ($pricing_config['alert_threshold'] ?? 30);
        $this->alert_email = $pricing_config['alert_email'] ?? null;
        $this->store_name = $pricing_config['store_name'] ?? 'Store';
    }

    public function updateProductPrices(string $sku, array $kicksdb_variants): array
    {
        $result = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        $wc_product = $this->findWcProduct($sku);
        if ($wc_product === null) {
            $this->log('warning', "Product not found in WC: {$sku}");
            $result['errors']++;
            return $result;
        }

        $product_id = $wc_product->id;
        $product_name = $wc_product->name ?? $sku;

        $wc_variations = $this->fetchWcVariations($product_id);
        if (empty($wc_variations)) {
            $this->log('warning', "No variations found for product {$sku} (ID: {$product_id})");
            $result['errors']++;
            return $result;
        }

        $kicksdb_price_map = $this->buildKicksDbPriceMap($kicksdb_variants);

        $to_update = [];

        foreach ($wc_variations as $wc_var) {
            $this->stats['variations_checked']++;

            $size = $this->extractSizeFromVariation($wc_var);
            if ($size === null) {
                continue;
            }

            $market_price = $kicksdb_price_map[$size] ?? null;
            if ($market_price === null) {
                $this->log('debug', "  No KicksDB price for size {$size} of {$sku}");
                $result['skipped']++;
                $this->stats['variations_skipped']++;
                continue;
            }

            $breakdown = $this->calculator->calculateWithBreakdown($market_price);
            $new_price = $breakdown['final_price'];
            $current_price = (float) ($wc_var->regular_price ?? 0);

            if (abs($current_price - $new_price) < 0.01) {
                $result['skipped']++;
                $this->stats['variations_skipped']++;
                continue;
            }

            if ($current_price > 0 && $this->alert_threshold > 0) {
                $change_pct = abs(($new_price - $current_price) / $current_price) * 100;
                if ($change_pct >= $this->alert_threshold) {
                    $this->sendPriceAlert($sku, $product_name, $size, $current_price, $new_price, $change_pct, $breakdown);
                }
            }

            $to_update[] = [
                'id' => $wc_var->id,
                'regular_price' => (string) $new_price,
            ];

            $var_sku = $wc_var->sku ?? "{$sku}-{$size}";
            $this->log('info', "  Price update: {$var_sku} size {$size}: €{$current_price} → €{$new_price} " .
                "(market: €{$breakdown['market_price']}, margin: {$breakdown['margin_pct']}% [{$breakdown['margin_type']}]" .
                ($breakdown['floor_applied'] ? ', floor applied' : '') . ")");
        }

        if (empty($to_update)) {
            $this->log('info', "  No price changes for {$sku}");
            return $result;
        }

        $updated = $this->batchUpdateVariations($product_id, $to_update);
        $result['updated'] = $updated;
        $result['errors'] += count($to_update) - $updated;

        return $result;
    }

    public function bulkUpdatePrices(array $products_data): array
    {
        $totals = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'products' => 0];

        foreach ($products_data as $data) {
            $sku = $data['sku'] ?? null;
            $variants = $data['variants'] ?? [];

            if (!$sku || empty($variants)) {
                continue;
            }

            $this->log('info', "Processing {$sku} (" . count($variants) . " variants)...");
            $result = $this->updateProductPrices($sku, $variants);

            $totals['updated'] += $result['updated'];
            $totals['skipped'] += $result['skipped'];
            $totals['errors'] += $result['errors'];
            $totals['products']++;
        }

        return $totals;
    }

    private function findWcProduct(string $sku): ?object
    {
        try {
            $products = $this->wc_client->get('products', [
                'sku' => $sku,
                'per_page' => 1,
            ]);

            return !empty($products) ? $products[0] : null;
        } catch (\Exception $e) {
            $this->log('error', "WC API error finding product {$sku}: " . $e->getMessage());
            $this->stats['errors']++;
            return null;
        }
    }

    private function fetchWcVariations(int $product_id): array
    {
        $all_variations = [];
        $page = 1;

        do {
            try {
                $variations = $this->wc_client->get("products/{$product_id}/variations", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

                $all_variations = array_merge($all_variations, (array) $variations);
                $page++;
            } catch (\Exception $e) {
                $this->log('error', "Error fetching variations for product {$product_id}: " . $e->getMessage());
                break;
            }
        } while (count($variations) === 100);

        return $all_variations;
    }

    private function batchUpdateVariations(int $product_id, array $updates): int
    {
        if ($this->dry_run) {
            $this->log('info', "  [DRY RUN] Would update " . count($updates) . " variations for product {$product_id}");
            $this->stats['variations_updated'] += count($updates);
            return count($updates);
        }

        $updated = 0;
        $chunks = array_chunk($updates, $this->batch_size);

        foreach ($chunks as $chunk) {
            try {
                $result = $this->wc_client->post(
                    "products/{$product_id}/variations/batch",
                    ['update' => $chunk]
                );
                $this->stats['batch_requests']++;

                foreach ($result->update ?? [] as $item) {
                    if (isset($item->error)) {
                        $this->stats['errors']++;
                        $this->log('error', "  Variation update error [{$item->id}]: " . ($item->error->message ?? 'Unknown'));
                    } else {
                        $updated++;
                        $this->stats['variations_updated']++;
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors'] += count($chunk);
                $this->log('error', "  Variation batch update failed [product:{$product_id}]: " . $e->getMessage());
            }
        }

        return $updated;
    }

    private function buildKicksDbPriceMap(array $kicksdb_variants): array
    {
        $map = [];

        foreach ($kicksdb_variants as $variant) {
            // Extract EU size: sizes[] sub-array first, then direct fields
            $size = VariantParser::extractEuSize($variant);

            // Extract price: filter for "standard" type, fallback to direct fields
            $price = VariantParser::extractStandardPrice($variant);

            if ($size !== null && $price > 0) {
                $map[$size] = (float) $price;
            }
        }

        return $map;
    }

    private function extractSizeFromVariation(object $variation): ?string
    {
        foreach ($variation->attributes ?? [] as $attr) {
            $slug = $attr->slug ?? $attr->name ?? '';
            if (stripos($slug, 'taglia') !== false || stripos($slug, 'size') !== false) {
                return $attr->option ?? null;
            }
        }

        $sku = $variation->sku ?? '';
        if (preg_match('/-(\d+\.?\d*)$/', $sku, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function sendPriceAlert(
        string $sku,
        string $product_name,
        string $size,
        float $old_price,
        float $new_price,
        float $change_pct,
        array $breakdown
    ): void {
        $direction = $new_price > $old_price ? 'INCREASE' : 'DROP';
        $change_pct_fmt = number_format($change_pct, 1);

        $this->log('warning', "  ALERT: {$sku} size {$size} price {$direction} {$change_pct_fmt}%: €{$old_price} → €{$new_price}");

        if (!$this->alert_email) {
            return;
        }

        $subject = "[{$this->store_name}] Price {$direction}: {$sku} size {$size} ({$change_pct_fmt}%)";

        $body = "Price Alert - {$this->store_name}\n";
        $body .= str_repeat('=', 50) . "\n\n";
        $body .= "Product: {$product_name}\n";
        $body .= "SKU: {$sku}\n";
        $body .= "Size: {$size}\n\n";
        $body .= "Price Change: {$direction} ({$change_pct_fmt}%)\n";
        $body .= "  Old Price: €" . number_format($old_price, 2) . "\n";
        $body .= "  New Price: €" . number_format($new_price, 2) . "\n\n";
        $body .= "Breakdown:\n";
        $body .= "  Market Price (StockX): €" . number_format($breakdown['market_price'], 2) . "\n";
        $body .= "  Margin Applied: {$breakdown['margin_pct']}% ({$breakdown['margin_type']})\n";
        $body .= "  Floor Price Applied: " . ($breakdown['floor_applied'] ? 'YES' : 'No') . "\n\n";
        $body .= "Threshold: {$this->alert_threshold}%\n";
        $body .= "Time: " . date('Y-m-d H:i:s T') . "\n";

        $headers = "From: noreply@{$this->store_name}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        if (@mail($this->alert_email, $subject, $body, $headers)) {
            $this->stats['alerts_sent']++;
            $this->log('info', "  Alert email sent to {$this->alert_email}");
        } else {
            $this->log('error', "  Failed to send alert email to {$this->alert_email}");
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = array_map(fn() => 0, $this->stats);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message);
        }
    }
}

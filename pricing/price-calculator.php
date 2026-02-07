<?php
/**
 * Price Calculator Engine
 *
 * Calculates final selling price from market data with configurable margin strategies:
 * - Flat percentage markup
 * - Tiered margins (different % for different price ranges)
 * - Floor price (minimum selling price)
 *
 * All three strategies stack: tiered takes precedence over flat when configured,
 * floor price is always enforced as the absolute minimum.
 *
 * @package ResellPiacenza\Pricing
 */

class PriceCalculator
{
    /** @var float Default flat margin percentage (e.g. 25 = +25%) */
    private float $flat_margin;

    /** @var array Tiered margin rules: [['min' => 0, 'max' => 100, 'margin' => 30], ...] */
    private array $tiers;

    /** @var float Absolute minimum selling price (0 = disabled) */
    private float $floor_price;

    /** @var string Rounding strategy: 'whole', 'half', 'none' */
    private string $rounding;

    /**
     * @param array $config Pricing configuration
     */
    public function __construct(array $config)
    {
        $this->flat_margin = (float) ($config['flat_margin'] ?? 25);
        $this->tiers = $config['tiers'] ?? [];
        $this->floor_price = (float) ($config['floor_price'] ?? 0);
        $this->rounding = $config['rounding'] ?? 'whole';

        // Sort tiers by min price ascending
        usort($this->tiers, fn($a, $b) => ($a['min'] ?? 0) <=> ($b['min'] ?? 0));
    }

    /**
     * Calculate selling price from market price
     *
     * Strategy priority:
     * 1. If tiered margins configured and a tier matches → use tier margin
     * 2. Otherwise → use flat margin
     * 3. Always enforce floor price as minimum
     * 4. Apply rounding
     *
     * @param float $market_price Source price (e.g. StockX lowest ask)
     * @return float Final selling price
     */
    public function calculate(float $market_price): float
    {
        if ($market_price <= 0) {
            return 0.0;
        }

        $margin = $this->resolveMargin($market_price);
        $price = $market_price * (1 + $margin / 100);

        // Enforce floor price
        if ($this->floor_price > 0 && $price < $this->floor_price) {
            $price = $this->floor_price;
        }

        return $this->applyRounding($price);
    }

    /**
     * Calculate price with full breakdown (for logging/audit)
     *
     * @param float $market_price Source price
     * @return array ['market_price', 'margin_pct', 'margin_type', 'raw_price', 'floor_applied', 'final_price']
     */
    public function calculateWithBreakdown(float $market_price): array
    {
        if ($market_price <= 0) {
            return [
                'market_price' => 0,
                'margin_pct' => 0,
                'margin_type' => 'none',
                'raw_price' => 0,
                'floor_applied' => false,
                'final_price' => 0,
            ];
        }

        $margin = $this->resolveMargin($market_price);
        $margin_type = $this->resolveMarginType($market_price);
        $raw_price = $market_price * (1 + $margin / 100);

        $floor_applied = false;
        if ($this->floor_price > 0 && $raw_price < $this->floor_price) {
            $raw_price = $this->floor_price;
            $floor_applied = true;
        }

        $final_price = $this->applyRounding($raw_price);

        return [
            'market_price' => round($market_price, 2),
            'margin_pct' => $margin,
            'margin_type' => $margin_type,
            'raw_price' => round($raw_price, 2),
            'floor_applied' => $floor_applied,
            'final_price' => $final_price,
        ];
    }

    /**
     * Resolve which margin percentage to apply
     */
    private function resolveMargin(float $price): float
    {
        // Check tiered margins first
        if (!empty($this->tiers)) {
            foreach ($this->tiers as $tier) {
                $min = $tier['min'] ?? 0;
                $max = $tier['max'] ?? PHP_FLOAT_MAX;

                if ($price >= $min && $price < $max) {
                    return (float) ($tier['margin'] ?? $this->flat_margin);
                }
            }
        }

        return $this->flat_margin;
    }

    /**
     * Resolve margin type label for audit logging
     */
    private function resolveMarginType(float $price): string
    {
        if (!empty($this->tiers)) {
            foreach ($this->tiers as $idx => $tier) {
                $min = $tier['min'] ?? 0;
                $max = $tier['max'] ?? PHP_FLOAT_MAX;

                if ($price >= $min && $price < $max) {
                    return "tier_{$idx}";
                }
            }
        }

        return 'flat';
    }

    /**
     * Apply rounding strategy
     */
    private function applyRounding(float $price): float
    {
        switch ($this->rounding) {
            case 'whole':
                return (float) ceil($price);
            case 'half':
                return ceil($price * 2) / 2;
            case 'none':
            default:
                return round($price, 2);
        }
    }

    /**
     * Get current configuration summary
     */
    public function getConfigSummary(): array
    {
        return [
            'flat_margin' => $this->flat_margin . '%',
            'tiers' => array_map(function ($t) {
                $min = $t['min'] ?? 0;
                $max = $t['max'] ?? '∞';
                $margin = $t['margin'] ?? $this->flat_margin;
                return "€{$min}-€{$max}: +{$margin}%";
            }, $this->tiers),
            'floor_price' => $this->floor_price > 0 ? '€' . $this->floor_price : 'disabled',
            'rounding' => $this->rounding,
        ];
    }
}

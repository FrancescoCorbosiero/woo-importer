<?php

namespace ResellPiacenza\Support;

/**
 * Virtual stock estimator based on selling price.
 *
 * Neither Golden Sneakers nor KicksDB provide real stock data,
 * so stock is assigned inversely proportional to price
 * (cheaper items = higher assumed demand).
 *
 * @package ResellPiacenza\Support
 */
class StockEstimator
{
    /**
     * Estimate virtual stock quantity from the final selling price.
     *
     * @param float $price Final selling price (markup/VAT included)
     * @return int Estimated stock quantity
     */
    public static function forPrice(float $price): int
    {
        if ($price < 140) {
            return 80;
        }
        if ($price < 240) {
            return 50;
        }
        if ($price < 340) {
            return 30;
        }
        return 13;
    }
}

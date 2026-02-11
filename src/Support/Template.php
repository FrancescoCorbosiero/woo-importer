<?php

namespace ResellPiacenza\Support;

/**
 * Template string parser for product placeholders.
 *
 * Supports: {product_name}, {brand_name}, {sku}, {store_name}, {model}, {colorway}
 *
 * @package ResellPiacenza\Support
 */
class Template
{
    /**
     * Parse template string by replacing {placeholder} tokens with values.
     *
     * @param string $template Template with {placeholders}
     * @param array $data Key-value map (keys without braces)
     * @return string Parsed string
     */
    public static function parse(string $template, array $data): string
    {
        $keys = array_map(fn($k) => '{' . $k . '}', array_keys($data));
        $values = array_map(fn($v) => (string) ($v ?? ''), array_values($data));
        return str_replace($keys, $values, $template);
    }
}

<?php

namespace ResellPiacenza\Support;

/**
 * Configuration singleton
 *
 * Loads and caches the project config from config.php.
 * Provides the project root path for filesystem references.
 *
 * @package ResellPiacenza\Support
 */
class Config
{
    private static ?array $config = null;
    private static ?string $projectRoot = null;

    /**
     * Load the full configuration array
     */
    public static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        self::$config = require self::projectRoot() . '/config.php';
        return self::$config;
    }

    /**
     * Get a config value by dot-notation key
     *
     * @param string|null $key Dot-notation key (e.g. 'pricing.kicksdb_api_key')
     * @param mixed $default Default if not found
     * @return mixed
     */
    public static function get(string $key = null, $default = null)
    {
        $config = self::load();

        if ($key === null) {
            return $config;
        }

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get the project root directory (where composer.json lives)
     */
    public static function projectRoot(): string
    {
        if (self::$projectRoot === null) {
            self::$projectRoot = dirname(__DIR__, 2);
        }
        return self::$projectRoot;
    }

    /**
     * Reset cached config (useful for testing)
     */
    public static function reset(): void
    {
        self::$config = null;
    }
}

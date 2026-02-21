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
    private static ?string $dataDir = null;

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
     * Get the data directory for intermediate files (feeds, assortments, maps)
     *
     * Supports multi-customer mode: set DATA_DIR in the customer's .env file
     * to isolate each store's data. Relative paths resolve from project root.
     *
     * Examples:
     *   DATA_DIR=data/clientA  →  /path/to/woo-importer/data/clientA
     *   DATA_DIR=/tmp/clientA  →  /tmp/clientA
     *   (not set)              →  /path/to/woo-importer/data
     *
     * @return string Absolute path to data directory (no trailing slash)
     */
    public static function dataDir(): string
    {
        if (self::$dataDir !== null) {
            return self::$dataDir;
        }

        $dir = $_ENV['DATA_DIR'] ?? getenv('DATA_DIR') ?: null;

        if ($dir) {
            $dir = rtrim($dir, '/');
            // Resolve relative paths from project root
            if ($dir[0] !== '/') {
                $dir = self::projectRoot() . '/' . $dir;
            }
        } else {
            $dir = self::projectRoot() . '/data';
        }

        // Ensure directory exists
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        self::$dataDir = $dir;
        return $dir;
    }

    /**
     * Get the path to image-map.json (inside the data directory)
     *
     * @return string Absolute path to image-map.json
     */
    public static function imageMapFile(): string
    {
        return self::dataDir() . '/image-map.json';
    }

    /**
     * Reset cached config (useful for testing)
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$dataDir = null;
    }
}

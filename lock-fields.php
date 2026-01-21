<?php
/**
 * Field Lock Manager for WooCommerce Product Sync
 *
 * Manages which product fields are protected from being overwritten
 * during feed synchronization. Use this to preserve SEO-optimized
 * content in WooCommerce.
 *
 * Usage:
 *   php lock-fields.php --sku=DD1873-102 --lock=description,short_description
 *   php lock-fields.php --sku=DD1873-102 --lock=all
 *   php lock-fields.php --sku=DD1873-102 --unlock=description
 *   php lock-fields.php --sku=DD1873-102 --unlock=all
 *   php lock-fields.php --list                    # List all locked products
 *   php lock-fields.php --status=DD1873-102       # Show lock status for SKU
 *   php lock-fields.php --set-default=description,short_description
 *   php lock-fields.php --clear-defaults
 *
 * Lockable Fields:
 *   - name              Product title
 *   - description       Long description (main content)
 *   - short_description Short description (excerpt)
 *   - images            Product images
 *   - categories        Product categories
 *
 * @package ResellPiacenza\WooImport
 */

// Lockable fields definition
const LOCKABLE_FIELDS = [
    'name',
    'description',
    'short_description',
    'images',
    'categories',
];

const LOCKS_FILE = __DIR__ . '/field-locks.json';

/**
 * Load field locks from JSON file
 *
 * @return array Lock data
 */
function loadLocks(): array
{
    if (!file_exists(LOCKS_FILE)) {
        return [
            'config' => [
                'enabled' => true,
                'auto_lock_on_edit' => false,
                'default_locked_fields' => [],
            ],
            'products' => [],
        ];
    }

    $data = json_decode(file_get_contents(LOCKS_FILE), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Failed to parse field-locks.json\n";
        exit(1);
    }

    // Ensure structure exists
    if (!isset($data['config'])) {
        $data['config'] = [
            'enabled' => true,
            'auto_lock_on_edit' => false,
            'default_locked_fields' => [],
        ];
    }
    if (!isset($data['products'])) {
        $data['products'] = [];
    }

    return $data;
}

/**
 * Save field locks to JSON file
 *
 * @param array $data Lock data
 */
function saveLocks(array $data): void
{
    // Remove internal comment if present
    unset($data['_comment']);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents(LOCKS_FILE, $json);
}

/**
 * Parse field list from string
 *
 * @param string $fields Comma-separated field list or "all"
 * @return array Array of field names
 */
function parseFields(string $fields): array
{
    if (strtolower($fields) === 'all') {
        return LOCKABLE_FIELDS;
    }

    $parsed = array_map('trim', explode(',', $fields));
    $valid = array_intersect($parsed, LOCKABLE_FIELDS);

    $invalid = array_diff($parsed, LOCKABLE_FIELDS);
    if (!empty($invalid)) {
        echo "Warning: Invalid fields ignored: " . implode(', ', $invalid) . "\n";
        echo "Valid fields: " . implode(', ', LOCKABLE_FIELDS) . "\n\n";
    }

    return array_values($valid);
}

/**
 * Lock fields for a product
 *
 * @param string $sku Product SKU
 * @param array $fields Fields to lock
 */
function lockFields(string $sku, array $fields): void
{
    $data = loadLocks();

    if (!isset($data['products'][$sku])) {
        $data['products'][$sku] = [
            'locked_fields' => [],
            'locked_at' => date('Y-m-d H:i:s'),
        ];
    }

    // Merge with existing locks
    $existing = $data['products'][$sku]['locked_fields'] ?? [];
    $merged = array_unique(array_merge($existing, $fields));
    $data['products'][$sku]['locked_fields'] = array_values($merged);
    $data['products'][$sku]['updated_at'] = date('Y-m-d H:i:s');

    saveLocks($data);

    echo "Locked fields for {$sku}: " . implode(', ', $data['products'][$sku]['locked_fields']) . "\n";
}

/**
 * Unlock fields for a product
 *
 * @param string $sku Product SKU
 * @param array $fields Fields to unlock (or all)
 */
function unlockFields(string $sku, array $fields): void
{
    $data = loadLocks();

    if (!isset($data['products'][$sku])) {
        echo "No locks found for {$sku}\n";
        return;
    }

    if ($fields === LOCKABLE_FIELDS) {
        // Unlock all = remove product from locks
        unset($data['products'][$sku]);
        saveLocks($data);
        echo "All locks removed for {$sku}\n";
        return;
    }

    // Remove specific fields
    $existing = $data['products'][$sku]['locked_fields'] ?? [];
    $remaining = array_diff($existing, $fields);

    if (empty($remaining)) {
        unset($data['products'][$sku]);
        echo "All locks removed for {$sku}\n";
    } else {
        $data['products'][$sku]['locked_fields'] = array_values($remaining);
        $data['products'][$sku]['updated_at'] = date('Y-m-d H:i:s');
        echo "Remaining locked fields for {$sku}: " . implode(', ', $remaining) . "\n";
    }

    saveLocks($data);
}

/**
 * Show status for a product
 *
 * @param string $sku Product SKU
 */
function showStatus(string $sku): void
{
    $data = loadLocks();

    echo "Lock Status for: {$sku}\n";
    echo str_repeat('-', 40) . "\n";

    if (!isset($data['products'][$sku])) {
        // Check global defaults
        $defaults = $data['config']['default_locked_fields'] ?? [];
        if (!empty($defaults)) {
            echo "Using global defaults: " . implode(', ', $defaults) . "\n";
        } else {
            echo "No locks configured (all fields will sync from feed)\n";
        }
        return;
    }

    $product = $data['products'][$sku];
    $locked = $product['locked_fields'] ?? [];

    if (empty($locked)) {
        echo "No fields locked\n";
    } else {
        echo "Locked fields: " . implode(', ', $locked) . "\n";
    }

    if (isset($product['locked_at'])) {
        echo "First locked: {$product['locked_at']}\n";
    }
    if (isset($product['updated_at'])) {
        echo "Last updated: {$product['updated_at']}\n";
    }
}

/**
 * List all locked products
 */
function listLocks(): void
{
    $data = loadLocks();

    echo "Field Lock Configuration\n";
    echo str_repeat('=', 60) . "\n\n";

    // Show config
    $enabled = ($data['config']['enabled'] ?? true) ? 'Yes' : 'No';
    echo "Locks Enabled: {$enabled}\n";

    $defaults = $data['config']['default_locked_fields'] ?? [];
    if (!empty($defaults)) {
        echo "Global Defaults: " . implode(', ', $defaults) . "\n";
    } else {
        echo "Global Defaults: (none)\n";
    }

    echo "\n";

    // Show products
    $products = $data['products'] ?? [];

    if (empty($products)) {
        echo "No product-specific locks configured.\n";
        return;
    }

    echo "Product-Specific Locks:\n";
    echo str_repeat('-', 60) . "\n";

    foreach ($products as $sku => $info) {
        $fields = implode(', ', $info['locked_fields'] ?? []);
        echo "  {$sku}: {$fields}\n";
    }

    echo "\nTotal: " . count($products) . " products with locks\n";
}

/**
 * Set global default locked fields
 *
 * @param array $fields Fields to lock by default
 */
function setDefaults(array $fields): void
{
    $data = loadLocks();
    $data['config']['default_locked_fields'] = $fields;
    saveLocks($data);

    if (empty($fields)) {
        echo "Global default locks cleared\n";
    } else {
        echo "Global default locked fields: " . implode(', ', $fields) . "\n";
    }
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo <<<HELP
Field Lock Manager for WooCommerce Product Sync
Protect product fields from being overwritten during feed sync.

Usage:
  php lock-fields.php [options]

Options:
  --sku=SKU              Target product SKU
  --lock=FIELDS          Lock fields (comma-separated or "all")
  --unlock=FIELDS        Unlock fields (comma-separated or "all")
  --status=SKU           Show lock status for a product
  --list                 List all locked products
  --set-default=FIELDS   Set global default locked fields
  --clear-defaults       Clear global default locked fields
  --enable               Enable field locking
  --disable              Disable field locking (all fields sync)
  --help, -h             Show this help message

Lockable Fields:
  name              - Product title
  description       - Long description (main content)
  short_description - Short description (excerpt)
  images            - Product images
  categories        - Product categories

Examples:
  # Lock description fields for a product (SEO content preserved)
  php lock-fields.php --sku=DD1873-102 --lock=description,short_description

  # Lock ALL editable fields for a product
  php lock-fields.php --sku=DD1873-102 --lock=all

  # Unlock specific field
  php lock-fields.php --sku=DD1873-102 --unlock=name

  # Remove all locks for a product
  php lock-fields.php --sku=DD1873-102 --unlock=all

  # Check status
  php lock-fields.php --status=DD1873-102

  # List all locks
  php lock-fields.php --list

  # Set global defaults (applies to ALL products without specific locks)
  php lock-fields.php --set-default=description,short_description

  # Disable locking entirely
  php lock-fields.php --disable

Notes:
  - Locked fields are PRESERVED during sync (not overwritten by feed)
  - Stock quantities and prices are ALWAYS synced (never locked)
  - Product-specific locks override global defaults
  - Use --ignore-locks in import-batch.php to bypass locks

HELP;
}

// ============================================================================
// CLI Runner
// ============================================================================

// Parse arguments
$sku = null;
$lock_fields = null;
$unlock_fields = null;
$status_sku = null;
$list = false;
$set_default = null;
$clear_defaults = false;
$enable = false;
$disable = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--sku=') === 0) {
        $sku = str_replace('--sku=', '', $arg);
    }
    if (strpos($arg, '--lock=') === 0) {
        $lock_fields = str_replace('--lock=', '', $arg);
    }
    if (strpos($arg, '--unlock=') === 0) {
        $unlock_fields = str_replace('--unlock=', '', $arg);
    }
    if (strpos($arg, '--status=') === 0) {
        $status_sku = str_replace('--status=', '', $arg);
    }
    if ($arg === '--list') {
        $list = true;
    }
    if (strpos($arg, '--set-default=') === 0) {
        $set_default = str_replace('--set-default=', '', $arg);
    }
    if ($arg === '--clear-defaults') {
        $clear_defaults = true;
    }
    if ($arg === '--enable') {
        $enable = true;
    }
    if ($arg === '--disable') {
        $disable = true;
    }
}

// Show help
if (in_array('--help', $argv) || in_array('-h', $argv) || $argc === 1) {
    showHelp();
    exit(0);
}

// Handle commands
if ($enable) {
    $data = loadLocks();
    $data['config']['enabled'] = true;
    saveLocks($data);
    echo "Field locking ENABLED\n";
    exit(0);
}

if ($disable) {
    $data = loadLocks();
    $data['config']['enabled'] = false;
    saveLocks($data);
    echo "Field locking DISABLED (all fields will sync from feed)\n";
    exit(0);
}

if ($list) {
    listLocks();
    exit(0);
}

if ($status_sku) {
    showStatus($status_sku);
    exit(0);
}

if ($set_default !== null) {
    $fields = parseFields($set_default);
    setDefaults($fields);
    exit(0);
}

if ($clear_defaults) {
    setDefaults([]);
    exit(0);
}

// Lock/unlock operations require SKU
if ($lock_fields !== null || $unlock_fields !== null) {
    if (!$sku) {
        echo "Error: --sku is required for lock/unlock operations\n";
        exit(1);
    }

    if ($lock_fields !== null) {
        $fields = parseFields($lock_fields);
        if (empty($fields)) {
            echo "Error: No valid fields to lock\n";
            exit(1);
        }
        lockFields($sku, $fields);
    }

    if ($unlock_fields !== null) {
        $fields = parseFields($unlock_fields);
        if (empty($fields)) {
            echo "Error: No valid fields to unlock\n";
            exit(1);
        }
        unlockFields($sku, $fields);
    }

    exit(0);
}

// No valid command
echo "Error: No valid command specified. Use --help for usage.\n";
exit(1);

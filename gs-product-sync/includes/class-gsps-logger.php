<?php
/**
 * Logger class - stores logs in database
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_Logger {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'gsps_logs';

    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /**
     * Level priorities
     */
    private static $level_priorities = [
        'debug' => 100,
        'info' => 200,
        'warning' => 300,
        'error' => 400,
    ];

    /**
     * Current context (e.g., 'sync', 'import', 'images')
     *
     * @var string
     */
    private $context = 'general';

    /**
     * Session ID for grouping related logs
     *
     * @var string
     */
    private $session_id;

    /**
     * Minimum log level
     *
     * @var string
     */
    private $min_level = 'info';

    /**
     * Constructor
     *
     * @param string $context Log context
     */
    public function __construct($context = 'general') {
        $this->context = $context;
        $this->session_id = wp_generate_uuid4();

        // Get minimum level from settings
        $settings = get_option(GSPS_Settings::OPTION_NAME, []);
        $this->min_level = $settings['logging']['level'] ?? 'info';
    }

    /**
     * Create logs table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(36) NOT NULL,
            context varchar(50) NOT NULL DEFAULT 'general',
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            data longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY context (context),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get table name with prefix
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $data Additional data
     */
    public function log($level, $message, array $data = []) {
        // Check if level meets minimum threshold
        if (self::$level_priorities[$level] < self::$level_priorities[$this->min_level]) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            self::get_table_name(),
            [
                'session_id' => $this->session_id,
                'context' => $this->context,
                'level' => $level,
                'message' => $message,
                'data' => !empty($data) ? wp_json_encode($data) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Debug log
     *
     * @param string $message
     * @param array $data
     */
    public function debug($message, array $data = []) {
        $this->log(self::LEVEL_DEBUG, $message, $data);
    }

    /**
     * Info log
     *
     * @param string $message
     * @param array $data
     */
    public function info($message, array $data = []) {
        $this->log(self::LEVEL_INFO, $message, $data);
    }

    /**
     * Warning log
     *
     * @param string $message
     * @param array $data
     */
    public function warning($message, array $data = []) {
        $this->log(self::LEVEL_WARNING, $message, $data);
    }

    /**
     * Error log
     *
     * @param string $message
     * @param array $data
     */
    public function error($message, array $data = []) {
        $this->log(self::LEVEL_ERROR, $message, $data);
    }

    /**
     * Get session ID
     *
     * @return string
     */
    public function get_session_id() {
        return $this->session_id;
    }

    /**
     * Set context
     *
     * @param string $context
     */
    public function set_context($context) {
        $this->context = $context;
    }

    /**
     * Get logs with filters
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function get_logs(array $args = []) {
        global $wpdb;

        $defaults = [
            'context' => null,
            'level' => null,
            'session_id' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
            'per_page' => 50,
            'page' => 1,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $table = self::get_table_name();

        // Build query
        $where = ['1=1'];
        $values = [];

        if ($args['context']) {
            $where[] = 'context = %s';
            $values[] = $args['context'];
        }

        if ($args['level']) {
            if (is_array($args['level'])) {
                $placeholders = implode(',', array_fill(0, count($args['level']), '%s'));
                $where[] = "level IN ({$placeholders})";
                $values = array_merge($values, $args['level']);
            } else {
                $where[] = 'level = %s';
                $values[] = $args['level'];
            }
        }

        if ($args['session_id']) {
            $where[] = 'session_id = %s';
            $values[] = $args['session_id'];
        }

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'];
        }

        if ($args['search']) {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if (!empty($values)) {
            $count_sql = $wpdb->prepare($count_sql, $values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get results
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = in_array($args['orderby'], ['id', 'created_at', 'level', 'context']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $values));

        // Parse data JSON
        foreach ($results as &$row) {
            $row->data = $row->data ? json_decode($row->data, true) : null;
        }

        return [
            'logs' => $results,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
            'page' => $args['page'],
        ];
    }

    /**
     * Get unique sessions
     *
     * @param int $limit
     * @return array
     */
    public static function get_sessions($limit = 20) {
        global $wpdb;
        $table = self::get_table_name();

        $sql = "SELECT
                    session_id,
                    context,
                    MIN(created_at) as started_at,
                    MAX(created_at) as ended_at,
                    COUNT(*) as log_count,
                    SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count
                FROM {$table}
                GROUP BY session_id, context
                ORDER BY started_at DESC
                LIMIT %d";

        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }

    /**
     * Delete old logs
     *
     * @param int $days Days to retain
     * @return int Number of deleted rows
     */
    public static function cleanup($days = 7) {
        global $wpdb;
        $table = self::get_table_name();

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff)
        );
    }

    /**
     * Clear all logs
     *
     * @return bool
     */
    public static function clear_all() {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->query("TRUNCATE TABLE {$table}") !== false;
    }

    /**
     * Get stats
     *
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::get_table_name();

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN level = 'debug' THEN 1 ELSE 0 END) as debug_count,
                SUM(CASE WHEN level = 'info' THEN 1 ELSE 0 END) as info_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM {$table}
        ", ARRAY_A);

        return $stats ?: [
            'total' => 0,
            'debug_count' => 0,
            'info_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'oldest' => null,
            'newest' => null,
        ];
    }
}

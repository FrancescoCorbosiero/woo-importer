<?php
/**
 * Logs page view
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

$page = absint($_GET['paged'] ?? 1);
$level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
$context = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : '';
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

$args = [
    'page' => $page,
    'per_page' => 50,
];

if ($level) {
    $args['level'] = $level;
}
if ($context) {
    $args['context'] = $context;
}
if ($search) {
    $args['search'] = $search;
}

$result = GSPS_Logger::get_logs($args);
$logs = $result['logs'];
$total_pages = $result['pages'];
$log_stats = GSPS_Logger::get_stats();
?>
<div class="wrap gsps-wrap">
    <h1>
        <?php esc_html_e('Log Sincronizzazione', 'gs-product-sync'); ?>
        <button type="button" class="page-title-action gsps-action-btn" data-action="gsps_clear_logs" data-confirm="confirm_clear_logs">
            <?php esc_html_e('Svuota Log', 'gs-product-sync'); ?>
        </button>
    </h1>

    <!-- Filters -->
    <div class="gsps-box gsps-filters">
        <form method="get">
            <input type="hidden" name="page" value="gsps-logs">

            <select name="level">
                <option value=""><?php esc_html_e('Tutti i livelli', 'gs-product-sync'); ?></option>
                <option value="debug" <?php selected($level, 'debug'); ?>>Debug</option>
                <option value="info" <?php selected($level, 'info'); ?>>Info</option>
                <option value="warning" <?php selected($level, 'warning'); ?>>Warning</option>
                <option value="error" <?php selected($level, 'error'); ?>>Error</option>
            </select>

            <select name="context">
                <option value=""><?php esc_html_e('Tutti i contesti', 'gs-product-sync'); ?></option>
                <option value="scheduled_sync" <?php selected($context, 'scheduled_sync'); ?>>Scheduled Sync</option>
                <option value="manual_sync" <?php selected($context, 'manual_sync'); ?>>Manual Sync</option>
                <option value="manual_full_sync" <?php selected($context, 'manual_full_sync'); ?>>Full Sync</option>
                <option value="manual_images" <?php selected($context, 'manual_images'); ?>>Images</option>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Cerca...', 'gs-product-sync'); ?>">

            <button type="submit" class="button"><?php esc_html_e('Filtra', 'gs-product-sync'); ?></button>

            <?php if ($level || $context || $search): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=gsps-logs')); ?>" class="button">
                <?php esc_html_e('Reset', 'gs-product-sync'); ?>
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Stats -->
    <div class="gsps-log-stats gsps-box">
        <div class="gsps-log-stat">
            <span class="gsps-log-count"><?php echo number_format($log_stats['total']); ?></span>
            <span class="gsps-log-label"><?php esc_html_e('Totale', 'gs-product-sync'); ?></span>
        </div>
        <div class="gsps-log-stat gsps-log-debug">
            <span class="gsps-log-count"><?php echo number_format($log_stats['debug_count']); ?></span>
            <span class="gsps-log-label">Debug</span>
        </div>
        <div class="gsps-log-stat gsps-log-info">
            <span class="gsps-log-count"><?php echo number_format($log_stats['info_count']); ?></span>
            <span class="gsps-log-label">Info</span>
        </div>
        <div class="gsps-log-stat gsps-log-warning">
            <span class="gsps-log-count"><?php echo number_format($log_stats['warning_count']); ?></span>
            <span class="gsps-log-label">Warning</span>
        </div>
        <div class="gsps-log-stat gsps-log-error">
            <span class="gsps-log-count"><?php echo number_format($log_stats['error_count']); ?></span>
            <span class="gsps-log-label">Error</span>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="gsps-box">
        <?php if (empty($logs)): ?>
        <p class="gsps-no-logs"><?php esc_html_e('Nessun log trovato.', 'gs-product-sync'); ?></p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped gsps-logs-table-full">
            <thead>
                <tr>
                    <th class="column-date"><?php esc_html_e('Data/Ora', 'gs-product-sync'); ?></th>
                    <th class="column-context"><?php esc_html_e('Contesto', 'gs-product-sync'); ?></th>
                    <th class="column-level"><?php esc_html_e('Livello', 'gs-product-sync'); ?></th>
                    <th class="column-message"><?php esc_html_e('Messaggio', 'gs-product-sync'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr class="gsps-log-row gsps-log-row-<?php echo esc_attr($log->level); ?>">
                    <td class="column-date">
                        <?php echo esc_html(wp_date('Y-m-d H:i:s', strtotime($log->created_at))); ?>
                    </td>
                    <td class="column-context">
                        <span class="gsps-context"><?php echo esc_html($log->context); ?></span>
                    </td>
                    <td class="column-level">
                        <span class="gsps-log-level gsps-level-<?php echo esc_attr($log->level); ?>">
                            <?php echo esc_html(strtoupper($log->level)); ?>
                        </span>
                    </td>
                    <td class="column-message">
                        <?php echo esc_html($log->message); ?>
                        <?php if ($log->data): ?>
                        <button type="button" class="gsps-toggle-data button-link">
                            <?php esc_html_e('Mostra dati', 'gs-product-sync'); ?>
                        </button>
                        <pre class="gsps-log-data" style="display: none;"><?php echo esc_html(wp_json_encode($log->data, JSON_PRETTY_PRINT)); ?></pre>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                $pagination_args = [
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'total' => $total_pages,
                    'current' => $page,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ];
                echo paginate_links($pagination_args);
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

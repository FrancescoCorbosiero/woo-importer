<?php
/**
 * Dashboard page view
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

$settings = new GSPS_Settings();
$sync_info = GSPS_Sync_Checker::get_last_sync_info();
$last_run = GSPS_Scheduler::get_last_run();
$next_run = GSPS_Scheduler::get_next_run();
$log_stats = GSPS_Logger::get_stats();
$image_map = get_option('gsps_image_map', []);
$scheduler_active = GSPS_Scheduler::is_scheduled();
$api_configured = $settings->is_api_configured();
?>
<div class="wrap gsps-wrap">
    <h1><?php esc_html_e('Golden Sneakers Product Sync', 'gs-product-sync'); ?></h1>

    <?php if (!$api_configured): ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('API non configurata', 'gs-product-sync'); ?></strong> -
            <?php
            printf(
                __('Configura il token API nelle <a href="%s">impostazioni</a> per iniziare.', 'gs-product-sync'),
                admin_url('admin.php?page=gsps-settings')
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="gsps-dashboard">
        <!-- Status Cards -->
        <div class="gsps-cards">
            <div class="gsps-card">
                <div class="gsps-card-icon dashicons dashicons-cloud"></div>
                <div class="gsps-card-content">
                    <h3><?php esc_html_e('Stato API', 'gs-product-sync'); ?></h3>
                    <p class="gsps-card-value <?php echo $api_configured ? 'status-ok' : 'status-warning'; ?>">
                        <?php echo $api_configured ? __('Configurata', 'gs-product-sync') : __('Non configurata', 'gs-product-sync'); ?>
                    </p>
                </div>
            </div>

            <div class="gsps-card">
                <div class="gsps-card-icon dashicons dashicons-update"></div>
                <div class="gsps-card-content">
                    <h3><?php esc_html_e('Scheduler', 'gs-product-sync'); ?></h3>
                    <p class="gsps-card-value <?php echo $scheduler_active ? 'status-ok' : 'status-inactive'; ?>">
                        <?php echo $scheduler_active ? __('Attivo', 'gs-product-sync') : __('Inattivo', 'gs-product-sync'); ?>
                    </p>
                </div>
            </div>

            <div class="gsps-card">
                <div class="gsps-card-icon dashicons dashicons-products"></div>
                <div class="gsps-card-content">
                    <h3><?php esc_html_e('Prodotti nel Feed', 'gs-product-sync'); ?></h3>
                    <p class="gsps-card-value"><?php echo number_format($sync_info['products_count']); ?></p>
                </div>
            </div>

            <div class="gsps-card">
                <div class="gsps-card-icon dashicons dashicons-format-gallery"></div>
                <div class="gsps-card-content">
                    <h3><?php esc_html_e('Immagini Caricate', 'gs-product-sync'); ?></h3>
                    <p class="gsps-card-value"><?php echo number_format(count($image_map)); ?></p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="gsps-main-content">
            <!-- Left Column: Actions & Status -->
            <div class="gsps-column">
                <!-- Quick Actions -->
                <div class="gsps-box">
                    <h2><?php esc_html_e('Azioni Rapide', 'gs-product-sync'); ?></h2>

                    <div class="gsps-actions">
                        <button type="button" class="button button-primary button-large gsps-action-btn" data-action="gsps_run_sync" <?php disabled(!$api_configured); ?>>
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Sincronizza Ora', 'gs-product-sync'); ?>
                        </button>

                        <button type="button" class="button button-large gsps-action-btn" data-action="gsps_run_full_sync" data-confirm="confirm_full_sync" <?php disabled(!$api_configured); ?>>
                            <span class="dashicons dashicons-database-import"></span>
                            <?php esc_html_e('Sync Completo', 'gs-product-sync'); ?>
                        </button>

                        <button type="button" class="button button-large gsps-action-btn" data-action="gsps_run_images_only" <?php disabled(!$api_configured); ?>>
                            <span class="dashicons dashicons-format-image"></span>
                            <?php esc_html_e('Solo Immagini', 'gs-product-sync'); ?>
                        </button>

                        <button type="button" class="button button-large gsps-action-btn" data-action="gsps_test_api" <?php disabled(!$api_configured); ?>>
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php esc_html_e('Test Connessione', 'gs-product-sync'); ?>
                        </button>
                    </div>

                    <div id="gsps-action-result" class="gsps-action-result" style="display: none;"></div>
                </div>

                <!-- Sync Status -->
                <div class="gsps-box">
                    <h2><?php esc_html_e('Stato Sincronizzazione', 'gs-product-sync'); ?></h2>

                    <table class="gsps-status-table">
                        <tr>
                            <th><?php esc_html_e('Ultima sincronizzazione:', 'gs-product-sync'); ?></th>
                            <td>
                                <?php
                                if ($sync_info['last_sync']) {
                                    echo esc_html(
                                        sprintf(
                                            __('%s', 'gs-product-sync'),
                                            wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sync_info['last_sync']))
                                        )
                                    );
                                } else {
                                    esc_html_e('Mai', 'gs-product-sync');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Prossima esecuzione:', 'gs-product-sync'); ?></th>
                            <td>
                                <?php
                                if ($next_run) {
                                    echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run));
                                } else {
                                    esc_html_e('Non programmata', 'gs-product-sync');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php if ($last_run): ?>
                        <tr>
                            <th><?php esc_html_e('Ultimo run schedulato:', 'gs-product-sync'); ?></th>
                            <td>
                                <span class="<?php echo $last_run['success'] ? 'status-ok' : 'status-error'; ?>">
                                    <?php echo esc_html($last_run['success'] ? __('Successo', 'gs-product-sync') : __('Errore', 'gs-product-sync')); ?>
                                </span>
                                - <?php echo esc_html($last_run['time']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Right Column: Logs Summary -->
            <div class="gsps-column">
                <div class="gsps-box">
                    <h2>
                        <?php esc_html_e('Log Recenti', 'gs-product-sync'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gsps-logs')); ?>" class="gsps-link-small">
                            <?php esc_html_e('Vedi tutti', 'gs-product-sync'); ?>
                        </a>
                    </h2>

                    <div class="gsps-log-stats">
                        <div class="gsps-log-stat">
                            <span class="gsps-log-count"><?php echo number_format($log_stats['total']); ?></span>
                            <span class="gsps-log-label"><?php esc_html_e('Totale', 'gs-product-sync'); ?></span>
                        </div>
                        <div class="gsps-log-stat gsps-log-info">
                            <span class="gsps-log-count"><?php echo number_format($log_stats['info_count']); ?></span>
                            <span class="gsps-log-label"><?php esc_html_e('Info', 'gs-product-sync'); ?></span>
                        </div>
                        <div class="gsps-log-stat gsps-log-warning">
                            <span class="gsps-log-count"><?php echo number_format($log_stats['warning_count']); ?></span>
                            <span class="gsps-log-label"><?php esc_html_e('Warning', 'gs-product-sync'); ?></span>
                        </div>
                        <div class="gsps-log-stat gsps-log-error">
                            <span class="gsps-log-count"><?php echo number_format($log_stats['error_count']); ?></span>
                            <span class="gsps-log-label"><?php esc_html_e('Errori', 'gs-product-sync'); ?></span>
                        </div>
                    </div>

                    <?php
                    $recent_logs = GSPS_Logger::get_logs(['per_page' => 10]);
                    if (!empty($recent_logs['logs'])):
                    ?>
                    <table class="gsps-logs-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Data', 'gs-product-sync'); ?></th>
                                <th><?php esc_html_e('Livello', 'gs-product-sync'); ?></th>
                                <th><?php esc_html_e('Messaggio', 'gs-product-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs['logs'] as $log): ?>
                            <tr>
                                <td class="gsps-log-date"><?php echo esc_html(wp_date('H:i:s', strtotime($log->created_at))); ?></td>
                                <td><span class="gsps-log-level gsps-level-<?php echo esc_attr($log->level); ?>"><?php echo esc_html($log->level); ?></span></td>
                                <td class="gsps-log-message"><?php echo esc_html(wp_trim_words($log->message, 10)); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="gsps-no-logs"><?php esc_html_e('Nessun log disponibile.', 'gs-product-sync'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Settings page view
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

$settings = new GSPS_Settings();
$options = $settings->get_all();
$intervals = GSPS_Scheduler::get_intervals();
?>
<div class="wrap gsps-wrap">
    <h1><?php esc_html_e('Impostazioni GS Product Sync', 'gs-product-sync'); ?></h1>

    <form method="post" action="options.php" class="gsps-settings-form">
        <?php settings_fields('gsps_settings'); ?>

        <!-- Tabs -->
        <nav class="gsps-tabs">
            <a href="#tab-api" class="gsps-tab active"><?php esc_html_e('API', 'gs-product-sync'); ?></a>
            <a href="#tab-import" class="gsps-tab"><?php esc_html_e('Import', 'gs-product-sync'); ?></a>
            <a href="#tab-templates" class="gsps-tab"><?php esc_html_e('Template', 'gs-product-sync'); ?></a>
            <a href="#tab-scheduler" class="gsps-tab"><?php esc_html_e('Scheduler', 'gs-product-sync'); ?></a>
        </nav>

        <!-- Tab: API -->
        <div id="tab-api" class="gsps-tab-content active">
            <div class="gsps-box">
                <h2><?php esc_html_e('Golden Sneakers API', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="api_base_url"><?php esc_html_e('URL API', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="api_base_url" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[api][base_url]"
                                   value="<?php echo esc_attr($options['api']['base_url']); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_bearer_token"><?php esc_html_e('Bearer Token', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="api_bearer_token" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[api][bearer_token]"
                                   value="<?php echo esc_attr($options['api']['bearer_token']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Token JWT per autenticazione API', 'gs-product-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_markup"><?php esc_html_e('Markup %', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="api_markup" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[api][markup_percentage]"
                                   value="<?php echo esc_attr($options['api']['markup_percentage']); ?>" min="0" max="100" class="small-text">
                            <span>%</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_vat"><?php esc_html_e('IVA %', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="api_vat" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[api][vat_percentage]"
                                   value="<?php echo esc_attr($options['api']['vat_percentage']); ?>" min="0" max="100" class="small-text">
                            <span>%</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="api_rounding"><?php esc_html_e('Arrotondamento', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <select id="api_rounding" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[api][rounding_type]">
                                <option value="whole" <?php selected($options['api']['rounding_type'], 'whole'); ?>><?php esc_html_e('Intero', 'gs-product-sync'); ?></option>
                                <option value="decimal" <?php selected($options['api']['rounding_type'], 'decimal'); ?>><?php esc_html_e('Decimale', 'gs-product-sync'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Informazioni Negozio', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="store_name"><?php esc_html_e('Nome Negozio', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="store_name" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[store][name]"
                                   value="<?php echo esc_attr($options['store']['name']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Usato nei template SEO', 'gs-product-sync'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab: Import -->
        <div id="tab-import" class="gsps-tab-content">
            <div class="gsps-box">
                <h2><?php esc_html_e('Categorie', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Categoria Sneakers', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[categories][sneakers][name]"
                                   value="<?php echo esc_attr($options['categories']['sneakers']['name']); ?>" class="regular-text" placeholder="Nome">
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[categories][sneakers][slug]"
                                   value="<?php echo esc_attr($options['categories']['sneakers']['slug']); ?>" class="regular-text" placeholder="Slug">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Categoria Abbigliamento', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[categories][clothing][name]"
                                   value="<?php echo esc_attr($options['categories']['clothing']['name']); ?>" class="regular-text" placeholder="Nome">
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[categories][clothing][slug]"
                                   value="<?php echo esc_attr($options['categories']['clothing']['slug']); ?>" class="regular-text" placeholder="Slug">
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Attributi', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Attributo Taglia', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[import][size_attribute_name]"
                                   value="<?php echo esc_attr($options['import']['size_attribute_name']); ?>" class="regular-text" placeholder="Nome">
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[import][size_attribute_slug]"
                                   value="<?php echo esc_attr($options['import']['size_attribute_slug']); ?>" class="regular-text" placeholder="Slug">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Attributo Marca', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[import][brand_attribute_name]"
                                   value="<?php echo esc_attr($options['import']['brand_attribute_name']); ?>" class="regular-text" placeholder="Nome">
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[import][brand_attribute_slug]"
                                   value="<?php echo esc_attr($options['import']['brand_attribute_slug']); ?>" class="regular-text" placeholder="Slug">
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Categorie Brand', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Abilita', 'gs-product-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[brand_categories][enabled]" value="1"
                                    <?php checked($options['brand_categories']['enabled']); ?>>
                                <?php esc_html_e('Crea categorie automatiche per brand', 'gs-product-sync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Suffisso Slug', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="text" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[brand_categories][slug_suffix]"
                                   value="<?php echo esc_attr($options['brand_categories']['slug_suffix']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Es: -originali genera nike-originali', 'gs-product-sync'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Opzioni Import', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Batch Size', 'gs-product-sync'); ?></th>
                        <td>
                            <input type="number" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[import][batch_size]"
                                   value="<?php echo esc_attr($options['import']['batch_size']); ?>" min="1" max="100" class="small-text">
                            <p class="description"><?php esc_html_e('Prodotti per batch (max 100)', 'gs-product-sync'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab: Templates -->
        <div id="tab-templates" class="gsps-tab-content">
            <div class="gsps-box">
                <h2><?php esc_html_e('Template Prodotto', 'gs-product-sync'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Placeholder disponibili: {product_name}, {brand_name}, {sku}, {store_name}', 'gs-product-sync'); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tpl_short_desc"><?php esc_html_e('Descrizione Breve', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <textarea id="tpl_short_desc" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[templates][short_description]"
                                      rows="3" class="large-text"><?php echo esc_textarea($options['templates']['short_description']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tpl_long_desc"><?php esc_html_e('Descrizione Lunga', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <textarea id="tpl_long_desc" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[templates][long_description]"
                                      rows="10" class="large-text"><?php echo esc_textarea($options['templates']['long_description']); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Template Immagini SEO', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tpl_img_alt"><?php esc_html_e('Alt Text', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="tpl_img_alt" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[templates][image_alt]"
                                   value="<?php echo esc_attr($options['templates']['image_alt']); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tpl_img_caption"><?php esc_html_e('Caption', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="tpl_img_caption" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[templates][image_caption]"
                                   value="<?php echo esc_attr($options['templates']['image_caption']); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tpl_img_desc"><?php esc_html_e('Descrizione', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <textarea id="tpl_img_desc" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[templates][image_description]"
                                      rows="2" class="large-text"><?php echo esc_textarea($options['templates']['image_description']); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Tab: Scheduler -->
        <div id="tab-scheduler" class="gsps-tab-content">
            <div class="gsps-box">
                <h2><?php esc_html_e('Sincronizzazione Automatica', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Abilita', 'gs-product-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[scheduler][enabled]" value="1"
                                    <?php checked($options['scheduler']['enabled']); ?>>
                                <?php esc_html_e('Esegui sincronizzazione automatica', 'gs-product-sync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="scheduler_interval"><?php esc_html_e('Intervallo', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <select id="scheduler_interval" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[scheduler][interval]">
                                <?php foreach ($intervals as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($options['scheduler']['interval'], $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Opzioni', 'gs-product-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[scheduler][skip_images]" value="1"
                                    <?php checked($options['scheduler']['skip_images']); ?>>
                                <?php esc_html_e('Salta upload immagini (solo inventario)', 'gs-product-sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="gsps-box">
                <h2><?php esc_html_e('Logging', 'gs-product-sync'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Abilita Log', 'gs-product-sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[logging][enabled]" value="1"
                                    <?php checked($options['logging']['enabled']); ?>>
                                <?php esc_html_e('Registra attivita', 'gs-product-sync'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="logging_level"><?php esc_html_e('Livello Log', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <select id="logging_level" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[logging][level]">
                                <option value="debug" <?php selected($options['logging']['level'], 'debug'); ?>>Debug</option>
                                <option value="info" <?php selected($options['logging']['level'], 'info'); ?>>Info</option>
                                <option value="warning" <?php selected($options['logging']['level'], 'warning'); ?>>Warning</option>
                                <option value="error" <?php selected($options['logging']['level'], 'error'); ?>>Error</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="logging_retention"><?php esc_html_e('Retention (giorni)', 'gs-product-sync'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="logging_retention" name="<?php echo GSPS_Settings::OPTION_NAME; ?>[logging][retention_days]"
                                   value="<?php echo esc_attr($options['logging']['retention_days']); ?>" min="1" max="90" class="small-text">
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(__('Salva Impostazioni', 'gs-product-sync')); ?>
    </form>
</div>

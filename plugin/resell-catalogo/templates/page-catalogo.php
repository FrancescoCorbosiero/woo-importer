<?php
/**
 * Catalogo Dashboard Page Template
 *
 * @var array|null $catalog  Parsed catalog data (null if no file)
 * @var bool       $has_api_key Whether KicksDB API key is configured
 * @var string     $market     KicksDB market (IT, US, etc.)
 */
defined('ABSPATH') || exit;

$catalog_meta = $catalog ? $this->get_catalog_meta($catalog) : null;
?>
<div class="wrap resell-catalogo-wrap">
    <h1 class="resell-catalogo-title">
        <span class="dashicons dashicons-store"></span>
        Catalogo Prodotti
    </h1>

    <!-- Tab Navigation -->
    <nav class="nav-tab-wrapper resell-tabs">
        <a href="#tab-catalog" class="nav-tab nav-tab-active" data-tab="tab-catalog">Catalogo</a>
        <a href="#tab-quick-add" class="nav-tab" data-tab="tab-quick-add">Aggiungi Prodotto</a>
        <a href="#tab-settings" class="nav-tab" data-tab="tab-settings">Impostazioni</a>
    </nav>

    <!-- ================================================================== -->
    <!-- TAB: Catalogo                                                       -->
    <!-- ================================================================== -->
    <div id="tab-catalog" class="resell-tab-content active">

        <!-- Upload Section -->
        <div class="resell-card">
            <h2>Upload Catalogo</h2>
            <p class="description">
                Carica il file <code>catalog.json</code> con la struttura del catalogo prodotti.
                Il file definisce sezioni, brand e SKU da importare.
            </p>

            <form id="catalog-upload-form" enctype="multipart/form-data">
                <div class="resell-upload-zone" id="upload-zone">
                    <div class="resell-upload-icon">
                        <span class="dashicons dashicons-upload"></span>
                    </div>
                    <p class="resell-upload-text">
                        Trascina qui il file <strong>catalog.json</strong> oppure
                        <label for="catalog-file-input" class="resell-upload-link">seleziona file</label>
                    </p>
                    <input type="file" id="catalog-file-input" name="catalog_file" accept=".json,application/json" style="display:none">
                    <p class="resell-upload-hint">Formato: JSON con chiave "sections"</p>
                </div>
                <div id="upload-progress" class="resell-progress" style="display:none">
                    <div class="resell-progress-bar"></div>
                    <span class="resell-progress-text">Caricamento...</span>
                </div>
            </form>
        </div>

        <!-- Catalog Viewer -->
        <div id="catalog-viewer" class="resell-card" style="<?php echo $catalog ? '' : 'display:none'; ?>">
            <div class="resell-card-header">
                <h2>Struttura Catalogo</h2>
                <div class="resell-card-actions">
                    <button type="button" class="button" id="btn-toggle-tree" title="Espandi/Comprimi">
                        <span class="dashicons dashicons-editor-expand"></span>
                    </button>
                    <button type="button" class="button button-link-delete" id="btn-delete-catalog">
                        <span class="dashicons dashicons-trash"></span> Elimina
                    </button>
                </div>
            </div>

            <!-- Metadata Summary -->
            <div id="catalog-meta" class="resell-meta-grid">
                <?php if ($catalog_meta): ?>
                <div class="resell-meta-item">
                    <span class="resell-meta-label">Sezioni</span>
                    <span class="resell-meta-value" id="meta-sections"><?php echo $catalog_meta['sections']; ?></span>
                </div>
                <div class="resell-meta-item">
                    <span class="resell-meta-label">Prodotti (SKU)</span>
                    <span class="resell-meta-value" id="meta-products"><?php echo $catalog_meta['total_products']; ?></span>
                </div>
                <div class="resell-meta-item">
                    <span class="resell-meta-label">Brand</span>
                    <span class="resell-meta-value" id="meta-brands"><?php echo $catalog_meta['total_brands']; ?></span>
                </div>
                <div class="resell-meta-item">
                    <span class="resell-meta-label">Dimensione File</span>
                    <span class="resell-meta-value" id="meta-filesize"><?php echo size_format($catalog_meta['file_size']); ?></span>
                </div>
                <div class="resell-meta-item">
                    <span class="resell-meta-label">Ultimo Aggiornamento</span>
                    <span class="resell-meta-value" id="meta-modified"><?php echo date_i18n('d/m/Y H:i', $catalog_meta['modified']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Section Tree -->
            <div id="catalog-tree" class="resell-tree">
                <?php if ($catalog_meta): ?>
                    <?php foreach ($catalog_meta['breakdown'] as $section): ?>
                    <div class="resell-tree-section">
                        <div class="resell-tree-toggle" data-expanded="true">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                            <strong><?php echo esc_html($section['name']); ?></strong>
                            <span class="resell-badge"><?php echo $section['products']; ?> SKU</span>
                            <code class="resell-tag"><?php echo esc_html($section['wc_category']); ?></code>
                        </div>
                        <div class="resell-tree-children">
                            <?php if (!empty($section['subcategories'])): ?>
                            <div class="resell-tree-subcategories">
                                <span class="resell-label">Sotto-categorie:</span>
                                <?php foreach ($section['subcategories'] as $sc): ?>
                                    <span class="resell-tag-sub"><?php echo esc_html($sc); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($section['brands'] as $brand): ?>
                            <div class="resell-tree-brand">
                                <span class="dashicons dashicons-tag"></span>
                                <?php echo esc_html($brand['name']); ?>
                                <span class="resell-badge-sm"><?php echo $brand['products']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Empty State -->
        <div id="catalog-empty" class="resell-card resell-empty-state" style="<?php echo $catalog ? 'display:none' : ''; ?>">
            <span class="dashicons dashicons-portfolio"></span>
            <h3>Nessun catalogo presente</h3>
            <p>Carica un file <code>catalog.json</code> per visualizzare la struttura del catalogo.</p>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- TAB: Aggiungi Prodotto (Quick-Add)                                  -->
    <!-- ================================================================== -->
    <div id="tab-quick-add" class="resell-tab-content">

        <?php if (!$has_api_key): ?>
        <div class="resell-card resell-notice-warning">
            <span class="dashicons dashicons-warning"></span>
            <p>Configura la <strong>API Key KicksDB</strong> nella scheda Impostazioni per abilitare la ricerca prodotti.</p>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <div class="resell-card">
            <h2>Cerca Prodotto su KicksDB</h2>
            <p class="description">
                Cerca per SKU, nome o modello. Seleziona un prodotto per importarlo nel tuo negozio WooCommerce.
            </p>

            <div class="resell-search-box">
                <input type="text"
                       id="kicksdb-search"
                       class="regular-text"
                       placeholder="Es: DD1391-100, Nike Dunk Low, Supreme Box Logo..."
                       autocomplete="off"
                       <?php echo $has_api_key ? '' : 'disabled'; ?>>
                <button type="button" class="button button-primary" id="btn-search" <?php echo $has_api_key ? '' : 'disabled'; ?>>
                    <span class="dashicons dashicons-search"></span> Cerca
                </button>
                <span id="search-spinner" class="spinner" style="float:none;"></span>
            </div>
        </div>

        <!-- Search Results -->
        <div id="search-results" class="resell-card" style="display:none">
            <div class="resell-card-header">
                <h2>Risultati</h2>
                <span id="results-count" class="resell-badge"></span>
            </div>
            <div id="results-grid" class="resell-results-grid"></div>
        </div>

        <!-- Import Modal -->
        <div id="import-modal" class="resell-modal" style="display:none">
            <div class="resell-modal-overlay"></div>
            <div class="resell-modal-content">
                <div class="resell-modal-header">
                    <h3>Importa Prodotto</h3>
                    <button type="button" class="resell-modal-close">&times;</button>
                </div>
                <div class="resell-modal-body">
                    <div class="resell-import-preview">
                        <img id="import-preview-img" src="" alt="">
                        <div class="resell-import-details">
                            <h4 id="import-preview-title"></h4>
                            <p id="import-preview-sku" class="resell-text-mono"></p>
                            <p id="import-preview-brand"></p>
                            <p id="import-preview-meta"></p>
                        </div>
                    </div>

                    <div class="resell-form-group">
                        <label for="import-category">Categoria WooCommerce</label>
                        <select id="import-category">
                            <option value="sneakers">Sneakers</option>
                            <option value="clothing">Abbigliamento</option>
                            <option value="accessories">Accessori</option>
                        </select>
                    </div>

                    <div class="resell-import-summary">
                        <div class="resell-import-stat">
                            <span class="resell-import-stat-label">Variazioni</span>
                            <span class="resell-import-stat-value" id="import-var-count">-</span>
                        </div>
                        <div class="resell-import-stat">
                            <span class="resell-import-stat-label">Prezzo min</span>
                            <span class="resell-import-stat-value" id="import-price-min">-</span>
                        </div>
                        <div class="resell-import-stat">
                            <span class="resell-import-stat-label">Prezzo max</span>
                            <span class="resell-import-stat-value" id="import-price-max">-</span>
                        </div>
                    </div>
                </div>
                <div class="resell-modal-footer">
                    <button type="button" class="button resell-modal-cancel">Annulla</button>
                    <button type="button" class="button button-primary" id="btn-confirm-import">
                        <span class="dashicons dashicons-download"></span> Importa Prodotto
                    </button>
                    <span id="import-spinner" class="spinner" style="float:none;"></span>
                </div>
            </div>
        </div>

        <!-- Import Result -->
        <div id="import-result" class="resell-card" style="display:none"></div>
    </div>

    <!-- ================================================================== -->
    <!-- TAB: Impostazioni                                                   -->
    <!-- ================================================================== -->
    <div id="tab-settings" class="resell-tab-content">
        <div class="resell-card">
            <h2>Impostazioni KicksDB</h2>
            <p class="description">
                Configura la connessione all'API KicksDB per la ricerca e importazione prodotti.
                Ottieni la tua API Key su <a href="https://kicks.dev" target="_blank">kicks.dev</a>.
            </p>

            <form id="settings-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="settings-api-key">API Key</label></th>
                        <td>
                            <input type="password"
                                   id="settings-api-key"
                                   class="regular-text"
                                   value="<?php echo esc_attr(get_option(Resell_Catalogo::OPT_KICKSDB_KEY, '')); ?>"
                                   placeholder="Inserisci la tua KicksDB API Key">
                            <button type="button" class="button" id="btn-toggle-key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <p class="description">Bearer token per l'autenticazione API.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="settings-market">Mercato</label></th>
                        <td>
                            <select id="settings-market">
                                <?php
                                $markets = ['IT' => 'Italia (IT)', 'US' => 'USA (US)', 'GB' => 'UK (GB)', 'DE' => 'Germania (DE)', 'FR' => 'Francia (FR)', 'ES' => 'Spagna (ES)'];
                                foreach ($markets as $code => $label):
                                ?>
                                <option value="<?php echo $code; ?>" <?php selected($market, $code); ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Determina la valuta e i prezzi di mercato restituiti dall'API.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="btn-save-settings">
                        Salva Impostazioni
                    </button>
                    <span id="settings-spinner" class="spinner" style="float:none;"></span>
                    <span id="settings-saved" class="resell-saved-notice" style="display:none">Salvato!</span>
                </p>
            </form>
        </div>
    </div>
</div>

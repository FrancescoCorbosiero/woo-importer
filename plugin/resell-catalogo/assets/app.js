/**
 * Resell Catalogo — Dashboard JavaScript
 */
(function ($) {
    'use strict';

    var config = window.resellCatalogo || {};
    var selectedProduct = null;

    // =====================================================================
    // Tabs
    // =====================================================================

    $('.resell-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        var tabId = $(this).data('tab');

        $('.resell-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.resell-tab-content').removeClass('active');
        $('#' + tabId).addClass('active');
    });

    // =====================================================================
    // Catalog Upload
    // =====================================================================

    var $zone = $('#upload-zone');
    var $fileInput = $('#catalog-file-input');

    // Drag and drop
    $zone.on('dragover dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $zone.addClass('drag-over');
    });

    $zone.on('dragleave drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $zone.removeClass('drag-over');
    });

    $zone.on('drop', function (e) {
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            uploadCatalog(files[0]);
        }
    });

    // Click to upload
    $zone.on('click', function (e) {
        if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') {
            $fileInput.trigger('click');
        }
    });

    $fileInput.on('change', function () {
        if (this.files.length) {
            uploadCatalog(this.files[0]);
        }
    });

    function uploadCatalog(file) {
        if (!file.name.endsWith('.json')) {
            alert('Seleziona un file .json');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'resell_upload_catalog');
        formData.append('nonce', config.nonce);
        formData.append('catalog_file', file);

        var $progress = $('#upload-progress');
        $progress.show().find('.resell-progress-bar').css('width', '30%');
        $progress.find('.resell-progress-text').text('Validazione JSON...');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
                var xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var pct = Math.round((e.loaded / e.total) * 70) + 30;
                        $progress.find('.resell-progress-bar').css('width', pct + '%');
                    }
                });
                return xhr;
            },
            success: function (response) {
                $progress.find('.resell-progress-bar').css('width', '100%');
                $progress.find('.resell-progress-text').text('Completato!');

                setTimeout(function () {
                    $progress.hide();
                    $progress.find('.resell-progress-bar').css('width', '0');
                }, 1000);

                if (response.success) {
                    renderCatalog(response.data.meta, response.data.catalog);
                    $('#catalog-viewer').show();
                    $('#catalog-empty').hide();
                } else {
                    alert(response.data.message || 'Errore durante il caricamento.');
                }
            },
            error: function () {
                $progress.hide();
                alert('Errore di rete durante il caricamento.');
            }
        });
    }

    // =====================================================================
    // Catalog Rendering
    // =====================================================================

    function renderCatalog(meta, catalog) {
        // Update metadata
        $('#meta-sections').text(meta.sections);
        $('#meta-products').text(meta.total_products);
        $('#meta-brands').text(meta.total_brands);
        $('#meta-filesize').text(formatFileSize(meta.file_size));
        $('#meta-modified').text(new Date(meta.modified * 1000).toLocaleString('it-IT'));

        // Render tree
        var $tree = $('#catalog-tree').empty();

        $.each(meta.breakdown || [], function (_, section) {
            var $section = $('<div class="resell-tree-section">');
            var $toggle = $('<div class="resell-tree-toggle" data-expanded="true">')
                .append('<span class="dashicons dashicons-arrow-down-alt2"></span>')
                .append('<strong>' + escHtml(section.name) + '</strong>')
                .append('<span class="resell-badge">' + section.products + ' SKU</span>')
                .append('<code class="resell-tag">' + escHtml(section.wc_category) + '</code>');

            var $children = $('<div class="resell-tree-children">');

            // Subcategories
            if (section.subcategories && section.subcategories.length) {
                var $subcats = $('<div class="resell-tree-subcategories"><span class="resell-label">Sotto-categorie:</span></div>');
                $.each(section.subcategories, function (_, sc) {
                    $subcats.append('<span class="resell-tag-sub">' + escHtml(sc) + '</span>');
                });
                $children.append($subcats);
            }

            // Brands
            $.each(section.brands || [], function (_, brand) {
                $children.append(
                    '<div class="resell-tree-brand">' +
                    '<span class="dashicons dashicons-tag"></span>' +
                    escHtml(brand.name) +
                    '<span class="resell-badge-sm">' + brand.products + '</span>' +
                    '</div>'
                );
            });

            $section.append($toggle).append($children);
            $tree.append($section);
        });
    }

    // Tree toggle
    $(document).on('click', '.resell-tree-toggle', function () {
        var expanded = $(this).attr('data-expanded') === 'true';
        $(this).attr('data-expanded', expanded ? 'false' : 'true');
    });

    // Toggle all
    $('#btn-toggle-tree').on('click', function () {
        var $toggles = $('.resell-tree-toggle');
        var allExpanded = $toggles.filter('[data-expanded="true"]').length === $toggles.length;
        $toggles.attr('data-expanded', allExpanded ? 'false' : 'true');
    });

    // Delete catalog
    $('#btn-delete-catalog').on('click', function () {
        if (!confirm('Sei sicuro di voler eliminare il catalogo?')) {
            return;
        }

        $.post(config.ajaxUrl, {
            action: 'resell_delete_catalog',
            nonce: config.nonce
        }, function (response) {
            if (response.success) {
                $('#catalog-viewer').hide();
                $('#catalog-empty').show();
                $('#catalog-tree').empty();
            }
        });
    });

    // =====================================================================
    // KicksDB Search
    // =====================================================================

    var searchTimer = null;

    $('#kicksdb-search').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            doSearch();
        }
    });

    // Auto-search after typing pause
    $('#kicksdb-search').on('input', function () {
        clearTimeout(searchTimer);
        var val = $(this).val().trim();
        if (val.length >= 3) {
            searchTimer = setTimeout(doSearch, 600);
        }
    });

    $('#btn-search').on('click', doSearch);

    function doSearch() {
        var query = $('#kicksdb-search').val().trim();
        if (query.length < 2) {
            return;
        }

        var $spinner = $('#search-spinner');
        var $btn = $('#btn-search');

        $spinner.addClass('is-active');
        $btn.prop('disabled', true);

        $.post(config.ajaxUrl, {
            action: 'resell_search_kicksdb',
            nonce: config.nonce,
            query: query
        }, function (response) {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);

            if (response.success) {
                renderResults(response.data.products);
            } else {
                alert(response.data.message || 'Errore ricerca.');
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            alert('Errore di rete.');
        });
    }

    // =====================================================================
    // Search Results Rendering
    // =====================================================================

    function renderResults(products) {
        var $container = $('#search-results');
        var $grid = $('#results-grid').empty();

        if (!products.length) {
            $grid.append('<p style="padding:16px;color:#757575;">Nessun risultato trovato.</p>');
            $container.show();
            $('#results-count').text('0');
            return;
        }

        $('#results-count').text(products.length + ' trovati');

        $.each(products, function (idx, product) {
            var priceText = '';
            if (product.price_min && product.price_max) {
                priceText = product.price_min === product.price_max
                    ? formatPrice(product.price_min)
                    : formatPrice(product.price_min) + ' - ' + formatPrice(product.price_max);
            }

            var imgHtml = product.image
                ? '<img class="resell-product-img" src="' + escAttr(product.image) + '" alt="' + escAttr(product.title) + '" loading="lazy">'
                : '<div class="resell-product-no-img"><span class="dashicons dashicons-format-image"></span></div>';

            var $card = $(
                '<div class="resell-product-card" data-idx="' + idx + '">' +
                imgHtml +
                '<div class="resell-product-info">' +
                '<p class="resell-product-title">' + escHtml(product.title) + '</p>' +
                '<p class="resell-product-sku">' + escHtml(product.sku) + '</p>' +
                '<div class="resell-product-footer">' +
                '<span class="resell-product-brand">' + escHtml(product.brand) + '</span>' +
                (priceText ? '<span class="resell-product-price">' + priceText + '</span>' : '') +
                '</div>' +
                '<p class="resell-product-variants">' + product.variant_count + ' taglie disponibili</p>' +
                '</div>' +
                '</div>'
            );

            // Store raw data on element
            $card.data('product', product);
            $grid.append($card);
        });

        $container.show();
    }

    // Click on product card → open import modal
    $(document).on('click', '.resell-product-card', function () {
        var product = $(this).data('product');
        if (!product) return;

        selectedProduct = product;
        openImportModal(product);
    });

    // =====================================================================
    // Import Modal
    // =====================================================================

    function openImportModal(product) {
        var $modal = $('#import-modal');

        // Fill preview
        if (product.image) {
            $('#import-preview-img').attr('src', product.image).show();
        } else {
            $('#import-preview-img').hide();
        }
        $('#import-preview-title').text(product.title);
        $('#import-preview-sku').text(product.sku);
        $('#import-preview-brand').text(product.brand);

        var metaParts = [];
        if (product.colorway) metaParts.push(product.colorway);
        if (product.gender) metaParts.push(product.gender);
        if (product.release_date) metaParts.push(product.release_date);
        $('#import-preview-meta').text(metaParts.join(' | '));

        // Stats
        $('#import-var-count').text(product.variant_count);
        $('#import-price-min').text(product.price_min ? formatPrice(product.price_min) : '-');
        $('#import-price-max').text(product.price_max ? formatPrice(product.price_max) : '-');

        // Auto-detect category
        var type = (product.product_type || '').toLowerCase();
        if (type === 'sneakers' || type === 'shoes') {
            $('#import-category').val('sneakers');
        } else if (type === 'streetwear' || type === 'apparel' || type === 'clothing') {
            $('#import-category').val('clothing');
        } else if (type === 'collectibles') {
            $('#import-category').val('accessories');
        }

        // Reset state
        $('#btn-confirm-import').prop('disabled', false);
        $('#import-spinner').removeClass('is-active');

        $modal.show();
    }

    function closeImportModal() {
        $('#import-modal').hide();
        selectedProduct = null;
    }

    $(document).on('click', '.resell-modal-overlay, .resell-modal-close, .resell-modal-cancel', closeImportModal);

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeImportModal();
        }
    });

    // =====================================================================
    // Import Execution
    // =====================================================================

    $('#btn-confirm-import').on('click', function () {
        if (!selectedProduct) return;

        var $btn = $(this);
        var $spinner = $('#import-spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(config.ajaxUrl, {
            action: 'resell_import_product',
            nonce: config.nonce,
            product_data: JSON.stringify(selectedProduct._raw),
            category: $('#import-category').val()
        }, function (response) {
            $spinner.removeClass('is-active');
            closeImportModal();

            if (response.success) {
                showImportResult(response.data);
            } else {
                var msg = response.data.message || 'Errore durante l\'importazione.';
                if (response.data.edit_url) {
                    msg += ' <a href="' + response.data.edit_url + '" target="_blank">Vedi prodotto</a>';
                }
                showImportResult({ error: true, message: msg });
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
            alert('Errore di rete durante l\'importazione.');
        });
    });

    function showImportResult(data) {
        var $result = $('#import-result').empty().show();

        if (data.error) {
            $result.html(
                '<div class="resell-import-success" style="color:#d63638;">' +
                '<span class="dashicons dashicons-dismiss" style="color:#d63638;"></span>' +
                '<div>' +
                '<h3 style="color:#d63638;">Errore</h3>' +
                '<p>' + data.message + '</p>' +
                '</div></div>'
            );
            return;
        }

        $result.html(
            '<div class="resell-import-success">' +
            '<span class="dashicons dashicons-yes-alt"></span>' +
            '<div>' +
            '<h3>Importazione Completata</h3>' +
            '<p>' + escHtml(data.message) + '</p>' +
            '<p>Tipo: <strong>' + data.type + '</strong> | Variazioni: <strong>' + data.variations + '</strong></p>' +
            '<div class="resell-result-links">' +
            '<a href="' + escAttr(data.edit_url) + '" class="button" target="_blank">Modifica Prodotto</a>' +
            '<a href="' + escAttr(data.view_url) + '" class="button" target="_blank">Vedi nel Negozio</a>' +
            '</div></div></div>'
        );

        // Scroll to result
        $('html, body').animate({ scrollTop: $result.offset().top - 50 }, 300);
    }

    // =====================================================================
    // Settings
    // =====================================================================

    $('#settings-form').on('submit', function (e) {
        e.preventDefault();

        var $spinner = $('#settings-spinner');
        var $saved = $('#settings-saved');

        $spinner.addClass('is-active');
        $saved.hide();

        $.post(config.ajaxUrl, {
            action: 'resell_save_settings',
            nonce: config.nonce,
            kicksdb_api_key: $('#settings-api-key').val(),
            kicksdb_market: $('#settings-market').val()
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                $saved.text('Salvato!').show().delay(3000).fadeOut();
            } else {
                alert(response.data.message || 'Errore salvataggio.');
            }
        });
    });

    // Toggle API key visibility
    $('#btn-toggle-key').on('click', function () {
        var $input = $('#settings-api-key');
        var isPassword = $input.attr('type') === 'password';
        $input.attr('type', isPassword ? 'text' : 'password');
        $(this).find('.dashicons')
            .toggleClass('dashicons-visibility', !isPassword)
            .toggleClass('dashicons-hidden', isPassword);
    });

    // =====================================================================
    // Utilities
    // =====================================================================

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatPrice(price) {
        return new Intl.NumberFormat('it-IT', {
            style: 'currency',
            currency: 'EUR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(price);
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

})(jQuery);

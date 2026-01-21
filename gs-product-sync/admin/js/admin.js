/**
 * GS Product Sync Admin JavaScript
 */

(function($) {
    'use strict';

    var GSPS_Admin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initTabs();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Action buttons
            $(document).on('click', '.gsps-action-btn', this.handleAction.bind(this));

            // Toggle log data
            $(document).on('click', '.gsps-toggle-data', this.toggleLogData);
        },

        /**
         * Initialize settings tabs
         */
        initTabs: function() {
            var $tabs = $('.gsps-tabs .gsps-tab');
            var $contents = $('.gsps-tab-content');

            if (!$tabs.length) return;

            $tabs.on('click', function(e) {
                e.preventDefault();

                var target = $(this).attr('href');

                $tabs.removeClass('active');
                $(this).addClass('active');

                $contents.removeClass('active');
                $(target).addClass('active');

                // Update URL hash
                if (history.pushState) {
                    history.pushState(null, null, target);
                }
            });

            // Check URL hash on load
            if (window.location.hash) {
                var $activeTab = $tabs.filter('[href="' + window.location.hash + '"]');
                if ($activeTab.length) {
                    $activeTab.trigger('click');
                }
            }
        },

        /**
         * Handle action button click
         */
        handleAction: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var action = $btn.data('action');
            var confirmKey = $btn.data('confirm');

            if (!action) return;

            // Confirmation dialog
            if (confirmKey && gsps_admin.strings[confirmKey]) {
                if (!confirm(gsps_admin.strings[confirmKey])) {
                    return;
                }
            }

            this.runAction($btn, action);
        },

        /**
         * Run AJAX action
         */
        runAction: function($btn, action) {
            var self = this;
            var originalText = $btn.html();

            // Disable button and show loading
            $btn.addClass('is-loading').prop('disabled', true);
            $btn.find('.dashicons').removeClass().addClass('dashicons dashicons-update');

            // Clear previous result
            $('#gsps-action-result').hide().removeClass('success error');

            $.ajax({
                url: gsps_admin.ajax_url,
                type: 'POST',
                data: {
                    action: action,
                    nonce: gsps_admin.nonce
                },
                success: function(response) {
                    self.showResult(response);
                },
                error: function(xhr, status, error) {
                    self.showResult({
                        success: false,
                        data: {
                            message: error || 'Request failed'
                        }
                    });
                },
                complete: function() {
                    $btn.removeClass('is-loading').prop('disabled', false);
                    $btn.html(originalText);
                }
            });
        },

        /**
         * Show action result
         */
        showResult: function(response) {
            var $result = $('#gsps-action-result');
            var html = '';

            if (response.success) {
                $result.addClass('success');
                html = '<strong>' + gsps_admin.strings.success + '</strong>';

                if (response.data.message) {
                    html += ': ' + response.data.message;
                }

                if (response.data.stats) {
                    html += '<br><br>';
                    html += this.formatStats(response.data.stats);
                }

                if (response.data.import_stats) {
                    html += '<br><br><strong>Import:</strong><br>';
                    html += this.formatStats(response.data.import_stats);
                }

                if (response.data.duration) {
                    html += '<br><br>Tempo: ' + response.data.duration + 's';
                }

                if (response.data.count !== undefined) {
                    html += '<br>Prodotti trovati: ' + response.data.count;
                }
            } else {
                $result.addClass('error');
                html = '<strong>' + gsps_admin.strings.error + '</strong>';

                if (response.data && response.data.message) {
                    html += ': ' + response.data.message;
                }
            }

            $result.html(html).fadeIn();

            // Scroll to result
            $('html, body').animate({
                scrollTop: $result.offset().top - 100
            }, 300);
        },

        /**
         * Format stats object as HTML
         */
        formatStats: function(stats) {
            var html = '';

            for (var key in stats) {
                if (stats.hasOwnProperty(key)) {
                    var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                        return l.toUpperCase();
                    });
                    html += label + ': ' + stats[key] + '<br>';
                }
            }

            return html;
        },

        /**
         * Toggle log data visibility
         */
        toggleLogData: function(e) {
            e.preventDefault();

            var $btn = $(this);
            var $data = $btn.next('.gsps-log-data');

            $data.slideToggle(200);

            if ($data.is(':visible')) {
                $btn.text('Nascondi dati');
            } else {
                $btn.text('Mostra dati');
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        GSPS_Admin.init();
    });

})(jQuery);

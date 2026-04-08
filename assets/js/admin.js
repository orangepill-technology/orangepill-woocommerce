/**
 * Orangepill WooCommerce - Admin JavaScript
 */

(function($) {
    'use strict';

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Connection test button handler
        $('#orangepill-test-connection').on('click', function(e) {
            e.preventDefault();
            testConnection();
        });

        // Webhook registration retry button handler
        $('#orangepill-retry-webhook').on('click', function(e) {
            e.preventDefault();
            retryWebhookRegistration();
        });

        // Toggle details in sync log
        $('.orangepill-toggle-details').on('click', function() {
            var target = $(this).data('target');
            $('#' + target).toggle();

            if ($('#' + target).is(':visible')) {
                $(this).text('Hide');
            } else {
                $(this).text('View');
            }
        });
    });

    /**
     * Test connection to Orangepill API
     */
    function testConnection() {
        var $button = $('#orangepill-test-connection');
        var $spinner = $('#orangepill-test-spinner');
        var $statusCard = $('.orangepill-connection-status .orangepill-status-card');

        // Disable button and show spinner
        $button.prop('disabled', true).addClass('loading');
        $spinner.addClass('is-active');

        // Make AJAX request
        $.ajax({
            url: orangepillWC.ajax_url,
            type: 'POST',
            data: {
                action: 'orangepill_test_connection',
                nonce: orangepillWC.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateConnectionStatus(response.data);
                } else {
                    showError(response.data.message || 'Connection test failed');
                }
            },
            error: function(xhr, status, error) {
                showError('AJAX request failed: ' + error);
            },
            complete: function() {
                // Re-enable button and hide spinner
                $button.prop('disabled', false).removeClass('loading');
                $spinner.removeClass('is-active');
            }
        });
    }

    /**
     * Update connection status display
     */
    function updateConnectionStatus(data) {
        var $statusCard = $('.orangepill-connection-status .orangepill-status-card');
        var html = '';

        if (data.success) {
            html = '<div class="orangepill-status-indicator orangepill-status-success">';
            html += '<span class="dashicons dashicons-yes-alt"></span>';
            html += '<span>Connected</span>';
            html += '</div>';
            html += '<p class="description">Last tested: just now</p>';
        } else {
            html = '<div class="orangepill-status-indicator orangepill-status-error">';
            html += '<span class="dashicons dashicons-warning"></span>';
            html += '<span>Connection failed</span>';
            html += '</div>';
            html += '<p class="description" style="color: #d63638;">' + escapeHtml(data.message) + '</p>';
        }

        html += '<div style="margin-top: 15px;">';
        html += '<button type="button" id="orangepill-test-connection" class="button button-secondary">Test Connection</button>';
        html += '<span id="orangepill-test-spinner" class="spinner" style="float: none; margin: 0 10px;"></span>';
        html += '</div>';

        $statusCard.html(html);

        // Reattach event handler to new button
        $('#orangepill-test-connection').on('click', function(e) {
            e.preventDefault();
            testConnection();
        });

        // Show success message
        if (data.success) {
            showNotice('Connection test successful!', 'success');
        }
    }

    /**
     * Show error message
     */
    function showError(message) {
        showNotice(message, 'error');
    }

    /**
     * Show admin notice
     */
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');

        $('.wrap h1').after($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);

        // Make dismissible
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }

    /**
     * Retry integration webhook registration
     */
    function retryWebhookRegistration() {
        var $button  = $('#orangepill-retry-webhook');
        var $spinner = $('#orangepill-webhook-spinner');
        var $card    = $('#orangepill-webhook-status-card');

        $button.prop('disabled', true);
        $spinner.addClass('is-active');

        $.ajax({
            url: orangepillWC.ajax_url,
            type: 'POST',
            data: {
                action: 'orangepill_retry_webhook_registration',
                nonce: orangepillWC.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateWebhookStatus(response.data);
                } else {
                    showError(response.data.message || 'Webhook registration failed');
                }
            },
            error: function(xhr, status, error) {
                showError('AJAX request failed: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    /**
     * Update webhook registration status display
     */
    function updateWebhookStatus(data) {
        var $card = $('#orangepill-webhook-status-card');
        var html  = '';

        if (data.success) {
            html += '<div class="orangepill-status-indicator orangepill-status-success">';
            html += '<span class="dashicons dashicons-yes-alt"></span>';
            html += '<span>Registered</span>';
            html += '</div>';
            if (data.webhook_id) {
                html += '<p class="description">Webhook ID: ' + escapeHtml(data.webhook_id) + '</p>';
            }
        } else {
            html += '<div class="orangepill-status-indicator orangepill-status-error">';
            html += '<span class="dashicons dashicons-warning"></span>';
            html += '<span>Registration failed</span>';
            html += '</div>';
            html += '<p class="description" style="color: #d63638;">' + escapeHtml(data.message) + '</p>';
        }

        html += '<div style="margin-top: 15px;">';
        html += '<button type="button" id="orangepill-retry-webhook" class="button button-secondary">Retry Registration</button>';
        html += '<span id="orangepill-webhook-spinner" class="spinner" style="float: none; margin: 0 10px;"></span>';
        html += '</div>';

        $card.html(html);

        // Reattach event handler to new button
        $('#orangepill-retry-webhook').on('click', function(e) {
            e.preventDefault();
            retryWebhookRegistration();
        });

        if (data.success) {
            showNotice('Webhook registered successfully!', 'success');
        } else {
            showNotice('Webhook registration failed: ' + data.message, 'error');
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);

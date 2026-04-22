/**
 * Orangepill Native Payment Shell (PR-WC-NATIVE-CHECKOUT-1)
 *
 * Flow:
 *   1. Load payment options  (GET  payment-options)
 *   2. Create intent         (POST create-intent)
 *   3. Execute intent        (POST execute-intent)
 *      a. redirect           → WC form submit → process_payment → redirect URL
 *      b. processing         → WC form submit → process_payment → on-hold order
 *      c. completed          → WC form submit → process_payment → payment_complete
 *      d. payment_request_required
 *           → render QR / dynamic key inline
 *           → poll GET payment-status every 4 s
 *           → on succeeded  → WC form submit (execution_type=completed)
 *           → on failed     → show error, allow retry
 */
(function ($) {
    'use strict';

    var AJAX_URL = orangepillNative.ajax_url;
    var NONCE    = orangepillNative.nonce;
    var i18n     = orangepillNative.i18n;

    var state = {
        selectedMethodKey: null,
        selectedChannel:   null,
        paymentOptions:    null,
        intentSubmitted:   false,
        isPlacing:         false,
        pollTimer:         null,
        pollPaymentId:     null,
        pollStarted:       null,
        expiryTimer:       null,
    };

    var POLL_INTERVAL_MS  = 4000;
    var POLL_TIMEOUT_MS   = 10 * 60 * 1000; // 10 min max

    // ─── Boot ──────────────────────────────────────────────────────────────────

    $(document).ready(function () {
        bindCheckoutEvents();
    });

    function bindCheckoutEvents() {
        $(document.body).on('updated_checkout', function () {
            if (isOrangepillSelected()) loadPaymentOptions();
        });

        if (isOrangepillSelected()) loadPaymentOptions();

        $(document).on('change', '#payment_method_orangepill', function () {
            if ($(this).is(':checked')) loadPaymentOptions();
        });

        $('form.checkout').on('checkout_place_order_orangepill', function () {
            if (state.intentSubmitted) {
                state.intentSubmitted = false;
                return true;
            }
            if (state.isPlacing) return false;
            if (!state.selectedMethodKey) {
                showFormError(i18n.select_method);
                return false;
            }
            state.isPlacing = true;
            setShellState('placing');
            handlePayment(state.selectedMethodKey, state.selectedChannel);
            return false;
        });
    }

    // ─── Payment options ───────────────────────────────────────────────────────

    function loadPaymentOptions() {
        var $shell = $('#orangepill-native-shell');
        if (!$shell.length) return;

        stopPolling();
        state.selectedMethodKey = null;
        state.selectedChannel   = null;
        setShellState('loading');

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data: {
                action:   'orangepill_get_payment_options',
                nonce:    NONCE,
                currency: $shell.data('currency'),
                amount:   $shell.data('amount'),
                country:  $shell.data('country'),
            },
            success: function (response) {
                if (response.success && response.data && response.data.options) {
                    state.paymentOptions = response.data.options;
                    renderOptions($shell, state.paymentOptions);
                } else {
                    setShellState('error', (response.data && response.data.message) || i18n.options_error);
                }
            },
            error: function () {
                setShellState('error', i18n.options_error);
            },
        });
    }

    function renderOptions($shell, options) {
        var eligible = options.filter(function (o) { return o.eligible; });
        if (!eligible.length) { setShellState('empty'); return; }

        // Expand methods with multiple channels into one entry per channel.
        // e.g., bank_transfer.bre_b with channels=['qr','reference'] → two rows.
        var rows = [];
        eligible.forEach(function (method) {
            var channels = method.channels || [];
            if (channels.length > 1) {
                channels.forEach(function (ch) {
                    rows.push({
                        methodKey:     method.methodKey,
                        channel:       ch,
                        label:         method.label + ' (' + getChannelLabel(ch) + ')',
                        estimatedSpeed: method.estimatedSpeed,
                    });
                });
            } else {
                rows.push({
                    methodKey:     method.methodKey,
                    channel:       channels.length === 1 ? channels[0] : null,
                    label:         method.label,
                    estimatedSpeed: method.estimatedSpeed,
                });
            }
        });

        var html = '<div class="op-methods-list" role="radiogroup" aria-label="' + esc(i18n.select_method) + '">';
        rows.forEach(function (row, idx) {
            var speedClass = row.estimatedSpeed ? ' op-speed-' + row.estimatedSpeed : '';
            var channelAttr = row.channel ? ' data-channel="' + esc(row.channel) + '"' : '';
            html += '<label class="op-method-item" data-method-key="' + esc(row.methodKey) + '"' + channelAttr + '>';
            html += '<input type="radio" class="op-method-radio" name="op_method_key_ui"';
            html += ' value="' + esc(row.methodKey) + '"';
            html += ' data-channel="' + esc(row.channel || '') + '">';
            html += '<span class="op-method-label">' + escHtml(row.label) + '</span>';
            if (row.estimatedSpeed) {
                html += '<span class="op-method-speed' + speedClass + '">' + escHtml(getSpeedLabel(row.estimatedSpeed)) + '</span>';
            }
            html += '</label>';
        });
        html += '</div>';

        $shell.html(html);
        $shell.removeAttr('aria-busy');

        var $first = $shell.find('.op-method-radio').first();
        $first.prop('checked', true);
        state.selectedMethodKey = $first.val();
        state.selectedChannel   = $first.data('channel') || null;
        $first.closest('.op-method-item').addClass('op-method-selected');

        $shell.on('change', '.op-method-radio', function () {
            state.selectedMethodKey = $(this).val();
            state.selectedChannel   = $(this).data('channel') || null;
            $shell.find('.op-method-item').removeClass('op-method-selected');
            $(this).closest('.op-method-item').addClass('op-method-selected');
        });
    }

    // ─── Payment execution ─────────────────────────────────────────────────────

    function handlePayment(methodKey, channel) {
        var $shell   = $('#orangepill-native-shell');
        var currency = $shell.data('currency');
        var amount   = $shell.data('amount');

        setShellState('placing', i18n.creating_payment);

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data: {
                action:     'orangepill_create_intent',
                nonce:      NONCE,
                method_key: methodKey,
                currency:   currency,
                amount:     amount,
            },
            success: function (response) {
                if (!response.success) {
                    handlePaymentError((response.data && response.data.message) || i18n.payment_error);
                    return;
                }
                executeIntent(response.data.intentId, methodKey, channel);
            },
            error: function () { handlePaymentError(i18n.payment_error); },
        });
    }

    function executeIntent(intentId, methodKey, channel) {
        setShellState('placing', i18n.processing_payment);

        var postData = {
            action:     'orangepill_execute_intent',
            nonce:      NONCE,
            intent_id:  intentId,
            method_key: methodKey,
        };
        if (channel) {
            postData.channel = channel;
        }

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data:   postData,
            success: function (response) {
                if (!response.success) {
                    handlePaymentError((response.data && response.data.message) || i18n.payment_error);
                    return;
                }

                var data     = response.data;
                var execType = data.execution_type;

                if (execType === 'payment_request_required') {
                    // Render QR / dynamic key inline — do NOT submit WC form yet
                    state.isPlacing = false;
                    renderPaymentRequest(intentId, data.payment_request);
                    return;
                }

                // All other types (redirect / processing / completed): inject and submit
                setHiddenField('_orangepill_intent_id',      intentId);
                setHiddenField('_orangepill_execution_type', execType);
                state.isPlacing       = false;
                state.intentSubmitted = true;
                $('form.checkout').submit();
            },
            error: function () { handlePaymentError(i18n.payment_error); },
        });
    }

    // ─── Payment request (QR / dynamic key) ───────────────────────────────────

    function renderPaymentRequest(intentId, pr) {
        if (!pr) {
            handlePaymentError(i18n.payment_error);
            return;
        }

        var $shell    = $('#orangepill-native-shell');
        var rendering = pr.rendering || {};
        var mode      = pr.mode || 'dynamic_key';
        var expiresAt = pr.expires_at || null;
        var paymentId = pr.payment_id || intentId;

        // Remove placing overlay if present
        $shell.find('.op-placing-overlay').remove();

        var html = '<div class="op-payment-request" data-payment-id="' + esc(paymentId) + '">';

        if (mode === 'dynamic_qr' && rendering.qr_image_base64) {
            html += '<div class="op-pr-qr">';
            html += '<img src="data:image/png;base64,' + escHtml(rendering.qr_image_base64) + '" alt="QR de pago" class="op-qr-image" />';
            html += '</div>';
        }

        // Dynamic key or reference number
        var keyValue = rendering.key_text || rendering.display_text || rendering.key_alias || rendering.instrument_id || '';
        if (keyValue) {
            html += '<div class="op-pr-key">';
            html += '<span class="op-pr-key-label">' + escHtml(i18n.payment_key || 'Clave de pago') + '</span>';
            html += '<div class="op-pr-key-value-row">';
            html += '<span class="op-pr-key-value">' + escHtml(keyValue) + '</span>';
            html += '<button type="button" class="op-copy-btn" data-copy="' + esc(keyValue) + '">' + escHtml(i18n.copy || 'Copiar') + '</button>';
            html += '</div>';
            if (rendering.instructions) {
                html += '<p class="op-pr-instructions">' + escHtml(rendering.instructions) + '</p>';
            }
            html += '</div>';
        }

        // Expiry countdown
        if (expiresAt) {
            html += '<div class="op-pr-expiry">';
            html += '<span class="op-pr-expiry-label">' + escHtml(i18n.expires_in || 'Expira en') + ': </span>';
            html += '<span class="op-pr-expiry-countdown"></span>';
            html += '</div>';
        }

        // Waiting indicator
        html += '<div class="op-pr-waiting">';
        html += '<span class="op-spinner"></span>';
        html += '<span>' + escHtml(i18n.waiting_payment || 'Esperando confirmación del pago...') + '</span>';
        html += '</div>';

        html += '</div>';

        $shell.find('.op-methods-list').after(html);
        $shell.attr('data-state', 'awaiting_payment');

        // Copy button
        $shell.on('click.op-copy', '.op-copy-btn', function () {
            var text = $(this).data('copy');
            var $btn = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(String(text)).then(function () {
                    $btn.text(i18n.copied || 'Copiado');
                    setTimeout(function () { $btn.text(i18n.copy || 'Copiar'); }, 2000);
                });
            }
        });

        // Start expiry countdown
        if (expiresAt) {
            startExpiryCountdown($shell, expiresAt, intentId, paymentId);
        }

        // Start polling
        startPolling(intentId, paymentId);
    }

    // ─── Expiry countdown ──────────────────────────────────────────────────────

    function startExpiryCountdown($shell, expiresAt, intentId, paymentId) {
        var expiry = new Date(expiresAt).getTime();

        function tick() {
            var remaining = Math.max(0, expiry - Date.now());
            var mins = Math.floor(remaining / 60000);
            var secs = Math.floor((remaining % 60000) / 1000);
            $shell.find('.op-pr-expiry-countdown').text(
                (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs
            );
            if (remaining <= 0) {
                clearInterval(state.expiryTimer);
                stopPolling();
                handlePaymentError(i18n.payment_expired || 'El tiempo para pagar ha expirado. Por favor intenta de nuevo.');
            }
        }

        tick();
        state.expiryTimer = setInterval(tick, 1000);
    }

    // ─── Polling ───────────────────────────────────────────────────────────────

    function startPolling(intentId, paymentId) {
        stopPolling();
        state.pollPaymentId = paymentId;
        state.pollStarted   = Date.now();

        state.pollTimer = setInterval(function () {
            if (Date.now() - state.pollStarted > POLL_TIMEOUT_MS) {
                stopPolling();
                handlePaymentError(i18n.payment_timeout || 'Tiempo de espera agotado. Verifica tu email para confirmar el pago.');
                return;
            }

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data: {
                    action:     'orangepill_get_payment_status',
                    nonce:      NONCE,
                    payment_id: paymentId,
                },
                success: function (response) {
                    if (!response.success) return;

                    var status = response.data.status;

                    if (status === 'succeeded' || status === 'completed') {
                        stopPolling();
                        onPaymentSucceeded(intentId);
                    } else if (status === 'failed' || status === 'cancelled' || status === 'expired') {
                        stopPolling();
                        handlePaymentError(i18n.payment_failed || 'El pago no fue completado. Por favor intenta de nuevo.');
                    }
                    // pending / processing → keep polling
                },
            });
        }, POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (state.pollTimer)   { clearInterval(state.pollTimer);   state.pollTimer   = null; }
        if (state.expiryTimer) { clearInterval(state.expiryTimer); state.expiryTimer = null; }
        state.pollPaymentId = null;
    }

    function onPaymentSucceeded(intentId) {
        var $shell = $('#orangepill-native-shell');
        $shell.find('.op-pr-waiting').html(
            '<span class="op-pr-success-icon">&#10003;</span> ' +
            escHtml(i18n.payment_confirmed || '¡Pago confirmado!')
        );
        $shell.find('.op-pr-expiry').hide();

        // Re-submit WC form — process_payment will verify and complete the order
        setTimeout(function () {
            setHiddenField('_orangepill_intent_id',      intentId);
            setHiddenField('_orangepill_execution_type', 'completed');
            state.intentSubmitted = true;
            $('form.checkout').submit();
        }, 600);
    }

    // ─── Error handling ────────────────────────────────────────────────────────

    function handlePaymentError(message) {
        stopPolling();
        state.isPlacing = false;
        setShellState('error', message);
        $('form.checkout').unblock();
        $('.woocommerce-checkout-review-order-table').unblock();
    }

    function showFormError(message) {
        $('.woocommerce-notices-wrapper').first().html(
            '<ul class="woocommerce-error" role="alert"><li>' + escHtml(message) + '</li></ul>'
        );
        $('html, body').animate({ scrollTop: 0 }, 400);
    }

    // ─── Shell state helpers ───────────────────────────────────────────────────

    function setShellState(stateName, message) {
        var $shell = $('#orangepill-native-shell');
        if (!$shell.length) return;

        $shell.attr('data-state', stateName);

        if (stateName === 'loading') {
            $shell.attr('aria-busy', 'true').html(
                '<div class="op-native-loading">' + escHtml(i18n.loading_options) + '</div>'
            );
        } else if (stateName === 'placing') {
            var msg = message || i18n.processing_payment;
            if (!$shell.find('.op-placing-overlay').length) {
                $shell.append(
                    '<div class="op-placing-overlay"><span class="op-spinner"></span>' +
                    '<span class="op-placing-msg">' + escHtml(msg) + '</span></div>'
                );
            } else {
                $shell.find('.op-placing-msg').text(msg);
            }
        } else if (stateName === 'error') {
            $shell.removeAttr('aria-busy');
            $shell.find('.op-placing-overlay, .op-payment-request').remove();
            $shell.prepend(
                '<div class="op-native-error" role="alert">' + escHtml(message || i18n.payment_error) + '</div>'
            );
            setTimeout(function () {
                $shell.find('.op-native-error').fadeOut(400, function () { $(this).remove(); });
            }, 6000);
        } else if (stateName === 'empty') {
            $shell.removeAttr('aria-busy').html(
                '<p class="op-no-methods">' + escHtml(i18n.no_methods) + '</p>'
            );
        }
    }

    // ─── DOM helpers ──────────────────────────────────────────────────────────

    function setHiddenField(name, value) {
        var $f = $('input[name="' + name + '"]');
        if ($f.length) { $f.val(value); }
        else { $('<input type="hidden">').attr('name', name).val(value).appendTo('form.checkout'); }
    }

    function getSpeedLabel(speed) {
        var map = {
            instant:  i18n.speed_instant  || 'Instant',
            same_day: i18n.speed_same_day || 'Same day',
            next_day: i18n.speed_next_day || 'Next day',
            unknown:  '',
        };
        return map[speed] || '';
    }

    function getChannelLabel(channel) {
        var map = {
            qr:        i18n.channel_qr        || 'QR',
            reference: i18n.channel_reference || 'Llave Dinámica',
            redirect:  'Redireccionado',
            embedded:  'Integrado',
        };
        return map[channel] || channel;
    }

    function isOrangepillSelected() {
        return $('#payment_method_orangepill').is(':checked');
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

}(jQuery));

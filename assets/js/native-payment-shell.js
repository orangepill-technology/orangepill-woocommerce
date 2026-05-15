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

    var AJAX_URL           = orangepillNative.ajax_url;
    var NONCE              = orangepillNative.nonce;
    var i18n               = orangepillNative.i18n;
    var WOMPI_PUBLIC_KEY   = orangepillNative.wompi_public_key   || '';
    var WOMPI_TOKENIZE_URL = orangepillNative.wompi_tokenize_url || '';

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

        // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1: populate hidden conversation field
        // at checkout load for classic hosted path (Path B — form submits directly).
        // Path A (native) overwrites this in handlePayment() with a fresh read.
        setHiddenField(
            '_orangepill_conversation_id',
            getConversationId()
        );

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
        renderSubForm(state.selectedMethodKey, $shell);

        $shell.on('change', '.op-method-radio', function () {
            state.selectedMethodKey = $(this).val();
            state.selectedChannel   = $(this).data('channel') || null;
            $shell.find('.op-method-item').removeClass('op-method-selected');
            $(this).closest('.op-method-item').addClass('op-method-selected');
            renderSubForm(state.selectedMethodKey, $shell);
        });
    }

    // ─── Method sub-forms ─────────────────────────────────────────────────────

    function renderSubForm(methodKey, $shell) {
        $shell.find('.op-subform').remove();

        if (methodKey === 'wallet.nequi') {
            $shell.find('.op-methods-list').after(
                '<div class="op-subform op-subform-nequi">' +
                '<p class="op-subform-desc">' + escHtml(i18n.nequi_desc || 'Ingresa el número de celular registrado en Nequi.') + '</p>' +
                '<label class="op-subform-label">' + escHtml(i18n.phone_number || 'Número de celular') + '</label>' +
                '<input type="tel" class="op-subform-phone input-text" placeholder="3001234567" maxlength="10" autocomplete="tel-national">' +
                '<span class="op-subform-error"></span>' +
                '</div>'
            );

        } else if (methodKey === 'wallet.daviplata') {
            $shell.find('.op-methods-list').after(
                '<div class="op-subform op-subform-daviplata">' +
                '<p class="op-subform-desc">' + escHtml(i18n.daviplata_desc || 'Ingresa los datos de tu cuenta Daviplata.') + '</p>' +
                '<label class="op-subform-label">' + escHtml(i18n.phone_number || 'Número de celular') + '</label>' +
                '<input type="tel" class="op-subform-phone input-text" placeholder="3001234567" maxlength="10" autocomplete="tel-national">' +
                '<label class="op-subform-label">' + escHtml(i18n.id_type || 'Tipo de documento') + '</label>' +
                '<select class="op-subform-id-type">' +
                '<option value="CC">Cédula de ciudadanía (CC)</option>' +
                '<option value="CE">Cédula de extranjería (CE)</option>' +
                '<option value="NIT">NIT</option>' +
                '<option value="PP">Pasaporte (PP)</option>' +
                '<option value="SSN">SSN</option>' +
                '<option value="LIC">Licencia (LIC)</option>' +
                '<option value="NIP">NIP</option>' +
                '</select>' +
                '<label class="op-subform-label">' + escHtml(i18n.id_number || 'Número de documento') + '</label>' +
                '<input type="text" class="op-subform-id-num input-text" placeholder="1234567890" autocomplete="off">' +
                '<span class="op-subform-error"></span>' +
                '</div>'
            );

        } else if (methodKey === 'card') {
            $shell.find('.op-methods-list').after(
                '<div class="op-subform op-subform-card">' +
                '<p class="op-subform-desc">' + escHtml(i18n.card_desc || 'Tus datos de tarjeta van directamente a Wompi.') + '</p>' +
                '<label class="op-subform-label">' + escHtml(i18n.card_number || 'Número de tarjeta') + '</label>' +
                '<input type="text" class="op-subform-card-num input-text" placeholder="1234 5678 9012 3456" maxlength="19" autocomplete="cc-number" inputmode="numeric">' +
                '<div class="op-subform-row">' +
                '<div class="op-subform-col">' +
                '<label class="op-subform-label">' + escHtml(i18n.card_expiry || 'Vencimiento (MM/AA)') + '</label>' +
                '<input type="text" class="op-subform-expiry input-text" placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">' +
                '</div>' +
                '<div class="op-subform-col">' +
                '<label class="op-subform-label">CVC</label>' +
                '<input type="text" class="op-subform-cvc input-text" placeholder="123" maxlength="4" autocomplete="cc-csc" inputmode="numeric">' +
                '</div>' +
                '</div>' +
                '<label class="op-subform-label">' + escHtml(i18n.card_holder || 'Nombre del titular') + '</label>' +
                '<input type="text" class="op-subform-holder input-text" placeholder="Juan Pérez" autocomplete="cc-name">' +
                '<span class="op-subform-error"></span>' +
                '</div>'
            );
        }
    }

    function collectSubFormData(methodKey, $shell) {
        var $sub = $shell.find('.op-subform');
        var $err = $sub.find('.op-subform-error').hide().text('');

        if (methodKey === 'wallet.nequi') {
            var phone = $sub.find('.op-subform-phone').val().replace(/\D/g, '');
            if (phone.length !== 10) {
                $err.text(i18n.nequi_phone_error || 'Ingresa un número colombiano de 10 dígitos (ej. 3001234567)').show();
                return null;
            }
            return { phone_number: phone };
        }

        if (methodKey === 'wallet.daviplata') {
            var phone   = $sub.find('.op-subform-phone').val().replace(/\D/g, '');
            var idType  = $sub.find('.op-subform-id-type').val();
            var idNum   = $sub.find('.op-subform-id-num').val().replace(/\s/g, '');
            if (phone.length !== 10) {
                $err.text(i18n.daviplata_phone_error || 'Ingresa un número colombiano de 10 dígitos').show();
                return null;
            }
            if (!idNum) {
                $err.text(i18n.daviplata_id_error || 'Ingresa tu número de documento').show();
                return null;
            }
            return { phone_number: phone, user_legal_id_type: idType, user_legal_id: idNum };
        }

        return {}; // no extra data required for this method
    }

    // ─── Payment execution ─────────────────────────────────────────────────────

    function handlePayment(methodKey, channel) {
        var $shell = $('#orangepill-native-shell');

        // Validate sub-form data for methods that require it.
        // Returns null on failure (error shown inline) — reset state and bail.
        var subData = collectSubFormData(methodKey, $shell);
        if (subData === null) {
            state.isPlacing = false;
            $('form.checkout').unblock();
            $('.woocommerce-checkout-review-order-table').unblock();
            return;
        }

        var conversationId = getConversationId();
        setHiddenField('_orangepill_conversation_id', conversationId);

        // Card: tokenize browser-side with Wompi public key before create_intent.
        // The Wompi token (payment_method_id) is the only thing that reaches our server —
        // raw card data (PAN, CVC) never leaves the browser.
        if (methodKey === 'card') {
            setShellState('placing', i18n.tokenizing_card || 'Verificando tarjeta...');
            tokenizeCard($shell, function (tokenId, tokenErr) {
                if (!tokenId) {
                    if (tokenErr) { return; } // inline error already shown
                    handlePaymentError(i18n.card_error || 'Error al verificar la tarjeta.');
                    return;
                }
                doCreateIntent(methodKey, { payment_method_id: tokenId }, conversationId, $shell);
            });
            return;
        }

        doCreateIntent(methodKey, subData, conversationId, $shell);
    }

    function doCreateIntent(methodKey, extraData, conversationId, $shell) {
        setShellState('placing', i18n.creating_payment);

        var postData = {
            action:          'orangepill_create_intent',
            nonce:           NONCE,
            method_key:      methodKey,
            currency:        $shell.data('currency'),
            amount:          $shell.data('amount'),
            conversation_id: conversationId,
        };

        if (extraData.phone_number)       postData.phone_number       = extraData.phone_number;
        if (extraData.user_legal_id_type) postData.user_legal_id_type = extraData.user_legal_id_type;
        if (extraData.user_legal_id)      postData.user_legal_id      = extraData.user_legal_id;
        if (extraData.payment_method_id)  postData.payment_method_id  = extraData.payment_method_id;

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data:   postData,
            success: function (response) {
                if (!response.success) {
                    handlePaymentError((response.data && response.data.message) || i18n.payment_error);
                    return;
                }
                // Channel is overridden server-side for wallet/card; passing state.selectedChannel
                // here is a no-op for those — the PHP enforce logic takes precedence.
                executeIntent(response.data.intentId, methodKey, state.selectedChannel);
            },
            error: function () { handlePaymentError(i18n.payment_error); },
        });
    }

    function tokenizeCard($shell, callback) {
        if (!WOMPI_TOKENIZE_URL || !WOMPI_PUBLIC_KEY) {
            var $err = $shell.find('.op-subform-card .op-subform-error');
            $err.text(i18n.card_not_configured || 'Pago con tarjeta no configurado.').show();
            callback(null, true); // true = inline error shown, no generic toast
            return;
        }

        var $sub     = $shell.find('.op-subform-card');
        var $err     = $sub.find('.op-subform-error').hide().text('');
        var rawNum   = $sub.find('.op-subform-card-num').val().replace(/[\s\-]/g, '');
        var rawExp   = $sub.find('.op-subform-expiry').val().trim();
        var cvc      = $sub.find('.op-subform-cvc').val().trim();
        var holder   = $sub.find('.op-subform-holder').val().trim();
        var parts    = rawExp.split('/');
        var expMonth = (parts[0] || '').trim();
        var expYear  = (parts[1] || '').trim();

        if (rawNum.length < 13 || !/^\d+$/.test(rawNum)) {
            $err.text(i18n.card_number_invalid || 'Número de tarjeta inválido').show();
            callback(null, true); return;
        }
        if (expMonth.length !== 2 || expYear.length !== 2 || !/^\d{2}$/.test(expMonth) || !/^\d{2}$/.test(expYear)) {
            $err.text(i18n.card_expiry_invalid || 'Fecha inválida — usa el formato MM/AA').show();
            callback(null, true); return;
        }
        if (!cvc || cvc.length < 3) {
            $err.text(i18n.card_cvc_invalid || 'CVC inválido').show();
            callback(null, true); return;
        }
        if (!holder) {
            $err.text(i18n.card_holder_invalid || 'Ingresa el nombre del titular').show();
            callback(null, true); return;
        }

        fetch(WOMPI_TOKENIZE_URL, {
            method:  'POST',
            headers: { 'Authorization': 'Bearer ' + WOMPI_PUBLIC_KEY, 'Content-Type': 'application/json' },
            body:    JSON.stringify({ number: rawNum, cvc: cvc, exp_month: expMonth, exp_year: expYear, card_holder: holder }),
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            var tokenId = json && json.data && json.data.id;
            if (tokenId) {
                callback(tokenId, null);
            } else {
                var errMsg = (json && json.error && json.error.reason) || '';
                $err.text(errMsg || i18n.card_error || 'Error al verificar la tarjeta.').show();
                callback(null, true);
            }
        })
        .catch(function () {
            callback(null, false); // generic toast
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
            var qrRaw = rendering.qr_image_base64;
            var qrSrc = (qrRaw.indexOf('data:') === 0) ? qrRaw : 'data:image/png;base64,' + qrRaw;
            html += '<div class="op-pr-qr">';
            html += '<img src="' + escHtml(qrSrc) + '" alt="QR de pago" class="op-qr-image" />';
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

    // ─── Conversation helper ──────────────────────────────────────────────────

    // Thin wrapper — delegates to conversation-helper.js global (loaded first).
    // Returns null if helper not loaded or widget not available.
    function getConversationId() {
        return ( window.OrangepillConversationHelper &&
            typeof window.OrangepillConversationHelper.getActiveConversationId === 'function' )
            ? window.OrangepillConversationHelper.getActiveConversationId()
            : null;
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

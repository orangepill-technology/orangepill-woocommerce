/**
 * Orangepill Native Payment Shell (PR-WC-NATIVE-CHECKOUT-1)
 *
 * Intercepts WooCommerce's checkout_place_order_orangepill event,
 * drives GET payment-options → POST create-intent → POST execute-intent,
 * then injects result fields and allows WC to proceed with form submission.
 *
 * State machine:
 *   idle → loading_options → options_ready → placing_order
 *   → (on execute result) → completed | processing | redirect
 *
 * All API calls are proxied through WP AJAX (API key never touches browser).
 */
(function ($) {
    'use strict';

    var AJAX_URL    = orangepillNative.ajax_url;
    var NONCE       = orangepillNative.nonce;
    var i18n        = orangepillNative.i18n;

    var state = {
        selectedMethodKey: null,
        paymentOptions:    null,
        intentSubmitted:   false, // guards re-entry after JS re-submits form
        isPlacing:         false,
    };

    // ─── Boot ──────────────────────────────────────────────────────────────

    $(document).ready(function () {
        bindCheckoutEvents();
    });

    function bindCheckoutEvents() {
        // Re-bind after WC rebuilds the payment section (AJAX cart updates)
        $(document.body).on('updated_checkout', function () {
            if (isOrangepillSelected()) {
                loadPaymentOptions();
            }
        });

        // Initial load when gateway already selected
        if (isOrangepillSelected()) {
            loadPaymentOptions();
        }

        // Switch to Orangepill
        $(document).on('change', '#payment_method_orangepill', function () {
            if ($(this).is(':checked')) {
                loadPaymentOptions();
            }
        });

        // Intercept WC form submission for our gateway
        $('form.checkout').on('checkout_place_order_orangepill', function () {
            if (state.intentSubmitted) {
                state.intentSubmitted = false;
                return true; // let WC proceed
            }

            if (state.isPlacing) {
                return false;
            }

            if (!state.selectedMethodKey) {
                showFormError(i18n.select_method);
                return false;
            }

            state.isPlacing = true;
            setShellState('placing');
            handlePayment();
            return false; // block WC's own submission
        });
    }

    // ─── Payment options ───────────────────────────────────────────────────

    function loadPaymentOptions() {
        var $shell = $('#orangepill-native-shell');
        if (!$shell.length) return;

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

        if (!eligible.length) {
            setShellState('empty');
            return;
        }

        var html = '<div class="op-methods-list" role="radiogroup" aria-label="' + esc(i18n.select_method) + '">';

        eligible.forEach(function (method) {
            var speedClass = method.estimatedSpeed ? ' op-speed-' + method.estimatedSpeed : '';
            html += '<label class="op-method-item" data-method-key="' + esc(method.methodKey) + '">';
            html += '<input type="radio" class="op-method-radio" name="op_method_key_ui"'
                +   ' value="' + esc(method.methodKey) + '">';
            html += '<span class="op-method-label">' + escHtml(method.label) + '</span>';
            if (method.estimatedSpeed) {
                html += '<span class="op-method-speed' + speedClass + '">'
                    +   escHtml(getSpeedLabel(method.estimatedSpeed)) + '</span>';
            }
            html += '</label>';
        });

        html += '</div>';
        $shell.html(html);
        $shell.removeAttr('aria-busy');

        // Auto-select first eligible method
        var $first = $shell.find('.op-method-radio').first();
        $first.prop('checked', true);
        state.selectedMethodKey = $first.val();

        $shell.on('change', '.op-method-radio', function () {
            state.selectedMethodKey = $(this).val();
            $shell.find('.op-method-item').removeClass('op-method-selected');
            $(this).closest('.op-method-item').addClass('op-method-selected');
        });

        $first.closest('.op-method-item').addClass('op-method-selected');
    }

    // ─── Payment execution ─────────────────────────────────────────────────

    function handlePayment() {
        var $shell    = $('#orangepill-native-shell');
        var currency  = $shell.data('currency');
        var amount    = $shell.data('amount');

        setShellState('placing', i18n.creating_payment);

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data: {
                action:     'orangepill_create_intent',
                nonce:      NONCE,
                method_key: state.selectedMethodKey,
                currency:   currency,
                amount:     amount,
            },
            success: function (response) {
                if (!response.success) {
                    handlePaymentError((response.data && response.data.message) || i18n.payment_error);
                    return;
                }
                executeIntent(response.data.intentId);
            },
            error: function () {
                handlePaymentError(i18n.payment_error);
            },
        });
    }

    function executeIntent(intentId) {
        setShellState('placing', i18n.processing_payment);

        $.ajax({
            url:    AJAX_URL,
            method: 'POST',
            data: {
                action:     'orangepill_execute_intent',
                nonce:      NONCE,
                intent_id:  intentId,
                method_key: state.selectedMethodKey,
            },
            success: function (response) {
                if (!response.success) {
                    handlePaymentError((response.data && response.data.message) || i18n.payment_error);
                    return;
                }

                var data     = response.data;
                var execType = data.execution_type;

                // Inject fields for process_payment()
                setHiddenField('_orangepill_intent_id',      intentId);
                setHiddenField('_orangepill_execution_type', execType);

                // Allow WC form to proceed on next submit
                state.isPlacing       = false;
                state.intentSubmitted = true;

                // Re-submit WC checkout form — checkout_place_order_orangepill
                // will fire again but intentSubmitted guard lets it through
                $('form.checkout').submit();
            },
            error: function () {
                handlePaymentError(i18n.payment_error);
            },
        });
    }

    // ─── Error handling ────────────────────────────────────────────────────

    function handlePaymentError(message) {
        state.isPlacing = false;
        setShellState('error', message);

        // Unblock WC form so user can retry
        $('form.checkout').unblock();
        $('.woocommerce-checkout-review-order-table').unblock();
    }

    function showFormError(message) {
        $('.woocommerce-notices-wrapper').first().html(
            '<ul class="woocommerce-error" role="alert">'
            + '<li>' + escHtml(message) + '</li>'
            + '</ul>'
        );
        $('html, body').animate({ scrollTop: 0 }, 400);
    }

    // ─── Shell state helpers ───────────────────────────────────────────────

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
            // Don't replace method list — just show a spinner overlay
            if (!$shell.find('.op-placing-overlay').length) {
                $shell.append(
                    '<div class="op-placing-overlay"><span class="op-spinner"></span>'
                    + '<span class="op-placing-msg">' + escHtml(msg) + '</span></div>'
                );
            } else {
                $shell.find('.op-placing-msg').text(msg);
            }
        } else if (stateName === 'error') {
            $shell.removeAttr('aria-busy');
            $shell.find('.op-placing-overlay').remove();
            $shell.prepend(
                '<div class="op-native-error" role="alert">' + escHtml(message || i18n.payment_error) + '</div>'
            );
            setTimeout(function () { $shell.find('.op-native-error').fadeOut(400, function () { $(this).remove(); }); }, 5000);
        } else if (stateName === 'empty') {
            $shell.removeAttr('aria-busy').html(
                '<p class="op-no-methods">' + escHtml(i18n.no_methods) + '</p>'
            );
        }
    }

    // ─── DOM helpers ──────────────────────────────────────────────────────

    function setHiddenField(name, value) {
        var $f = $('input[name="' + name + '"]');
        if ($f.length) {
            $f.val(value);
        } else {
            $('<input type="hidden">').attr('name', name).val(value).appendTo('form.checkout');
        }
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

    function isOrangepillSelected() {
        return $('#payment_method_orangepill').is(':checked');
    }

    function esc(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#039;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

}(jQuery));

/**
 * Orangepill WooCommerce — Checkout Rewards Wallet Widget
 *
 * PR-WC-CHECKOUT-WALLET-UX-1 Part 1:
 * Fetches the logged-in customer's spendable rewards balance from the server
 * and renders a compact opt-in toggle on the checkout page.
 *
 * Rules enforced here:
 *  - Woo never computes balances (amount comes from API response verbatim)
 *  - Woo never computes max applicable amount (full spendable shown; backend caps it)
 *  - Wallet ID passed through hidden field so server doesn't need a second API call
 *  - Widget failure is silent — checkout always remains functional
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initWalletWidget();

        // Re-init after WooCommerce updates the checkout (shipping recalc, coupon, etc.)
        $(document.body).on('updated_checkout', function () {
            initWalletWidget();
        });
    });

    function initWalletWidget() {
        var $widget = $('#orangepill-wallet-widget');
        if (!$widget.length || !$widget.data('loading')) {
            return;
        }

        // Mark initialised — remove HTML attribute so updated_checkout re-runs don't re-fetch
        $widget.removeAttr('data-loading');

        $.ajax({
            url: orangepillCheckout.ajax_url,
            type: 'POST',
            data: {
                action: 'orangepill_get_wallet_balance',
                nonce:  orangepillCheckout.nonce,
            },
            success: function (response) {
                console.log('[OP] wallet AJAX response:', JSON.stringify(response));
                if (response.success && response.data && response.data.wallet) {
                    console.log('[OP] rendering wallet widget, spendable:', response.data.wallet.spendable_balance);
                    renderWalletWidget($widget, response.data.wallet);
                } else {
                    console.log('[OP] no wallet returned, hiding widget');
                    $widget.hide();
                }
            },
            error: function (xhr, status, err) {
                console.error('[OP] wallet AJAX error:', status, err);
                // Silent failure — widget hidden, checkout unaffected
                $widget.hide();
            },
        });
    }

    function renderWalletWidget($widget, wallet) {
        var spendable = parseFloat(wallet.spendable_balance || wallet.balance || 0);

        if (spendable <= 0) {
            $widget.hide();
            return;
        }

        var currency  = escapeHtml(wallet.currency || '');
        var walletId  = escapeHtml(wallet.id || '');
        var formatted = formatAmount(spendable, currency);

        var html =
            '<div class="op-wallet-widget">' +
            '<p class="op-wallet-label">' +
                escapeHtml(orangepillCheckout.i18n.available_label) + ' ' +
                '<strong>' + formatted + '</strong>' +
            '</p>' +
            '<label class="op-wallet-toggle">' +
                '<input type="checkbox" id="op-use-wallet" />' +
                '<span>' + escapeHtml(orangepillCheckout.i18n.apply_label) + '</span>' +
            '</label>' +
            '</div>';

        $widget.html(html).show();

        // Store wallet_id so server skips a second API call
        $('#orangepill_wallet_id').val(walletId);

        $('#op-use-wallet').on('change', function () {
            if ($(this).is(':checked')) {
                var cartTotal = parseFloat(orangepillCheckout.cart_total || 0);
                // Backend requires amount < session total (full coverage not allowed via this path).
                // Token wallets apply at 1:1 (1 PCF = 1 COP), so cap against cart total directly.
                var capped = (cartTotal > 0 && spendable >= cartTotal)
                    ? parseFloat((cartTotal - 0.01).toFixed(2))
                    : spendable;
                $('#orangepill_apply_wallet').val('1');
                $('#orangepill_wallet_amount').val(capped.toFixed(2));
                showApplyPreview($widget, capped, currency);
            } else {
                $('#orangepill_apply_wallet').val('0');
                $('#orangepill_wallet_amount').val('');
                $('#op-wallet-preview').remove();
            }
        });
    }

    function showApplyPreview($widget, applying, currency) {
        $('#op-wallet-preview').remove();

        // Token wallets apply at 1:1 (1 PCF = 1 COP), so remaining is always in store currency.
        var cartTotal   = parseFloat(orangepillCheckout.cart_total || 0);
        var remaining   = Math.max(0, cartTotal - applying);
        var walletCur   = escapeHtml(currency);
        var storeCur    = escapeHtml(orangepillCheckout.currency || '');

        var html = '<div id="op-wallet-preview">';
        html += '<p class="op-wallet-preview">' +
            escapeHtml(orangepillCheckout.i18n.applying_label) + ' <strong>' + formatAmount(applying, walletCur) + '</strong>' +
            ' &nbsp;|&nbsp; ' +
            escapeHtml(orangepillCheckout.i18n.remaining_label) + ' <strong>' + formatAmount(remaining, storeCur) + '</strong>' +
            ' <em style="font-size:11px;color:#888;">*</em></p>';
        html += '<p style="font-size:11px;color:#888;margin:2px 0 0 0;">* ' +
            escapeHtml('Final amount determined by Orangepill') + '</p>';
        html += '</div>';

        $widget.append(html);
    }

    function formatAmount(amount, currency) {
        return escapeHtml(
            new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            }).format(amount) + (currency ? ' ' + currency : '')
        );
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

})(jQuery);

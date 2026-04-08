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

        // Mark initialised — prevents double-fetch on rapid checkout updates
        $widget.removeData('loading');

        $.ajax({
            url: orangepillCheckout.ajax_url,
            type: 'POST',
            data: {
                action: 'orangepill_get_wallet_balance',
                nonce:  orangepillCheckout.nonce,
            },
            success: function (response) {
                if (response.success && response.data && response.data.wallet) {
                    renderWalletWidget($widget, response.data.wallet);
                } else {
                    $widget.hide();
                }
            },
            error: function () {
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
                $('#orangepill_apply_wallet').val('1');
                $('#orangepill_wallet_amount').val(spendable.toFixed(2));
                showApplyPreview($widget, spendable, currency);
            } else {
                $('#orangepill_apply_wallet').val('0');
                $('#orangepill_wallet_amount').val('');
                $('#op-wallet-preview').remove();
            }
        });
    }

    function showApplyPreview($widget, applying, currency) {
        $('#op-wallet-preview').remove();

        var cartTotal  = parseFloat(orangepillCheckout.cart_total || 0);
        // Cap applying at order total (backend will enforce; this is UX hint only)
        var applied    = Math.min(applying, cartTotal);
        var remaining  = Math.max(0, cartTotal - applied);
        var cur        = escapeHtml(currency);

        var html =
            '<p id="op-wallet-preview" class="op-wallet-preview">' +
            escapeHtml(orangepillCheckout.i18n.applying_label) + ' <strong>' + formatAmount(applied, cur) + '</strong>' +
            ' &nbsp;|&nbsp; ' +
            escapeHtml(orangepillCheckout.i18n.remaining_label) + ' <strong>' + formatAmount(remaining, cur) + '</strong>' +
            ' <em style="font-size:11px;color:#888;">*</em>' +
            '</p>' +
            '<p style="font-size:11px;color:#888;margin:2px 0 0 0;">* ' +
            escapeHtml('Final amount determined by Orangepill') +
            '</p>';

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

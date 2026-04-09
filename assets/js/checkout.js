/**
 * Orangepill WooCommerce — Checkout Rewards Wallet Widget
 *
 * Fetches the logged-in customer's spendable rewards balance and renders
 * a slider + input box so the customer can choose how much to apply.
 *
 * Rules enforced here:
 *  - Woo never computes balances (amount comes from API response verbatim)
 *  - Max applicable amount capped at cartTotal-0.01 (full coverage not allowed)
 *  - Token wallets apply at 1:1 (1 PCF = 1 COP) for remaining-to-pay display
 *  - Wallet ID passed through hidden field so server skips a second API call
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
                if (response.success && response.data && response.data.wallet) {
                    renderWalletWidget($widget, response.data.wallet);
                } else {
                    $widget.hide();
                }
            },
            error: function () {
                $widget.hide();
            },
        });
    }

    function renderWalletWidget($widget, wallet) {
        var spendable  = Math.floor(parseFloat(wallet.spendable_balance || wallet.balance || 0));

        if (spendable <= 0) {
            $widget.hide();
            return;
        }

        var cartTotal  = Math.floor(parseFloat(orangepillCheckout.cart_total || 0));
        var maxApply   = cartTotal > 0 ? Math.min(spendable, cartTotal - 1) : spendable;
        var currency   = wallet.currency || '';
        var walletId   = wallet.id || '';
        var storeCur   = orangepillCheckout.currency || '';

        var html =
            '<div class="op-wallet-widget">' +
            '<p class="op-wallet-label">' +
                escapeHtml(orangepillCheckout.i18n.available_label) + ' ' +
                '<strong>' + formatInt(spendable, currency) + '</strong>' +
            '</p>' +
            '<div class="op-wallet-slider-row">' +
                '<input type="range" id="op-wallet-slider"' +
                    ' min="0" max="' + maxApply + '" step="1" value="' + maxApply + '"' +
                    ' style="flex:1;cursor:pointer;">' +
                '<input type="number" id="op-wallet-input"' +
                    ' min="0" max="' + maxApply + '" step="1" value="' + maxApply + '"' +
                    ' style="width:90px;text-align:right;margin-left:10px;">' +
                '<span style="margin-left:6px;white-space:nowrap;">' + escapeHtml(currency) + '</span>' +
            '</div>' +
            '<div id="op-wallet-preview"></div>' +
            '</div>';

        $widget.html(html).show();

        // Store wallet_id
        $('#orangepill_wallet_id').val(escapeHtml(walletId));

        var $slider = $('#op-wallet-slider');
        var $input  = $('#op-wallet-input');

        // Render initial preview with full amount
        syncWallet(maxApply, currency, storeCur, cartTotal);

        $slider.on('input change', function () {
            var val = parseInt($(this).val(), 10);
            $input.val(val);
            syncWallet(val, currency, storeCur, cartTotal);
        });

        $input.on('input change', function () {
            var val = Math.max(0, Math.min(maxApply, parseInt($(this).val(), 10) || 0));
            $(this).val(val);
            $slider.val(val);
            syncWallet(val, currency, storeCur, cartTotal);
        });
    }

    function syncWallet(amount, walletCur, storeCur, cartTotal) {
        if (amount > 0) {
            $('#orangepill_apply_wallet').val('1');
            $('#orangepill_wallet_amount').val(amount.toFixed(2));
            updatePreview(amount, walletCur, storeCur, cartTotal);
        } else {
            $('#orangepill_apply_wallet').val('0');
            $('#orangepill_wallet_amount').val('');
            $('#op-wallet-preview').html('');
        }
    }

    function updatePreview(applying, walletCur, storeCur, cartTotal) {
        var remaining = Math.max(0, cartTotal - applying);
        var html =
            '<p class="op-wallet-preview" style="margin:8px 0 2px;">' +
            escapeHtml(orangepillCheckout.i18n.applying_label) + ' <strong>' + formatInt(applying, walletCur) + '</strong>' +
            ' &nbsp;|&nbsp; ' +
            escapeHtml(orangepillCheckout.i18n.remaining_label) + ' <strong>' + formatInt(remaining, storeCur) + '</strong>' +
            ' <em style="font-size:11px;color:#888;">*</em></p>' +
            '<p style="font-size:11px;color:#888;margin:0;">* ' +
            escapeHtml('Final amount determined by Orangepill') + '</p>';
        $('#op-wallet-preview').html(html);
    }

    function formatInt(amount, currency) {
        return escapeHtml(
            new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0,
            }).format(amount) + (currency ? ' ' + currency : '')
        );
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

})(jQuery);

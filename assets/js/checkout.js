/**
 * Orangepill WooCommerce - Checkout Loyalty Widget
 *
 * PR-OP-WOO-INTEGRATION-CORE-1 Part 4:
 * Fetches the logged-in customer's spendable wallet balance and renders a
 * toggle on the checkout page. The customer's choice (apply / don't apply)
 * is written to hidden fields that process_payment() reads on the server.
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        initWalletWidget();

        // Re-init after WooCommerce updates the checkout (e.g. shipping recalc)
        $(document.body).on('updated_checkout', function () {
            initWalletWidget();
        });
    });

    function initWalletWidget() {
        var $widget = $('#orangepill-wallet-widget');
        if (!$widget.length || !$widget.data('loading')) {
            return;
        }

        // Mark as initialised so we don't double-fetch
        $widget.removeData('loading');

        $.ajax({
            url: orangepillCheckout.ajax_url,
            type: 'POST',
            data: {
                action: 'orangepill_get_wallet_balance',
                nonce: orangepillCheckout.nonce,
            },
            success: function (response) {
                if (response.success && response.data.wallet) {
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
        var spendable = parseFloat(wallet.spendable_balance || wallet.balance || 0);
        if (spendable <= 0) {
            $widget.hide();
            return;
        }

        var currency = wallet.currency || '';
        var label    = orangepillCheckout.i18n.apply_balance
            .replace('{amount}', spendable.toFixed(2))
            .replace('{currency}', currency);

        var html =
            '<div class="op-wallet-widget" style="border:1px solid #e0e0e0;border-radius:4px;padding:12px;background:#f9f9f9;">' +
            '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">' +
            '<input type="checkbox" id="op-use-wallet" /> ' +
            '<span>' + escapeHtml(label) + '</span>' +
            '</label>' +
            '</div>';

        $widget.html(html).removeAttr('style');

        $('#op-use-wallet').on('change', function () {
            if ($(this).is(':checked')) {
                $('#orangepill_apply_wallet').val('1');
                $('#orangepill_wallet_amount').val(spendable.toFixed(2));
            } else {
                $('#orangepill_apply_wallet').val('0');
                $('#orangepill_wallet_amount').val('');
            }
        });
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

})(jQuery);

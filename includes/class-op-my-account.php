<?php
/**
 * Orangepill My Account Integration
 *
 * PR-WC-CHECKOUT-WALLET-UX-1:
 * - Part 3: Rewards wallet balance page (My Account → Rewards Balance)
 * - Part 4: Rewards history page (My Account → Rewards History)
 * - Part 5: Compact dashboard widget
 *
 * Rules enforced:
 *  - All balance/incentive data fetched from Orangepill API (never computed locally)
 *  - Pages are read-only views; Woo never mutates wallet state
 *  - User-facing copy uses "rewards balance" / "wallet balance" (not internal token jargon)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_My_Account {
    const ENDPOINT_LOYALTY = 'op-loyalty';
    const ENDPOINT_REWARDS = 'op-rewards';

    public function init() {
        add_filter('query_vars',                      array($this, 'add_query_vars'));
        add_action('init',                            array($this, 'add_endpoints'));
        add_filter('woocommerce_account_menu_items',  array($this, 'add_menu_items'));

        add_action('woocommerce_account_' . self::ENDPOINT_LOYALTY . '_endpoint', array($this, 'render_loyalty_page'));
        add_action('woocommerce_account_' . self::ENDPOINT_REWARDS . '_endpoint', array($this, 'render_rewards_page'));

        add_action('woocommerce_account_dashboard', array($this, 'render_dashboard_widget'));

        // AJAX: spendable balance for checkout wallet widget (Part 1)
        add_action('wp_ajax_orangepill_get_wallet_balance', array($this, 'ajax_get_wallet_balance'));
    }

    public function add_endpoints() {
        add_rewrite_endpoint(self::ENDPOINT_LOYALTY, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_REWARDS, EP_ROOT | EP_PAGES);
    }

    public function add_query_vars($vars) {
        $vars[] = self::ENDPOINT_LOYALTY;
        $vars[] = self::ENDPOINT_REWARDS;
        return $vars;
    }

    public function add_menu_items($items) {
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items[self::ENDPOINT_LOYALTY] = __('Rewards Balance', 'orangepill-wc');
                $new_items[self::ENDPOINT_REWARDS] = __('Rewards History', 'orangepill-wc');
            }
        }
        return $new_items;
    }

    // ─── Dashboard Widget (Part 5) ────────────────────────────────────────────

    /**
     * Compact widget on My Account dashboard.
     * Only shown when spendable balance > 0.
     * Balance value comes from API verbatim — never computed locally.
     */
    public function render_dashboard_widget() {
        $loyalty = new OP_Loyalty();
        $wallet  = $loyalty->get_spendable_wallet_for_current_user();

        if (!$wallet) {
            return;
        }

        $spendable = (float) ($wallet['spendable_balance'] ?? $wallet['balance'] ?? 0);
        if ($spendable <= 0) {
            return;
        }

        $formatted = number_format($spendable, 0, ',', '.') . ' ' . ($wallet['currency'] ?? '');

        ?>
        <div class="orangepill-dashboard-widget">
            <h3><?php esc_html_e('Rewards Balance', 'orangepill-wc'); ?></h3>
            <p>
                <?php esc_html_e('Available:', 'orangepill-wc'); ?>
                <strong><?php echo esc_html($formatted); ?></strong>
                &nbsp;&mdash;&nbsp;
                <a href="<?php echo esc_url(wc_get_account_endpoint_url(self::ENDPOINT_LOYALTY)); ?>">
                    <?php esc_html_e('View details', 'orangepill-wc'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // ─── Rewards Balance Page (Part 3) ────────────────────────────────────────

    public function render_loyalty_page() {
        $user_id     = get_current_user_id();
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        echo '<h2>' . esc_html__('Rewards Balance', 'orangepill-wc') . '</h2>';

        if (empty($customer_id)) {
            echo '<p>' . esc_html__('Your rewards balance will appear here after your first order.', 'orangepill-wc') . '</p>';
            return;
        }

        $loyalty = new OP_Loyalty();
        $wallets = $loyalty->get_wallets($customer_id);

        if (is_wp_error($wallets)) {
            OP_Logger::warning(
                'loyalty_balance_page_error',
                'Failed to load wallet balance for My Account page: ' . $wallets->get_error_message(),
                array('user_id' => $user_id, 'customer_id' => $customer_id)
            );
            echo '<p>' . esc_html__('Rewards balance is not available yet. Check back after your first completed order.', 'orangepill-wc') . '</p>';
            return;
        }

        if (empty($wallets)) {
            echo '<p>' . esc_html__('No rewards balance yet. Earn rewards on your next purchase!', 'orangepill-wc') . '</p>';
            return;
        }

        echo '<table class="woocommerce-table shop_table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Wallet', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Balance', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Available to spend', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Currency', 'orangepill-wc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($wallets as $wallet) {
            $name      = $wallet['name'] ?? $wallet['type'] ?? __('Rewards', 'orangepill-wc');
            $balance   = number_format((float) ($wallet['balance'] ?? 0), 0, ',', '.');
            $spendable = number_format((float) ($wallet['spendable_balance'] ?? $wallet['balance'] ?? 0), 0, ',', '.');
            $currency  = $wallet['currency'] ?? '';

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($balance) . '</td>';
            echo '<td>' . esc_html($spendable) . '</td>';
            echo '<td>' . esc_html($currency) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:15px;">';
        echo '<a href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT_REWARDS)) . '" class="button">';
        echo esc_html__('View Rewards History', 'orangepill-wc');
        echo '</a></p>';
    }

    // ─── Rewards History Page (Part 4) ───────────────────────────────────────

    public function render_rewards_page() {
        $user_id     = get_current_user_id();
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        echo '<h2>' . esc_html__('Rewards History', 'orangepill-wc') . '</h2>';

        if (empty($customer_id)) {
            echo '<p>' . esc_html__('Your rewards history will appear here after your first order.', 'orangepill-wc') . '</p>';
            return;
        }

        $page    = max(1, intval($_GET['rpage'] ?? 1));
        $loyalty = new OP_Loyalty();
        $result  = $loyalty->get_incentives($customer_id, $page, 20);

        if (is_wp_error($result)) {
            OP_Logger::warning(
                'rewards_history_page_error',
                'Failed to load incentives for My Account page: ' . $result->get_error_message(),
                array('user_id' => $user_id, 'customer_id' => $customer_id)
            );
            echo '<p>' . esc_html__('Rewards history is not available yet. Check back after your first completed order.', 'orangepill-wc') . '</p>';
            return;
        }

        $incentives = $result['data'] ?? (is_array($result) ? $result : array());
        $total      = $result['total'] ?? count($incentives);

        if (empty($incentives)) {
            echo '<p>' . esc_html__('No rewards yet. Earn rewards on your next purchase!', 'orangepill-wc') . '</p>';
            return;
        }

        echo '<table class="woocommerce-table shop_table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Type', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Description', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Amount', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Status', 'orangepill-wc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($incentives as $item) {
            $date        = !empty($item['created_at']) ? date_i18n(get_option('date_format'), strtotime($item['created_at'])) : '—';
            $type        = $item['type'] ?? '—';
            $description = $item['description'] ?? $item['reason'] ?? '—';
            $amount      = isset($item['amount']) ? number_format((float) $item['amount'], 0, ',', '.') : '—';
            $currency    = $item['currency'] ?? '';
            $status      = $item['status'] ?? '—';

            echo '<tr>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td>' . esc_html($description) . '</td>';
            echo '<td>' . esc_html($amount . ($currency ? ' ' . $currency : '')) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $total_pages = max(1, ceil($total / 20));
        if ($total_pages > 1) {
            echo '<div class="woocommerce-pagination" style="margin-top:15px;">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $url = add_query_arg('rpage', $i, wc_get_account_endpoint_url(self::ENDPOINT_REWARDS));
                if ($i === $page) {
                    echo '<strong style="margin:0 5px;">' . esc_html($i) . '</strong>';
                } else {
                    echo '<a href="' . esc_url($url) . '" style="margin:0 5px;">' . esc_html($i) . '</a>';
                }
            }
            echo '</div>';
        }
    }

    // ─── AJAX: wallet balance for checkout widget (Part 1) ───────────────────

    /**
     * Returns the customer's primary spendable wallet for the checkout widget.
     * Returns null when user has no Orangepill account or zero balance.
     * Errors are logged server-side; JS handles the silent-failure UX.
     */
    public function ajax_get_wallet_balance() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success(array('wallet' => null));
            return;
        }

        $loyalty = new OP_Loyalty();
        $wallet  = $loyalty->get_spendable_wallet_for_current_user();

        if (!$wallet) {
            // Log if we expected a wallet (customer_id exists) but got nothing
            $user_id     = get_current_user_id();
            $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);
            if (!empty($customer_id)) {
                OP_Logger::info(
                    'wallet_balance_empty',
                    'No spendable wallet found for customer on checkout',
                    array('user_id' => $user_id, 'customer_id' => $customer_id)
                );
            }
        }

        wp_send_json_success(array('wallet' => $wallet));
    }
}

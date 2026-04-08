<?php
/**
 * Orangepill My Account Integration
 *
 * PR-OP-WOO-INTEGRATION-CORE-1:
 * - Part 5: Loyalty balance display on My Account dashboard
 * - Part 6: Incentives/rewards history page (My Account → Rewards)
 *
 * Registers two custom WooCommerce My Account endpoints:
 * - /my-account/op-loyalty/   → Balance overview
 * - /my-account/op-rewards/   → Incentives history
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_My_Account {
    /**
     * My Account endpoint slugs
     */
    const ENDPOINT_LOYALTY = 'op-loyalty';
    const ENDPOINT_REWARDS = 'op-rewards';

    /**
     * Register all hooks
     */
    public function init() {
        // Register query vars
        add_filter('query_vars', array($this, 'add_query_vars'));

        // Register endpoints
        add_action('init', array($this, 'add_endpoints'));

        // Add menu items
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'));

        // Render endpoint content
        add_action('woocommerce_account_' . self::ENDPOINT_LOYALTY . '_endpoint', array($this, 'render_loyalty_page'));
        add_action('woocommerce_account_' . self::ENDPOINT_REWARDS . '_endpoint', array($this, 'render_rewards_page'));

        // Show compact balance widget on My Account dashboard
        add_action('woocommerce_account_dashboard', array($this, 'render_dashboard_widget'));

        // AJAX: get wallet balance for checkout widget (Part 4)
        add_action('wp_ajax_orangepill_get_wallet_balance', array($this, 'ajax_get_wallet_balance'));
    }

    /**
     * Register rewrite endpoints
     */
    public function add_endpoints() {
        add_rewrite_endpoint(self::ENDPOINT_LOYALTY, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::ENDPOINT_REWARDS, EP_ROOT | EP_PAGES);
    }

    /**
     * Register query vars
     */
    public function add_query_vars($vars) {
        $vars[] = self::ENDPOINT_LOYALTY;
        $vars[] = self::ENDPOINT_REWARDS;
        return $vars;
    }

    /**
     * Add menu items to My Account navigation
     */
    public function add_menu_items($items) {
        // Insert after 'orders', before 'logout'
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'orders') {
                $new_items[self::ENDPOINT_LOYALTY] = __('Loyalty Balance', 'orangepill-wc');
                $new_items[self::ENDPOINT_REWARDS] = __('Rewards History', 'orangepill-wc');
            }
        }
        return $new_items;
    }

    /**
     * Render compact wallet balance widget on My Account dashboard (Part 5)
     */
    public function render_dashboard_widget() {
        $loyalty = new OP_Loyalty();
        $wallet  = $loyalty->get_spendable_wallet_for_current_user();

        if (!$wallet) {
            return; // No balance — nothing to show on dashboard
        }

        $balance  = number_format((float) ($wallet['spendable_balance'] ?? $wallet['balance'] ?? 0), 2);
        $currency = $wallet['currency'] ?? '';

        ?>
        <div class="orangepill-dashboard-widget">
            <h3><?php esc_html_e('Orangepill Loyalty', 'orangepill-wc'); ?></h3>
            <p>
                <?php
                printf(
                    /* translators: 1: balance amount 2: currency code */
                    esc_html__('You have %1$s %2$s in loyalty rewards.', 'orangepill-wc'),
                    '<strong>' . esc_html($balance) . '</strong>',
                    esc_html($currency)
                );
                ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url(self::ENDPOINT_LOYALTY)); ?>">
                    <?php esc_html_e('View details', 'orangepill-wc'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render loyalty balance page (Part 5)
     */
    public function render_loyalty_page() {
        $user_id     = get_current_user_id();
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        echo '<h2>' . esc_html__('Loyalty Balance', 'orangepill-wc') . '</h2>';

        if (empty($customer_id)) {
            echo '<p>' . esc_html__('No loyalty account found. Your loyalty balance will appear here after your first order.', 'orangepill-wc') . '</p>';
            return;
        }

        $loyalty = new OP_Loyalty();
        $wallets = $loyalty->get_wallets($customer_id);

        if (is_wp_error($wallets)) {
            echo '<p>' . esc_html__('Loyalty balance is not available yet. Check back after your first completed order.', 'orangepill-wc') . '</p>';
            return;
        }

        if (empty($wallets)) {
            echo '<p>' . esc_html__('No loyalty balance yet. Earn rewards on your next purchase!', 'orangepill-wc') . '</p>';
            return;
        }

        echo '<table class="woocommerce-table shop_table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Wallet', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Balance', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Spendable', 'orangepill-wc') . '</th>';
        echo '<th>' . esc_html__('Currency', 'orangepill-wc') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($wallets as $wallet) {
            $name      = $wallet['name'] ?? $wallet['type'] ?? __('Rewards', 'orangepill-wc');
            $balance   = number_format((float) ($wallet['balance'] ?? 0), 2);
            $spendable = number_format((float) ($wallet['spendable_balance'] ?? $wallet['balance'] ?? 0), 2);
            $currency  = $wallet['currency'] ?? '';

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($balance) . '</td>';
            echo '<td>' . esc_html($spendable) . '</td>';
            echo '<td>' . esc_html($currency) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:15px;"><a href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT_REWARDS)) . '" class="button">';
        echo esc_html__('View Rewards History', 'orangepill-wc');
        echo '</a></p>';
    }

    /**
     * Render rewards / incentives history page (Part 6)
     */
    public function render_rewards_page() {
        $user_id     = get_current_user_id();
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        echo '<h2>' . esc_html__('Rewards History', 'orangepill-wc') . '</h2>';

        if (empty($customer_id)) {
            echo '<p>' . esc_html__('No loyalty account found. Your rewards history will appear here after your first order.', 'orangepill-wc') . '</p>';
            return;
        }

        $page    = max(1, intval($_GET['rpage'] ?? 1));
        $loyalty = new OP_Loyalty();
        $result  = $loyalty->get_incentives($customer_id, $page, 20);

        if (is_wp_error($result)) {
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
            $amount      = isset($item['amount']) ? number_format((float) $item['amount'], 2) : '—';
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

        // Basic pagination
        $total_pages = ceil($total / 20);
        if ($total_pages > 1) {
            echo '<div class="woocommerce-pagination" style="margin-top:15px;">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $url = add_query_arg('rpage', $i, wc_get_account_endpoint_url(self::ENDPOINT_REWARDS));
                if ($i === $page) {
                    echo '<strong style="margin: 0 5px;">' . esc_html($i) . '</strong>';
                } else {
                    echo '<a href="' . esc_url($url) . '" style="margin: 0 5px;">' . esc_html($i) . '</a>';
                }
            }
            echo '</div>';
        }
    }

    /**
     * AJAX: Return wallet balance for the checkout wallet widget (Part 4)
     *
     * Returns the primary spendable wallet or null. Used by checkout JS to
     * decide whether to show the loyalty application widget.
     */
    public function ajax_get_wallet_balance() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_success(array('wallet' => null));
        }

        $loyalty = new OP_Loyalty();
        $wallet  = $loyalty->get_spendable_wallet_for_current_user();

        wp_send_json_success(array('wallet' => $wallet));
    }
}

<?php
/**
 * Orangepill Loyalty
 *
 * PR-OP-WOO-INTEGRATION-CORE-1:
 * - Part 4: Wallet/loyalty application during checkout
 * - Part 5: Loyalty balance display (My Account)
 * - Part 6: Incentives history (My Account → Rewards)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Loyalty {
    /**
     * Transient TTL for wallet balance cache (5 minutes)
     */
    const WALLET_CACHE_TTL = 300;

    /**
     * Get wallet balances for a customer.
     *
     * Cached per customer_id (5-minute TTL) to avoid hammering the API on
     * every checkout page load.
     *
     * @param string $customer_id Orangepill customer ID
     * @param bool   $force       Skip cache and fetch fresh data
     * @return array|WP_Error Array of wallet objects or error
     */
    public function get_wallets($customer_id, $force = false) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', __('Customer ID is required', 'orangepill-wc'));
        }

        $cache_key = 'op_wallets_' . md5($customer_id);

        if (!$force) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $api    = new OP_API_Client();
        $result = $api->get_customer_wallets($customer_id);

        if (is_wp_error($result)) {
            OP_Logger::warning(
                'loyalty_wallet_fetch_failed',
                'Failed to fetch wallet balances: ' . $result->get_error_message(),
                array('customer_id' => $customer_id)
            );
            return $result;
        }

        // API may return { data: [...] } or a bare array
        $wallets = $result['data'] ?? (is_array($result) ? $result : array());

        set_transient($cache_key, $wallets, self::WALLET_CACHE_TTL);

        return $wallets;
    }

    /**
     * Get spendable wallet balance for the current WooCommerce user.
     *
     * Returns the wallet with the highest spendable balance, or null
     * if the user has no Orangepill account or no balance.
     *
     * @return array|null Wallet object {id, balance, currency, ...} or null
     */
    public function get_spendable_wallet_for_current_user() {
        if (!is_user_logged_in()) {
            return null;
        }

        $user_id     = get_current_user_id();
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        if (empty($customer_id)) {
            return null;
        }

        $wallets = $this->get_wallets($customer_id);

        if (is_wp_error($wallets) || empty($wallets)) {
            return null;
        }

        // Find wallet with highest spendable_balance
        $best_wallet = null;
        foreach ($wallets as $wallet) {
            $spendable = (float) ($wallet['spendable_balance'] ?? $wallet['balance'] ?? 0);
            if ($spendable <= 0) {
                continue;
            }
            if ($best_wallet === null || $spendable > (float) ($best_wallet['spendable_balance'] ?? $best_wallet['balance'] ?? 0)) {
                $best_wallet = $wallet;
            }
        }

        return $best_wallet;
    }

    /**
     * Apply wallet balance to a checkout session (Part 4).
     *
     * Called from process_payment() after session creation if the customer
     * opts in via the checkout wallet widget.
     *
     * @param string $session_id    Checkout session ID
     * @param string $amount        Amount to apply (from hidden form field)
     * @param string $wallet_id     Wallet ID (optional; uses primary wallet if omitted)
     * @return array|WP_Error Result or error
     */
    public function apply_wallet_to_session($session_id, $amount, $wallet_id = '') {
        if (empty($session_id) || empty($amount)) {
            return new WP_Error('missing_params', 'Session ID and amount are required');
        }

        $api = new OP_API_Client();

        // Validate wallet_id from hidden field against live wallet list.
        // Never trust it blindly — it can be stale (cached result, multi-wallet
        // future, or tampered form field). Fall back to API fetch if invalid.
        if (!empty($wallet_id)) {
            $wallet_id = $this->validate_wallet_id($wallet_id);
        }

        if (empty($wallet_id)) {
            // Fallback: fetch primary spendable wallet fresh from API
            $wallet    = $this->get_spendable_wallet_for_current_user();
            $wallet_id = $wallet['id'] ?? '';
        }

        if (empty($wallet_id)) {
            return new WP_Error('no_wallet', 'No wallet found to apply');
        }

        return $api->apply_wallet_to_session($session_id, $wallet_id, $amount);
    }

    /**
     * Validate that a wallet_id belongs to the current user's wallets.
     *
     * Compares against the cached (or freshly fetched) wallet list.
     * Returns the wallet_id if valid, empty string if not found.
     *
     * @param string $wallet_id Wallet ID from hidden form field
     * @return string Validated wallet_id or ''
     */
    private function validate_wallet_id($wallet_id) {
        $wallet  = $this->get_spendable_wallet_for_current_user();
        if ($wallet && ($wallet['id'] ?? '') === $wallet_id) {
            return $wallet_id;
        }
        // Not found in wallet list — could be stale; force API fallback
        OP_Logger::info(
            'wallet_id_stale',
            'wallet_id from form not found in customer wallets — falling back to API fetch',
            array('wallet_id' => $wallet_id)
        );
        return '';
    }

    /**
     * Get incentives history for a customer (Part 6).
     *
     * @param string $customer_id Orangepill customer ID
     * @param int    $page        Page number (1-based)
     * @param int    $per_page    Items per page
     * @return array|WP_Error {data: [...], total: N, ...} or error
     */
    public function get_incentives($customer_id, $page = 1, $per_page = 20) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', __('Customer ID is required', 'orangepill-wc'));
        }

        $api    = new OP_API_Client();
        $result = $api->get_customer_incentives($customer_id, array(
            'page'     => $page,
            'per_page' => $per_page,
        ));

        if (is_wp_error($result)) {
            OP_Logger::warning(
                'loyalty_incentives_fetch_failed',
                'Failed to fetch incentives: ' . $result->get_error_message(),
                array('customer_id' => $customer_id)
            );
        }

        return $result;
    }

    /**
     * Invalidate wallet cache for a customer (call after wallet is applied).
     *
     * @param string $customer_id Orangepill customer ID
     */
    public function invalidate_wallet_cache($customer_id) {
        delete_transient('op_wallets_' . md5($customer_id));
    }
}

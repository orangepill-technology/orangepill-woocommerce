<?php
/**
 * Orangepill Customer Sync
 *
 * PR-OP-WOO-INTEGRATION-CORE-1 Part 1:
 * Customer mapping layer — woo_customer_id ↔ orangepill_customer_id
 *
 * Deduplication via external_id = "woo:{user_id}".
 * Customer ID cached in user meta (_orangepill_customer_id) to avoid
 * redundant API calls on every checkout.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Customer_Sync {
    /**
     * Get or create an Orangepill customer for a WordPress user.
     *
     * Returns the Orangepill customer ID. For logged-in users, checks
     * user meta cache first. On cache miss, creates the customer via
     * POST /v4/customers with external_id for deduplication.
     *
     * @param int        $user_id WordPress user ID
     * @param WC_Order   $order   WooCommerce order (used for billing data)
     * @return string|WP_Error Orangepill customer ID or error
     */
    public function get_or_create($user_id, $order = null) {
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', __('Invalid user ID', 'orangepill-wc'));
        }

        // Cache hit — return immediately
        $cached_customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);
        if (!empty($cached_customer_id)) {
            OP_Logger::info(
                'customer_cache_hit',
                'Using cached Orangepill customer ID',
                array(
                    'user_id'     => $user_id,
                    'customer_id' => $cached_customer_id,
                )
            );
            return $cached_customer_id;
        }

        // Build customer data for API
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', __('WordPress user not found', 'orangepill-wc'));
        }

        // Prefer billing data from order (most up-to-date), fallback to user profile
        $first_name = $order ? $order->get_billing_first_name() : $user->first_name;
        $last_name  = $order ? $order->get_billing_last_name()  : $user->last_name;
        $phone      = $order ? $order->get_billing_phone()      : get_user_meta($user_id, 'billing_phone', true);

        $customer_data = array(
            'external_id'  => 'woo:' . $user_id,   // Deduplication key
            'email'        => $user->user_email,
            'display_name' => trim($first_name . ' ' . $last_name) ?: $user->display_name,
        );

        if (!empty($phone)) {
            $customer_data['phone'] = $phone;
        }

        $api      = new OP_API_Client();
        $endpoint = '/v4/customers';
        $settings = $api->get_settings();

        $event_id = OP_Sync_Journal::record_outbound_pending(
            'customer.create',
            null,
            $customer_data,
            $endpoint,
            $settings['base_url']
        );
        $event = OP_Sync_Journal::get_event($event_id);

        $result = $api->request('POST', $endpoint, $customer_data, array(
            'Idempotency-Key' => $event->idempotency_key,
        ));

        if (is_wp_error($result)) {
            // On "already exists" conflict, fetch the customer by external_id instead
            $error_data = $result->get_error_data();
            $status     = $error_data['status_code'] ?? 0;
            $message    = $result->get_error_message();

            if ($status === 400 && stripos($message, 'already exists') !== false) {
                OP_Sync_Journal::mark_sent($event_id, array('note' => 'conflict_fetch_fallback'));
                return $this->fetch_by_external_id($user_id, $api);
            }

            OP_Sync_Journal::mark_failed($event_id, $message);
            OP_Logger::error(
                'customer_creation_failed',
                'Failed to create Orangepill customer: ' . $message,
                array(
                    'user_id'         => $user_id,
                    'email'           => $user->user_email,
                    'event_id'        => $event_id,
                    'idempotency_key' => $event->idempotency_key,
                )
            );
            return $result;
        }

        $customer_id = $result['id'] ?? null;

        if (empty($customer_id)) {
            OP_Sync_Journal::mark_failed($event_id, 'API response missing customer id');
            return new WP_Error('invalid_response', __('Orangepill customer API returned no ID', 'orangepill-wc'));
        }

        OP_Sync_Journal::mark_sent($event_id, $result);

        // Cache in user meta for subsequent checkouts
        update_user_meta($user_id, '_orangepill_customer_id', $customer_id);

        OP_Logger::info(
            'customer_created',
            'Orangepill customer created',
            array(
                'user_id'         => $user_id,
                'customer_id'     => $customer_id,
                'external_id'     => 'woo:' . $user_id,
                'event_id'        => $event_id,
                'idempotency_key' => $event->idempotency_key,
            )
        );

        return $customer_id;
    }

    /**
     * Fetch existing customer by external_id (conflict recovery).
     *
     * Called when POST /v4/customers returns 400 "already exists".
     * GET /v4/customers?external_id=woo:{user_id} → cache + return ID.
     *
     * @param int           $user_id WordPress user ID
     * @param OP_API_Client $api     API client instance
     * @return string|WP_Error Customer ID or error
     */
    private function fetch_by_external_id($user_id, $api) {
        $external_id = 'woo:' . $user_id;
        $result      = $api->request('GET', '/v4/customers?external_id=' . rawurlencode($external_id));

        if (is_wp_error($result)) {
            OP_Logger::error(
                'customer_fetch_failed',
                'Failed to fetch customer by external_id: ' . $result->get_error_message(),
                array('user_id' => $user_id, 'external_id' => $external_id)
            );
            return $result;
        }

        // Response shape: { data: { customers: [...] } }
        $customers   = $result['data']['customers'] ?? $result['customers'] ?? $result['data'] ?? array();
        $customer_id = null;

        foreach ((array) $customers as $customer) {
            if (isset($customer['external_id']) && $customer['external_id'] === $external_id) {
                $customer_id = $customer['id'];
                break;
            }
        }

        if (empty($customer_id)) {
            return new WP_Error('customer_not_found', __('Customer not found by external_id after conflict', 'orangepill-wc'));
        }

        update_user_meta($user_id, '_orangepill_customer_id', $customer_id);

        OP_Logger::info(
            'customer_fetched_on_conflict',
            'Resolved existing customer via external_id lookup',
            array('user_id' => $user_id, 'customer_id' => $customer_id)
        );

        return $customer_id;
    }

    /**
     * Get cached Orangepill customer ID for a WordPress user (no API call).
     *
     * @param int $user_id WordPress user ID
     * @return string|null Customer ID or null if not yet synced
     */
    public function get_cached($user_id) {
        $id = get_user_meta($user_id, '_orangepill_customer_id', true);
        return !empty($id) ? $id : null;
    }

    /**
     * Clear cached customer ID (forces re-sync on next checkout).
     *
     * @param int $user_id WordPress user ID
     */
    public function clear_cache($user_id) {
        delete_user_meta($user_id, '_orangepill_customer_id');
    }

    /**
     * Alias kept for backward compatibility with existing calls.
     *
     * @deprecated Use get_or_create() instead
     */
    public function sync_customer($user_id) {
        return $this->get_or_create($user_id);
    }

    /**
     * Alias kept for backward compatibility.
     *
     * @deprecated Use get_cached() instead
     */
    public function get_cached_customer_id($user_id) {
        return $this->get_cached($user_id);
    }
}

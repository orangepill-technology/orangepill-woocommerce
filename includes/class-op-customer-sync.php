<?php
/**
 * Orangepill Customer Sync
 *
 * Handles customer synchronization with deduplication
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Customer_Sync {
    /**
     * Sync customer to Orangepill
     *
     * Gets existing customer_id from cache or creates new customer
     *
     * @param int $user_id WordPress user ID
     * @return string|WP_Error Customer ID or error
     */
    public function sync_customer($user_id) {
        if ($user_id <= 0) {
            return new WP_Error('invalid_user', __('Invalid user ID', 'orangepill-wc'));
        }

        // Check cache first
        $cached_customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);

        if (!empty($cached_customer_id)) {
            OP_Logger::info(
                'customer_cache_hit',
                'Using cached customer ID',
                array(
                    'user_id' => $user_id,
                    'customer_id' => $cached_customer_id,
                )
            );
            return $cached_customer_id;
        }

        // Create new customer
        $user = get_userdata($user_id);

        if (!$user) {
            return new WP_Error('user_not_found', __('User not found', 'orangepill-wc'));
        }

        $api = new OP_API_Client();

        // Build customer data with external_id for deduplication
        $customer_data = array(
            'external_id' => 'woo:' . $user_id,
            'email' => $user->user_email,
            'name' => trim($user->first_name . ' ' . $user->last_name),
        );

        // Add phone if available
        $phone = get_user_meta($user_id, 'billing_phone', true);
        if (!empty($phone)) {
            $customer_data['phone'] = $phone;
        }

        // PR-WC-3b: Record outbound event before API send
        // Store base_url + endpoint separately for environment safety
        $endpoint = '/v4/admin/customers';
        $api_settings = $api->get_settings();
        $base_url = $api_settings['base_url'];

        $event_id = OP_Sync_Journal::record_outbound_pending('customer.create', null, $customer_data, $endpoint, $base_url);
        $event = OP_Sync_Journal::get_event($event_id);

        // Create customer via API with idempotency key (standard header)
        $result = $api->request('POST', $endpoint, $customer_data, array(
            'Idempotency-Key' => $event->idempotency_key,
        ));

        if (is_wp_error($result)) {
            // PR-WC-3b: Mark event as failed
            OP_Sync_Journal::mark_failed($event_id, $result->get_error_message());

            OP_Logger::error(
                'customer_creation_failed',
                'Failed to create customer: ' . $result->get_error_message(),
                array(
                    'user_id' => $user_id,
                    'email' => $user->user_email,
                    'event_id' => $event_id,
                    'idempotency_key' => $event->idempotency_key, // Correlation ID
                )
            );
            return $result;
        }

        $customer_id = $result['id'] ?? null;

        if (empty($customer_id)) {
            // PR-WC-3b: Mark event as failed
            OP_Sync_Journal::mark_failed($event_id, 'Invalid customer response from API');

            return new WP_Error('invalid_response', __('Invalid customer response from API', 'orangepill-wc'));
        }

        // PR-WC-3b: Mark event as sent
        OP_Sync_Journal::mark_sent($event_id, $result);

        // Cache customer_id in user meta
        update_user_meta($user_id, '_orangepill_customer_id', $customer_id);

        OP_Logger::info(
            'customer_created',
            'Customer created successfully',
            array(
                'user_id' => $user_id,
                'customer_id' => $customer_id,
                'external_id' => 'woo:' . $user_id,
                'event_id' => $event_id,
                'idempotency_key' => $event->idempotency_key, // Correlation ID
            )
        );

        return $customer_id;
    }

    /**
     * Clear cached customer ID
     *
     * @param int $user_id WordPress user ID
     * @return bool Success
     */
    public function clear_cache($user_id) {
        return delete_user_meta($user_id, '_orangepill_customer_id');
    }

    /**
     * Get cached customer ID
     *
     * @param int $user_id WordPress user ID
     * @return string|null Customer ID or null
     */
    public function get_cached_customer_id($user_id) {
        $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true);
        return !empty($customer_id) ? $customer_id : null;
    }
}

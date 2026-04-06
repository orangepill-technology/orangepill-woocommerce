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

        // Create customer via API
        $result = $api->create_customer($customer_data);

        if (is_wp_error($result)) {
            OP_Logger::error(
                'customer_creation_failed',
                'Failed to create customer: ' . $result->get_error_message(),
                array(
                    'user_id' => $user_id,
                    'email' => $user->user_email,
                )
            );
            return $result;
        }

        $customer_id = $result['id'] ?? null;

        if (empty($customer_id)) {
            return new WP_Error('invalid_response', __('Invalid customer response from API', 'orangepill-wc'));
        }

        // Cache customer_id in user meta
        update_user_meta($user_id, '_orangepill_customer_id', $customer_id);

        OP_Logger::info(
            'customer_created',
            'Customer created successfully',
            array(
                'user_id' => $user_id,
                'customer_id' => $customer_id,
                'external_id' => 'woo:' . $user_id,
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

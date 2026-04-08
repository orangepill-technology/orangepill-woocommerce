<?php
/**
 * Orangepill Integration Webhook Manager
 *
 * PR-WC-INTEGRATION-WEBHOOKS-1:
 * Registers and maintains a single integration-level webhook with Orangepill.
 * This replaces per-session callback registration as the primary event delivery path.
 *
 * Architecture:
 *   Integration-level webhooks = PRIMARY path (this class)
 *   Session-level callback      = TEMPORARY compatibility only
 *
 * Registration happens once on settings save (or manual retry).
 * Orangepill emits checkout.session.* events for all sessions under the integration.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Integration_Webhooks {
    /**
     * Option key where registration status is cached.
     */
    const STATUS_OPTION = 'orangepill_wc_webhook_status';

    /**
     * Events Woo wants to receive from Orangepill.
     */
    const EVENTS = array(
        'checkout.session.completed',
        'checkout.session.failed',
        'checkout.session.expired',
    );

    /**
     * Register or update the integration-level webhook.
     *
     * Logic:
     *  1. List existing webhooks for this integration
     *  2. Find one whose URL matches the canonical Woo webhook URL
     *  3. If found → update events/secret if needed
     *  4. If not found → create new webhook
     *
     * Idempotent: safe to call on every settings save.
     *
     * @return array{success: bool, message: string, webhook_id: string|null}
     */
    public static function register_or_update() {
        $settings       = get_option('woocommerce_orangepill_settings', array());
        $integration_id = $settings['integration_id'] ?? '';
        $webhook_secret = $settings['webhook_secret'] ?? '';

        if (empty($integration_id)) {
            $result = array(
                'success'    => false,
                'message'    => 'Integration ID not configured',
                'webhook_id' => null,
            );
            self::save_status($result);
            return $result;
        }

        $webhook_url = orangepill_wc_get_webhook_url();
        $api         = new OP_API_Client();

        OP_Logger::info(
            'integration_webhook_register_started',
            'Starting integration webhook registration',
            array('integration_id' => $integration_id, 'url' => $webhook_url)
        );

        // Step 1: List existing webhooks
        $existing = $api->list_integration_webhooks($integration_id);

        if (is_wp_error($existing)) {
            $result = array(
                'success'    => false,
                'message'    => 'Failed to list webhooks: ' . $existing->get_error_message(),
                'webhook_id' => null,
            );
            self::save_status($result);
            OP_Logger::error(
                'integration_webhook_register_failed',
                $result['message'],
                array('integration_id' => $integration_id)
            );
            return $result;
        }

        // Step 2: Find matching webhook by URL
        $webhooks   = $existing['data'] ?? (is_array($existing) ? $existing : array());
        $match_id   = null;

        foreach ($webhooks as $hook) {
            if (($hook['url'] ?? '') === $webhook_url) {
                $match_id = $hook['id'] ?? null;
                break;
            }
        }

        $payload = array(
            'url'    => $webhook_url,
            'events' => self::EVENTS,
        );
        if (!empty($webhook_secret)) {
            $payload['secret'] = $webhook_secret;
        }

        if ($match_id) {
            // Step 3: Update existing webhook
            $result_data = $api->update_integration_webhook($integration_id, $match_id, $payload);

            if (is_wp_error($result_data)) {
                $result = array(
                    'success'    => false,
                    'message'    => 'Failed to update webhook: ' . $result_data->get_error_message(),
                    'webhook_id' => $match_id,
                );
                self::save_status($result);
                OP_Logger::error(
                    'integration_webhook_register_failed',
                    $result['message'],
                    array('integration_id' => $integration_id, 'webhook_id' => $match_id)
                );
                return $result;
            }

            $result = array(
                'success'    => true,
                'message'    => 'Webhook updated successfully',
                'webhook_id' => $match_id,
            );
            self::save_status($result);
            OP_Logger::info(
                'integration_webhook_updated',
                'Integration webhook updated',
                array('integration_id' => $integration_id, 'webhook_id' => $match_id, 'url' => $webhook_url)
            );
            return $result;
        }

        // Step 4: Create new webhook
        $result_data = $api->register_integration_webhook($integration_id, $payload);

        if (is_wp_error($result_data)) {
            $result = array(
                'success'    => false,
                'message'    => 'Failed to register webhook: ' . $result_data->get_error_message(),
                'webhook_id' => null,
            );
            self::save_status($result);
            OP_Logger::error(
                'integration_webhook_register_failed',
                $result['message'],
                array('integration_id' => $integration_id, 'url' => $webhook_url)
            );
            return $result;
        }

        $webhook_id = $result_data['id'] ?? null;
        $result     = array(
            'success'    => true,
            'message'    => 'Webhook registered successfully',
            'webhook_id' => $webhook_id,
        );
        self::save_status($result);
        OP_Logger::info(
            'integration_webhook_registered',
            'Integration webhook registered',
            array('integration_id' => $integration_id, 'webhook_id' => $webhook_id, 'url' => $webhook_url)
        );
        return $result;
    }

    /**
     * Get the last known registration status.
     *
     * @return array|null {success, message, webhook_id, timestamp} or null if never attempted
     */
    public static function get_status() {
        return get_option(self::STATUS_OPTION, null);
    }

    /**
     * Clear the cached registration status.
     */
    public static function clear_status() {
        delete_option(self::STATUS_OPTION);
    }

    /**
     * Save registration status to options.
     *
     * @param array $result {success, message, webhook_id}
     */
    private static function save_status($result) {
        $result['timestamp'] = current_time('mysql');
        update_option(self::STATUS_OPTION, $result, false);
    }
}

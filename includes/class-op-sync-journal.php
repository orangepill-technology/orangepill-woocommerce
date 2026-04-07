<?php
/**
 * Orangepill Sync Journal
 *
 * Durable event persistence for replay + debug visibility.
 * Plugin is source of truth for outbound events (Woo → Orangepill).
 * Inbound events (Orangepill → Woo) are mirrored for debug only.
 *
 * PR-WC-3b: Durable Sync + Replay + Debug
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Sync_Journal {
    /**
     * Record outbound event (pending state) before API send
     *
     * Plugin is source of truth for outbound events.
     * Generates idempotency_key: woo:{order_id}:{event_type}:{timestamp}
     *
     * @param string $event_type Event type (e.g., 'customer.create', 'checkout.session.create')
     * @param int|null $order_id WooCommerce order ID
     * @param array $payload Request payload
     * @param string|null $idempotency_key Optional pre-generated idempotency key
     * @return int Event ID
     */
    public static function record_outbound_pending($event_type, $order_id, $payload, $idempotency_key = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        // Generate idempotency key if not provided (plugin is source of truth)
        if (!$idempotency_key) {
            $idempotency_key = sprintf(
                'woo:%s:%s:%d',
                $order_id ?? 'none',
                $event_type,
                time()
            );
        }

        // Sanitize payload - NEVER store API keys, secrets, or auth headers
        $safe_payload = self::sanitize_payload($payload);

        $wpdb->insert($table, array(
            'direction' => 'woo_to_op',
            'event_type' => $event_type,
            'order_id' => $order_id,
            'payload_json' => wp_json_encode($safe_payload),
            'status' => 'pending',
            'idempotency_key' => $idempotency_key,
            'attempt_count' => 0,
        ));

        return $wpdb->insert_id;
    }

    /**
     * Mark outbound event as sent (success)
     *
     * @param int $event_id Event ID
     * @param array|null $response API response
     */
    public static function mark_sent($event_id, $response = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $wpdb->update($table, array(
            'status' => 'sent',
            'response_json' => $response ? wp_json_encode($response) : null,
            'last_attempt_at' => current_time('mysql'),
        ), array('id' => $event_id));
    }

    /**
     * Mark outbound event as failed
     *
     * @param int $event_id Event ID
     * @param string $error Error message
     */
    public static function mark_failed($event_id, $error) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET
                status = 'failed',
                last_error = %s,
                attempt_count = attempt_count + 1,
                last_attempt_at = %s
            WHERE id = %d",
            $error,
            current_time('mysql'),
            $event_id
        ));
    }

    /**
     * Get event by ID
     *
     * @param int $event_id Event ID
     * @return object|null Event row
     */
    public static function get_event($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $event_id
        ));
    }

    /**
     * Get last failed outbound event (optionally filtered by order_id)
     *
     * @param int|null $order_id Optional order ID filter
     * @return object|null Event row
     */
    public static function get_last_failed($order_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $where = "direction = 'woo_to_op' AND status = 'failed'";
        if ($order_id) {
            $where .= $wpdb->prepare(" AND order_id = %d", $order_id);
        }

        return $wpdb->get_row(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT 1"
        );
    }

    /**
     * Get last event for order (filtered by direction)
     *
     * @param int $order_id Order ID
     * @param string $direction Direction ('woo_to_op' or 'op_to_woo')
     * @return object|null Event row
     */
    public static function get_last_event_for_order($order_id, $direction) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d AND direction = %s ORDER BY created_at DESC LIMIT 1",
            $order_id,
            $direction
        ));
    }

    /**
     * Get all failed outbound events
     *
     * @param int $limit Max results
     * @return array Event rows
     */
    public static function get_failed_events($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE direction = 'woo_to_op' AND status = 'failed' ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Replay failed outbound event
     *
     * Re-sends EXACT stored payload (no mutation).
     * Only failed outbound events can be replayed.
     *
     * @param int $event_id Event ID
     * @return array Result ['success' => bool, 'result' => string, 'error' => string|null]
     */
    public static function replay($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $event_id
        ));

        if (!$event) {
            return array('success' => false, 'result' => 'not_found', 'error' => 'Event not found');
        }

        if ($event->direction !== 'woo_to_op') {
            return array('success' => false, 'result' => 'not_replayable', 'error' => 'Only outbound events can be replayed');
        }

        if ($event->status !== 'failed') {
            return array('success' => false, 'result' => 'not_replayable', 'error' => 'Only failed events can be replayed');
        }

        $payload = json_decode($event->payload_json, true);
        $api = new OP_API_Client();

        try {
            // Determine endpoint based on event type
            $endpoint = self::get_endpoint_for_event($event->event_type);

            // Re-send EXACT stored payload with idempotency key
            $response = $api->request('POST', $endpoint, $payload, array(
                'X-Idempotency-Key' => $event->idempotency_key,
            ));

            self::mark_sent($event_id, $response);

            OP_Logger::info(
                $event->event_type . '.replay',
                'Replay successful for event #' . $event_id,
                array(
                    'event_id' => $event_id,
                    'order_id' => $event->order_id,
                )
            );

            return array('success' => true, 'result' => 'sent');
        } catch (Exception $e) {
            self::mark_failed($event_id, $e->getMessage());

            OP_Logger::error(
                $event->event_type . '.replay',
                'Replay failed for event #' . $event_id . ': ' . $e->getMessage(),
                array(
                    'event_id' => $event_id,
                    'order_id' => $event->order_id,
                )
            );

            return array('success' => false, 'result' => 'failed', 'error' => $e->getMessage());
        }
    }

    /**
     * Record inbound webhook receipt (debug visibility only, not replayable)
     *
     * Orangepill is source of truth for inbound events.
     * Plugin mirrors for debug visibility.
     *
     * @param string $event_type Event type (e.g., 'payment.succeeded')
     * @param int|null $order_id WooCommerce order ID
     * @param array $payload Webhook payload
     * @return int Event ID
     */
    public static function record_inbound_received($event_type, $order_id, $payload) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        // Sanitize - never store webhook secrets
        $safe = self::sanitize_payload($payload);

        $wpdb->insert($table, array(
            'direction' => 'op_to_woo',
            'event_type' => $event_type,
            'order_id' => $order_id,
            'payload_json' => wp_json_encode($safe),
            'status' => 'received',
        ));

        return $wpdb->insert_id;
    }

    /**
     * Mark inbound event as processed
     *
     * @param int $event_id Event ID
     */
    public static function mark_inbound_processed($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $wpdb->update($table, array(
            'status' => 'processed',
        ), array('id' => $event_id));
    }

    /**
     * Mark inbound event as failed
     *
     * @param int $event_id Event ID
     * @param string $error Error message
     */
    public static function mark_inbound_failed($event_id, $error) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $wpdb->update($table, array(
            'status' => 'failed',
            'last_error' => $error,
        ), array('id' => $event_id));
    }

    /**
     * Mark event as dismissed (soft-close, still in table)
     *
     * @param int $event_id Event ID
     */
    public static function dismiss($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'orangepill_sync_events';

        $wpdb->update($table, array(
            'status' => 'dismissed',
        ), array('id' => $event_id));
    }

    /**
     * Sanitize payload - remove sensitive data
     *
     * CRITICAL: Never store API keys, secrets, auth headers
     *
     * @param array $payload Payload to sanitize
     * @return array Sanitized payload
     */
    private static function sanitize_payload($payload) {
        if (!is_array($payload)) {
            return $payload;
        }

        $sensitive_keys = array(
            'api_key',
            'apiKey',
            'api_secret',
            'apiSecret',
            'secret',
            'webhook_secret',
            'webhookSecret',
            'password',
            'token',
            'authorization',
            'Authorization',
            'Bearer',
        );

        $safe = $payload;

        foreach ($sensitive_keys as $key) {
            unset($safe[$key]);
        }

        // Recursively sanitize nested arrays
        foreach ($safe as $k => $v) {
            if (is_array($v)) {
                $safe[$k] = self::sanitize_payload($v);
            }
        }

        return $safe;
    }

    /**
     * Get API endpoint for event type
     *
     * @param string $event_type Event type
     * @return string Endpoint path
     */
    private static function get_endpoint_for_event($event_type) {
        $gateway = new OP_Payment_Gateway();
        $integration_id = $gateway->get_option('integration_id');

        switch ($event_type) {
            case 'customer.create':
                return '/v4/admin/customers';
            case 'checkout.session.create':
                return '/v4/payments/integrations/' . $integration_id . '/sessions';
            case 'order.updated':
                // Note: Currently order.updated is informational logging only
                // No dedicated API endpoint exists
                return '/v4/commerce/integrations/' . $integration_id . '/orders';
            default:
                return '/v4/unknown';
        }
    }
}

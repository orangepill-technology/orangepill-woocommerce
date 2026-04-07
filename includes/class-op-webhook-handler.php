<?php
/**
 * Orangepill Webhook Handler
 *
 * Handles incoming webhook requests from Orangepill with:
 * - HMAC-SHA256 signature verification (timing-safe)
 * - Explicit idempotency guard (event ID tracking)
 *
 * Webhook Signature Spec:
 * - Header: X-Orangepill-Signature
 * - Algorithm: HMAC-SHA256
 * - Payload: Raw request body
 * - Encoding: Hex
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Webhook_Handler {
    /**
     * Handle incoming webhook request
     */
    public function handle() {
        // Get raw POST body
        $raw_body = file_get_contents('php://input');

        if (empty($raw_body)) {
            $this->send_response(400, array('error' => 'Empty request body'));
            return;
        }

        // Calculate payload hash for idempotency verification
        $payload_hash = hash('sha256', $raw_body);

        // Get signature from header
        $signature = isset($_SERVER['HTTP_X_ORANGEPILL_SIGNATURE'])
            ? $_SERVER['HTTP_X_ORANGEPILL_SIGNATURE']
            : null;

        if (empty($signature)) {
            OP_Logger::warning(
                'webhook_missing_signature',
                'Webhook request missing signature header'
            );
            $this->send_response(401, array('error' => 'Missing signature'));
            return;
        }

        // Verify signature
        if (!$this->verify_signature($raw_body, $signature)) {
            OP_Logger::error(
                'webhook_invalid_signature',
                'Webhook signature verification failed'
            );
            $this->send_response(401, array('error' => 'Invalid signature'));
            return;
        }

        // Parse webhook payload
        $payload = json_decode($raw_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            OP_Logger::error(
                'webhook_invalid_json',
                'Invalid JSON in webhook payload'
            );
            $this->send_response(400, array('error' => 'Invalid JSON'));
            return;
        }

        // Process webhook event (with payload hash for idempotency)
        $this->process_event($payload, $payload_hash);

        // Return 200 OK
        $this->send_response(200, array('success' => true));
    }

    /**
     * Verify webhook signature
     *
     * Spec:
     * - Header: X-Orangepill-Signature
     * - Algorithm: HMAC-SHA256
     * - Payload: Raw request body
     * - Encoding: Hex
     *
     * @param string $payload Raw request body
     * @param string $signature Signature from header
     * @return bool Valid signature
     */
    private function verify_signature($payload, $signature) {
        $gateway = new OP_Payment_Gateway();
        $webhook_secret = $gateway->get_option('webhook_secret');

        if (empty($webhook_secret)) {
            OP_Logger::error(
                'webhook_no_secret',
                'Webhook secret not configured'
            );
            return false;
        }

        // Remove common prefixes (e.g., "sha256=", "v1=")
        $clean_signature = preg_replace('/^[a-zA-Z0-9]+=/i', '', $signature);

        // Calculate expected signature using HMAC-SHA256 (hex encoding)
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

        // Timing-safe comparison (protects against timing attacks)
        if (strlen($clean_signature) !== strlen($expected_signature)) {
            return false;
        }

        return hash_equals($expected_signature, $clean_signature);
    }

    /**
     * Process webhook event
     *
     * @param array $payload Webhook payload
     * @param string $payload_hash SHA256 hash of raw payload
     */
    private function process_event($payload, $payload_hash) {
        $event_type = $payload['type'] ?? null;
        $event_data = $payload['data'] ?? array();

        if (empty($event_type)) {
            OP_Logger::warning(
                'webhook_missing_type',
                'Webhook payload missing event type'
            );
            return;
        }

        switch ($event_type) {
            case 'payment.succeeded':
                $this->handle_payment_succeeded($event_data, $payload_hash);
                break;

            case 'payment.failed':
                $this->handle_payment_failed($event_data, $payload_hash);
                break;

            default:
                OP_Logger::info(
                    'webhook_unhandled_event',
                    'Received unhandled webhook event: ' . $event_type,
                    array('event_type' => $event_type)
                );
                break;
        }
    }

    /**
     * Handle payment.succeeded event
     *
     * @param array $data Event data
     * @param string $payload_hash SHA256 hash of raw payload
     */
    private function handle_payment_succeeded($data, $payload_hash) {
        $session_id = $data['session_id'] ?? null;
        $payment_id = $data['id'] ?? null;
        $event_id = $data['event_id'] ?? null;

        if (empty($session_id)) {
            OP_Logger::warning(
                'webhook_missing_session_id',
                'payment.succeeded event missing session_id'
            );
            return;
        }

        // Find order by session_id
        $order = $this->find_order_by_session_id($session_id);

        if (!$order) {
            OP_Logger::warning(
                'webhook_order_not_found',
                'Order not found for session_id: ' . $session_id,
                array('session_id' => $session_id)
            );
            return;
        }

        // PR-WC-3b: Record inbound webhook receipt (debug visibility only)
        OP_Sync_Journal::record_inbound_received('payment.succeeded', $order->get_id(), $data);

        // CRITICAL: Idempotency guard - check if event already processed
        if (!empty($event_id)) {
            $idempotency_check = $this->is_event_processed($order, $event_id, $payload_hash);

            if ($idempotency_check === true) {
                // Exact duplicate (same event_id, same payload hash)
                OP_Logger::info(
                    'webhook_duplicate_event',
                    'Duplicate payment.succeeded event ignored (event_id: ' . $event_id . ')',
                    array(
                        'order_id' => $order->get_id(),
                        'event_id' => $event_id,
                        'payment_id' => $payment_id,
                    )
                );
                return;
            } elseif ($idempotency_check === 'hash_mismatch') {
                // ANOMALY: Same event_id but different payload
                OP_Logger::error(
                    'webhook_event_id_reuse',
                    'Duplicate event_id with different payload detected (event_id: ' . $event_id . ')',
                    array(
                        'order_id' => $order->get_id(),
                        'event_id' => $event_id,
                        'payment_id' => $payment_id,
                        'anomaly' => 'event_id_reuse_with_different_payload',
                    )
                );
                // Still return without processing (financial-grade paranoia)
                return;
            }
        }

        // Update order status to processing
        $order->update_status('processing', __('Payment confirmed by Orangepill', 'orangepill-wc'));

        // Store payment_id in order meta
        if (!empty($payment_id)) {
            $order->update_meta_data('_orangepill_payment_id', $payment_id);
        }

        $order->update_meta_data('_orangepill_payment_status', 'succeeded');
        $order->update_meta_data('_orangepill_payment_confirmed_at', current_time('mysql'));

        // Mark event as processed (idempotency with payload hash)
        if (!empty($event_id)) {
            $this->mark_event_processed($order, $event_id, $payload_hash);
        }

        $order->save();

        OP_Logger::info(
            'payment_succeeded',
            'Payment succeeded for order #' . $order->get_id(),
            array(
                'order_id' => $order->get_id(),
                'session_id' => $session_id,
                'payment_id' => $payment_id,
                'event_id' => $event_id,
            )
        );
    }

    /**
     * Handle payment.failed event
     *
     * @param array $data Event data
     * @param string $payload_hash SHA256 hash of raw payload
     */
    private function handle_payment_failed($data, $payload_hash) {
        $session_id = $data['session_id'] ?? null;
        $payment_id = $data['id'] ?? null;
        $event_id = $data['event_id'] ?? null;
        $failure_reason = $data['failure_reason'] ?? 'Unknown reason';

        if (empty($session_id)) {
            OP_Logger::warning(
                'webhook_missing_session_id',
                'payment.failed event missing session_id'
            );
            return;
        }

        // Find order by session_id
        $order = $this->find_order_by_session_id($session_id);

        if (!$order) {
            OP_Logger::warning(
                'webhook_order_not_found',
                'Order not found for session_id: ' . $session_id,
                array('session_id' => $session_id)
            );
            return;
        }

        // PR-WC-3b: Record inbound webhook receipt (debug visibility only)
        OP_Sync_Journal::record_inbound_received('payment.failed', $order->get_id(), $data);

        // CRITICAL: Idempotency guard - check if event already processed
        if (!empty($event_id)) {
            $idempotency_check = $this->is_event_processed($order, $event_id, $payload_hash);

            if ($idempotency_check === true) {
                // Exact duplicate (same event_id, same payload hash)
                OP_Logger::info(
                    'webhook_duplicate_event',
                    'Duplicate payment.failed event ignored (event_id: ' . $event_id . ')',
                    array(
                        'order_id' => $order->get_id(),
                        'event_id' => $event_id,
                        'payment_id' => $payment_id,
                    )
                );
                return;
            } elseif ($idempotency_check === 'hash_mismatch') {
                // ANOMALY: Same event_id but different payload
                OP_Logger::error(
                    'webhook_event_id_reuse',
                    'Duplicate event_id with different payload detected (event_id: ' . $event_id . ')',
                    array(
                        'order_id' => $order->get_id(),
                        'event_id' => $event_id,
                        'payment_id' => $payment_id,
                        'anomaly' => 'event_id_reuse_with_different_payload',
                    )
                );
                // Still return without processing (financial-grade paranoia)
                return;
            }
        }

        // Update order status to failed
        $order->update_status('failed', sprintf(
            __('Payment failed: %s', 'orangepill-wc'),
            $failure_reason
        ));

        // Store payment_id and failure info
        if (!empty($payment_id)) {
            $order->update_meta_data('_orangepill_payment_id', $payment_id);
        }

        $order->update_meta_data('_orangepill_payment_status', 'failed');
        $order->update_meta_data('_orangepill_failure_reason', $failure_reason);
        $order->update_meta_data('_orangepill_payment_failed_at', current_time('mysql'));

        // Mark event as processed (idempotency with payload hash)
        if (!empty($event_id)) {
            $this->mark_event_processed($order, $event_id, $payload_hash);
        }

        $order->save();

        OP_Logger::error(
            'payment_failed',
            'Payment failed for order #' . $order->get_id() . ': ' . $failure_reason,
            array(
                'order_id' => $order->get_id(),
                'session_id' => $session_id,
                'payment_id' => $payment_id,
                'event_id' => $event_id,
                'failure_reason' => $failure_reason,
            )
        );
    }

    /**
     * Find order by session_id
     *
     * @param string $session_id Session ID
     * @return WC_Order|null Order or null
     */
    private function find_order_by_session_id($session_id) {
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_orangepill_session_id',
            'meta_value' => $session_id,
        ));

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Check if event has already been processed (idempotency)
     *
     * Financial-grade paranoia: Checks both event_id AND payload hash
     * to detect event_id reuse with different payloads (provider bugs)
     *
     * @param WC_Order $order Order object
     * @param string $event_id Event ID
     * @param string $payload_hash SHA256 hash of payload
     * @return bool|string
     *         - true: Exact duplicate (event_id + hash match)
     *         - 'hash_mismatch': Event_id exists but hash differs (ANOMALY)
     *         - false: New event (not processed)
     */
    private function is_event_processed($order, $event_id, $payload_hash) {
        $processed_events = $order->get_meta('_orangepill_processed_events');

        if (empty($processed_events) || !is_array($processed_events)) {
            return false;
        }

        if (!isset($processed_events[$event_id])) {
            return false;
        }

        $stored_data = $processed_events[$event_id];

        // Legacy format (timestamp only) - treat as processed
        if (!is_array($stored_data)) {
            return true;
        }

        // Check payload hash (financial-grade paranoia)
        $stored_hash = $stored_data['hash'] ?? null;

        if ($stored_hash !== $payload_hash) {
            // CRITICAL ANOMALY: Same event_id, different payload
            return 'hash_mismatch';
        }

        return true;
    }

    /**
     * Mark event as processed (idempotency)
     *
     * Stores event_id with timestamp + payload hash.
     * Limits to last 100 events to prevent unbounded growth.
     *
     * @param WC_Order $order Order object
     * @param string $event_id Event ID
     * @param string $payload_hash SHA256 hash of payload
     */
    private function mark_event_processed($order, $event_id, $payload_hash) {
        $processed_events = $order->get_meta('_orangepill_processed_events');

        if (empty($processed_events) || !is_array($processed_events)) {
            $processed_events = array();
        }

        // Store event with timestamp + hash
        $processed_events[$event_id] = array(
            'timestamp' => current_time('timestamp'),
            'hash' => $payload_hash,
        );

        // Limit to last 100 events (FIFO pruning based on timestamp)
        if (count($processed_events) > 100) {
            // Sort by timestamp (oldest first)
            uasort($processed_events, function($a, $b) {
                $a_ts = is_array($a) ? $a['timestamp'] : $a;
                $b_ts = is_array($b) ? $b['timestamp'] : $b;
                return $a_ts - $b_ts;
            });
            // Remove oldest entries
            $processed_events = array_slice($processed_events, -100, null, true);
        }

        $order->update_meta_data('_orangepill_processed_events', $processed_events);
    }

    /**
     * Send HTTP response
     *
     * @param int $status_code HTTP status code
     * @param array $data Response data
     */
    private function send_response($status_code, $data = array()) {
        status_header($status_code);
        header('Content-Type: application/json');
        echo wp_json_encode($data);
    }
}

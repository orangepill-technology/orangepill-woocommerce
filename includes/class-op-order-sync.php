<?php
/**
 * Orangepill Order Sync
 *
 * Handles order status synchronization (informational logging only)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Order_Sync {
    /**
     * Status mapping from WooCommerce to Orangepill
     *
     * @var array
     */
    private $status_map = array(
        'pending' => 'pending',
        'processing' => 'confirmed',
        'on-hold' => 'on_hold',
        'completed' => 'fulfilled',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
        'failed' => 'failed',
    );

    /**
     * Sync order status to Orangepill
     *
     * Note: This is informational logging only. No dedicated API endpoint
     * exists for updating order status in Orangepill.
     *
     * @param WC_Order $order Order object
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function sync_order_status($order, $old_status, $new_status) {
        if (!$order) {
            return;
        }

        // Skip if not an Orangepill order
        $session_id = $order->get_meta('_orangepill_session_id');
        if (empty($session_id)) {
            return;
        }

        // Map WooCommerce status to Orangepill status
        $mapped_old_status = $this->status_map[$old_status] ?? $old_status;
        $mapped_new_status = $this->status_map[$new_status] ?? $new_status;

        // Prevent downgrading terminal states
        if ($this->is_terminal_status($old_status) && !$this->is_terminal_status($new_status)) {
            OP_Logger::warning(
                'order_status_downgrade_prevented',
                sprintf(
                    'Prevented downgrade of order #%d from %s to %s',
                    $order->get_id(),
                    $old_status,
                    $new_status
                ),
                array(
                    'order_id' => $order->get_id(),
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                )
            );
            return;
        }

        // Log status change
        OP_Logger::info(
            'order_status_changed',
            sprintf(
                'Order #%d status changed from %s to %s',
                $order->get_id(),
                $old_status,
                $new_status
            ),
            array(
                'order_id' => $order->get_id(),
                'old_status' => $old_status,
                'new_status' => $new_status,
                'mapped_old_status' => $mapped_old_status,
                'mapped_new_status' => $mapped_new_status,
                'session_id' => $session_id,
                'payment_id' => $order->get_meta('_orangepill_payment_id'),
            )
        );

        // Update last sync timestamp
        $order->update_meta_data('_orangepill_last_sync_at', current_time('mysql'));
        $order->save();

        // [PR-WC-LOYALTY-1 / RULE 12] Fire order.finalized on TRANSITION into completed
        // Guard: old_status !== completed AND new_status === completed
        // This avoids double emission on idempotent re-saves, manual status writes, or Woo quirks
        if ($new_status === 'completed' && $old_status !== 'completed') {
            $this->send_order_finalized($order, $old_status, $new_status);
        }
    }

    /**
     * Send order.finalized event to Orangepill for loyalty processing
     *
     * [PR-WC-LOYALTY-1] Fires exactly once per order completion transition.
     * Plugin emits trigger ONLY — never computes loyalty.
     *
     * @param WC_Order $order Order object
     * @param string $old_status Old status
     * @param string $new_status New status (must be 'completed')
     */
    private function send_order_finalized($order, $old_status, $new_status) {
        $order_id = $order->get_id();

        // Get gateway settings
        $gateway = new OP_Payment_Gateway();
        $integration_id = $gateway->get_option('integration_id');
        $base_url = $gateway->get_option('api_base_url');

        if (empty($integration_id)) {
            OP_Logger::error(
                'order_finalized_skipped',
                'Integration ID not configured — cannot send loyalty trigger',
                array('order_id' => $order_id)
            );
            return;
        }

        // Resolve OP customer ID if available
        $user_id = $order->get_user_id();
        $op_customer_id = $user_id ? get_user_meta($user_id, '_orangepill_customer_id', true) : null;

        // [PR-WC-LOYALTY-1] Payload for loyalty earn trigger
        // Uses RAW Woo status (not payment sync mapping)
        $payload = array(
            'event' => 'order.finalized',
            'woo_order_id' => (string) $order_id,
            'status' => $new_status,
            'previous_status' => $old_status,
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer' => array(
                'woo_customer_id' => $user_id ? (string) $user_id : null,
                'orangepill_customer_id' => $op_customer_id ?: null,
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ),
            'metadata' => array(
                'channel' => 'woocommerce',
                'integration_id' => $integration_id,
            ),
        );

        // [RULE 4] Stable idempotency key — NOT timestamp-based
        // Format: woo:{order_id}:order.finalized:completed
        $idempotency_key = sprintf('woo:%s:order.finalized:completed', $order_id);

        // [PR-OP-COMMERCE-EVENT-INGESTION-1] Confirmed endpoint
        $endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';

        // Record in sync journal (with dedupe)
        $event_id = OP_Sync_Journal::record_outbound_pending(
            'order.finalized',
            $order_id,
            $payload,
            $endpoint,
            $base_url,
            $idempotency_key
        );

        $event = OP_Sync_Journal::get_event($event_id);
        $api = new OP_API_Client();

        // [RULE 7] Failure MUST NOT block Woo admin
        try {
            $result = $api->request('POST', $endpoint, $payload, array(
                'Idempotency-Key' => $event->idempotency_key,
            ));

            if (is_wp_error($result)) {
                OP_Sync_Journal::mark_failed($event_id, $result->get_error_message());
                OP_Logger::error(
                    'order_finalized_failed',
                    'Failed to send loyalty earn trigger',
                    array(
                        'order_id' => $order_id,
                        'event_id' => $event_id,
                        'error' => $result->get_error_message(),
                        'idempotency_key' => $event->idempotency_key,
                    )
                );
            } else {
                OP_Sync_Journal::mark_sent($event_id, $result);
                OP_Logger::info(
                    'order_finalized_sent',
                    'Loyalty earn trigger sent',
                    array(
                        'order_id' => $order_id,
                        'event_id' => $event_id,
                        'idempotency_key' => $event->idempotency_key,
                    )
                );
            }
        } catch (Exception $e) {
            OP_Sync_Journal::mark_failed($event_id, $e->getMessage());
            OP_Logger::error(
                'order_finalized_exception',
                'Exception sending loyalty earn trigger',
                array(
                    'order_id' => $order_id,
                    'event_id' => $event_id,
                    'error' => $e->getMessage(),
                    'idempotency_key' => $event->idempotency_key,
                )
            );
        }
    }

    /**
     * Check if status is terminal (should not be downgraded)
     *
     * @param string $status Status
     * @return bool Is terminal
     */
    private function is_terminal_status($status) {
        $terminal_statuses = array('completed', 'cancelled', 'refunded', 'failed');
        return in_array($status, $terminal_statuses, true);
    }

    /**
     * Get mapped status
     *
     * @param string $wc_status WooCommerce status
     * @return string Mapped status
     */
    public function get_mapped_status($wc_status) {
        return $this->status_map[$wc_status] ?? $wc_status;
    }

    /**
     * Get all status mappings
     *
     * @return array Status map
     */
    public function get_status_map() {
        return $this->status_map;
    }
}

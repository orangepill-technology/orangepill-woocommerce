<?php
/**
 * Orangepill Refund Sync (PR-WC-LOYALTY-1)
 *
 * Handles refund event synchronization for loyalty reversal triggers.
 * Plugin emits trigger ONLY — never computes loyalty reversal.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Refund_Sync {
    /**
     * Initialize refund sync hooks
     */
    public function init() {
        // [PR-WC-LOYALTY-1 / RULE 3] Hook into WooCommerce refund creation
        // Fires once per Woo refund object
        add_action('woocommerce_create_refund', array($this, 'on_refund_created'), 10, 2);
    }

    /**
     * Fire order.refunded event when a WooCommerce refund is created
     *
     * [RULE 3] Fires once per refund_id (natural idempotency anchor)
     * [RULE 7] Failure MUST NOT block Woo refund flow
     *
     * @param WC_Order_Refund $refund Refund object
     * @param array $args Refund creation arguments
     */
    public function on_refund_created($refund, $args) {
        // Validate refund object
        if (!$refund || !is_a($refund, 'WC_Order_Refund')) {
            OP_Logger::warning(
                'refund_sync_skipped',
                'Invalid refund object',
                array('refund' => $refund)
            );
            return;
        }

        // Get parent order
        $order = wc_get_order($refund->get_parent_id());
        if (!$order) {
            OP_Logger::warning(
                'refund_sync_skipped',
                'Parent order not found',
                array('refund_id' => $refund->get_id(), 'parent_id' => $refund->get_parent_id())
            );
            return;
        }

        // Only process Orangepill orders
        if ($order->get_payment_method() !== 'orangepill') {
            return;
        }

        $this->send_order_refunded($order, $refund);
    }

    /**
     * Send order.refunded event to Orangepill for loyalty reversal processing
     *
     * [PR-WC-LOYALTY-1] Plugin emits trigger ONLY — never computes loyalty reversal.
     *
     * @param WC_Order $order Parent order object
     * @param WC_Order_Refund $refund Refund object
     */
    private function send_order_refunded($order, $refund) {
        $order_id = $order->get_id();
        $refund_id = $refund->get_id();
        $refund_amount = abs($refund->get_amount());

        // Get gateway settings
        $gateway = new OP_Payment_Gateway();
        $integration_id = $gateway->get_option('integration_id');
        $base_url = $gateway->get_option('api_base_url');

        if (empty($integration_id)) {
            OP_Logger::error(
                'order_refunded_skipped',
                'Integration ID not configured — cannot send loyalty reversal trigger',
                array('order_id' => $order_id, 'refund_id' => $refund_id)
            );
            return;
        }

        // Resolve OP customer ID if available
        $user_id = $order->get_user_id();
        $op_customer_id = $user_id ? get_user_meta($user_id, '_orangepill_customer_id', true) : null;

        // [PR-WC-LOYALTY-1] Payload for loyalty reversal trigger
        $payload = array(
            'event' => 'order.refunded',
            'woo_order_id' => (string) $order_id,
            'refund_id' => (string) $refund_id,
            'refund_amount' => (string) $refund_amount,
            'order_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer' => array(
                'woo_customer_id' => $user_id ? (string) $user_id : null,
                'orangepill_customer_id' => $op_customer_id ?: null,
            ),
            'metadata' => array(
                'channel' => 'woocommerce',
                'integration_id' => $integration_id,
                'refund_reason' => $refund->get_reason() ?: '',
            ),
        );

        // [RULE 4] Refund idempotency uses refund_id — NOT timestamp
        // Format: woo:{order_id}:refund:{refund_id}
        $idempotency_key = sprintf('woo:%s:refund:%s', $order_id, $refund_id);

        // [PR-OP-COMMERCE-EVENT-INGESTION-1] Confirmed endpoint
        $endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';

        // Record in sync journal (with dedupe)
        $event_id = OP_Sync_Journal::record_outbound_pending(
            'order.refunded',
            $order_id,
            $payload,
            $endpoint,
            $base_url,
            $idempotency_key
        );

        $event = OP_Sync_Journal::get_event($event_id);
        $api = new OP_API_Client();

        // [RULE 7] Failure MUST NOT block Woo admin refund flow
        try {
            $result = $api->request('POST', $endpoint, $payload, array(
                'Idempotency-Key' => $event->idempotency_key,
            ));

            if (is_wp_error($result)) {
                OP_Sync_Journal::mark_failed($event_id, $result->get_error_message());
                OP_Logger::error(
                    'order_refunded_failed',
                    'Failed to send loyalty reversal trigger',
                    array(
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'event_id' => $event_id,
                        'error' => $result->get_error_message(),
                        'idempotency_key' => $event->idempotency_key,
                    )
                );
            } else {
                OP_Sync_Journal::mark_sent($event_id, $result);
                OP_Logger::info(
                    'order_refunded_sent',
                    'Loyalty reversal trigger sent',
                    array(
                        'order_id' => $order_id,
                        'refund_id' => $refund_id,
                        'event_id' => $event_id,
                        'idempotency_key' => $event->idempotency_key,
                    )
                );
            }
        } catch (Exception $e) {
            OP_Sync_Journal::mark_failed($event_id, $e->getMessage());
            OP_Logger::error(
                'order_refunded_exception',
                'Exception sending loyalty reversal trigger',
                array(
                    'order_id' => $order_id,
                    'refund_id' => $refund_id,
                    'event_id' => $event_id,
                    'error' => $e->getMessage(),
                    'idempotency_key' => $event->idempotency_key,
                )
            );
        }
    }
}

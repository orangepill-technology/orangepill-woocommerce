<?php
/**
 * Native Checkout Return Handler (PR-WC-NATIVE-CHECKOUT-1)
 *
 * Handles the browser return after a 3DS / PSE provider redirect.
 * Endpoint: /?wc-api=orangepill-native-return
 *
 * Looks up the pending order by _orangepill_intent_id meta, verifies
 * the intent status via API, and finalises or fails the order.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OP_Checkout_Return_Handler {

    public function init() {
        add_action('woocommerce_api_orangepill-native-return', array($this, 'handle'));
    }

    public function handle() {
        // intent_id may come from GET param (if provider passes it through)
        // or from WC session (stored by process_payment)
        $intent_id = isset($_GET['intent_id']) ? sanitize_text_field($_GET['intent_id']) : '';

        if (empty($intent_id) && WC()->session) {
            $intent_id = WC()->session->get('op_native_intent_id');
        }

        if (empty($intent_id)) {
            OP_Logger::warning(
                'native_return_no_intent',
                'Return handler called with no resolvable intent_id',
                array('get' => array_keys($_GET))
            );
            wc_add_notice(__('Payment could not be verified. Please check your email or contact support.', 'orangepill-wc'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Find order by intent_id meta
        $orders = wc_get_orders(array(
            'meta_key'   => '_orangepill_intent_id',
            'meta_value' => $intent_id,
            'limit'      => 1,
            'status'     => 'any',
        ));

        if (empty($orders)) {
            OP_Logger::warning(
                'native_return_order_not_found',
                'No order found for intent_id on return',
                array('intent_id' => $intent_id)
            );
            wc_add_notice(__('Payment could not be verified. Please check your email or contact support.', 'orangepill-wc'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order    = $orders[0];
        $order_id = $order->get_id();

        // Already terminal — avoid double-processing
        if (in_array($order->get_status(), array('processing', 'completed', 'refunded'), true)) {
            if (WC()->session) {
                WC()->session->set('op_native_intent_id', null);
            }
            wp_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // Verify status from API (never trust GET params for financial state)
        $api    = new OP_API_Client();
        $intent = $api->get_payment_intent($intent_id);

        if (is_wp_error($intent)) {
            OP_Logger::error(
                'native_return_intent_fetch_failed',
                'Failed to fetch intent on return: ' . $intent->get_error_message(),
                array('order_id' => $order_id, 'intent_id' => $intent_id)
            );
            wc_add_notice(__('Payment verification failed. Please contact support.', 'orangepill-wc'), 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $status     = $intent['status'] ?? '';
        $payment_id = $intent['id'] ?? $intent_id;

        if (WC()->session) {
            WC()->session->set('op_native_intent_id', null);
        }

        if ($status === 'succeeded') {
            $order->update_meta_data('_orangepill_payment_status', 'succeeded');
            $order->update_meta_data('_orangepill_payment_confirmed_at', current_time('mysql'));
            if (!empty($intent['execution']['providerPaymentId'])) {
                $order->update_meta_data('_orangepill_payment_id', $intent['execution']['providerPaymentId']);
            }
            $order->save();
            $order->payment_complete($payment_id);

            OP_Logger::info(
                'native_payment_completed_on_return',
                'Order #' . $order_id . ' confirmed succeeded on return',
                array('order_id' => $order_id, 'intent_id' => $intent_id)
            );

            wp_redirect($order->get_checkout_order_received_url());

        } elseif ($status === 'processing') {
            if ($order->get_status() === 'pending') {
                $order->update_status(
                    'on-hold',
                    __('Payment submitted and processing. Awaiting confirmation from provider.', 'orangepill-wc')
                );
            }

            OP_Logger::info(
                'native_payment_processing_on_return',
                'Order #' . $order_id . ' still processing on return',
                array('order_id' => $order_id, 'intent_id' => $intent_id)
            );

            wp_redirect($order->get_checkout_order_received_url());

        } else {
            // failed, cancelled, expired, etc.
            $order->update_status(
                'failed',
                sprintf(__('Payment not completed (status: %s).', 'orangepill-wc'), $status)
            );

            OP_Logger::warning(
                'native_payment_failed_on_return',
                'Order #' . $order_id . ' failed on return (status: ' . $status . ')',
                array('order_id' => $order_id, 'intent_id' => $intent_id, 'status' => $status)
            );

            wc_add_notice(__('Payment was not completed. Please try again.', 'orangepill-wc'), 'error');
            wp_redirect(wc_get_checkout_url());
        }

        exit;
    }
}

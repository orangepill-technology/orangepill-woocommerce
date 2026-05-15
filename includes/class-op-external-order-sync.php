<?php
/**
 * External Order Sync (PR-WC-EXTERNAL-ORDERS-SYNC-1)
 *
 * Push every WooCommerce order to POST /v4/external-orders/woocommerce
 * on create, update, or status change.
 *
 * Fire-and-forget: wp_remote_post with blocking:false.
 * No response is read; the API call does not block the checkout/admin flow.
 * All orders are pushed regardless of payment method.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_External_Order_Sync {

    /**
     * Register WooCommerce hooks
     */
    public function init() {
        add_action('woocommerce_new_order',            array($this, 'on_order_created'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'on_status_changed'), 10, 4);
    }

    /**
     * @param int           $order_id
     * @param WC_Order|null $order    May be null on older WooCommerce versions
     */
    public function on_order_created($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if ($order) {
            $this->push($order);
        }
    }

    /**
     * @param int      $order_id
     * @param string   $old_status
     * @param string   $new_status
     * @param WC_Order $order
     */
    public function on_status_changed($order_id, $old_status, $new_status, $order) {
        if ($order) {
            $this->push($order);
        }
    }

    /**
     * Fire-and-forget POST to external-orders endpoint.
     *
     * Uses blocking:false so the HTTP request is dispatched without waiting
     * for a response. Failures are silent — the checkout / admin flow is never blocked.
     *
     * @param WC_Order $order
     */
    private function push($order) {
        $gateway = new OP_Payment_Gateway();
        $api_key        = $gateway->get_option('api_key');
        $integration_id = $gateway->get_option('integration_id');
        $merchant_id    = $gateway->get_option('merchant_id');
        $raw_base_url   = $gateway->get_option('api_base_url');
        $base_url       = rtrim(!empty($raw_base_url) ? $raw_base_url : 'https://console.orangepill.cloud', '/');

        if (empty($api_key)) {
            return; // Plugin not configured — skip silently
        }

        $payload = $this->build_payload($order, $integration_id, $merchant_id);
        $url     = $base_url . '/v4/external-orders/woocommerce';

        // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1: attribution verification.
        // When conversation_id is present AND attribution status not yet determined,
        // use a blocking request to get the platform's identity-match outcome.
        // After the first attribution response, subsequent pushes revert to fire-and-forget.
        // Per RULE 2: attribution failures never surface to the customer — silent.
        $conversation_id    = $order->get_meta('_orangepill_conversation_id', true);
        $attribution_status = $order->get_meta('_orangepill_conversation_attribution_status', true);
        $need_attribution   = !empty($conversation_id) && empty($attribution_status);

        $response = wp_remote_post($url, array(
            'blocking' => $need_attribution,
            'timeout'  => $need_attribution ? 15 : 5,
            'headers'  => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'Orangepill-WooCommerce/' . ORANGEPILL_WC_VERSION,
            ),
            'body'     => wp_json_encode($payload),
        ));

        // Store attribution outcome when the platform responds (blocking path only).
        // Canonical rejection reasons: conversation_not_found | customer_mismatch | conversation_anonymous.
        // Per RULE 6: verification status stored visibly so operators can investigate.
        if ($need_attribution && !is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['conversation_attribution'])) {
                    $attr   = $body['conversation_attribution'];
                    $status = sanitize_text_field($attr['status'] ?? 'none');
                    update_post_meta($order->get_id(), '_orangepill_conversation_attribution_status', $status);
                    if (!empty($attr['reason'])) {
                        $reason = sanitize_text_field($attr['reason']);
                        update_post_meta($order->get_id(), '_orangepill_conversation_attribution_reason', $reason);
                    }

                    OP_Logger::info(
                        'conversation_attribution_received',
                        'Conversation attribution: ' . $status,
                        array(
                            'order_id'        => $order->get_id(),
                            'conversation_id' => $conversation_id,
                            'status'          => $status,
                            'reason'          => $attr['reason'] ?? '',
                        )
                    );
                }
            }
        }

        OP_Logger::info(
            'external_order_pushed',
            'Order #' . $order->get_id() . ' pushed to external-orders API'
                . ( $need_attribution ? ' (blocking — attribution check)' : ' (fire-and-forget)' ),
            array(
                'order_id'          => $order->get_id(),
                'status'            => $order->get_status(),
                'url'               => $url,
                'has_conversation'  => !empty($conversation_id),
            )
        );
    }

    /**
     * Build the external order payload.
     *
     * @param WC_Order $order
     * @param string   $integration_id
     * @param string   $merchant_id
     * @return array
     */
    private function build_payload($order, $integration_id, $merchant_id) {
        // PHP 7.4: no nullsafe operator — use ternary
        $created_at   = ($d = $order->get_date_created())   ? $d->format('c') : null;
        $updated_at   = ($d = $order->get_date_modified())  ? $d->format('c') : null;
        $completed_at = ($d = $order->get_date_completed()) ? $d->format('c') : null;

        $currency = $order->get_currency();

        // Items
        $items = array();
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $items[] = array(
                'externalProductId' => (string) $item->get_product_id(),
                'title'             => $item->get_name(),
                'sku'               => $product ? $product->get_sku() : '',
                'quantity'          => $item->get_quantity(),
                'unitPriceAmount'   => $product ? (float) $product->get_price() : 0.0,
                'lineTotalAmount'   => (float) $item->get_total(),
                'currency'          => $currency,
            );
        }

        // Customer
        $user_id        = $order->get_user_id();
        $op_customer_id = $user_id ? get_user_meta($user_id, '_orangepill_customer_id', true) : null;
        $op_session_id  = $order->get_meta('_orangepill_session_id', true);
        $op_payment_id  = $order->get_meta('_orangepill_payment_id', true);
        // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1: conversation attribution claim.
        $op_conv_id     = $order->get_meta('_orangepill_conversation_id', true);

        $payload = array(
            'externalOrderId'  => (string) $order->get_id(),
            'integrationId'    => $integration_id,
            'externalStatus'   => $order->get_status(),
            'currency'         => $currency,
            'totalAmount'      => (float) $order->get_total(),
            'subtotalAmount'   => (float) $order->get_subtotal(),
            'taxAmount'        => (float) $order->get_total_tax(),
            'shippingAmount'   => (float) $order->get_shipping_total(),
            'discountAmount'   => (float) $order->get_discount_total(),
            'paymentMethod'    => $order->get_payment_method(),
            'orderReference'   => '#' . $order->get_order_number(),
            'customer'         => array(
                'id'                      => $user_id ?: 0,
                'email'                   => $order->get_billing_email(),
                'first_name'              => $order->get_billing_first_name(),
                'last_name'               => $order->get_billing_last_name(),
                'orangepill_customer_id'  => $op_customer_id ?: null,
                'billing'                 => array(
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                ),
            ),
            'billingAddress'   => array(
                'address1' => $order->get_billing_address_1(),
                'address2' => $order->get_billing_address_2(),
                'city'     => $order->get_billing_city(),
                'state'    => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country'  => $order->get_billing_country(),
            ),
            'shippingAddress'  => array(
                'address1' => $order->get_shipping_address_1(),
                'address2' => $order->get_shipping_address_2(),
                'city'     => $order->get_shipping_city(),
                'state'    => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country'  => $order->get_shipping_country(),
            ),
            'items'            => $items,
            'createdAt'        => $created_at,
            'updatedAt'        => $updated_at,
            'completedAt'      => $completed_at,
            'rawPayload'       => array(
                'channel'               => 'woocommerce',
                'orangepill_session_id' => $op_session_id ?: null,
                'orangepill_payment_id' => $op_payment_id ?: null,
            ),
        );

        // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1: include conversation claim (top-level).
        // Platform verifies via canonical customer match — not trusted by plugin.
        if (!empty($op_conv_id)) {
            $payload['conversation_id'] = $op_conv_id;
        }

        return $payload;
    }
}

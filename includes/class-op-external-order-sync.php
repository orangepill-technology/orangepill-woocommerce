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
        add_action('woocommerce_update_order',         array($this, 'on_order_updated'), 10, 2);
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
     * @param int           $order_id
     * @param WC_Order|null $order
     */
    public function on_order_updated($order_id, $order = null) {
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

        wp_remote_post($url, array(
            'blocking' => false,
            'timeout'  => 5,
            'headers'  => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'Orangepill-WooCommerce/' . ORANGEPILL_WC_VERSION,
            ),
            'body'     => wp_json_encode($payload),
        ));
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
        $created_at  = ($d = $order->get_date_created())   ? $d->format('c') : null;
        $updated_at  = ($d = $order->get_date_modified())  ? $d->format('c') : null;
        $completed_at = ($d = $order->get_date_completed()) ? $d->format('c') : null;

        // Line items
        $line_items = array();
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product      = $item->get_product();
            $line_items[] = array(
                'id'           => (string) $item->get_id(),
                'product_id'   => (string) $item->get_product_id(),
                'variation_id' => (string) $item->get_variation_id(),
                'sku'          => $product ? $product->get_sku() : '',
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'unit_price'   => $product ? (string) $product->get_price() : '0',
                'subtotal'     => (string) $item->get_subtotal(),
                'total'        => (string) $item->get_total(),
                'tax'          => (string) $item->get_total_tax(),
            );
        }

        // Customer
        $user_id            = $order->get_user_id();
        $op_customer_id     = $user_id ? get_user_meta($user_id, '_orangepill_customer_id', true) : null;
        $op_session_id      = $order->get_meta('_orangepill_session_id', true);
        $op_payment_id      = $order->get_meta('_orangepill_payment_id', true);

        return array(
            'external_id'      => (string) $order->get_id(),
            'tenant_id'        => $merchant_id,
            'status'           => $order->get_status(),
            'currency'         => $order->get_currency(),
            'total'            => (string) $order->get_total(),
            'subtotal'         => (string) $order->get_subtotal(),
            'tax_total'        => (string) $order->get_total_tax(),
            'shipping_total'   => (string) $order->get_shipping_total(),
            'discount_total'   => (string) $order->get_discount_total(),
            'payment_method'   => $order->get_payment_method(),
            'customer'         => array(
                'woo_user_id'             => $user_id ? (string) $user_id : null,
                'orangepill_customer_id'  => $op_customer_id ?: null,
                'email'                   => $order->get_billing_email(),
                'first_name'              => $order->get_billing_first_name(),
                'last_name'               => $order->get_billing_last_name(),
            ),
            'billing_address'  => array(
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
                'phone'     => $order->get_billing_phone(),
            ),
            'shipping_address' => array(
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
            ),
            'line_items'       => $line_items,
            'created_at'       => $created_at,
            'updated_at'       => $updated_at,
            'completed_at'     => $completed_at,
            'metadata'         => array(
                'channel'              => 'woocommerce',
                'integration_id'       => $integration_id,
                'orangepill_session_id' => $op_session_id ?: null,
                'orangepill_payment_id' => $op_payment_id ?: null,
            ),
        );
    }
}

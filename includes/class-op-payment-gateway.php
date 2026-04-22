<?php
/**
 * Orangepill Payment Gateway
 *
 * WooCommerce payment gateway implementation for Orangepill.
 *
 * PR-OP-WOO-INTEGRATION-CORE-1:
 * - Part 1: Customer mapping (get_or_create → customer_id in session)
 * - Part 2: Channel propagation (_orangepill_channel = 'web' on order meta)
 * - Part 3: Checkout session via POST /v4/checkout/sessions
 * - Part 4: Wallet/loyalty pre-application before redirect
 * - Part 8: No null customer_id; error surfaced clearly
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Payment_Gateway extends WC_Payment_Gateway {
    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'orangepill';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('Orangepill', 'orangepill-wc');
        $this->method_description = __('Accept payments via Orangepill embedded finance platform', 'orangepill-wc');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Native checkout AJAX proxy endpoints (PR-WC-NATIVE-CHECKOUT-1)
        add_action('wp_ajax_orangepill_get_payment_options',  array($this, 'ajax_get_payment_options'));
        add_action('wp_ajax_nopriv_orangepill_get_payment_options', array($this, 'ajax_get_payment_options'));
        add_action('wp_ajax_orangepill_create_intent',  array($this, 'ajax_create_intent'));
        add_action('wp_ajax_nopriv_orangepill_create_intent',  array($this, 'ajax_create_intent'));
        add_action('wp_ajax_orangepill_execute_intent', array($this, 'ajax_execute_intent'));
        add_action('wp_ajax_nopriv_orangepill_execute_intent', array($this, 'ajax_execute_intent'));
        add_action('wp_ajax_orangepill_get_intent_status', array($this, 'ajax_get_intent_status'));
        add_action('wp_ajax_nopriv_orangepill_get_intent_status', array($this, 'ajax_get_intent_status'));
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'orangepill-wc'),
                'type'    => 'checkbox',
                'label'   => __('Enable Orangepill payment gateway', 'orangepill-wc'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Payment method title that customers see during checkout', 'orangepill-wc'),
                'default'     => __('Orangepill', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'orangepill-wc'),
                'type'        => 'textarea',
                'description' => __('Payment method description that customers see during checkout', 'orangepill-wc'),
                'default'     => __('Pay securely via Orangepill', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'orangepill-wc'),
                'type'        => 'password',
                'description' => __('Your Orangepill integration API key', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'api_base_url' => array(
                'title'       => __('API Base URL', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Orangepill API base URL (leave default for production)', 'orangepill-wc'),
                'default'     => 'https://console.orangepill.cloud',
                'desc_tip'    => true,
            ),
            'integration_id' => array(
                'title'       => __('Integration ID', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Your Orangepill integration ID', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'merchant_id' => array(
                'title'       => __('Merchant ID', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Your Orangepill merchant ID', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'webhook_secret' => array(
                'title'       => __('Webhook Secret', 'orangepill-wc'),
                'type'        => 'password',
                'description' => __('Your Orangepill webhook signing secret', 'orangepill-wc'),
                'desc_tip'    => true,
            ),
            'checkout_ui_url' => array(
                'title'       => __('Checkout UI URL', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Base URL of the Orangepill hosted checkout UI (leave default for production)', 'orangepill-wc'),
                'default'     => 'https://checkout.orangepill.cloud',
                'desc_tip'    => true,
            ),
            'webhook_public_url' => array(
                'title'       => __('Public Webhook URL', 'orangepill-wc'),
                'type'        => 'text',
                'description' => __('Override the webhook callback URL sent to Orangepill. Required for local dev behind ngrok. Leave empty to use the auto-generated WooCommerce API URL.', 'orangepill-wc'),
                'default'     => '',
                'placeholder' => WC()->api_request_url('orangepill-webhook'),
                'desc_tip'    => false,
            ),
        );
    }

    /**
     * Render native payment shell + optional wallet widget.
     *
     * Native shell (PR-WC-NATIVE-CHECKOUT-1): fetches payment options via AJAX,
     * renders method list, drives create_intent → execute_intent, then submits
     * the WC form with hidden intent fields for process_payment().
     *
     * Wallet widget (PR-WC-CHECKOUT-WALLET-UX-1): only shown for logged-in users.
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        // Native payment shell — populated by native-payment-shell.js
        $cart_total = WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;
        echo '<div id="orangepill-native-shell"'
            . ' data-currency="' . esc_attr(get_woocommerce_currency()) . '"'
            . ' data-amount="' . esc_attr((string) $cart_total) . '"'
            . ' data-country="' . esc_attr(substr(get_option('woocommerce_default_country', 'CO'), 0, 2)) . '">'
            . '<div class="op-native-loading">'
            . esc_html__('Loading payment options\xe2\x80\xa6', 'orangepill-wc')
            . '</div>'
            . '</div>';

        // Hidden fields written by native-payment-shell.js; read by process_payment()
        echo '<input type="hidden" name="_orangepill_intent_id"       id="op_intent_id"       value="" />';
        echo '<input type="hidden" name="_orangepill_execution_type"  id="op_execution_type"  value="" />';

        if (is_user_logged_in()) {
            echo '<div id="orangepill-wallet-widget" style="margin-top:12px;" data-loading="1">';
            echo '<span class="op-wallet-loading">' . esc_html__('Checking rewards balance\xe2\x80\xa6', 'orangepill-wc') . '</span>';
            echo '</div>';
            echo '<input type="hidden" name="orangepill_apply_wallet"  id="orangepill_apply_wallet"  value="0" />';
            echo '<input type="hidden" name="orangepill_wallet_amount" id="orangepill_wallet_amount" value="" />';
            echo '<input type="hidden" name="orangepill_wallet_id"     id="orangepill_wallet_id"     value="" />';
        }
    }

    /**
     * Process payment
     *
     * Two paths:
     *
     * [A] Native checkout (PR-WC-NATIVE-CHECKOUT-1): JS has already created and
     *     executed the intent. process_payment() verifies via API and finalises
     *     the order. Hidden field _orangepill_intent_id triggers this path.
     *
     * [B] Hosted checkout (legacy): creates a session and redirects to the
     *     Orangepill hosted checkout UI.
     *
     * @param int $order_id WooCommerce order ID
     * @return array Result array (success/failure + redirect URL)
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found', 'orangepill-wc'), 'error');
            return array('result' => 'failure');
        }

        // ── Path A: native intent already executed by JS ──────────────────
        $intent_id = isset($_POST['_orangepill_intent_id'])
            ? sanitize_text_field($_POST['_orangepill_intent_id'])
            : '';

        if (!empty($intent_id)) {
            return $this->process_native_payment($order, $intent_id);
        }

        // ── Path B: legacy hosted-checkout flow ───────────────────────────
        try {
            // ─── Part 2: Channel propagation ───────────────────────────────
            $order->update_meta_data('_orangepill_channel', 'web');
            $order->save();

            // ─── Part 1: Customer mapping ──────────────────────────────────
            $customer_id = null;
            $user_id     = $order->get_user_id();

            if ($user_id > 0) {
                $customer_sync = new OP_Customer_Sync();
                $sync_result   = $customer_sync->get_or_create($user_id, $order);

                if (is_wp_error($sync_result)) {
                    // Part 8: Surface the error clearly — do not proceed with null customer
                    OP_Logger::error(
                        'checkout_customer_sync_failed',
                        'Cannot create checkout session without customer: ' . $sync_result->get_error_message(),
                        array('order_id' => $order_id, 'user_id' => $user_id)
                    );
                    wc_add_notice(
                        __('Unable to set up your customer account. Please try again or contact support.', 'orangepill-wc'),
                        'error'
                    );
                    return array('result' => 'failure');
                }

                $customer_id = $sync_result;
            }

            // ─── Part 3: Checkout session creation ─────────────────────────
            $api = new OP_API_Client();

            $session_params = array(
                'integration_id'  => $this->get_option('integration_id'),
                'merchant_id'     => $this->get_option('merchant_id'),
                'amount'          => (string) $order->get_total(),
                'currency'        => $order->get_currency(),
                'order_reference' => 'WC_' . $order_id,
                'success_url'     => $this->get_return_url($order),
                'cancel_url'      => wc_get_checkout_url(),
                'metadata'        => array(
                    'channel'      => 'web',
                    'woo_order_id' => (string) $order_id,
                ),
            );

            // Determine webhook delivery path and log it for operator visibility.
            // Integration-level webhook = PRIMARY (registered once on settings save).
            // Session-level callback    = FALLBACK (only when integration webhook not confirmed).
            $webhook_status = OP_Integration_Webhooks::get_status();
            $using_fallback = empty($webhook_status) || !$webhook_status['success'];

            if ($using_fallback) {
                $session_params['callback'] = array(
                    'url'    => orangepill_wc_get_webhook_url(),
                    'events' => array('checkout.session.completed', 'checkout.session.failed'),
                );
            }

            OP_Logger::info(
                'checkout_session_webhook_path',
                $using_fallback
                    ? 'Session created with session-level callback fallback (integration webhook not registered)'
                    : 'Session created using integration-level webhook (primary path)',
                array(
                    'order_id'       => $order_id,
                    'webhook_path'   => $using_fallback ? 'session_callback_fallback' : 'integration_webhook',
                    'webhook_status' => $webhook_status['message'] ?? 'not registered',
                )
            );

            // Pass customer_id if we have one (never inline data for registered users)
            if ($customer_id) {
                $session_params['customer_id'] = $customer_id;
            } elseif ($order->get_billing_email()) {
                // Guest checkout: pass inline customer data so Orangepill can track it
                $session_params['customer'] = array(
                    'email' => $order->get_billing_email(),
                    'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'phone' => $order->get_billing_phone() ?: null,
                );
            }

            $endpoint    = '/v4/checkout/sessions';
            $api_settings = $api->get_settings();

            $event_id = OP_Sync_Journal::record_outbound_pending(
                'checkout.session.create',
                $order_id,
                $session_params,
                $endpoint,
                $api_settings['base_url']
            );
            $event = OP_Sync_Journal::get_event($event_id);

            $session = $api->request(
                'POST',
                $endpoint,
                $session_params,
                array('Idempotency-Key' => $event->idempotency_key)
            );

            if (is_wp_error($session)) {
                OP_Sync_Journal::mark_failed($event_id, $session->get_error_message());

                OP_Logger::error(
                    'checkout_session_failed',
                    'Failed to create checkout session: ' . $session->get_error_message(),
                    array(
                        'order_id'        => $order_id,
                        'event_id'        => $event_id,
                        'idempotency_key' => $event->idempotency_key,
                    )
                );

                wc_add_notice(__('Unable to create payment session. Please try again.', 'orangepill-wc'), 'error');
                return array('result' => 'failure');
            }

            OP_Sync_Journal::mark_sent($event_id, $session);

            $session_id    = $session['id'] ?? null;
            $client_secret = $session['client_secret'] ?? null;

            if (empty($session_id) || empty($client_secret)) {
                OP_Logger::error(
                    'checkout_session_invalid',
                    'Checkout session response missing id or client_secret',
                    array('order_id' => $order_id, 'response' => $session)
                );
                wc_add_notice(__('Invalid payment session response. Please try again.', 'orangepill-wc'), 'error');
                return array('result' => 'failure');
            }

            // ─── Part 4: Wallet application ────────────────────────────────
            // If customer opted to apply loyalty balance, apply it to the session
            // before redirecting. A failure here is non-fatal — we log and continue.
            $apply_wallet  = isset($_POST['orangepill_apply_wallet']) && $_POST['orangepill_apply_wallet'] === '1';
            $wallet_amount = isset($_POST['orangepill_wallet_amount']) ? sanitize_text_field($_POST['orangepill_wallet_amount']) : '';
            $wallet_id     = isset($_POST['orangepill_wallet_id'])     ? sanitize_text_field($_POST['orangepill_wallet_id'])     : '';

            if ($apply_wallet && $customer_id && !empty($wallet_amount)) {
                $loyalty = new OP_Loyalty();
                // /apply-wallet requires CheckoutSession auth scheme — pass client_secret as token
                $apply_result = $loyalty->apply_wallet_to_session($session_id, $wallet_amount, $wallet_id, $client_secret);

                if (is_wp_error($apply_result)) {
                    $err_data = $apply_result->get_error_data();
                    OP_Logger::warning(
                        'wallet_apply_failed',
                        'Failed to apply wallet to session (continuing without): ' . $apply_result->get_error_message(),
                        array(
                            'order_id'        => $order_id,
                            'session_id'      => $session_id,
                            'wallet_id'       => $wallet_id,
                            'amount'          => $wallet_amount,
                            'http_status'     => $err_data['status_code'] ?? null,
                            'api_response'    => $err_data['response'] ?? null,
                        )
                    );
                    // Non-fatal: continue to checkout without wallet applied
                } else {
                    // Invalidate cached wallet balance — it just changed
                    $loyalty->invalidate_wallet_cache($customer_id);

                    $order->update_meta_data('_orangepill_wallet_applied', $wallet_amount);
                    OP_Logger::info(
                        'wallet_applied',
                        'Wallet balance applied to session',
                        array(
                            'order_id'        => $order_id,
                            'session_id'      => $session_id,
                            'amount'          => $wallet_amount,
                            'api_response'    => $apply_result,
                        )
                    );

                    // Zero-payable guard: if wallet covers 100% of the order,
                    // the backend completes the session internally — no hosted
                    // checkout UI needed. Skip the redirect and finalise locally.
                    $payable = (float) ($apply_result['remaining_amount'] ?? $apply_result['payable_amount'] ?? -1);
                    if ($payable === 0.0) {
                        $order->update_meta_data('_orangepill_payment_status', 'succeeded');
                        $order->update_meta_data('_orangepill_payment_confirmed_at', current_time('mysql'));
                        $order->save();
                        $order->payment_complete();

                        OP_Logger::info(
                            'wallet_full_cover',
                            'Order fully covered by wallet — skipping hosted checkout redirect',
                            array('order_id' => $order_id, 'session_id' => $session_id)
                        );

                        return array(
                            'result'   => 'success',
                            'redirect' => $this->get_return_url($order),
                        );
                    }
                }
            }

            // Store session metadata on order
            $order->update_meta_data('_orangepill_session_id', $session_id);
            if ($customer_id) {
                $order->update_meta_data('_orangepill_customer_id', $customer_id);
            }
            $order->save();

            OP_Logger::info(
                'checkout_session_created',
                'Checkout session created',
                array(
                    'order_id'        => $order_id,
                    'session_id'      => $session_id,
                    'customer_id'     => $customer_id,
                    'channel'         => 'web',
                    'event_id'        => $event_id,
                    'idempotency_key' => $event->idempotency_key,
                )
            );

            // ─── Redirect to hosted checkout UI ───────────────────────────
            // client_secret goes in the URL fragment (never query string — not sent to server)
            $checkout_ui_base = rtrim($this->get_option('checkout_ui_url', 'https://checkout.orangepill.cloud'), '/');
            $checkout_url     = $checkout_ui_base . '/sessions/' . $session_id . '#cs=' . $client_secret;

            return array(
                'result'   => 'success',
                'redirect' => $checkout_url,
            );

        } catch (Exception $e) {
            OP_Logger::error(
                'payment_processing_error',
                'Payment processing exception: ' . $e->getMessage(),
                array('order_id' => $order_id)
            );

            wc_add_notice(__('Payment processing failed. Please try again.', 'orangepill-wc'), 'error');
            return array('result' => 'failure');
        }
    }

    // ─── Native checkout helpers (PR-WC-NATIVE-CHECKOUT-1) ───────────────────

    /**
     * Finalise an order where the payment intent was already created + executed
     * by the JS shell. Verifies status server-side via API.
     *
     * @param WC_Order $order
     * @param string   $intent_id
     * @return array WC process_payment result
     */
    private function process_native_payment($order, $intent_id) {
        $order_id      = $order->get_id();
        $execution_type = isset($_POST['_orangepill_execution_type'])
            ? sanitize_text_field($_POST['_orangepill_execution_type'])
            : '';

        // Persist intent_id on order immediately so return handler can look it up
        $order->update_meta_data('_orangepill_intent_id', $intent_id);
        $order->update_meta_data('_orangepill_channel', 'web');
        $order->save();

        // Store in session as backup for return handler
        if (WC()->session) {
            WC()->session->set('op_native_intent_id', $intent_id);
        }

        $api = new OP_API_Client();

        if ($execution_type === 'completed') {
            $intent = $api->get_payment_intent($intent_id);

            if (is_wp_error($intent)) {
                OP_Logger::error(
                    'native_intent_verify_failed',
                    'Failed to verify completed intent: ' . $intent->get_error_message(),
                    array('order_id' => $order_id, 'intent_id' => $intent_id)
                );
                wc_add_notice(__('Unable to verify payment. Please try again.', 'orangepill-wc'), 'error');
                return array('result' => 'failure');
            }

            $status = $intent['status'] ?? '';

            if ($status === 'succeeded') {
                $order->update_meta_data('_orangepill_payment_status', 'succeeded');
                $order->update_meta_data('_orangepill_payment_confirmed_at', current_time('mysql'));
                $order->save();
                $order->payment_complete($intent_id);

                OP_Logger::info(
                    'native_payment_completed',
                    'Order #' . $order_id . ' completed via native checkout',
                    array('order_id' => $order_id, 'intent_id' => $intent_id)
                );

                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }

            OP_Logger::error(
                'native_payment_unexpected_status',
                'Intent status not succeeded after completed execution: ' . $status,
                array('order_id' => $order_id, 'intent_id' => $intent_id, 'status' => $status)
            );
            wc_add_notice(__('Payment was not completed. Please try again.', 'orangepill-wc'), 'error');
            return array('result' => 'failure');
        }

        if ($execution_type === 'processing') {
            $order->update_status(
                'on-hold',
                __('Payment submitted and awaiting confirmation from provider.', 'orangepill-wc')
            );

            OP_Logger::info(
                'native_payment_processing',
                'Order #' . $order_id . ' awaiting async confirmation',
                array('order_id' => $order_id, 'intent_id' => $intent_id)
            );

            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        }

        if ($execution_type === 'redirect') {
            // Redirect URL was stored in a transient by the AJAX execute handler
            $transient_key = 'op_exec_url_' . sanitize_key($intent_id);
            $redirect_url  = get_transient($transient_key);
            delete_transient($transient_key);

            if (empty($redirect_url)) {
                OP_Logger::error(
                    'native_redirect_url_missing',
                    'No execution redirect URL found in transient for intent',
                    array('order_id' => $order_id, 'intent_id' => $intent_id)
                );
                wc_add_notice(__('Unable to redirect to payment page. Please try again.', 'orangepill-wc'), 'error');
                return array('result' => 'failure');
            }

            $order->update_status(
                'pending',
                __('Customer redirected to complete payment.', 'orangepill-wc')
            );

            OP_Logger::info(
                'native_payment_redirect',
                'Order #' . $order_id . ' redirected to provider for payment',
                array('order_id' => $order_id, 'intent_id' => $intent_id)
            );

            return array('result' => 'success', 'redirect' => $redirect_url);
        }

        // payment_request_required or unknown
        OP_Logger::warning(
            'native_payment_unsupported_type',
            'Unsupported native execution type: ' . $execution_type,
            array('order_id' => $order_id, 'intent_id' => $intent_id, 'type' => $execution_type)
        );
        wc_add_notice(__('This payment method requires additional steps. Please choose another method.', 'orangepill-wc'), 'error');
        return array('result' => 'failure');
    }

    // ─── AJAX proxy handlers ──────────────────────────────────────────────────

    /**
     * AJAX: GET /v4/payment-options
     */
    public function ajax_get_payment_options() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        $currency = isset($_POST['currency']) ? strtoupper(sanitize_text_field($_POST['currency'])) : '';
        $amount   = isset($_POST['amount'])   ? (float) $_POST['amount'] : null;
        $country  = isset($_POST['country'])  ? strtoupper(sanitize_text_field($_POST['country'])) : null;

        if (empty($currency)) {
            wp_send_json_error(array('message' => 'currency is required'));
            return;
        }

        $params = array_filter(array(
            'currency' => $currency,
            'amount'   => $amount > 0 ? $amount : null,
            'country'  => $country ?: null,
        ), function ($v) { return $v !== null; });

        $api    = new OP_API_Client();
        $result = $api->get_payment_options($params);

        if (is_wp_error($result)) {
            OP_Logger::warning(
                'native_get_options_failed',
                'Failed to fetch payment options: ' . $result->get_error_message(),
                array('currency' => $currency)
            );
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: POST /v4/payment-intents
     */
    public function ajax_create_intent() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        $method_key = isset($_POST['method_key']) ? sanitize_text_field($_POST['method_key']) : '';
        $currency   = isset($_POST['currency'])   ? strtoupper(sanitize_text_field($_POST['currency'])) : '';
        $amount     = isset($_POST['amount'])     ? (float) $_POST['amount'] : 0.0;

        if (empty($method_key) || empty($currency) || $amount <= 0) {
            wp_send_json_error(array('message' => 'method_key, currency and amount are required'));
            return;
        }

        $settings      = get_option('woocommerce_orangepill_settings', array());
        $merchant_id   = $settings['merchant_id'] ?? '';
        $return_url    = home_url('/?wc-api=orangepill-native-return');

        $customer_id = null;
        if (is_user_logged_in()) {
            $user_id     = get_current_user_id();
            $customer_id = get_user_meta($user_id, '_orangepill_customer_id', true) ?: null;
        }

        $idempotency_key = wp_generate_password(32, false);

        $body = array(
            'amount'         => $amount,
            'currency'       => $currency,
            'productKey'     => 'checkout',
            'idempotencyKey' => $idempotency_key,
            'metadata'       => array(
                '_selectedMethodKey' => $method_key,
                'channel'            => 'woocommerce',
            ),
            'experience' => array(
                'type'      => 'two_step',
                'returnUrl' => $return_url,
            ),
        );

        if (!empty($merchant_id)) {
            $body['merchantId'] = $merchant_id;
        }
        if ($customer_id) {
            $body['customerId'] = $customer_id;
        }

        $api    = new OP_API_Client();
        $result = $api->create_payment_intent($body);

        if (is_wp_error($result)) {
            OP_Logger::error(
                'native_create_intent_failed',
                'Failed to create payment intent: ' . $result->get_error_message(),
                array('method_key' => $method_key, 'currency' => $currency)
            );
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        $intent_id = $result['id'] ?? '';
        if (empty($intent_id)) {
            wp_send_json_error(array('message' => 'API returned no intent id'));
            return;
        }

        OP_Logger::info(
            'native_intent_created',
            'Payment intent created',
            array('intent_id' => $intent_id, 'method_key' => $method_key)
        );

        wp_send_json_success(array(
            'intentId' => $intent_id,
            'status'   => $result['status'] ?? '',
        ));
    }

    /**
     * AJAX: POST /v4/payment-intents/:id/execute
     *
     * Stores redirect URL in a transient so process_payment() can use it
     * without trusting browser-submitted data.
     */
    public function ajax_execute_intent() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        $intent_id  = isset($_POST['intent_id'])  ? sanitize_text_field($_POST['intent_id'])  : '';
        $method_key = isset($_POST['method_key']) ? sanitize_text_field($_POST['method_key']) : '';

        if (empty($intent_id) || empty($method_key)) {
            wp_send_json_error(array('message' => 'intent_id and method_key are required'));
            return;
        }

        $api    = new OP_API_Client();
        $result = $api->execute_payment_intent($intent_id, array(
            'selection' => array('methodKey' => $method_key),
        ));

        if (is_wp_error($result)) {
            OP_Logger::error(
                'native_execute_intent_failed',
                'Failed to execute payment intent: ' . $result->get_error_message(),
                array('intent_id' => $intent_id, 'method_key' => $method_key)
            );
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        $execution  = $result['execution'] ?? array();
        $exec_type  = $execution['type']   ?? '';
        $exec_url   = $execution['url']    ?? '';

        // Store redirect URL server-side for secure use in process_payment()
        if ($exec_type === 'redirect' && !empty($exec_url)) {
            set_transient(
                'op_exec_url_' . sanitize_key($intent_id),
                $exec_url,
                15 * MINUTE_IN_SECONDS
            );
        }

        OP_Logger::info(
            'native_intent_executed',
            'Payment intent executed (type: ' . $exec_type . ')',
            array('intent_id' => $intent_id, 'exec_type' => $exec_type)
        );

        wp_send_json_success(array(
            'intentId'       => $result['intentId'] ?? $intent_id,
            'status'         => $result['status']   ?? '',
            'execution_type' => $exec_type,
            // Send a flag, not the URL — URL is securely stored in transient
            'has_redirect'   => $exec_type === 'redirect' && !empty($exec_url),
        ));
    }

    /**
     * AJAX: GET /v4/payment-intents/:id — for polling / status check.
     */
    public function ajax_get_intent_status() {
        check_ajax_referer('orangepill_wc_checkout', 'nonce');

        $intent_id = isset($_POST['intent_id']) ? sanitize_text_field($_POST['intent_id']) : '';

        if (empty($intent_id)) {
            wp_send_json_error(array('message' => 'intent_id is required'));
            return;
        }

        $api    = new OP_API_Client();
        $result = $api->get_payment_intent($intent_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        wp_send_json_success(array(
            'intentId' => $result['id']     ?? $intent_id,
            'status'   => $result['status'] ?? '',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @deprecated PR-WC-INTEGRATION-WEBHOOKS-1
     * Webhook URL is now handled by the global orangepill_wc_get_webhook_url() helper.
     * Session-level callback is only a fallback — see process_payment().
     */
    private function get_webhook_callback_url() {
        return orangepill_wc_get_webhook_url();
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }

        if (empty($this->get_option('api_key'))) {
            return false;
        }

        if (empty($this->get_option('integration_id'))) {
            return false;
        }

        if (empty($this->get_option('merchant_id'))) {
            return false;
        }

        return true;
    }

    /**
     * Display admin settings
     */
    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>

        <div class="orangepill-webhook-url">
            <h3><?php esc_html_e('Webhook URL', 'orangepill-wc'); ?></h3>
            <p><?php esc_html_e('Configure this webhook URL in your Orangepill dashboard:', 'orangepill-wc'); ?></p>
            <div style="display: flex; align-items: center; gap: 10px; margin: 10px 0;">
                <input
                    type="text"
                    value="<?php echo esc_url(WC()->api_request_url('orangepill-webhook')); ?>"
                    readonly
                    style="width: 500px; max-width: 100%;"
                    id="orangepill-webhook-url"
                />
                <button
                    type="button"
                    class="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('orangepill-webhook-url').value); this.textContent='Copied!';"
                >
                    <?php esc_html_e('Copy', 'orangepill-wc'); ?>
                </button>
            </div>
        </div>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
}

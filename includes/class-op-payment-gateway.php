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
        $this->has_fields         = true; // We render the wallet widget
        $this->method_title       = __('Orangepill', 'orangepill-wc');
        $this->method_description = __('Accept payments via Orangepill embedded finance platform', 'orangepill-wc');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
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
     * Render rewards wallet widget on checkout page (Part 1 — PR-WC-CHECKOUT-WALLET-UX-1)
     *
     * Outputs the widget container populated by checkout.js via AJAX. Three hidden
     * fields carry the customer's opt-in decision into process_payment():
     *   orangepill_apply_wallet  — "1" if customer checked the box
     *   orangepill_wallet_amount — full spendable balance (verbatim from API, never computed here)
     *   orangepill_wallet_id     — wallet ID so server skips a second API call
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        if (is_user_logged_in()) {
            echo '<div id="orangepill-wallet-widget" style="margin-top:12px;" data-loading="1">';
            echo '<span class="op-wallet-loading">' . esc_html__('Checking rewards balance\xe2\x80\xa6', 'orangepill-wc') . '</span>';
            echo '</div>';
            // Values written by checkout.js; read by process_payment()
            echo '<input type="hidden" name="orangepill_apply_wallet"  id="orangepill_apply_wallet"  value="0" />';
            echo '<input type="hidden" name="orangepill_wallet_amount" id="orangepill_wallet_amount" value="" />';
            echo '<input type="hidden" name="orangepill_wallet_id"     id="orangepill_wallet_id"     value="" />';
        }
    }

    /**
     * Process payment
     *
     * Flow:
     * 1. Get/create Orangepill customer (logged-in users only)
     * 2. Store channel on order meta (_orangepill_channel = 'web')
     * 3. Create checkout session via POST /v4/checkout/sessions
     * 4. Apply wallet balance if customer opted in (Part 4)
     * 5. Redirect to hosted checkout UI
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
                // Pass wallet_id from hidden field — avoids a second API call server-side
                $apply_result = $loyalty->apply_wallet_to_session($session_id, $wallet_amount, $wallet_id);

                if (is_wp_error($apply_result)) {
                    OP_Logger::warning(
                        'wallet_apply_failed',
                        'Failed to apply wallet to session (continuing without): ' . $apply_result->get_error_message(),
                        array(
                            'order_id'   => $order_id,
                            'session_id' => $session_id,
                            'amount'     => $wallet_amount,
                        )
                    );
                    // Non-fatal: continue to checkout without wallet applied
                } else {
                    $order->update_meta_data('_orangepill_wallet_applied', $wallet_amount);
                    OP_Logger::info(
                        'wallet_applied',
                        'Wallet balance applied to session',
                        array(
                            'order_id'        => $order_id,
                            'session_id'      => $session_id,
                            'amount'          => $wallet_amount,
                            'payable_amount'  => $apply_result['remaining_amount'] ?? 'unknown',
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

<?php
/**
 * Orangepill Payment Gateway
 *
 * WooCommerce payment gateway implementation for Orangepill
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
        $this->id = 'orangepill';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Orangepill', 'orangepill-wc');
        $this->method_description = __('Accept payments via Orangepill embedded finance platform', 'orangepill-wc');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize gateway settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'orangepill-wc'),
                'type' => 'checkbox',
                'label' => __('Enable Orangepill payment gateway', 'orangepill-wc'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'orangepill-wc'),
                'type' => 'text',
                'description' => __('Payment method title that customers see during checkout', 'orangepill-wc'),
                'default' => __('Orangepill', 'orangepill-wc'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'orangepill-wc'),
                'type' => 'textarea',
                'description' => __('Payment method description that customers see during checkout', 'orangepill-wc'),
                'default' => __('Pay securely via Orangepill', 'orangepill-wc'),
                'desc_tip' => true,
            ),
            'api_key' => array(
                'title' => __('API Key', 'orangepill-wc'),
                'type' => 'password',
                'description' => __('Your Orangepill integration API key', 'orangepill-wc'),
                'desc_tip' => true,
            ),
            'api_base_url' => array(
                'title' => __('API Base URL', 'orangepill-wc'),
                'type' => 'text',
                'description' => __('Orangepill API base URL (leave default for production)', 'orangepill-wc'),
                'default' => 'https://api.orangepill.dev',
                'desc_tip' => true,
            ),
            'integration_id' => array(
                'title' => __('Integration ID', 'orangepill-wc'),
                'type' => 'text',
                'description' => __('Your Orangepill integration ID', 'orangepill-wc'),
                'desc_tip' => true,
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'orangepill-wc'),
                'type' => 'text',
                'description' => __('Your Orangepill merchant ID', 'orangepill-wc'),
                'desc_tip' => true,
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'orangepill-wc'),
                'type' => 'password',
                'description' => __('Your Orangepill webhook signing secret', 'orangepill-wc'),
                'desc_tip' => true,
            ),
        );
    }

    /**
     * Process payment
     *
     * @param int $order_id Order ID
     * @return array Redirect data or error
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found', 'orangepill-wc'), 'error');
            return array('result' => 'failure');
        }

        try {
            $api = new OP_API_Client();

            // Step 1: Sync customer (get or create customer_id)
            $customer_id = null;
            $user_id = $order->get_user_id();

            if ($user_id > 0) {
                $customer_sync = new OP_Customer_Sync();
                $customer_result = $customer_sync->sync_customer($user_id);

                if (is_wp_error($customer_result)) {
                    OP_Logger::error(
                        'customer_sync_failed',
                        'Failed to sync customer: ' . $customer_result->get_error_message(),
                        array(
                            'order_id' => $order_id,
                            'user_id' => $user_id,
                        )
                    );
                    // Continue without customer_id (guest checkout)
                } else {
                    $customer_id = $customer_result;
                }
            }

            // Step 2: Create checkout session
            $session_params = array(
                'merchant_id' => $this->get_option('merchant_id'),
                'amount' => array(
                    'value' => $order->get_total(),
                    'currency' => $order->get_currency(),
                ),
                'customer_id' => $customer_id,
                'metadata' => array(
                    'channel' => 'woocommerce',
                    'woo_order_id' => (string) $order_id,
                ),
                'success_url' => $this->get_return_url($order),
                'cancel_url' => wc_get_checkout_url(),
            );

            // PR-WC-3b: Record outbound event before API send
            $event_id = OP_Sync_Journal::record_outbound_pending('checkout.session.create', $order_id, $session_params);
            $event = OP_Sync_Journal::get_event($event_id);

            // Create checkout session with idempotency key
            $session = $api->request(
                'POST',
                '/v4/payments/integrations/' . $this->get_option('integration_id') . '/sessions',
                $session_params,
                array('X-Idempotency-Key' => $event->idempotency_key)
            );

            if (is_wp_error($session)) {
                // PR-WC-3b: Mark event as failed
                OP_Sync_Journal::mark_failed($event_id, $session->get_error_message());

                OP_Logger::error(
                    'checkout_session_failed',
                    'Failed to create checkout session: ' . $session->get_error_message(),
                    array(
                        'order_id' => $order_id,
                        'event_id' => $event_id,
                    )
                );

                wc_add_notice(__('Unable to create payment session. Please try again.', 'orangepill-wc'), 'error');
                return array('result' => 'failure');
            }

            // PR-WC-3b: Mark event as sent
            OP_Sync_Journal::mark_sent($event_id, $session);

            // Step 3: Store session_id in order meta
            $order->update_meta_data('_orangepill_session_id', $session['id']);
            if ($customer_id) {
                $order->update_meta_data('_orangepill_customer_id', $customer_id);
            }
            $order->save();

            // Step 4: Log event
            OP_Logger::info(
                'checkout_session_created',
                'Checkout session created successfully',
                array(
                    'order_id' => $order_id,
                    'session_id' => $session['id'],
                    'customer_id' => $customer_id,
                    'event_id' => $event_id,
                )
            );

            // Step 5: Redirect to Orangepill hosted checkout
            return array(
                'result' => 'success',
                'redirect' => $session['checkout_url'],
            );

        } catch (Exception $e) {
            OP_Logger::error(
                'payment_processing_error',
                'Payment processing exception: ' . $e->getMessage(),
                array(
                    'order_id' => $order_id,
                )
            );

            wc_add_notice(__('Payment processing failed. Please try again.', 'orangepill-wc'), 'error');
            return array('result' => 'failure');
        }
    }

    /**
     * Check if gateway is available
     *
     * @return bool
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Check required settings
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

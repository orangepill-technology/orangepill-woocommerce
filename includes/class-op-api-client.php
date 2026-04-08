<?php
/**
 * Orangepill API Client
 *
 * Handles all HTTP communication with the Orangepill API
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_API_Client {
    /**
     * @var string API base URL
     */
    private $base_url;

    /**
     * @var string API key (integration-level)
     */
    private $api_key;

    /**
     * @var string Integration ID
     */
    private $integration_id;

    /**
     * @var string Merchant ID
     */
    private $merchant_id;

    /**
     * Constructor
     */
    public function __construct() {
        $gateway = new OP_Payment_Gateway();
        $settings = $gateway->settings;

        $this->base_url = !empty($settings['api_base_url'])
            ? rtrim($settings['api_base_url'], '/')
            : 'https://console.orangepill.cloud';

        $this->api_key = $settings['api_key'] ?? '';
        $this->integration_id = $settings['integration_id'] ?? '';
        $this->merchant_id = $settings['merchant_id'] ?? '';
    }

    /**
     * Create checkout session (SPRINT-034: Session-Based Checkout)
     *
     * @param array $params Session parameters
     * @return array|WP_Error Response data or error
     */
    public function create_checkout_session($params) {
        $endpoint = '/v4/checkout/sessions';

        $response = $this->request('POST', $endpoint, $params);

        if (is_wp_error($response)) {
            return $response;
        }

        return $response;
    }

    /**
     * Create customer
     *
     * @param array $params Customer data
     * @return array|WP_Error Response data or error
     */
    public function create_customer($params) {
        $endpoint = '/v4/admin/customers';

        $response = $this->request('POST', $endpoint, $params);

        if (is_wp_error($response)) {
            return $response;
        }

        return $response;
    }

    /**
     * Get customer wallet balances (Part 5)
     *
     * @param string $customer_id Orangepill customer ID
     * @return array|WP_Error Wallet list or error
     */
    public function get_customer_wallets($customer_id) {
        return $this->request('GET', '/v4/customers/' . rawurlencode($customer_id) . '/wallets');
    }

    /**
     * Get customer incentives / rewards history (Part 6)
     *
     * @param string $customer_id Orangepill customer ID
     * @param array  $params      Optional query params (page, per_page)
     * @return array|WP_Error Incentives list or error
     */
    public function get_customer_incentives($customer_id, $params = array()) {
        $endpoint = '/v4/customers/' . rawurlencode($customer_id) . '/incentives';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->request('GET', $endpoint);
    }

    /**
     * Apply wallet balance to a checkout session (Part 4)
     *
     * @param string $session_id    Checkout session ID
     * @param string $wallet_id     Wallet ID to apply from
     * @param string $amount        Amount to apply (string for precision)
     * @return array|WP_Error Result or error
     */
    public function apply_wallet_to_session($session_id, $wallet_id, $amount) {
        return $this->request('POST', '/v4/checkout/sessions/' . rawurlencode($session_id) . '/apply-wallet', array(
            'wallet_id' => $wallet_id,
            'amount'    => $amount,
        ));
    }

    // ─── Integration-level webhook management (PR-WC-INTEGRATION-WEBHOOKS-1) ──

    /**
     * Register a webhook on the integration (primary webhook path).
     *
     * POST /v4/commerce/integrations/{id}/webhooks
     *
     * @param string $integration_id Orangepill integration ID
     * @param array  $payload        {url, events[], secret?}
     * @return array|WP_Error Created webhook object or error
     */
    public function register_integration_webhook($integration_id, $payload) {
        return $this->request(
            'POST',
            '/v4/commerce/integrations/' . rawurlencode($integration_id) . '/webhooks',
            $payload
        );
    }

    /**
     * List webhooks registered on the integration.
     *
     * GET /v4/commerce/integrations/{id}/webhooks
     *
     * @param string $integration_id Orangepill integration ID
     * @return array|WP_Error Webhook list or error
     */
    public function list_integration_webhooks($integration_id) {
        return $this->request(
            'GET',
            '/v4/commerce/integrations/' . rawurlencode($integration_id) . '/webhooks'
        );
    }

    /**
     * Update an existing integration webhook.
     *
     * PATCH /v4/commerce/integrations/{id}/webhooks/{webhook_id}
     *
     * @param string $integration_id Orangepill integration ID
     * @param string $webhook_id     Webhook ID
     * @param array  $payload        Fields to update
     * @return array|WP_Error Updated webhook or error
     */
    public function update_integration_webhook($integration_id, $webhook_id, $payload) {
        return $this->request(
            'PATCH',
            '/v4/commerce/integrations/' . rawurlencode($integration_id) . '/webhooks/' . rawurlencode($webhook_id),
            $payload
        );
    }

    /**
     * Delete an integration webhook.
     *
     * DELETE /v4/commerce/integrations/{id}/webhooks/{webhook_id}
     *
     * @param string $integration_id Orangepill integration ID
     * @param string $webhook_id     Webhook ID
     * @return array|WP_Error Result or error
     */
    public function delete_integration_webhook($integration_id, $webhook_id) {
        return $this->request(
            'DELETE',
            '/v4/commerce/integrations/' . rawurlencode($integration_id) . '/webhooks/' . rawurlencode($webhook_id)
        );
    }

    /**
     * Validate integration
     *
     * Calls the commerce integrations validate endpoint (not payments)
     * since WooCommerce integrations are in commerce.provider_integrations table.
     *
     * @return array|WP_Error Validation result or error
     */
    public function validate_integration() {
        $endpoint = '/v4/commerce/integrations/' . $this->integration_id . '/validate';

        $response = $this->request('POST', $endpoint, array());

        if (is_wp_error($response)) {
            return $response;
        }

        return $response;
    }

    /**
     * Make HTTP request to Orangepill API
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param array $custom_headers Optional custom headers (e.g., Idempotency-Key)
     * @return array|WP_Error Response data or error
     */
    public function request($method, $endpoint, $data = array(), $custom_headers = array()) {
        if (empty($this->api_key)) {
            return new WP_Error('missing_api_key', __('API key is not configured', 'orangepill-wc'));
        }

        // Support full URLs for replay (version drift protection)
        if (preg_match('/^https?:\/\//', $endpoint)) {
            $url = $endpoint; // Already a full URL
        } else {
            $url = $this->base_url . $endpoint; // Relative path
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
            'User-Agent' => 'Orangepill-WooCommerce/' . ORANGEPILL_WC_VERSION,
        );

        // Merge custom headers (e.g., idempotency keys)
        if (!empty($custom_headers)) {
            $headers = array_merge($headers, $custom_headers);
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        // Always send JSON body for POST/PUT/PATCH requests (even if empty)
        if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            $args['body'] = wp_json_encode($data);
        } elseif (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Redact API key from error message
            $error_message = $this->redact_sensitive_data($response->get_error_message());
            return new WP_Error(
                $response->get_error_code(),
                $error_message
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        // Handle non-2xx responses
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = $this->get_error_message($decoded, $status_code);
            return new WP_Error(
                'api_error',
                $error_message,
                array('status_code' => $status_code, 'response' => $decoded)
            );
        }

        // Handle empty response
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'invalid_response',
                __('Invalid JSON response from API', 'orangepill-wc')
            );
        }

        return $decoded;
    }

    /**
     * Get user-friendly error message from API response
     *
     * @param array|null $response API response
     * @param int $status_code HTTP status code
     * @return string Error message
     */
    private function get_error_message($response, $status_code) {
        // Try to extract error message from response
        if (is_array($response)) {
            if (!empty($response['error']['message'])) {
                return $response['error']['message'];
            }
            if (!empty($response['message'])) {
                return $response['message'];
            }
        }

        // Fallback to generic messages based on status code
        switch ($status_code) {
            case 401:
                return __('Authentication failed. Please check your API key.', 'orangepill-wc');
            case 403:
                return __('Access forbidden. Please check your integration permissions.', 'orangepill-wc');
            case 404:
                return __('Resource not found. Please check your integration ID.', 'orangepill-wc');
            case 422:
                return __('Validation error. Please check the request data.', 'orangepill-wc');
            case 429:
                return __('Rate limit exceeded. Please try again later.', 'orangepill-wc');
            case 500:
            case 502:
            case 503:
            case 504:
                return __('Orangepill service temporarily unavailable. Please try again later.', 'orangepill-wc');
            default:
                return sprintf(__('API request failed with status code %d', 'orangepill-wc'), $status_code);
        }
    }

    /**
     * Redact sensitive data from strings
     *
     * @param string $text Text to redact
     * @return string Redacted text
     */
    private function redact_sensitive_data($text) {
        if (empty($this->api_key)) {
            return $text;
        }

        // Redact API key
        $text = str_replace($this->api_key, '[REDACTED]', $text);

        return $text;
    }

    /**
     * Get current settings
     *
     * @return array Settings
     */
    public function get_settings() {
        return array(
            'base_url' => $this->base_url,
            'integration_id' => $this->integration_id,
            'merchant_id' => $this->merchant_id,
            'has_api_key' => !empty($this->api_key),
        );
    }
}

<?php
/**
 * Orangepill Settings Page
 *
 * Enhanced settings page with connection test functionality
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Settings_Page {
    /**
     * Transient key for cached validation result
     */
    const VALIDATION_CACHE_KEY = 'orangepill_wc_validation_result';

    /**
     * Cache TTL (1 hour)
     */
    const VALIDATION_CACHE_TTL = 3600;

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_orangepill_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Render settings page
     */
    public function render() {
        // Check if settings were saved
        if (isset($_POST['orangepill_save_settings']) && check_admin_referer('orangepill_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully.', 'orangepill-wc') . '</p></div>';
        }

        $gateway = new OP_Payment_Gateway();
        $settings = $gateway->settings;

        // Get cached validation result
        $validation_result = get_transient(self::VALIDATION_CACHE_KEY);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Orangepill Settings', 'orangepill-wc'); ?></h1>

            <!-- Connection Status Panel -->
            <div class="orangepill-connection-status">
                <h2><?php esc_html_e('Connection Status', 'orangepill-wc'); ?></h2>
                <div class="orangepill-status-card">
                    <?php $this->render_connection_status($validation_result); ?>
                    <div style="margin-top: 15px;">
                        <button type="button" id="orangepill-test-connection" class="button button-secondary">
                            <?php esc_html_e('Test Connection', 'orangepill-wc'); ?>
                        </button>
                        <span id="orangepill-test-spinner" class="spinner" style="float: none; margin: 0 10px;"></span>
                    </div>
                </div>
            </div>

            <!-- Settings Form -->
            <form method="post" action="">
                <?php wp_nonce_field('orangepill_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php esc_html_e('API Key', 'orangepill-wc'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="api_key"
                                id="api_key"
                                value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e('Your Orangepill integration API key', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="api_base_url"><?php esc_html_e('API Base URL', 'orangepill-wc'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                name="api_base_url"
                                id="api_base_url"
                                value="<?php echo esc_attr($settings['api_base_url'] ?? 'https://api.orangepill.dev'); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e('Orangepill API base URL (leave default for production)', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="integration_id"><?php esc_html_e('Integration ID', 'orangepill-wc'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                name="integration_id"
                                id="integration_id"
                                value="<?php echo esc_attr($settings['integration_id'] ?? ''); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e('Your Orangepill integration ID', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="merchant_id"><?php esc_html_e('Merchant ID', 'orangepill-wc'); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                name="merchant_id"
                                id="merchant_id"
                                value="<?php echo esc_attr($settings['merchant_id'] ?? ''); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e('Your Orangepill merchant ID', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="webhook_secret"><?php esc_html_e('Webhook Secret', 'orangepill-wc'); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                name="webhook_secret"
                                id="webhook_secret"
                                value="<?php echo esc_attr($settings['webhook_secret'] ?? ''); ?>"
                                class="regular-text"
                            />
                            <p class="description">
                                <?php esc_html_e('Your Orangepill webhook signing secret', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Webhook URL', 'orangepill-wc'); ?>
                        </th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input
                                    type="text"
                                    value="<?php echo esc_url(WC()->api_request_url('orangepill-webhook')); ?>"
                                    readonly
                                    class="regular-text"
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
                            <p class="description">
                                <?php esc_html_e('Configure this webhook URL in your Orangepill dashboard', 'orangepill-wc'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input
                        type="submit"
                        name="orangepill_save_settings"
                        class="button button-primary"
                        value="<?php esc_attr_e('Save Settings', 'orangepill-wc'); ?>"
                    />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render connection status
     *
     * @param mixed $validation_result Cached validation result
     */
    private function render_connection_status($validation_result) {
        if (empty($validation_result)) {
            echo '<div class="orangepill-status-indicator orangepill-status-unknown">';
            echo '<span class="dashicons dashicons-minus"></span>';
            echo '<span>' . esc_html__('Connection not tested', 'orangepill-wc') . '</span>';
            echo '</div>';
            return;
        }

        if ($validation_result['success']) {
            echo '<div class="orangepill-status-indicator orangepill-status-success">';
            echo '<span class="dashicons dashicons-yes-alt"></span>';
            echo '<span>' . esc_html__('Connected', 'orangepill-wc') . '</span>';
            echo '</div>';
            echo '<p class="description">';
            echo esc_html__('Last tested: ', 'orangepill-wc');
            echo esc_html(human_time_diff($validation_result['timestamp'], current_time('timestamp'))) . ' ' . esc_html__('ago', 'orangepill-wc');
            echo '</p>';
        } else {
            echo '<div class="orangepill-status-indicator orangepill-status-error">';
            echo '<span class="dashicons dashicons-warning"></span>';
            echo '<span>' . esc_html__('Connection failed', 'orangepill-wc') . '</span>';
            echo '</div>';
            echo '<p class="description" style="color: #d63638;">';
            echo esc_html($validation_result['message']);
            echo '</p>';
        }
    }

    /**
     * Save settings
     */
    private function save_settings() {
        $gateway = new OP_Payment_Gateway();

        $settings = array(
            'api_key' => sanitize_text_field($_POST['api_key'] ?? ''),
            'api_base_url' => esc_url_raw($_POST['api_base_url'] ?? 'https://api.orangepill.dev'),
            'integration_id' => sanitize_text_field($_POST['integration_id'] ?? ''),
            'merchant_id' => sanitize_text_field($_POST['merchant_id'] ?? ''),
            'webhook_secret' => sanitize_text_field($_POST['webhook_secret'] ?? ''),
        );

        update_option('woocommerce_orangepill_settings', array_merge($gateway->settings, $settings));

        // Clear validation cache
        delete_transient(self::VALIDATION_CACHE_KEY);

        OP_Logger::info(
            'settings_updated',
            'Orangepill settings updated',
            array('has_api_key' => !empty($settings['api_key']))
        );
    }

    /**
     * AJAX handler for connection test
     */
    public function ajax_test_connection() {
        check_ajax_referer('orangepill_wc_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'orangepill-wc')));
        }

        $api = new OP_API_Client();
        $result = $api->validate_integration();

        if (is_wp_error($result)) {
            $validation_result = array(
                'success' => false,
                'message' => $result->get_error_message(),
                'timestamp' => current_time('timestamp'),
            );

            OP_Logger::error(
                'connection_test_failed',
                'Connection test failed: ' . $result->get_error_message()
            );
        } else {
            $validation_result = array(
                'success' => true,
                'message' => __('Connection successful', 'orangepill-wc'),
                'timestamp' => current_time('timestamp'),
                'data' => $result,
            );

            OP_Logger::info(
                'connection_test_success',
                'Connection test successful'
            );
        }

        // Cache result for 1 hour
        set_transient(self::VALIDATION_CACHE_KEY, $validation_result, self::VALIDATION_CACHE_TTL);

        wp_send_json_success($validation_result);
    }
}

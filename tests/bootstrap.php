<?php
/**
 * PHPUnit bootstrap file for Orangepill WooCommerce Plugin Tests
 *
 * This bootstrap file loads the plugin and sets up WordPress mocks for testing.
 */

// Autoload dependencies
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

// Define plugin constants
define('ORANGEPILL_WC_VERSION', '1.0.0');
define('ORANGEPILL_WC_PLUGIN_FILE', dirname(__DIR__) . '/orangepill-woocommerce.php');
define('ORANGEPILL_WC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('ORANGEPILL_WC_PLUGIN_URL', 'https://example.com/wp-content/plugins/orangepill-woocommerce/');
define('ORANGEPILL_WC_PLUGIN_BASENAME', 'orangepill-woocommerce/orangepill-woocommerce.php');

// Mock WordPress functions commonly used in tests
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        if ($type === 'timestamp') {
            return time();
        }
        return time();
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        return '';
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags($str);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Mock WP_Error class
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = array();
        private $error_data = array();

        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $message = isset($this->errors[$code]) ? $this->errors[$code][0] : '';
            return $message;
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            return isset($codes[0]) ? $codes[0] : '';
        }
    }
}

echo "Bootstrap loaded for Orangepill WooCommerce Plugin tests\n";

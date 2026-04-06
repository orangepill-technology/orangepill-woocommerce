<?php
/**
 * Plugin Name: Orangepill for WooCommerce
 * Plugin URI: https://github.com/orangepill-technology/orangepill-woocommerce
 * Description: Accept payments via Orangepill - embedded finance infrastructure for modern commerce platforms
 * Version: 1.0.0
 * Author: Orangepill
 * Author URI: https://orangepill.technology
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: orangepill-wc
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ORANGEPILL_WC_VERSION', '1.0.0');
define('ORANGEPILL_WC_PLUGIN_FILE', __FILE__);
define('ORANGEPILL_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ORANGEPILL_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ORANGEPILL_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    // Only autoload classes in our namespace
    $prefix = 'OP_';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    // Convert class name to file path
    $class_file = str_replace('_', '-', strtolower($class));
    $class_file = 'class-' . $class_file . '.php';

    // Check in includes directory
    $includes_file = ORANGEPILL_WC_PLUGIN_DIR . 'includes/' . $class_file;
    if (file_exists($includes_file)) {
        require_once $includes_file;
        return;
    }

    // Check in admin directory
    $admin_file = ORANGEPILL_WC_PLUGIN_DIR . 'admin/' . $class_file;
    if (file_exists($admin_file)) {
        require_once $admin_file;
        return;
    }
});

/**
 * Check if WooCommerce is active
 */
function orangepill_wc_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'orangepill_wc_missing_woocommerce_notice');
        return false;
    }
    return true;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function orangepill_wc_missing_woocommerce_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Orangepill for WooCommerce requires WooCommerce to be installed and active.', 'orangepill-wc'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function orangepill_wc_init() {
    if (!orangepill_wc_check_woocommerce()) {
        return;
    }

    // Initialize payment gateway
    add_filter('woocommerce_payment_gateways', 'orangepill_wc_add_gateway');

    // Initialize admin menu
    if (is_admin()) {
        $admin_menu = new OP_Admin_Menu();
    }

    // Initialize webhook handler (WooCommerce native routing)
    add_action('woocommerce_api_orangepill-webhook', 'orangepill_wc_handle_webhook');

    // Initialize order sync
    add_action('woocommerce_order_status_changed', 'orangepill_wc_sync_order_status', 10, 3);

    // Enqueue admin assets
    add_action('admin_enqueue_scripts', 'orangepill_wc_enqueue_admin_assets');
}
add_action('plugins_loaded', 'orangepill_wc_init', 11);

/**
 * Add Orangepill gateway to WooCommerce
 */
function orangepill_wc_add_gateway($gateways) {
    $gateways[] = 'OP_Payment_Gateway';
    return $gateways;
}

/**
 * Handle webhook requests
 */
function orangepill_wc_handle_webhook() {
    $handler = new OP_Webhook_Handler();
    $handler->handle();
    exit;
}

/**
 * Sync order status changes to Orangepill
 */
function orangepill_wc_sync_order_status($order_id, $old_status, $new_status) {
    $order = wc_get_order($order_id);

    // Only sync Orangepill orders
    if (!$order || $order->get_payment_method() !== 'orangepill') {
        return;
    }

    $sync = new OP_Order_Sync();
    $sync->sync_order_status($order, $old_status, $new_status);
}

/**
 * Enqueue admin assets
 */
function orangepill_wc_enqueue_admin_assets($hook) {
    // Only load on Orangepill admin pages
    if (strpos($hook, 'orangepill') === false && $hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }

    wp_enqueue_style(
        'orangepill-wc-admin',
        ORANGEPILL_WC_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        ORANGEPILL_WC_VERSION
    );

    wp_enqueue_script(
        'orangepill-wc-admin',
        ORANGEPILL_WC_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        ORANGEPILL_WC_VERSION,
        true
    );

    wp_localize_script('orangepill-wc-admin', 'orangepillWC', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('orangepill_wc_admin'),
    ));
}

/**
 * Plugin activation hook
 */
function orangepill_wc_activate() {
    // Create default options
    if (!get_option('orangepill_wc_sync_log')) {
        update_option('orangepill_wc_sync_log', array());
    }

    // Flush rewrite rules for webhook endpoint
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'orangepill_wc_activate');

/**
 * Plugin deactivation hook
 */
function orangepill_wc_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'orangepill_wc_deactivate');

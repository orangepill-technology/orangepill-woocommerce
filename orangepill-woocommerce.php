<?php
/**
 * Plugin Name: Orangepill for WooCommerce
 * Plugin URI: https://github.com/orangepill-technology/orangepill-woocommerce
 * Description: Accept payments via Orangepill - embedded finance infrastructure for modern commerce platforms
 * Version: 1.1.0
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
define('ORANGEPILL_WC_VERSION', '1.1.0');
define('ORANGEPILL_WC_PLUGIN_FILE', __FILE__);
define('ORANGEPILL_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ORANGEPILL_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ORANGEPILL_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Canonical webhook URL for this site.
 *
 * Single source of truth — used by OP_Integration_Webhooks (registration)
 * and OP_Payment_Gateway (session-level fallback). Never duplicate this logic.
 *
 * Respects the "Public Webhook URL" setting for ngrok / reverse-proxy environments
 * where home_url() is not publicly reachable.
 *
 * @return string Full webhook endpoint URL
 */
function orangepill_wc_get_webhook_url() {
    $settings = get_option('woocommerce_orangepill_settings', array());
    $override  = trim($settings['webhook_public_url'] ?? '');
    if (!empty($override)) {
        return rtrim($override, '/');
    }
    return home_url('/?wc-api=orangepill-webhook');
}

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

    // [PR-WC-LOYALTY-1] Check for database upgrades on every load
    orangepill_wc_check_db_version();

    // Initialize payment gateway
    add_filter('woocommerce_payment_gateways', 'orangepill_wc_add_gateway');

    // Initialize admin menu and settings (AJAX handlers need to be registered globally)
    if (is_admin()) {
        $admin_menu = new OP_Admin_Menu();
        $settings_page = new OP_Settings_Page();
    }

    // Initialize webhook handler (WooCommerce native routing)
    add_action('woocommerce_api_orangepill-webhook', 'orangepill_wc_handle_webhook');

    // Initialize order sync
    add_action('woocommerce_order_status_changed', 'orangepill_wc_sync_order_status', 10, 3);

    // [PR-WC-LOYALTY-1] Initialize refund sync for loyalty reversal triggers
    $refund_sync = new OP_Refund_Sync();
    $refund_sync->init();

    // [PR-OP-WOO-INTEGRATION-CORE-1] My Account loyalty + rewards pages
    $my_account = new OP_My_Account();
    $my_account->init();

    // Enqueue admin assets
    add_action('admin_enqueue_scripts', 'orangepill_wc_enqueue_admin_assets');

    // Enqueue frontend checkout assets
    add_action('wp_enqueue_scripts', 'orangepill_wc_enqueue_checkout_assets');

    // Admin: manual "Mark as Paid" action (reconciliation — not polling)
    add_action('admin_post_orangepill_mark_paid', 'orangepill_wc_mark_paid');

    // PR-WC-3b: Replay admin action handler
    add_action('admin_post_orangepill_replay_event', 'orangepill_wc_replay_event');

    // PR-WC-3b FIX: Dismiss admin action handler
    add_action('admin_post_orangepill_dismiss_event', 'orangepill_wc_dismiss_event');
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
        'admin_post_url' => admin_url('admin-post.php'),
        'nonce' => wp_create_nonce('orangepill_wc_admin'),
    ));
}

/**
 * Admin: manually mark an Orangepill order as paid (processing).
 *
 * Safety net for when webhooks cannot reach the server. Intended for use
 * by shop operators who have verified payment in the Orangepill dashboard.
 */
function orangepill_wc_mark_paid() {
    $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;

    if (!$order_id || !check_admin_referer('orangepill_mark_paid_' . $order_id)) {
        wp_die(__('Security check failed', 'orangepill-wc'));
    }

    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('Permission denied', 'orangepill-wc'));
    }
    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'orangepill') {
        wp_die(__('Order not found or not an Orangepill order', 'orangepill-wc'));
    }

    // Protect terminal states
    if (in_array($order->get_status(), array('completed', 'refunded', 'cancelled'), true)) {
        wp_redirect(add_query_arg('op_notice', 'already_terminal', wp_get_referer()));
        exit;
    }

    $order->update_meta_data('_orangepill_payment_status', 'succeeded');
    $order->update_meta_data('_orangepill_payment_confirmed_at', current_time('mysql'));
    $order->save();
    $order->add_order_note(__('Payment manually confirmed by admin after verifying in Orangepill dashboard.', 'orangepill-wc'));
    $order->payment_complete();

    OP_Logger::info(
        'payment_manually_confirmed',
        'Order #' . $order_id . ' manually marked as paid by admin',
        array('order_id' => $order_id, 'admin_user' => get_current_user_id())
    );

    wp_redirect(add_query_arg('op_notice', 'marked_paid', wp_get_referer()));
    exit;
}

/**
 * Enqueue frontend checkout assets (wallet widget)
 *
 * PR-OP-WOO-INTEGRATION-CORE-1 Part 4
 */
function orangepill_wc_enqueue_checkout_assets() {
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_script(
        'orangepill-wc-checkout',
        ORANGEPILL_WC_PLUGIN_URL . 'assets/js/checkout.js',
        array('jquery'),
        ORANGEPILL_WC_VERSION,
        true
    );

    wp_localize_script('orangepill-wc-checkout', 'orangepillCheckout', array(
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('orangepill_wc_checkout'),
        'cart_total'  => (string) WC()->cart->get_total('edit'),
        'currency'    => get_woocommerce_currency(),
        'i18n'        => array(
            'available_label' => __('Rewards balance available:', 'orangepill-wc'),
            'apply_label'     => __('Apply rewards balance to this purchase', 'orangepill-wc'),
            'applying_label'  => __('Applying:', 'orangepill-wc'),
            'remaining_label' => __('Estimated remaining to pay:', 'orangepill-wc'),
        ),
    ));
}

/**
 * PR-WC-3b: Replay failed sync event
 */
function orangepill_wc_replay_event() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'orangepill_wc_admin')) {
        wp_die(__('Security check failed', 'orangepill-wc'));
    }

    // Verify capability
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('Permission denied', 'orangepill-wc'));
    }

    // Get event ID
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

    if ($event_id <= 0) {
        wp_die(__('Invalid event ID', 'orangepill-wc'));
    }

    // Replay event
    $result = OP_Sync_Journal::replay($event_id);

    // Handle AJAX requests
    if (wp_doing_ajax() || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        wp_send_json($result);
    }

    // Handle regular form submissions
    $redirect_url = wp_get_referer() ?? admin_url('admin.php?page=orangepill-failed-syncs');

    if ($result['success']) {
        $redirect_url = add_query_arg('replay', 'success', $redirect_url);
    } else {
        $redirect_url = add_query_arg('replay', 'failed', $redirect_url);
        $redirect_url = add_query_arg('error', urlencode($result['error'] ?? 'Unknown error'), $redirect_url);
    }

    wp_redirect($redirect_url);
    exit;
}

/**
 * PR-WC-3b FIX: Dismiss failed sync event
 */
function orangepill_wc_dismiss_event() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'orangepill_wc_admin')) {
        wp_die(__('Security check failed', 'orangepill-wc'));
    }

    // Verify capability
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('Permission denied', 'orangepill-wc'));
    }

    // Get event ID
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

    if ($event_id <= 0) {
        wp_die(__('Invalid event ID', 'orangepill-wc'));
    }

    // Dismiss event
    OP_Sync_Journal::dismiss($event_id);

    // Handle AJAX requests
    if (wp_doing_ajax() || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        wp_send_json(array('success' => true));
    }

    // Handle regular form submissions
    $redirect_url = wp_get_referer() ?? admin_url('admin.php?page=orangepill-failed-syncs');
    $redirect_url = add_query_arg('dismissed', 'success', $redirect_url);

    wp_redirect($redirect_url);
    exit;
}

/**
 * Check database version and run migrations if needed
 *
 * [PR-WC-LOYALTY-1] This runs on every plugins_loaded to ensure upgrades apply migrations.
 * Uses option to track DB version (not plugin version, for schema independence).
 */
function orangepill_wc_check_db_version() {
    $current_db_version = 2; // Increment when schema changes
    $installed_db_version = get_option('orangepill_wc_db_version', 0);

    if ($installed_db_version < $current_db_version) {
        orangepill_wc_create_sync_events_table();
        update_option('orangepill_wc_db_version', $current_db_version);
    }
}

/**
 * Plugin activation hook
 */
function orangepill_wc_activate() {
    // Create default options
    if (!get_option('orangepill_wc_sync_log')) {
        update_option('orangepill_wc_sync_log', array());
    }

    // Create sync events table
    orangepill_wc_create_sync_events_table();

    // Set initial DB version
    if (!get_option('orangepill_wc_db_version')) {
        update_option('orangepill_wc_db_version', 2);
    }

    // Register My Account endpoints before flushing rewrite rules
    add_rewrite_endpoint(OP_My_Account::ENDPOINT_LOYALTY, EP_ROOT | EP_PAGES);
    add_rewrite_endpoint(OP_My_Account::ENDPOINT_REWARDS, EP_ROOT | EP_PAGES);

    // Flush rewrite rules (webhook endpoint + My Account endpoints)
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'orangepill_wc_activate');

/**
 * Create sync events table for durable replay
 */
function orangepill_wc_create_sync_events_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'orangepill_sync_events';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        direction VARCHAR(32) NOT NULL,
        event_type VARCHAR(128) NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        payload_json LONGTEXT NOT NULL,
        response_json LONGTEXT NULL,
        endpoint VARCHAR(255) NOT NULL DEFAULT '',
        base_url VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        idempotency_key VARCHAR(255) NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_attempt_at DATETIME NULL,
        KEY idx_status (status, created_at),
        KEY idx_order (order_id),
        KEY idx_direction (direction, status),
        KEY idx_idempotency (idempotency_key),
        UNIQUE KEY idx_dedupe (direction, event_type, idempotency_key)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // PR-WC-3b FIX: Add endpoint column if table already exists
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'endpoint'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN endpoint VARCHAR(255) NOT NULL DEFAULT '' AFTER response_json");
    }

    // PR-WC-3b FIX: Add base_url column if table already exists (environment safety)
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'base_url'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN base_url VARCHAR(255) NOT NULL DEFAULT '' AFTER endpoint");
    }

    // PR-WC-LOYALTY-1 FIX: Add UNIQUE constraint for dedupe (CRITICAL for concurrent safety)
    $index_exists = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'idx_dedupe'");
    if (empty($index_exists)) {
        // Remove old non-unique idempotency index if exists (suppress errors if not present)
        $wpdb->query("ALTER TABLE $table DROP INDEX idx_idempotency");

        // Add UNIQUE constraint on (direction, event_type, idempotency_key)
        // If this fails due to duplicate data, it will be logged
        $result = $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY idx_dedupe (direction, event_type, idempotency_key)");

        if ($result === false) {
            // Log critical error if UNIQUE constraint fails (likely due to existing duplicates)
            error_log('CRITICAL: Orangepill UNIQUE constraint failed to apply. Check for duplicate events in wp_orangepill_sync_events table.');
            error_log('SQL Error: ' . $wpdb->last_error);
        }
    }
}

/**
 * Plugin deactivation hook
 */
function orangepill_wc_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'orangepill_wc_deactivate');

<?php
/**
 * Orangepill Admin Menu
 *
 * Registers admin menu pages under WooCommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Admin_Menu {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
    }

    /**
     * Register admin menu pages
     */
    public function register_menu() {
        // Add overview page (default)
        add_submenu_page(
            'woocommerce',
            __('Orangepill Overview', 'orangepill-wc'),
            __('Orangepill', 'orangepill-wc'),
            'manage_woocommerce',
            'orangepill-overview',
            array($this, 'render_overview_page')
        );

        // Add settings page
        add_submenu_page(
            'woocommerce',
            __('Orangepill Settings', 'orangepill-wc'),
            __('Orangepill Settings', 'orangepill-wc'),
            'manage_woocommerce',
            'orangepill-settings',
            array($this, 'render_settings_page')
        );

        // Add sync log page
        add_submenu_page(
            'woocommerce',
            __('Orangepill Sync Log', 'orangepill-wc'),
            __('Orangepill Sync Log', 'orangepill-wc'),
            'manage_woocommerce',
            'orangepill-sync-log',
            array($this, 'render_sync_log_page')
        );

        // PR-WC-3b: Add failed syncs page
        add_submenu_page(
            'woocommerce',
            __('Orangepill Failed Syncs', 'orangepill-wc'),
            __('Orangepill Failed Syncs', 'orangepill-wc'),
            'manage_woocommerce',
            'orangepill-failed-syncs',
            array($this, 'render_failed_syncs_page')
        );

        // Add order metabox
        add_action('add_meta_boxes', array($this, 'register_order_metabox'));
    }

    /**
     * Render overview page
     */
    public function render_overview_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'orangepill-wc'));
        }

        $page = new OP_Overview_Page();
        $page->render();
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'orangepill-wc'));
        }

        $page = new OP_Settings_Page();
        $page->render();
    }

    /**
     * Render sync log page
     */
    public function render_sync_log_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'orangepill-wc'));
        }

        $page = new OP_Sync_Log_Page();
        $page->render();
    }

    /**
     * PR-WC-3b: Render failed syncs page
     */
    public function render_failed_syncs_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'orangepill-wc'));
        }

        $page = new OP_Failed_Syncs_Page();
        $page->render();
    }

    /**
     * Register order metabox
     */
    public function register_order_metabox() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'orangepill_order_metabox',
            __('Orangepill Payment Details', 'orangepill-wc'),
            array($this, 'render_order_metabox'),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render order metabox
     *
     * @param WP_Post|WC_Order $post_or_order Post or order object
     */
    public function render_order_metabox($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            return;
        }

        // Only show for Orangepill orders
        if ($order->get_payment_method() !== 'orangepill') {
            echo '<p>' . esc_html__('This is not an Orangepill order.', 'orangepill-wc') . '</p>';
            return;
        }

        $metabox = new OP_Order_Metabox();
        $metabox->render($order);
    }
}

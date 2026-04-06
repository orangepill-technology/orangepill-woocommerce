<?php
/**
 * Orangepill Order Metabox
 *
 * Displays Orangepill payment metadata on order edit screen
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Order_Metabox {
    /**
     * Render metabox content
     *
     * @param WC_Order $order Order object
     */
    public function render($order) {
        if (!$order) {
            return;
        }

        // Get Orangepill metadata
        $session_id = $order->get_meta('_orangepill_session_id');
        $payment_id = $order->get_meta('_orangepill_payment_id');
        $customer_id = $order->get_meta('_orangepill_customer_id');
        $payment_status = $order->get_meta('_orangepill_payment_status');
        $last_sync = $order->get_meta('_orangepill_last_sync_at');
        $payment_confirmed_at = $order->get_meta('_orangepill_payment_confirmed_at');
        $payment_failed_at = $order->get_meta('_orangepill_payment_failed_at');
        $failure_reason = $order->get_meta('_orangepill_failure_reason');

        ?>
        <div class="orangepill-metabox">
            <?php if (!empty($session_id)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Session ID:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <code><?php echo esc_html($session_id); ?></code>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($payment_id)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Payment ID:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <code><?php echo esc_html($payment_id); ?></code>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($customer_id)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Customer ID:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <code><?php echo esc_html($customer_id); ?></code>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($payment_status)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Payment Status:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <span class="orangepill-payment-status orangepill-payment-status-<?php echo esc_attr($payment_status); ?>">
                            <?php echo esc_html(ucfirst($payment_status)); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($payment_confirmed_at)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Payment Confirmed:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment_confirmed_at))); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($payment_failed_at)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Payment Failed:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($payment_failed_at))); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($failure_reason)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Failure Reason:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <?php echo esc_html($failure_reason); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($last_sync)): ?>
                <div class="orangepill-metabox-field">
                    <label><?php esc_html_e('Last Sync:', 'orangepill-wc'); ?></label>
                    <div class="orangepill-metabox-value">
                        <?php echo esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))); ?>
                        <?php esc_html_e('ago', 'orangepill-wc'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($session_id) && empty($payment_id)): ?>
                <p class="description">
                    <?php esc_html_e('No Orangepill payment data available for this order.', 'orangepill-wc'); ?>
                </p>
            <?php endif; ?>
        </div>

        <style>
            .orangepill-metabox-field {
                margin-bottom: 12px;
            }
            .orangepill-metabox-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
            }
            .orangepill-metabox-value code {
                display: inline-block;
                padding: 2px 6px;
                background: #f0f0f1;
                border-radius: 3px;
                font-size: 12px;
                word-break: break-all;
            }
            .orangepill-payment-status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .orangepill-payment-status-succeeded {
                background: #d4edda;
                color: #155724;
            }
            .orangepill-payment-status-failed {
                background: #f8d7da;
                color: #721c24;
            }
            .orangepill-payment-status-pending {
                background: #fff3cd;
                color: #856404;
            }
        </style>
        <?php
    }
}

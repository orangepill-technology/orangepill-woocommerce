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

        // PR-WC-3b: Get sync health from journal
        $order_id = $order->get_id();
        $last_outbound = OP_Sync_Journal::get_last_event_for_order($order_id, 'woo_to_op');
        $last_inbound = OP_Sync_Journal::get_last_event_for_order($order_id, 'op_to_woo');
        $last_failed = OP_Sync_Journal::get_last_failed($order_id);

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

            <?php // PR-WC-3b: Sync Health Section ?>
            <?php if ($last_outbound || $last_inbound || $last_failed): ?>
                <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
                <h4 style="margin: 10px 0;"><?php esc_html_e('Sync Health', 'orangepill-wc'); ?></h4>

                <?php if ($last_outbound): ?>
                    <div class="orangepill-metabox-field">
                        <label><?php esc_html_e('Last Outbound Sync:', 'orangepill-wc'); ?></label>
                        <div class="orangepill-metabox-value">
                            <span class="orangepill-sync-status orangepill-sync-status-<?php echo esc_attr($last_outbound->status); ?>">
                                <?php echo esc_html(ucfirst($last_outbound->status)); ?>
                            </span>
                            <br>
                            <small class="description">
                                <?php echo esc_html($last_outbound->event_type); ?>
                                &middot;
                                <?php echo esc_html(human_time_diff(strtotime($last_outbound->created_at), current_time('timestamp'))); ?>
                                <?php esc_html_e('ago', 'orangepill-wc'); ?>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($last_inbound): ?>
                    <div class="orangepill-metabox-field">
                        <label><?php esc_html_e('Last Inbound Webhook:', 'orangepill-wc'); ?></label>
                        <div class="orangepill-metabox-value">
                            <span class="orangepill-sync-status orangepill-sync-status-<?php echo esc_attr($last_inbound->status); ?>">
                                <?php echo esc_html(ucfirst($last_inbound->status)); ?>
                            </span>
                            <br>
                            <small class="description">
                                <?php echo esc_html($last_inbound->event_type); ?>
                                &middot;
                                <?php echo esc_html(human_time_diff(strtotime($last_inbound->created_at), current_time('timestamp'))); ?>
                                <?php esc_html_e('ago', 'orangepill-wc'); ?>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($last_failed): ?>
                    <div class="orangepill-metabox-field">
                        <div class="notice notice-error inline" style="margin: 10px 0; padding: 8px 12px;">
                            <p style="margin: 0;">
                                <strong><?php esc_html_e('Failed Sync Detected', 'orangepill-wc'); ?></strong><br>
                                <?php echo esc_html($last_failed->last_error); ?>
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                                <strong><?php esc_html_e('Attempts:', 'orangepill-wc'); ?></strong> <?php echo esc_html($last_failed->attempt_count); ?>
                                <?php if ($last_failed->last_attempt_at): ?>
                                    &nbsp;|&nbsp;
                                    <strong><?php esc_html_e('Last attempt:', 'orangepill-wc'); ?></strong>
                                    <?php echo esc_html(human_time_diff(strtotime($last_failed->last_attempt_at), current_time('timestamp'))); ?>
                                    <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                <?php endif; ?>
                            </p>
                            <p style="margin: 8px 0 0 0;">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                    <input type="hidden" name="action" value="orangepill_replay_event" />
                                    <input type="hidden" name="event_id" value="<?php echo esc_attr($last_failed->id); ?>" />
                                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('orangepill_wc_admin')); ?>" />
                                    <button
                                        type="submit"
                                        class="button button-primary button-small"
                                        onclick="return confirm('<?php echo esc_js(__('Replay this event?\n\nThis will re-send the original request to Orangepill using the same data and idempotency key.\n\nIdempotency protection ensures safe replay (no duplicate charges or side effects).', 'orangepill-wc')); ?>');"
                                    >
                                        <?php esc_html_e('Replay Failed Sync', 'orangepill-wc'); ?>
                                    </button>
                                </form>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=orangepill-failed-syncs')); ?>" class="button button-small">
                                    <?php esc_html_e('View All Failed Syncs', 'orangepill-wc'); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php // PR-WC-LOYALTY-1: Loyalty Activity Section ?>
            <?php
            $last_earn = OP_Sync_Journal::get_last_event_for_order_by_type($order_id, 'woo_to_op', 'order.finalized');
            $refund_events = OP_Sync_Journal::get_events_for_order($order_id, 'woo_to_op', 'order.refunded');
            ?>

            <?php if ($last_earn || !empty($refund_events)): ?>
                <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
                <h4 style="margin: 10px 0;"><?php esc_html_e('Loyalty Activity', 'orangepill-wc'); ?></h4>

                <?php // Loyalty Earn (single event) ?>
                <?php if ($last_earn): ?>
                    <div class="orangepill-metabox-field">
                        <label><?php esc_html_e('Loyalty Earn:', 'orangepill-wc'); ?></label>
                        <div class="orangepill-metabox-value">
                            <?php
                            $earn_icon = $last_earn->status === 'sent' ? '✅' : ($last_earn->status === 'failed' ? '❌' : '⏳');
                            ?>
                            <span class="orangepill-sync-status orangepill-sync-status-<?php echo esc_attr($last_earn->status); ?>">
                                <?php echo esc_html($earn_icon . ' ' . ucfirst($last_earn->status)); ?>
                            </span>
                            <br>
                            <small class="description">
                                <?php esc_html_e('Event:', 'orangepill-wc'); ?> <?php echo esc_html($last_earn->event_type); ?>
                                &middot;
                                <?php echo esc_html(human_time_diff(strtotime($last_earn->created_at), current_time('timestamp'))); ?>
                                <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                <?php if ($last_earn->idempotency_key): ?>
                                    <br>
                                    <?php esc_html_e('Idempotency Key:', 'orangepill-wc'); ?> <code><?php echo esc_html($last_earn->idempotency_key); ?></code>
                                <?php endif; ?>
                            </small>

                            <?php if ($last_earn->status === 'failed'): ?>
                                <div style="margin-top: 8px; padding: 8px; background: #f8d7da; border-radius: 3px;">
                                    <strong><?php esc_html_e('Error:', 'orangepill-wc'); ?></strong>
                                    <?php echo esc_html($last_earn->last_error); ?>
                                    <br>
                                    <small>
                                        <?php esc_html_e('Attempts:', 'orangepill-wc'); ?> <?php echo esc_html($last_earn->attempt_count); ?>
                                        <?php if ($last_earn->last_attempt_at): ?>
                                            &nbsp;|&nbsp;
                                            <?php esc_html_e('Last attempt:', 'orangepill-wc'); ?>
                                            <?php echo esc_html(human_time_diff(strtotime($last_earn->last_attempt_at), current_time('timestamp'))); ?>
                                            <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                        <?php endif; ?>
                                    </small>
                                    <div style="margin-top: 8px;">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="orangepill_replay_event" />
                                            <input type="hidden" name="event_id" value="<?php echo esc_attr($last_earn->id); ?>" />
                                            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('orangepill_wc_admin')); ?>" />
                                            <button
                                                type="submit"
                                                class="button button-primary button-small"
                                                onclick="return confirm('<?php echo esc_js(__('Replay loyalty earn event?\n\nThis will re-send the order.finalized event with the same idempotency key.\n\nIdempotency protection ensures safe replay.', 'orangepill-wc')); ?>');"
                                            >
                                                <?php esc_html_e('Replay Event', 'orangepill-wc'); ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php // Loyalty Reversals (multiple events) - RULE 13 ?>
                <?php if (!empty($refund_events)): ?>
                    <?php
                    $refund_count = count($refund_events);
                    $failed_refunds = array_filter($refund_events, function($e) { return $e->status === 'failed'; });
                    $failed_count = count($failed_refunds);
                    ?>
                    <div class="orangepill-metabox-field">
                        <label>
                            <?php esc_html_e('Loyalty Reversals:', 'orangepill-wc'); ?>
                            <span style="font-weight: normal; color: #666;">
                                (<?php echo esc_html($refund_count); ?> <?php echo esc_html(_n('event', 'events', $refund_count, 'orangepill-wc')); ?>
                                <?php if ($failed_count > 0): ?>
                                    &middot; <span style="color: #721c24; font-weight: 600;"><?php echo esc_html($failed_count); ?> failed</span>
                                <?php endif; ?>)
                            </span>
                        </label>
                        <div class="orangepill-metabox-value">
                            <?php foreach ($refund_events as $idx => $refund_event): ?>
                                <?php
                                $refund_icon = $refund_event->status === 'sent' ? '✅' : ($refund_event->status === 'failed' ? '❌' : '⏳');
                                $payload = json_decode($refund_event->payload_json, true);
                                $refund_id = $payload['refund_id'] ?? '?';
                                $refund_amount = $payload['refund_amount'] ?? '?';
                                $currency = $payload['currency'] ?? '';
                                ?>
                                <div style="margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-left: 3px solid <?php echo $refund_event->status === 'sent' ? '#155724' : ($refund_event->status === 'failed' ? '#721c24' : '#856404'); ?>; border-radius: 3px;">
                                    <div>
                                        <span class="orangepill-sync-status orangepill-sync-status-<?php echo esc_attr($refund_event->status); ?>" style="font-size: 11px;">
                                            <?php echo esc_html($refund_icon . ' ' . ucfirst($refund_event->status)); ?>
                                        </span>
                                        <strong style="margin-left: 8px;">
                                            <?php esc_html_e('Refund', 'orangepill-wc'); ?> #<?php echo esc_html($refund_id); ?>
                                        </strong>
                                        <span style="margin-left: 8px; color: #666;">
                                            <?php echo esc_html($refund_amount . ' ' . $currency); ?>
                                        </span>
                                    </div>
                                    <small class="description" style="display: block; margin-top: 4px;">
                                        <?php echo esc_html(human_time_diff(strtotime($refund_event->created_at), current_time('timestamp'))); ?>
                                        <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                        <?php if ($refund_event->idempotency_key): ?>
                                            <br>
                                            <?php esc_html_e('Idempotency Key:', 'orangepill-wc'); ?> <code style="font-size: 10px;"><?php echo esc_html($refund_event->idempotency_key); ?></code>
                                        <?php endif; ?>
                                    </small>

                                    <?php if ($refund_event->status === 'failed'): ?>
                                        <div style="margin-top: 6px; padding: 6px; background: #f8d7da; border-radius: 3px;">
                                            <strong style="font-size: 11px;"><?php esc_html_e('Error:', 'orangepill-wc'); ?></strong>
                                            <span style="font-size: 11px;"><?php echo esc_html($refund_event->last_error); ?></span>
                                            <br>
                                            <small style="font-size: 10px;">
                                                <?php esc_html_e('Attempts:', 'orangepill-wc'); ?> <?php echo esc_html($refund_event->attempt_count); ?>
                                                <?php if ($refund_event->last_attempt_at): ?>
                                                    &nbsp;|&nbsp;
                                                    <?php esc_html_e('Last attempt:', 'orangepill-wc'); ?>
                                                    <?php echo esc_html(human_time_diff(strtotime($refund_event->last_attempt_at), current_time('timestamp'))); ?>
                                                    <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                                <?php endif; ?>
                                            </small>
                                            <div style="margin-top: 6px;">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                                    <input type="hidden" name="action" value="orangepill_replay_event" />
                                                    <input type="hidden" name="event_id" value="<?php echo esc_attr($refund_event->id); ?>" />
                                                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('orangepill_wc_admin')); ?>" />
                                                    <button
                                                        type="submit"
                                                        class="button button-primary button-small"
                                                        style="font-size: 11px; padding: 2px 8px; height: auto;"
                                                        onclick="return confirm('<?php echo esc_js(__('Replay loyalty reversal event?\n\nThis will re-send the order.refunded event with the same idempotency key.\n\nIdempotency protection ensures safe replay.', 'orangepill-wc')); ?>');"
                                                    >
                                                        <?php esc_html_e('Replay', 'orangepill-wc'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($session_id) && empty($payment_id)): ?>
                <p class="description">
                    <?php esc_html_e('No Orangepill payment data available for this order.', 'orangepill-wc'); ?>
                </p>
            <?php endif; ?>

            <?php
            // Manual "Mark as Paid" — for when webhooks can't reach the server.
            $current_status = $order->get_status();
            $can_mark_paid  = in_array($current_status, array('pending', 'on-hold'), true);
            if (!empty($session_id) && $can_mark_paid):
            ?>
            <hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">
            <div class="orangepill-metabox-field">
                <p class="description" style="margin-bottom: 8px;">
                    <?php esc_html_e('Webhooks not received? Verify payment in Orangepill dashboard then confirm manually.', 'orangepill-wc'); ?>
                </p>
                <?php
                // Use a GET link — metabox is inside WP's outer <form>, nested forms are invalid HTML
                // and cause the browser to submit the outer form instead of ours.
                $mark_paid_url = wp_nonce_url(
                    admin_url('admin-post.php?action=orangepill_mark_paid&order_id=' . $order->get_id()),
                    'orangepill_mark_paid_' . $order->get_id()
                );
                ?>
                <a
                    href="<?php echo esc_url($mark_paid_url); ?>"
                    class="button button-primary button-small"
                    onclick="return confirm('<?php echo esc_js(__('Mark this order as paid?\n\nOnly do this after verifying payment in the Orangepill dashboard.', 'orangepill-wc')); ?>');"
                >
                    <?php esc_html_e('Mark as Paid', 'orangepill-wc'); ?>
                </a>
            </div>
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
            /* PR-WC-3b: Sync status indicators */
            .orangepill-sync-status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .orangepill-sync-status-sent {
                background: #d4edda;
                color: #155724;
            }
            .orangepill-sync-status-failed {
                background: #f8d7da;
                color: #721c24;
            }
            .orangepill-sync-status-pending {
                background: #fff3cd;
                color: #856404;
            }
            .orangepill-sync-status-received,
            .orangepill-sync-status-processed {
                background: #d1ecf1;
                color: #0c5460;
            }
        </style>
        <?php
    }
}

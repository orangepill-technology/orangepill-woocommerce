<?php
/**
 * Orangepill Overview Page
 *
 * Dashboard with status cards and recent activity
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Overview_Page {
    /**
     * Render overview page
     */
    public function render() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Orangepill Overview', 'orangepill-wc'); ?></h1>

            <!-- Status Cards -->
            <div class="orangepill-status-cards">
                <?php $this->render_connection_status_card(); ?>
                <?php $this->render_recent_payments_card(); ?>
                <?php $this->render_pending_orders_card(); ?>
                <?php $this->render_sync_errors_card(); ?>
            </div>

            <!-- Recent Activity -->
            <div class="orangepill-recent-activity">
                <h2><?php esc_html_e('Recent Activity', 'orangepill-wc'); ?></h2>
                <?php $this->render_recent_activity(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render connection status card
     */
    private function render_connection_status_card() {
        $validation_result = get_transient('orangepill_wc_validation_result');
        $is_connected = !empty($validation_result) && $validation_result['success'];

        ?>
        <div class="orangepill-status-card">
            <div class="orangepill-card-header">
                <h3><?php esc_html_e('Connection Status', 'orangepill-wc'); ?></h3>
            </div>
            <div class="orangepill-card-body">
                <?php if ($is_connected): ?>
                    <div class="orangepill-status-indicator orangepill-status-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php esc_html_e('Connected', 'orangepill-wc'); ?></span>
                    </div>
                <?php else: ?>
                    <div class="orangepill-status-indicator orangepill-status-error">
                        <span class="dashicons dashicons-warning"></span>
                        <span><?php esc_html_e('Not Connected', 'orangepill-wc'); ?></span>
                    </div>
                    <p class="description">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=orangepill-settings')); ?>">
                            <?php esc_html_e('Configure settings', 'orangepill-wc'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent payments card
     *
     * Source: WooCommerce order data (NOT derived state)
     * Queries orders with payment_method='orangepill' created in last 24h
     */
    private function render_recent_payments_card() {
        $date_from = date('Y-m-d H:i:s', strtotime('-24 hours'));

        // Query WooCommerce orders (source of truth)
        $succeeded_orders = wc_get_orders(array(
            'limit' => -1,
            'payment_method' => 'orangepill',
            'status' => 'processing',
            'date_created' => '>' . $date_from,
        ));

        $failed_orders = wc_get_orders(array(
            'limit' => -1,
            'payment_method' => 'orangepill',
            'status' => 'failed',
            'date_created' => '>' . $date_from,
        ));

        $succeeded_count = count($succeeded_orders);
        $failed_count = count($failed_orders);

        ?>
        <div class="orangepill-status-card">
            <div class="orangepill-card-header">
                <h3><?php esc_html_e('Recent Payments (24h)', 'orangepill-wc'); ?></h3>
            </div>
            <div class="orangepill-card-body">
                <?php if ($succeeded_count > 0 || $failed_count > 0): ?>
                    <div class="orangepill-payment-stats">
                        <div class="orangepill-stat-item orangepill-stat-success">
                            <span class="orangepill-stat-value"><?php echo esc_html($succeeded_count); ?></span>
                            <span class="orangepill-stat-label"><?php esc_html_e('Succeeded', 'orangepill-wc'); ?></span>
                        </div>
                        <div class="orangepill-stat-item orangepill-stat-error">
                            <span class="orangepill-stat-value"><?php echo esc_html($failed_count); ?></span>
                            <span class="orangepill-stat-label"><?php esc_html_e('Failed', 'orangepill-wc'); ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="orangepill-no-data"><?php esc_html_e('No payments in the last 24 hours', 'orangepill-wc'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render pending orders card
     */
    private function render_pending_orders_card() {
        $pending_orders = wc_get_orders(array(
            'limit' => -1,
            'payment_method' => 'orangepill',
            'status' => 'pending',
        ));

        $count = count($pending_orders);

        ?>
        <div class="orangepill-status-card">
            <div class="orangepill-card-header">
                <h3><?php esc_html_e('Pending Orders', 'orangepill-wc'); ?></h3>
            </div>
            <div class="orangepill-card-body">
                <div class="orangepill-stat-value-large">
                    <?php echo esc_html($count); ?>
                </div>
                <?php if ($count > 0): ?>
                    <p class="description">
                        <a href="<?php echo esc_url(admin_url('edit.php?post_status=wc-pending&post_type=shop_order')); ?>">
                            <?php esc_html_e('View pending orders', 'orangepill-wc'); ?>
                        </a>
                    </p>
                <?php else: ?>
                    <p class="orangepill-no-data"><?php esc_html_e('No pending orders', 'orangepill-wc'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render sync errors card
     *
     * Source: OP_Logger events (last 50 entries filtered by level=error, last 24h)
     */
    private function render_sync_errors_card() {
        $error_count = OP_Logger::get_recent_error_count();

        ?>
        <div class="orangepill-status-card">
            <div class="orangepill-card-header">
                <h3><?php esc_html_e('Sync Errors (24h)', 'orangepill-wc'); ?></h3>
            </div>
            <div class="orangepill-card-body">
                <div class="orangepill-stat-value-large <?php echo $error_count > 0 ? 'orangepill-stat-error' : ''; ?>">
                    <?php echo esc_html($error_count); ?>
                </div>
                <?php if ($error_count > 0): ?>
                    <p class="description">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=orangepill-sync-log&level=error')); ?>">
                            <?php esc_html_e('View error log', 'orangepill-wc'); ?>
                        </a>
                    </p>
                <?php else: ?>
                    <p class="orangepill-no-data"><?php esc_html_e('No errors', 'orangepill-wc'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent activity table
     */
    private function render_recent_activity() {
        $logs = OP_Logger::get_logs();
        $recent_logs = array_slice($logs, 0, 20);

        if (empty($recent_logs)) {
            echo '<p class="orangepill-no-data">' . esc_html__('No events yet', 'orangepill-wc') . '</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped orangepill-activity-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Time', 'orangepill-wc'); ?></th>
                    <th><?php esc_html_e('Level', 'orangepill-wc'); ?></th>
                    <th><?php esc_html_e('Event', 'orangepill-wc'); ?></th>
                    <th><?php esc_html_e('Message', 'orangepill-wc'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $log): ?>
                    <tr>
                        <td>
                            <?php echo esc_html(human_time_diff(strtotime($log['timestamp']), current_time('timestamp'))); ?>
                            <?php esc_html_e('ago', 'orangepill-wc'); ?>
                        </td>
                        <td>
                            <span class="orangepill-log-level orangepill-log-level-<?php echo esc_attr($log['level']); ?>">
                                <?php echo esc_html(ucfirst($log['level'])); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log['event']); ?></td>
                        <td><?php echo esc_html($log['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top: 15px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=orangepill-sync-log')); ?>" class="button">
                <?php esc_html_e('View Full Log', 'orangepill-wc'); ?>
            </a>
        </p>
        <?php
    }
}

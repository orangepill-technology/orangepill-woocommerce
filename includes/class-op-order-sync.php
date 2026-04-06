<?php
/**
 * Orangepill Order Sync
 *
 * Handles order status synchronization (informational logging only)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Order_Sync {
    /**
     * Status mapping from WooCommerce to Orangepill
     *
     * @var array
     */
    private $status_map = array(
        'pending' => 'pending',
        'processing' => 'confirmed',
        'on-hold' => 'on_hold',
        'completed' => 'fulfilled',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
        'failed' => 'failed',
    );

    /**
     * Sync order status to Orangepill
     *
     * Note: This is informational logging only. No dedicated API endpoint
     * exists for updating order status in Orangepill.
     *
     * @param WC_Order $order Order object
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function sync_order_status($order, $old_status, $new_status) {
        if (!$order) {
            return;
        }

        // Skip if not an Orangepill order
        $session_id = $order->get_meta('_orangepill_session_id');
        if (empty($session_id)) {
            return;
        }

        // Map WooCommerce status to Orangepill status
        $mapped_old_status = $this->status_map[$old_status] ?? $old_status;
        $mapped_new_status = $this->status_map[$new_status] ?? $new_status;

        // Prevent downgrading terminal states
        if ($this->is_terminal_status($old_status) && !$this->is_terminal_status($new_status)) {
            OP_Logger::warning(
                'order_status_downgrade_prevented',
                sprintf(
                    'Prevented downgrade of order #%d from %s to %s',
                    $order->get_id(),
                    $old_status,
                    $new_status
                ),
                array(
                    'order_id' => $order->get_id(),
                    'old_status' => $old_status,
                    'new_status' => $new_status,
                )
            );
            return;
        }

        // Log status change
        OP_Logger::info(
            'order_status_changed',
            sprintf(
                'Order #%d status changed from %s to %s',
                $order->get_id(),
                $old_status,
                $new_status
            ),
            array(
                'order_id' => $order->get_id(),
                'old_status' => $old_status,
                'new_status' => $new_status,
                'mapped_old_status' => $mapped_old_status,
                'mapped_new_status' => $mapped_new_status,
                'session_id' => $session_id,
                'payment_id' => $order->get_meta('_orangepill_payment_id'),
            )
        );

        // Update last sync timestamp
        $order->update_meta_data('_orangepill_last_sync_at', current_time('mysql'));
        $order->save();
    }

    /**
     * Check if status is terminal (should not be downgraded)
     *
     * @param string $status Status
     * @return bool Is terminal
     */
    private function is_terminal_status($status) {
        $terminal_statuses = array('completed', 'cancelled', 'refunded', 'failed');
        return in_array($status, $terminal_statuses, true);
    }

    /**
     * Get mapped status
     *
     * @param string $wc_status WooCommerce status
     * @return string Mapped status
     */
    public function get_mapped_status($wc_status) {
        return $this->status_map[$wc_status] ?? $wc_status;
    }

    /**
     * Get all status mappings
     *
     * @return array Status map
     */
    public function get_status_map() {
        return $this->status_map;
    }
}

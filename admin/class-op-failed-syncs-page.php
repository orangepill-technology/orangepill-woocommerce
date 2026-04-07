<?php
/**
 * Orangepill Failed Syncs Page (PR-WC-3b)
 *
 * Displays failed outbound sync events with manual replay
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Failed_Syncs_Page {
    /**
     * Entries per page
     */
    const PER_PAGE = 20;

    /**
     * Render failed syncs page
     */
    public function render() {
        // Handle replay notices
        $this->render_notices();

        // PR-WC-3b FIX: Get failed events with time filter (7 days by default, or all if requested)
        $show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';
        $days = $show_all ? 0 : 7;
        $all_events = OP_Sync_Journal::get_failed_events(200, $days);

        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_events = count($all_events);
        $total_pages = ceil($total_events / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;
        $events = array_slice($all_events, $offset, self::PER_PAGE);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Orangepill Failed Syncs', 'orangepill-wc'); ?></h1>

            <p class="description">
                <?php
                if ($show_all) {
                    esc_html_e('Showing all failed outbound sync events (Woo → Orangepill). Plugin is source of truth.', 'orangepill-wc');
                    echo ' <a href="' . esc_url(admin_url('admin.php?page=orangepill-failed-syncs')) . '">' . esc_html__('Show last 7 days only', 'orangepill-wc') . '</a>';
                } else {
                    esc_html_e('Showing failed outbound sync events from last 7 days (Woo → Orangepill). Plugin is source of truth.', 'orangepill-wc');
                    echo ' <a href="' . esc_url(admin_url('admin.php?page=orangepill-failed-syncs&show_all=1')) . '">' . esc_html__('Show all time', 'orangepill-wc') . '</a>';
                }
                ?>
            </p>

            <!-- Events Table -->
            <?php if (empty($events)): ?>
                <div class="notice notice-success inline">
                    <p><?php esc_html_e('No failed sync events. All syncs succeeded!', 'orangepill-wc'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped orangepill-failed-syncs-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;"><?php esc_html_e('ID', 'orangepill-wc'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('Time', 'orangepill-wc'); ?></th>
                            <th style="width: 180px;"><?php esc_html_e('Event Type', 'orangepill-wc'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Order', 'orangepill-wc'); ?></th>
                            <th><?php esc_html_e('Error', 'orangepill-wc'); ?></th>
                            <th style="width: 80px;"><?php esc_html_e('Attempts', 'orangepill-wc'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('Actions', 'orangepill-wc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo esc_html($event->id); ?></td>
                                <td>
                                    <span title="<?php echo esc_attr($event->created_at); ?>">
                                        <?php echo esc_html(human_time_diff(strtotime($event->created_at), current_time('timestamp'))); ?>
                                        <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                    </span>
                                    <?php if ($event->last_attempt_at): ?>
                                        <br><small class="description">
                                            <?php esc_html_e('Last attempt:', 'orangepill-wc'); ?>
                                            <?php echo esc_html(human_time_diff(strtotime($event->last_attempt_at), current_time('timestamp'))); ?>
                                            <?php esc_html_e('ago', 'orangepill-wc'); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($event->event_type); ?></strong>
                                </td>
                                <td>
                                    <?php if ($event->order_id): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $event->order_id . '&action=edit')); ?>">
                                            #<?php echo esc_html($event->order_id); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="description"><?php esc_html_e('N/A', 'orangepill-wc'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="orangepill-error-text">
                                        <?php echo esc_html($event->last_error ?? __('Unknown error', 'orangepill-wc')); ?>
                                    </span>
                                    <br>
                                    <button
                                        type="button"
                                        class="button button-small orangepill-toggle-details"
                                        data-target="orangepill-payload-<?php echo esc_attr($event->id); ?>"
                                    >
                                        <?php esc_html_e('View Payload', 'orangepill-wc'); ?>
                                    </button>
                                </td>
                                <td>
                                    <span class="orangepill-attempt-count">
                                        <?php echo esc_html($event->attempt_count); ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="orangepill_replay_event" />
                                        <input type="hidden" name="event_id" value="<?php echo esc_attr($event->id); ?>" />
                                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('orangepill_wc_admin')); ?>" />
                                        <button
                                            type="submit"
                                            class="button button-primary button-small"
                                            onclick="return confirm('<?php echo esc_js(__('Replay this event?\n\nThis will re-send the original request to Orangepill using the same data and idempotency key.\n\nIdempotency protection ensures safe replay (no duplicate charges or side effects).', 'orangepill-wc')); ?>');"
                                        >
                                            <?php esc_html_e('Replay', 'orangepill-wc'); ?>
                                        </button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="orangepill_dismiss_event" />
                                        <input type="hidden" name="event_id" value="<?php echo esc_attr($event->id); ?>" />
                                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('orangepill_wc_admin')); ?>" />
                                        <button
                                            type="submit"
                                            class="button button-small orangepill-dismiss-event"
                                            onclick="return confirm('<?php esc_attr_e('Dismiss this event? It will be hidden from the failed syncs list.', 'orangepill-wc'); ?>');"
                                        >
                                            <?php esc_html_e('Dismiss', 'orangepill-wc'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <tr id="orangepill-payload-<?php echo esc_attr($event->id); ?>" class="orangepill-details-row" style="display: none;">
                                <td colspan="7">
                                    <div class="orangepill-details-content">
                                        <strong><?php esc_html_e('Payload:', 'orangepill-wc'); ?></strong>
                                        <pre><?php echo esc_html(print_r(json_decode($event->payload_json, true), true)); ?></pre>
                                        <strong><?php esc_html_e('Idempotency Key:', 'orangepill-wc'); ?></strong>
                                        <code><?php echo esc_html($event->idempotency_key); ?></code>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(
                                    esc_html(_n('%s item', '%s items', $total_events, 'orangepill-wc')),
                                    number_format_i18n($total_events)
                                ); ?>
                            </span>
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $page,
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render admin notices for replay results
     */
    private function render_notices() {
        // Replay notices
        if (isset($_GET['replay'])) {
            $replay_result = sanitize_text_field($_GET['replay']);

            if ($replay_result === 'success') {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Event replayed successfully!', 'orangepill-wc'); ?></p>
                </div>
                <?php
            } elseif ($replay_result === 'failed') {
                $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : __('Unknown error', 'orangepill-wc');
                ?>
                <div class="notice notice-error is-dismissible">
                    <p>
                        <?php esc_html_e('Replay failed:', 'orangepill-wc'); ?>
                        <strong><?php echo esc_html($error); ?></strong>
                    </p>
                </div>
                <?php
            }
        }

        // Dismiss notices
        if (isset($_GET['dismissed']) && $_GET['dismissed'] === 'success') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Event dismissed successfully.', 'orangepill-wc'); ?></p>
            </div>
            <?php
        }
    }
}

<?php
/**
 * Orangepill Sync Log Page
 *
 * Filterable event log viewer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Sync_Log_Page {
    /**
     * Entries per page
     */
    const PER_PAGE = 20;

    /**
     * Render sync log page
     */
    public function render() {
        // Handle clear log action
        if (isset($_POST['orangepill_clear_log']) && check_admin_referer('orangepill_clear_log')) {
            $this->clear_log();
        }

        // Get filters from query string
        $filters = $this->get_filters();

        // Get filtered logs
        $all_logs = OP_Logger::get_logs($filters);

        // Pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_logs = count($all_logs);
        $total_pages = ceil($total_logs / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;
        $logs = array_slice($all_logs, $offset, self::PER_PAGE);

        // Get event types for filter
        $event_types = OP_Logger::get_event_types();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Orangepill Sync Log', 'orangepill-wc'); ?></h1>

            <!-- Filters -->
            <div class="orangepill-log-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="orangepill-sync-log" />

                    <select name="level" id="orangepill-filter-level">
                        <option value=""><?php esc_html_e('All Levels', 'orangepill-wc'); ?></option>
                        <option value="info" <?php selected($filters['level'], 'info'); ?>>
                            <?php esc_html_e('Info', 'orangepill-wc'); ?>
                        </option>
                        <option value="warning" <?php selected($filters['level'], 'warning'); ?>>
                            <?php esc_html_e('Warning', 'orangepill-wc'); ?>
                        </option>
                        <option value="error" <?php selected($filters['level'], 'error'); ?>>
                            <?php esc_html_e('Error', 'orangepill-wc'); ?>
                        </option>
                    </select>

                    <select name="event" id="orangepill-filter-event">
                        <option value=""><?php esc_html_e('All Events', 'orangepill-wc'); ?></option>
                        <?php foreach ($event_types as $event_type): ?>
                            <option value="<?php echo esc_attr($event_type); ?>" <?php selected($filters['event'], $event_type); ?>>
                                <?php echo esc_html($event_type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input
                        type="text"
                        name="search"
                        placeholder="<?php esc_attr_e('Search...', 'orangepill-wc'); ?>"
                        value="<?php echo esc_attr($filters['search']); ?>"
                    />

                    <button type="submit" class="button">
                        <?php esc_html_e('Filter', 'orangepill-wc'); ?>
                    </button>

                    <?php if (!empty(array_filter($filters))): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=orangepill-sync-log')); ?>" class="button">
                            <?php esc_html_e('Clear Filters', 'orangepill-wc'); ?>
                        </a>
                    <?php endif; ?>
                </form>

                <div style="margin-top: 10px;">
                    <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs?', 'orangepill-wc'); ?>');">
                        <?php wp_nonce_field('orangepill_clear_log'); ?>
                        <button type="submit" name="orangepill_clear_log" class="button button-secondary">
                            <?php esc_html_e('Clear All Logs', 'orangepill-wc'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Log Table -->
            <?php if (empty($logs)): ?>
                <p class="orangepill-no-data"><?php esc_html_e('No log entries found', 'orangepill-wc'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped orangepill-log-table">
                    <thead>
                        <tr>
                            <th style="width: 180px;"><?php esc_html_e('Time', 'orangepill-wc'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Level', 'orangepill-wc'); ?></th>
                            <th style="width: 200px;"><?php esc_html_e('Event', 'orangepill-wc'); ?></th>
                            <th><?php esc_html_e('Message', 'orangepill-wc'); ?></th>
                            <th style="width: 80px;"><?php esc_html_e('Details', 'orangepill-wc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $index => $log): ?>
                            <tr>
                                <td>
                                    <span title="<?php echo esc_attr($log['timestamp']); ?>">
                                        <?php echo esc_html(date('Y-m-d H:i:s', strtotime($log['timestamp']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="orangepill-log-level orangepill-log-level-<?php echo esc_attr($log['level']); ?>">
                                        <?php echo esc_html(ucfirst($log['level'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['event']); ?></td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td>
                                    <?php if (!empty($log['context'])): ?>
                                        <button
                                            type="button"
                                            class="button button-small orangepill-toggle-details"
                                            data-target="orangepill-details-<?php echo esc_attr($index); ?>"
                                        >
                                            <?php esc_html_e('View', 'orangepill-wc'); ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="description"><?php esc_html_e('None', 'orangepill-wc'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($log['context'])): ?>
                                <tr id="orangepill-details-<?php echo esc_attr($index); ?>" class="orangepill-details-row" style="display: none;">
                                    <td colspan="5">
                                        <div class="orangepill-details-content">
                                            <strong><?php esc_html_e('Context:', 'orangepill-wc'); ?></strong>
                                            <pre><?php echo esc_html(print_r($log['context'], true)); ?></pre>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(
                                    esc_html(_n('%s item', '%s items', $total_logs, 'orangepill-wc')),
                                    number_format_i18n($total_logs)
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
     * Get filters from query string
     *
     * @return array Filters
     */
    private function get_filters() {
        return array(
            'level' => isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '',
            'event' => isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
        );
    }

    /**
     * Clear all logs
     */
    private function clear_log() {
        OP_Logger::clear_logs();
        echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared successfully.', 'orangepill-wc') . '</p></div>';
    }
}

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
                                <?php
                                $ctx         = $log['context'];
                                $req_url     = $ctx['request_url']     ?? null;
                                $req_method  = $ctx['request_method']  ?? null;
                                $req_headers = $ctx['request_headers'] ?? null;
                                $req_body    = $ctx['execute_body']    ?? $ctx['request_body'] ?? null;
                                $resp        = $ctx['api_response']    ?? null;
                                $http_status = $ctx['status_code']     ?? null;
                                $rest_ctx    = array_diff_key($ctx, array_flip(array('execute_body', 'request_body', 'api_response', 'status_code', 'request_url', 'request_method', 'request_headers', 'merchant_id', 'customer_id')));
                                $merchant_id_ctx = $ctx['merchant_id'] ?? null;
                                $customer_id_ctx = $ctx['customer_id'] ?? null;
                                ?>
                                <tr id="orangepill-details-<?php echo esc_attr($index); ?>" class="orangepill-details-row" style="display: none;">
                                    <td colspan="5">
                                        <div class="orangepill-details-content" style="font-size:12px;">
                                            <?php if ($merchant_id_ctx || $customer_id_ctx): ?>
                                                <table style="margin-bottom:8px;border-collapse:collapse;font-size:12px;">
                                                    <?php if ($merchant_id_ctx): ?>
                                                    <tr>
                                                        <td style="color:#555;padding:2px 8px 2px 0;white-space:nowrap;"><?php esc_html_e('Merchant ID', 'orangepill-wc'); ?></td>
                                                        <td><code><?php echo esc_html($merchant_id_ctx); ?></code></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <?php if ($customer_id_ctx): ?>
                                                    <tr>
                                                        <td style="color:#555;padding:2px 8px 2px 0;white-space:nowrap;"><?php esc_html_e('Customer ID', 'orangepill-wc'); ?></td>
                                                        <td><code><?php echo esc_html($customer_id_ctx); ?></code></td>
                                                    </tr>
                                                    <?php else: ?>
                                                    <tr>
                                                        <td style="color:#555;padding:2px 8px 2px 0;white-space:nowrap;"><?php esc_html_e('Customer ID', 'orangepill-wc'); ?></td>
                                                        <td><span style="color:#72777c;"><?php esc_html_e('(guest)', 'orangepill-wc'); ?></span></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                </table>
                                            <?php endif; ?>

                                            <?php if (!empty($rest_ctx)): ?>
                                                <strong><?php esc_html_e('Context:', 'orangepill-wc'); ?></strong>
                                                <pre style="background:#f6f7f7;padding:8px;overflow:auto;"><?php echo esc_html(wp_json_encode($rest_ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                            <?php endif; ?>

                                            <?php if ($req_url !== null): ?>
                                                <strong><?php esc_html_e('Request:', 'orangepill-wc'); ?></strong>
                                                <pre style="background:#f0f4ff;padding:8px;overflow:auto;margin-bottom:4px;"><?php echo esc_html(($req_method ?? 'POST') . ' ' . $req_url); ?></pre>
                                            <?php endif; ?>

                                            <?php if ($req_headers !== null): ?>
                                                <strong><?php esc_html_e('Request headers:', 'orangepill-wc'); ?></strong>
                                                <div style="position:relative;">
                                                    <button type="button" class="button button-small op-copy-json" style="position:absolute;top:4px;right:4px;"
                                                        data-json="<?php echo esc_attr(wp_json_encode($req_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                                                        <?php esc_html_e('Copy', 'orangepill-wc'); ?>
                                                    </button>
                                                    <pre style="background:#f0f4ff;padding:8px;overflow:auto;padding-right:70px;"><?php echo esc_html(wp_json_encode($req_headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($req_body !== null): ?>
                                                <strong><?php esc_html_e('Request body:', 'orangepill-wc'); ?></strong>
                                                <div style="position:relative;">
                                                    <button type="button" class="button button-small op-copy-json" style="position:absolute;top:4px;right:4px;"
                                                        data-json="<?php echo esc_attr(wp_json_encode($req_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                                                        <?php esc_html_e('Copy', 'orangepill-wc'); ?>
                                                    </button>
                                                    <pre style="background:#f0f4ff;padding:8px;overflow:auto;padding-right:70px;"><?php echo esc_html(wp_json_encode($req_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($resp !== null): ?>
                                                <strong>
                                                    <?php esc_html_e('Response:', 'orangepill-wc'); ?>
                                                    <?php if ($http_status): ?>
                                                        <span style="color:<?php echo (int)$http_status >= 400 ? '#d63638' : '#00a32a'; ?>">
                                                            HTTP <?php echo esc_html($http_status); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </strong>
                                                <div style="position:relative;">
                                                    <button type="button" class="button button-small op-copy-json" style="position:absolute;top:4px;right:4px;"
                                                        data-json="<?php echo esc_attr(wp_json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                                                        <?php esc_html_e('Copy', 'orangepill-wc'); ?>
                                                    </button>
                                                    <pre style="background:#fff0f0;padding:8px;overflow:auto;padding-right:70px;"><?php echo esc_html(wp_json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
                                                </div>
                                            <?php endif; ?>
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
        <script>
        (function () {
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.op-copy-json');
                if (btn) {
                    var text = btn.dataset.json;
                    navigator.clipboard.writeText(text).then(function () {
                        var orig = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = orig; }, 1500);
                    });
                }
            });
        })();
        </script>
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

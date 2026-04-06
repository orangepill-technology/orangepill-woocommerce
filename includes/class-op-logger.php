<?php
/**
 * Orangepill Logger
 *
 * Lightweight logging system using wp_options (50 entry limit)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OP_Logger {
    /**
     * @var string Option name for storing logs
     */
    const OPTION_NAME = 'orangepill_wc_sync_log';

    /**
     * @var int Maximum number of log entries to keep
     */
    const MAX_ENTRIES = 50;

    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /**
     * Log an event
     *
     * @param string $level Log level (info, warning, error)
     * @param string $event Event type
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public static function log($level, $event, $message, $context = array()) {
        $logs = get_option(self::OPTION_NAME, array());

        if (!is_array($logs)) {
            $logs = array();
        }

        // Redact sensitive data from context
        $context = self::redact_sensitive_data($context);

        // Create log entry
        $entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        );

        // Add to beginning of array (newest first)
        array_unshift($logs, $entry);

        // Limit to MAX_ENTRIES (FIFO pruning)
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, 0, self::MAX_ENTRIES);
        }

        update_option(self::OPTION_NAME, $logs, false);
    }

    /**
     * Log info level message
     *
     * @param string $event Event type
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function info($event, $message, $context = array()) {
        self::log(self::LEVEL_INFO, $event, $message, $context);
    }

    /**
     * Log warning level message
     *
     * @param string $event Event type
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function warning($event, $message, $context = array()) {
        self::log(self::LEVEL_WARNING, $event, $message, $context);
    }

    /**
     * Log error level message
     *
     * @param string $event Event type
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function error($event, $message, $context = array()) {
        self::log(self::LEVEL_ERROR, $event, $message, $context);
    }

    /**
     * Get all log entries
     *
     * @param array $filters Optional filters (level, event, search, date_from, date_to)
     * @return array Log entries
     */
    public static function get_logs($filters = array()) {
        $logs = get_option(self::OPTION_NAME, array());

        if (!is_array($logs)) {
            return array();
        }

        // Apply filters
        if (!empty($filters)) {
            $logs = self::apply_filters($logs, $filters);
        }

        return $logs;
    }

    /**
     * Apply filters to log entries
     *
     * @param array $logs Log entries
     * @param array $filters Filters to apply
     * @return array Filtered logs
     */
    private static function apply_filters($logs, $filters) {
        $filtered = $logs;

        // Filter by level
        if (!empty($filters['level'])) {
            $filtered = array_filter($filtered, function($entry) use ($filters) {
                return $entry['level'] === $filters['level'];
            });
        }

        // Filter by event type
        if (!empty($filters['event'])) {
            $filtered = array_filter($filtered, function($entry) use ($filters) {
                return $entry['event'] === $filters['event'];
            });
        }

        // Filter by search term
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $filtered = array_filter($filtered, function($entry) use ($search) {
                return strpos(strtolower($entry['message']), $search) !== false
                    || strpos(strtolower($entry['event']), $search) !== false;
            });
        }

        return array_values($filtered);
    }

    /**
     * Get log entry count
     *
     * @param array $filters Optional filters
     * @return int Number of entries
     */
    public static function get_count($filters = array()) {
        return count(self::get_logs($filters));
    }

    /**
     * Clear all log entries
     *
     * @return bool Success
     */
    public static function clear_logs() {
        return update_option(self::OPTION_NAME, array(), false);
    }

    /**
     * Redact sensitive data from context array
     *
     * @param array $context Context data
     * @return array Redacted context
     */
    private static function redact_sensitive_data($context) {
        if (!is_array($context)) {
            return $context;
        }

        $sensitive_keys = array(
            'api_key',
            'apiKey',
            'api_secret',
            'apiSecret',
            'secret',
            'webhook_secret',
            'webhookSecret',
            'password',
            'token',
            'authorization',
            'Authorization',
        );

        foreach ($context as $key => $value) {
            // Redact sensitive keys
            if (in_array($key, $sensitive_keys, true)) {
                $context[$key] = '[REDACTED]';
                continue;
            }

            // Recursively redact nested arrays
            if (is_array($value)) {
                $context[$key] = self::redact_sensitive_data($value);
            }

            // Redact long strings that might be tokens
            if (is_string($value) && strlen($value) > 32 && preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
                if (strpos($key, 'id') === false && strpos($key, 'ID') === false) {
                    $context[$key] = '[REDACTED]';
                }
            }
        }

        return $context;
    }

    /**
     * Get recent errors (last 24 hours)
     *
     * @return int Error count
     */
    public static function get_recent_error_count() {
        $date_from = date('Y-m-d', strtotime('-24 hours'));
        return self::get_count(array(
            'level' => self::LEVEL_ERROR,
            'date_from' => $date_from,
        ));
    }

    /**
     * Get unique event types from logs
     *
     * @return array Event types
     */
    public static function get_event_types() {
        $logs = get_option(self::OPTION_NAME, array());
        $events = array();

        foreach ($logs as $entry) {
            if (!empty($entry['event']) && !in_array($entry['event'], $events)) {
                $events[] = $entry['event'];
            }
        }

        sort($events);
        return $events;
    }
}

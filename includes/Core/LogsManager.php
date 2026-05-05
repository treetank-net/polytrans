<?php

namespace PolyTrans\Core;

use PolyTrans\Templating\TemplateRenderer;

/**
 * PolyTrans Logs Manager
 * Handles logs database table creation and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class LogsManager
{
    /**
     * Create the logs database table
     * 
     * @return bool True if the table was created successfully
     */
    public static function create_logs_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'polytrans_logs';

        // Check if the table already exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
            // Table exists - let's check the structure and adapt if needed
            self::check_and_adapt_table_structure($table_name);
            return true;
        }

        // If the database logging is disabled, don't create the table
        if (!self::is_db_logging_enabled()) {
            return true;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Create a standard logs table with all commonly needed columns
        // Using created_at instead of timestamp to avoid conflicts with reserved words
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin installation/upgrade
        $sql = "CREATE TABLE {$wpdb->prefix}polytrans_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context text,
            process_id int(11),
            source varchar(20),
            user_id bigint(20) unsigned,
            post_id bigint(20) unsigned,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at),
            KEY source (source),
            KEY user_id (user_id),
            KEY post_id (post_id)
        ) $charset_collate;";

        $result = dbDelta($sql);

        // Clear the flag
        delete_option('polytrans_logs_table_needed');

        // Log successful creation
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Created logs table $table_name");

        return true;
    }

    /**
     * Check the structure of the logs table and adapt if needed
     * 
     * @param string $table_name The table name to check
     * @return void
     */
    private static function check_and_adapt_table_structure($table_name)
    {
        global $wpdb;
        $existing_columns = self::get_table_column_details($table_name);

        // If we have an empty array, something went wrong with the table
        if (empty($existing_columns)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Error checking logs table structure: Could not retrieve columns");
            return;
        }

        // Define expected schema
        $expected_schema = [
            'id' => [
                'type' => 'bigint(20) unsigned',
                'null' => 'NO',
                'extra' => 'auto_increment'
            ],
            'created_at' => [
                'type' => 'datetime',
                'null' => 'NO'
            ],
            'level' => [
                'type' => 'varchar(20)',
                'null' => 'NO',
                'default' => 'info'
            ],
            'message' => [
                'type' => 'text',
                'null' => 'NO'
            ],
            'context' => [
                'type' => 'text',
                'null' => 'YES'
            ],
            'process_id' => [
                'type' => 'int(11)',
                'null' => 'YES'
            ],
            'source' => [
                'type' => 'varchar(20)',
                'null' => 'YES'
            ],
            'user_id' => [
                'type' => 'bigint(20) unsigned',
                'null' => 'YES'
            ],
            'post_id' => [
                'type' => 'bigint(20) unsigned',
                'null' => 'YES'
            ]
        ];

        $data_corrupted = false;

        // Check each expected column
        foreach ($expected_schema as $column_name => $expected) {
            if (!isset($existing_columns[$column_name])) {
                // Column doesn't exist - add it
                $sql = self::build_add_column_sql($table_name, $column_name, $expected);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Plugin table migration with safe internal schema definitions
                $wpdb->query($sql);
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Added missing column '$column_name' to logs table");
            } else {
                // Column exists - check if type matches
                $existing = $existing_columns[$column_name];

                // Normalize types for comparison
                $expected_type = self::normalize_column_type($expected['type']);
                $existing_type = self::normalize_column_type($existing['type']);

                if ($expected_type !== $existing_type) {
                    // Type mismatch detected - this indicates corrupted data
                    $data_corrupted = true;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log("[polytrans] Type mismatch detected for column '$column_name': expected '$expected_type', found '$existing_type'");
                }

                // Check if NULL constraint matches
                if ($expected['null'] !== $existing['null']) {
                    $data_corrupted = true;
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log("[polytrans] NULL constraint mismatch for column '$column_name': expected '{$expected['null']}', found '{$existing['null']}'");
                }
            }
        }

        // If data corruption detected, clean and recreate table
        if ($data_corrupted) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Data corruption detected in logs table. Cleaning and recreating table structure.");
            self::clean_and_recreate_logs_table($table_name);
        } else {
            // Add indexes if they don't exist
            self::ensure_table_indexes($table_name);
        }
    }

    /**
     * Clean corrupted data and recreate the logs table with proper structure
     * 
     * @param string $table_name The table name
     * @return void
     */
    private static function clean_and_recreate_logs_table($table_name)
    {
        global $wpdb;

        try {
            // Backup any existing data that might be salvageable
            $backup_data = [];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table salvage
            $existing_data = $wpdb->get_results(
                "SELECT * FROM `{$wpdb->prefix}polytrans_logs` ORDER BY id DESC LIMIT 1000",
                ARRAY_A
            );

            if ($existing_data) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Attempting to salvage " . count($existing_data) . " recent log entries");
                $backup_data = $existing_data;
            }

            // Drop the corrupted table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin table maintenance
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}polytrans_logs`");
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Dropped corrupted logs table");

            // Get charset collate
            $charset_collate = $wpdb->get_charset_collate();

            // Recreate table with correct structure
            $recreate_table = $wpdb->prefix . 'polytrans_logs';
            $sql = "CREATE TABLE {$wpdb->prefix}polytrans_logs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                created_at datetime NOT NULL,
                level varchar(20) NOT NULL DEFAULT 'info',
                message text NOT NULL,
                context text,
                process_id int(11),
                source varchar(20),
                user_id bigint(20) unsigned,
                post_id bigint(20) unsigned,
                PRIMARY KEY  (id),
                KEY level (level),
                KEY created_at (created_at),
                KEY source (source),
                KEY user_id (user_id),
                KEY post_id (post_id)
            ) $charset_collate;";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Plugin installation/upgrade, DDL statement
            $result = $wpdb->query($sql);

            if ($result !== false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Successfully recreated logs table with correct structure");

                // Try to restore salvageable data
                if (!empty($backup_data)) {
                    self::restore_salvageable_data($table_name, $backup_data);
                }
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Failed to recreate logs table: " . $wpdb->last_error);
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Error during table cleanup and recreation: " . $e->getMessage());
        }
    }

    /**
     * Restore salvageable data to the recreated table
     * 
     * @param string $table_name The table name
     * @param array $backup_data The data to restore
     * @return void
     */
    private static function restore_salvageable_data($table_name, $backup_data)
    {
        global $wpdb;

        $restored_count = 0;
        $failed_count = 0;

        foreach ($backup_data as $row) {
            try {
                // Clean and validate the data
                $clean_data = [
                    'created_at' => self::clean_datetime_value($row['created_at'] ?? null),
                    'level' => self::clean_varchar_value($row['level'] ?? 'info', 20),
                    'message' => self::clean_text_value($row['message'] ?? ''),
                    'context' => self::clean_text_value($row['context'] ?? null),
                    'process_id' => self::clean_int_value($row['process_id'] ?? null),
                    'source' => self::clean_varchar_value($row['source'] ?? null, 20),
                    'user_id' => self::clean_bigint_value($row['user_id'] ?? null),
                    'post_id' => self::clean_bigint_value($row['post_id'] ?? null)
                ];

                // Remove null values for columns that don't allow them
                if (empty($clean_data['message'])) {
                    $clean_data['message'] = 'Restored log entry';
                }

                if (empty($clean_data['created_at'])) {
                    $clean_data['created_at'] = current_time('mysql');
                }

                // Insert the cleaned data
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->insert($table_name, $clean_data);

                if ($result !== false) {
                    $restored_count++;
                } else {
                    $failed_count++;
                }
            } catch (\Exception $e) {
                $failed_count++;
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Failed to restore log entry: " . $e->getMessage());
            }
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Data restoration complete: $restored_count entries restored, $failed_count failed");
    }

    /**
     * Ensure all required indexes exist on the table
     *
     * @param string $table_name The table name
     * @return void
     */
    private static function ensure_table_indexes($table_name)
    {
        global $wpdb;

        // Define expected indexes (index_name => column_name)
        $indexes = [
            'level' => 'level',
            'created_at' => 'created_at',
            'source' => 'source',
            'user_id' => 'user_id',
            'post_id' => 'post_id'
        ];

        // Get existing indexes
        $existing_indexes = self::get_table_indexes($table_name);

        foreach ($indexes as $index_name => $column_name) {
            // Check if index already exists with the same definition
            if (isset($existing_indexes[$index_name])) {
                // Index exists - verify it's on the correct column
                if ($existing_indexes[$index_name] === $column_name) {
                    // Index exists and matches - skip
                    continue;
                } else {
                    // Index exists but on different column - drop and recreate
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log("[polytrans] Index '$index_name' exists but on wrong column (expected '$column_name', found '{$existing_indexes[$index_name]}'). Recreating.");
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin table maintenance
                    $wpdb->query("ALTER TABLE `{$wpdb->prefix}polytrans_logs` DROP INDEX `$index_name`");
                }
            }

            // Add the index
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin table maintenance
            $result = $wpdb->query("ALTER TABLE `{$wpdb->prefix}polytrans_logs` ADD INDEX `$index_name` (`$column_name`)");
            if ($result === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Failed to add index '$index_name' on column '$column_name': " . $wpdb->last_error);
            }
        }
    }

    /**
     * Get existing indexes for a table
     *
     * @param string $table_name The table name
     * @return array Array of index_name => column_name
     */
    private static function get_table_indexes($table_name)
    {
        global $wpdb;
        static $indexes_cache = [];

        // Return from cache if available
        if (isset($indexes_cache[$table_name])) {
            return $indexes_cache[$table_name];
        }

        $indexes = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $results = $wpdb->get_results(
            "SHOW INDEX FROM `{$table_name}`"
        );

        if ($results) {
            foreach ($results as $row) {
                // Skip PRIMARY key
                if ($row->Key_name === 'PRIMARY') {
                    continue;
                }
                // Store index_name => column_name (for single-column indexes)
                // If index already exists, it means it's a multi-column index
                if (!isset($indexes[$row->Key_name])) {
                    $indexes[$row->Key_name] = $row->Column_name;
                }
            }
        }

        $indexes_cache[$table_name] = $indexes;
        return $indexes;
    }

    /**
     * Build SQL for adding a column
     * 
     * @param string $table_name The table name
     * @param string $column_name The column name
     * @param array $column_def The column definition
     * @return string The SQL statement
     */
    private static function build_add_column_sql($table_name, $column_name, $column_def)
    {
        $sql = "ALTER TABLE `$table_name` ADD COLUMN `$column_name` {$column_def['type']}";

        if ($column_def['null'] === 'NO') {
            $sql .= ' NOT NULL';
        }

        if (isset($column_def['default'])) {
            $sql .= " DEFAULT '{$column_def['default']}'";
        }

        if (isset($column_def['extra']) && $column_def['extra'] === 'auto_increment') {
            $sql .= ' AUTO_INCREMENT';
            if ($column_name === 'id') {
                $sql .= ' PRIMARY KEY FIRST';
            }
        }

        return $sql;
    }

    /**
     * Normalize column type for comparison
     * 
     * @param string $type The column type
     * @return string Normalized type
     */
    private static function normalize_column_type($type)
    {
        // Remove whitespace and convert to lowercase
        $type = strtolower(trim($type));

        // Handle common variations
        $type = str_replace([' unsigned', ' signed'], ['_unsigned', '_signed'], $type);

        return $type;
    }

    /**
     * Clean datetime value
     * 
     * @param mixed $value The value to clean
     * @return string|null Clean datetime or null
     */
    private static function clean_datetime_value($value)
    {
        if (empty($value)) {
            return current_time('mysql');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Clean varchar value
     * 
     * @param mixed $value The value to clean
     * @param int $max_length Maximum length
     * @return string|null Clean varchar or null
     */
    private static function clean_varchar_value($value, $max_length)
    {
        if ($value === null) {
            return null;
        }

        return substr(sanitize_text_field($value), 0, $max_length);
    }

    /**
     * Clean text value
     * 
     * @param mixed $value The value to clean
     * @return string|null Clean text or null
     */
    private static function clean_text_value($value)
    {
        if ($value === null) {
            return null;
        }

        return sanitize_textarea_field($value);
    }

    /**
     * Clean integer value
     * 
     * @param mixed $value The value to clean
     * @return int|null Clean integer or null
     */
    private static function clean_int_value($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return intval($value);
    }

    /**
     * Clean bigint value
     * 
     * @param mixed $value The value to clean
     * @return int|null Clean bigint or null
     */
    private static function clean_bigint_value($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return intval($value);
    }

    /**
     * Get logs with pagination and filtering
     * 
     * @param array $args Query arguments
     * @return array Array of logs
     */
    public static function get_logs($args = [])
    {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'level' => '',
            'source' => '',
            'post_id' => 0,
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'date_from' => '',
            'date_to' => '',
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . 'polytrans_logs';

        // Start building the query -- table name comes directly from $wpdb->prefix (safe)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $sql = "SELECT * FROM {$wpdb->prefix}polytrans_logs WHERE 1=1";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $count_sql = "SELECT COUNT(id) FROM {$wpdb->prefix}polytrans_logs WHERE 1=1";

        // Apply filters
        if (!empty($args['level'])) {
            $level = sanitize_text_field($args['level']);
            $sql .= $wpdb->prepare(" AND level = %s", $level);
            $count_sql .= $wpdb->prepare(" AND level = %s", $level);
        }

        if (!empty($args['source'])) {
            $source = sanitize_text_field($args['source']);
            $sql .= $wpdb->prepare(" AND source = %s", $source);
            $count_sql .= $wpdb->prepare(" AND source = %s", $source);
        }

        if (!empty($args['post_id'])) {
            $post_id = intval($args['post_id']);
            $sql .= $wpdb->prepare(" AND context LIKE %s", '%"post_id":' . $post_id . '%');
            $count_sql .= $wpdb->prepare(" AND context LIKE %s", '%"post_id":' . $post_id . '%');
        }

        if (!empty($args['search'])) {
            $search = sanitize_text_field($args['search']);
            $sql .= $wpdb->prepare(" AND message LIKE %s", '%' . $wpdb->esc_like($search) . '%');
            $count_sql .= $wpdb->prepare(" AND message LIKE %s", '%' . $wpdb->esc_like($search) . '%');
        }

        if (!empty($args['date_from'])) {
            $date_from = sanitize_text_field($args['date_from']);
            $sql .= $wpdb->prepare(" AND created_at >= %s", $date_from);
            $count_sql .= $wpdb->prepare(" AND created_at >= %s", $date_from);
        }

        if (!empty($args['date_to'])) {
            $date_to = sanitize_text_field($args['date_to']);
            $sql .= $wpdb->prepare(" AND created_at <= %s", $date_to);
            $count_sql .= $wpdb->prepare(" AND created_at <= %s", $date_to);
        }

        // Order
        $orderby = in_array($args['orderby'], ['id', 'created_at', 'level', 'source', 'user_id']) ? $args['orderby'] : 'created_at';
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : 'DESC';

        // Always include ID as secondary sort to ensure consistent ordering for logs with same timestamp
        if ($orderby !== 'id') {
            $sql .= " ORDER BY $orderby $order, id $order";
        } else {
            $sql .= " ORDER BY $orderby $order";
        }

        // Pagination
        $page = max(1, intval($args['page']));
        $per_page = max(1, intval($args['per_page']));
        $offset = ($page - 1) * $per_page;

        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

        // Get the total count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with $wpdb->prepare() for all user inputs
        $total_items = $wpdb->get_var($count_sql);

        // Get the logs
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query built with $wpdb->prepare() for all user inputs
        $logs = $wpdb->get_results($sql);
        if ($logs === null) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Error fetching logs: " . $wpdb->last_error);
            return [];
        }

        // Process the logs to format context data
        foreach ($logs as &$log) {
            if (!empty($log->context)) {
                $log->context = json_decode($log->context, true);
            } else {
                $log->context = [];
            }
        }

        return [
            'logs' => $logs,
            'total' => intval($total_items),
            'pages' => ceil($total_items / $per_page),
            'page' => $page,
        ];
    }

    /**
     * Clear logs older than a certain number of days
     * 
     * @param int $days Number of days to keep logs (default: 30)
     * @return int Number of logs deleted
     */
    public static function clear_old_logs($days = 30)
    {
        global $wpdb;

        if (!self::logs_table_exists() || !self::is_db_logging_enabled()) {
            return 0;
        }

        $table_name = $wpdb->prefix . 'polytrans_logs';
        $date = gmdate('Y-m-d H:i:s', strtotime('-' . intval($days) . ' days'));

        // Get the date column name (timestamp or created_at)
        $columns = self::get_table_columns($table_name);
        $date_column = in_array('created_at', $columns) ? 'created_at' : (in_array('created_at', $columns) ? 'created_at' : null);

        // If we can't find a date column, we can't delete by date
        if (!$date_column) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Cannot clear logs: No date column found in logs table");
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}polytrans_logs WHERE created_at < %s",
                $date
            )
        );

        if ($result === false) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Error clearing logs: " . $wpdb->last_error);
            return 0;
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_storage_stats($retention_days = null): array
    {
        global $wpdb;

        if (!self::logs_table_exists()) {
            return [
                'total_logs' => 0,
                'old_logs' => 0,
                'total_payload_size' => 0,
                'oldest_created_at' => '',
                'newest_created_at' => '',
                'last_cleanup_at' => (int) get_option('polytrans_logs_cleanup_at', 0),
                'retention_days' => self::get_retention_days($retention_days),
            ];
        }

        $table_name = $wpdb->prefix . 'polytrans_logs';
        $retention_days = self::get_retention_days($retention_days);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $retention_days . ' days'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin maintenance stats for plugin-owned table.
        $row = $wpdb->get_row("SELECT COUNT(*) AS total_logs, COALESCE(SUM(CHAR_LENGTH(message) + COALESCE(CHAR_LENGTH(context), 0)), 0) AS total_payload_size, MIN(created_at) AS oldest_created_at, MAX(created_at) AS newest_created_at FROM {$table_name}", ARRAY_A);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin maintenance stats for plugin-owned table.
        $old_logs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE created_at < %s", $cutoff));

        return [
            'total_logs' => (int) ($row['total_logs'] ?? 0),
            'old_logs' => $old_logs,
            'total_payload_size' => (int) ($row['total_payload_size'] ?? 0),
            'oldest_created_at' => (string) ($row['oldest_created_at'] ?? ''),
            'newest_created_at' => (string) ($row['newest_created_at'] ?? ''),
            'last_cleanup_at' => (int) get_option('polytrans_logs_cleanup_at', 0),
            'retention_days' => $retention_days,
        ];
    }

    public static function truncate_logs(): int
    {
        global $wpdb;

        if (!self::logs_table_exists()) {
            return 0;
        }

        $stats = self::get_storage_stats();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Manual admin maintenance for plugin-owned table.
        $truncated = $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . 'polytrans_logs');

        return $truncated !== false ? (int) ($stats['total_logs'] ?? 0) : 0;
    }

    public static function maybe_cleanup_old_logs(): int
    {
        $settings = get_option('polytrans_settings', []);
        // must be set explicitly
        if (!is_numeric($settings['logs_retention_days'])) {
            return 0;
        }
        $retention_days = self::get_retention_days($settings['logs_retention_days']);
        $last_cleanup = (int) get_option('polytrans_logs_cleanup_at', 0);
        if ($last_cleanup > 0 && (time() - $last_cleanup) < DAY_IN_SECONDS) {
            return 0;
        }

        update_option('polytrans_logs_cleanup_at', time(), false);
        return self::clear_old_logs($retention_days);
    }

    private static function get_retention_days($days = null): int
    {
        if ($days === null) {
            $settings = get_option('polytrans_settings', []);
            $days = $settings['logs_retention_days'] ?? 30;
        }

        return max(1, min(365, intval($days)));
    }

    /**
     * Create admin interface to view logs
     */
    public static function admin_logs_page()
    {
        // Include necessary WordPress files
        require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');

        // Check if the logs table exists
        $table_exists = self::logs_table_exists();

        // Check if database logging is enabled
        $db_logging_enabled = self::is_db_logging_enabled();

        // Prepare warning message
        $warning_message = '';
        if (!$db_logging_enabled || !$table_exists) {
            if (!$db_logging_enabled) {
                $warning_message = esc_html__('Database logging is currently disabled. Logs are only being written to the WordPress error log and post meta. Enable database logging in PolyTrans settings to view logs here.', 'polytrans');
            } else {
                $warning_message = esc_html__('Logs database table does not exist. Logs are only being written to the WordPress error log and post meta.', 'polytrans');
                $warning_message .= ' <a href="' . esc_url(admin_url('admin.php?page=polytrans-settings')) . '">' . esc_html__('Go to Settings', 'polytrans') . '</a>';
            }
        }

        // Prepare success message
        $success_message = '';
        if ($table_exists && isset($_POST['polytrans_clear_logs']) && check_admin_referer('polytrans_clear_logs')) {
            $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
            $deleted = self::clear_old_logs($days);

            $success_message = sprintf(
                /* translators: %d: number of log entries deleted */
                _n(
                    '%d log entry cleared.',
                    '%d log entries cleared.',
                    $deleted,
                    'polytrans'
                ),
                $deleted
            );
        }

        // Process filters and pagination
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        $source = isset($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '';
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        // Get logs
        $logs_data = self::get_logs([
            'page' => $current_page,
            'per_page' => 50,
            'search' => $search,
            'level' => $level,
            'source' => $source,
            'post_id' => $post_id
        ]);

        // Generate pagination links
        $top_page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $logs_data['pages'],
            'current' => $current_page,
        ]);

        $top_pagination = '';
        if ($top_page_links) {
            $top_pagination = '<span class="pagination-links">' . $top_page_links . '</span>';
        }

        // Generate bottom pagination
        $bottom_page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $logs_data['pages'],
            'current' => $current_page,
        ]);

        $bottom_pagination = '';
        if ($bottom_page_links) {
            $bottom_pagination = '<span class="pagination-links">' . $bottom_page_links . '</span>';
        }

        // Render template
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/logs/page.twig', [
            'warning_message' => $warning_message,
            'success_message' => $success_message,
            'current_page' => $current_page,
            'search' => $search,
            'level' => $level,
            'source' => $source,
            'post_id' => $post_id,
            'logs_data' => $logs_data,
            'top_pagination' => $top_pagination,
            'bottom_pagination' => $bottom_pagination,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Check if database logging is enabled in settings
     * 
     * @return bool True if database logging is enabled
     */
    public static function is_db_logging_enabled()
    {
        $settings = get_option('polytrans_settings', []);
        // Default to true if not set - backward compatibility
        return isset($settings['enable_db_logging']) ? (bool)$settings['enable_db_logging'] : true;
    }

    /**
     * Check if the logs table exists
     * 
     * @return bool True if the table exists
     */
    public static function logs_table_exists()
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        $table_name = $wpdb->prefix . 'polytrans_logs';

        // Check if the table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name);
    }

    /**
     * Add an entry to the logs
     * Gracefully handles cases where the table doesn't exist or has different structure
     * Always logs to WordPress error log regardless of database settings
     * 
     * @param string $message The log message
     * @param string $level The log level (info, warning, error, debug)
     * @param array $context Additional context for the log
     * @return bool True if successful
     */
    public static function log($message, $level = 'info', $context = [])
    {
        // Always log to WordPress error log
        $context_string = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] [$level] $message $context_string");

        // If database logging is disabled, we're done
        if (!self::is_db_logging_enabled()) {
            return true;
        }

        // Check if the table exists
        if (!self::logs_table_exists()) {
            return false;
        }

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'polytrans_logs';

            // Process context data
            $source = !empty($context['source']) ? $context['source'] : 'system';
            $process_id = !empty($context['process_id']) ? (int)$context['process_id'] : 0;
            $post_id = !empty($context['post_id']) ? (int)$context['post_id'] : 0;
            $user_id = get_current_user_id();

            // Format context as JSON
            $context_json = !empty($context) ? wp_json_encode($context) : null;

            // Get the actual columns that exist in the table
            $existing_columns = self::get_table_columns($table_name);

            // If no columns were retrieved, don't try to insert
            if (empty($existing_columns) || is_null($existing_columns)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Could not determine columns for $table_name, skipping database logging");
                return false;
            }

            // Build data array based on available columns only
            $data = [];

            // Map our data to existing columns
            $column_mappings = [
                'created_at' => ['timestamp', 'log_time', 'created_at', 'date'],
                'level' => ['level', 'log_level', 'severity'],
                'message' => ['message', 'log_message', 'content'],
                'context' => ['context', 'data', 'metadata'],
                'source' => ['source', 'log_source'],
                'process_id' => ['process_id', 'pid'],
                'user_id' => ['user_id'],
                'post_id' => ['post_id']
            ];

            // Find the best column matches for our data
            $time_column = self::find_first_match($existing_columns, $column_mappings['created_at']);

            if ($time_column) {
                $data[$time_column] = current_time('mysql');
            }

            $level_column = self::find_first_match($existing_columns, $column_mappings['level']);
            if ($level_column) {
                $data[$level_column] = $level;
            }

            $message_column = self::find_first_match($existing_columns, $column_mappings['message']);
            if ($message_column) {
                $data[$message_column] = $message;
            }

            $context_column = self::find_first_match($existing_columns, $column_mappings['context']);
            if ($context_column && !empty($context)) {
                $data[$context_column] = $context_json;
            }

            $source_column = self::find_first_match($existing_columns, $column_mappings['source']);
            if ($source_column) {
                $data[$source_column] = $source;
            }

            $process_id_column = self::find_first_match($existing_columns, $column_mappings['process_id']);
            if ($process_id_column) {
                $data[$process_id_column] = $process_id;
            }

            $user_id_column = self::find_first_match($existing_columns, $column_mappings['user_id']);
            if ($user_id_column) {
                $data[$user_id_column] = $user_id;
            }

            $post_id_column = self::find_first_match($existing_columns, $column_mappings['post_id']);
            if ($post_id_column && $post_id > 0) {
                $data[$post_id_column] = $post_id;
            }

            // Insert the log entry if we have data and required columns
            if (!empty($data) && isset($data[$message_column])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->insert($table_name, $data);
                if ($result === false) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log("[polytrans] Database error when inserting log: " . $wpdb->last_error);
                    return false;
                }
                return true;
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("[polytrans] Missing required columns for logging to database");
                return false;
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Error logging to database: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all column names for a table
     * 
     * @param string $table Table name
     * @return array Array of column names
     */
    private static function get_table_columns($table)
    {
        global $wpdb;
        static $columns_cache = [];

        // Return from cache if available
        if (isset($columns_cache[$table])) {
            return $columns_cache[$table];
        }

        // Get all columns for this table
        $columns = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $cols = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$table}`"
        );

        if ($cols) {
            foreach ($cols as $col) {
                $columns[] = $col->Field;
            }
            $columns_cache[$table] = $columns;
            return $columns;
        }

        // Return empty array if table doesn't exist or has no columns
        $columns_cache[$table] = [];
        return [];
    }

    /**
     * Get detailed column information for a table
     *
     * @param string $table Table name
     * @return array Array with column details including types
     */
    private static function get_table_column_details($table)
    {
        global $wpdb;
        static $details_cache = [];

        // Return from cache if available
        if (isset($details_cache[$table])) {
            return $details_cache[$table];
        }

        // Get detailed column information
        $column_details = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $cols = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$table}`"
        );

        if ($cols) {
            foreach ($cols as $col) {
                $column_details[$col->Field] = [
                    'type' => $col->Type,
                    'null' => $col->Null,
                    'key' => $col->Key,
                    'default' => $col->Default,
                    'extra' => $col->Extra
                ];
            }
            $details_cache[$table] = $column_details;
            return $column_details;
        }

        // Return empty array if table doesn't exist or has no columns
        $details_cache[$table] = [];
        return [];
    }

    /**
     * Find the first matching column name from a list of possibilities
     * 
     * @param array $existing_columns Columns that exist in the table
     * @param array $possible_columns Possible column names to look for
     * @return string|null First matching column or null if none found
     */
    private static function find_first_match($existing_columns, $possible_columns)
    {
        foreach ($possible_columns as $column) {
            if (in_array($column, $existing_columns)) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Check if a column exists in a table
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @return bool True if the column exists
     */
    private static function column_exists($table, $column)
    {
        global $wpdb;
        static $columns_cache = [];

        // Cache the column information to avoid repeated queries
        $cache_key = $table . '_columns';

        if (!isset($columns_cache[$cache_key])) {
            // Get all columns for this table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
            $cols = $wpdb->get_results(
                "SHOW COLUMNS FROM `{$table}`"
            );
            $columns_cache[$cache_key] = [];

            if ($cols) {
                foreach ($cols as $col) {
                    $columns_cache[$cache_key][] = $col->Field;
                }
            }
        }

        return in_array($column, $columns_cache[$cache_key]);
    }

    /**
     * AJAX handler for refreshing logs table
     */
    public static function ajax_refresh_logs()
    {
        // Check nonce
        if (!check_ajax_referer('polytrans_refresh_logs', 'nonce', false)) {
            wp_die('Security check failed');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Parse the filters from the form data
        $filters = isset($_POST['filters']) ? sanitize_text_field(wp_unslash($_POST['filters'])) : '';
        parse_str($filters, $filter_params);

        // Process filters
        $current_page = isset($filter_params['paged']) ? max(1, intval($filter_params['paged'])) : 1;
        $search = isset($filter_params['s']) ? sanitize_text_field($filter_params['s']) : '';
        $level = isset($filter_params['level']) ? sanitize_text_field($filter_params['level']) : '';
        $source = isset($filter_params['source']) ? sanitize_text_field($filter_params['source']) : '';
        $post_id = isset($filter_params['post_id']) ? intval($filter_params['post_id']) : 0;

        // Get logs
        $logs_data = self::get_logs([
            'page' => $current_page,
            'per_page' => 50,
            'search' => $search,
            'level' => $level,
            'source' => $source,
            'post_id' => $post_id
        ]);

        // Generate table HTML using Twig
        $html = self::render_logs_table($logs_data, $current_page, $search, $level, $source, $post_id);

        // Generate top pagination HTML (same as bottom, but for top nav)
        $base_url = admin_url('admin.php');
        $args = ['page' => 'polytrans-logs'];
        if (!empty($search)) {
            $args['s'] = $search;
        }
        if (!empty($level)) {
            $args['level'] = $level;
        }
        if (!empty($source)) {
            $args['source'] = $source;
        }
        if (!empty($post_id)) {
            $args['post_id'] = $post_id;
        }
        $base_url = add_query_arg($args, $base_url);
        
        $top_page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%', $base_url),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $logs_data['pages'],
            'current' => $current_page,
        ]);
        
        $top_pagination_html = '';
        if ($top_page_links) {
            $top_pagination_html = '<span class="pagination-links">' . $top_page_links . '</span>';
        }

        wp_send_json_success([
            'html' => $html,
            'current_page' => $current_page,
            'total_pages' => $logs_data['pages'],
            'top_pagination' => $top_pagination_html
        ]);
    }

    /**
     * Render just the logs table (for AJAX refresh)
     */
    private static function render_logs_table($logs_data, $current_page, $search = '', $level = '', $source = '', $post_id = 0)
    {
        // Build the base URL for pagination with all current filters
        $base_url = admin_url('admin.php');
        $args = ['page' => 'polytrans-logs'];

        if (!empty($search)) {
            $args['s'] = $search;
        }
        if (!empty($level)) {
            $args['level'] = $level;
        }
        if (!empty($source)) {
            $args['source'] = $source;
        }
        if (!empty($post_id)) {
            $args['post_id'] = $post_id;
        }

        $base_url = add_query_arg($args, $base_url);

        $page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%', $base_url),
            'format' => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total' => $logs_data['pages'],
            'current' => $current_page,
        ]);

        $bottom_pagination = '';
        if ($page_links) {
            $bottom_pagination = '<span class="pagination-links">' . $page_links . '</span>';
        }

        // Render table template
        return TemplateRenderer::render('admin/logs/table.twig', [
            'logs_data' => $logs_data,
            'bottom_pagination' => $bottom_pagination,
        ], false); // Don't enqueue assets for AJAX responses
    }
}

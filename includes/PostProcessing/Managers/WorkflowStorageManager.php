<?php

namespace PolyTrans\PostProcessing\Managers;

use PolyTrans\Core\Diagnostics;

/**
 * Workflow Storage Manager
 * 
 * Handles saving, loading, and managing workflow definitions
 * in the WordPress database.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WorkflowStorageManager
{
    const TABLE_NAME = 'polytrans_workflows';
    const LEGACY_OPTION_NAME = 'polytrans_workflows';
    const SETTINGS_OPTION_NAME = 'polytrans_postprocessing_settings';
    const MIGRATION_FLAG = 'polytrans_workflows_migrated';
    const DEFAULT_PRIORITY = 100;
    private static bool $priority_column_checked = false;

    /**
     * Initialize - Create table and migrate if needed
     * Called on plugin activation and admin_init
     */
    public static function initialize()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name);

        if (!$table_exists) {
            // Create table
            self::create_workflows_table();

            // Migrate from wp_options if data exists
            $legacy_data = get_option(self::LEGACY_OPTION_NAME, []);
            if (!empty($legacy_data)) {
                self::migrate_from_options($legacy_data);
            }

            return;
        }

        self::ensure_priority_column();

        // Table exists - check if we need to migrate
        $migrated = get_option(self::MIGRATION_FLAG, false);

        if (!$migrated) {
            $legacy_data = get_option(self::LEGACY_OPTION_NAME, []);
            if (!empty($legacy_data)) {
                self::migrate_from_options($legacy_data);
            } else {
                // No data to migrate, just set flag
                update_option(self::MIGRATION_FLAG, true);
            }
        }
    }

    /**
     * Create workflows table using dbDelta
     */
    private static function create_workflows_table()
    {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workflow_id varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            language varchar(10) NOT NULL,
            enabled tinyint(1) DEFAULT 1,
            priority int(11) NOT NULL DEFAULT 100,
            triggers text NOT NULL,
            steps longtext NOT NULL,
            output_actions text NOT NULL,
            attribution_user_id bigint(20) unsigned,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            created_by bigint(20) unsigned,
            PRIMARY KEY  (id),
            UNIQUE KEY workflow_id (workflow_id),
            KEY language (language),
            KEY enabled (enabled),
            KEY name (name)
        ) $charset_collate;";

        dbDelta($sql);

        Diagnostics::log("Created workflows table: $table_name");
    }

    /**
     * Migrate workflows from wp_options to database table
     */
    private static function migrate_from_options($legacy_workflows)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $migrated_count = 0;
        $errors = [];

        // Backup legacy data first
        update_option(self::LEGACY_OPTION_NAME . '_backup', $legacy_workflows);

        foreach ($legacy_workflows as $workflow) {
            try {
                // Prepare data for insertion
                $data = [
                    'workflow_id' => $workflow['id'] ?? 'workflow_' . uniqid(),
                    'name' => $workflow['name'] ?? 'Unnamed Workflow',
                    'description' => $workflow['description'] ?? '',
                    'language' => $workflow['language'] ?? $workflow['target_language'] ?? '',
                    'enabled' => isset($workflow['enabled']) ? (int)$workflow['enabled'] : 1,
                    'priority' => isset($workflow['priority']) ? (int) $workflow['priority'] : self::DEFAULT_PRIORITY,

                    // JSON encode complex fields
                    'triggers' => wp_json_encode($workflow['triggers'] ?? []),
                    'steps' => wp_json_encode($workflow['steps'] ?? []),
                    'output_actions' => wp_json_encode($workflow['output_actions'] ?? []),

                    'attribution_user_id' => $workflow['attribution_user'] ?? null,

                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'created_by' => get_current_user_id()
                ];

                // Insert into table
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $result = $wpdb->insert($table_name, $data);

                if ($result) {
                    $migrated_count++;
                } else {
                    $errors[] = "Failed to migrate workflow: {$data['workflow_id']} - " . $wpdb->last_error;
                }
            } catch (\Exception $e) {
                $errors[] = "Exception migrating workflow: " . $e->getMessage();
            }
        }

        // Set migration flag
        update_option(self::MIGRATION_FLAG, true);

        // Log results
        Diagnostics::log("Workflow migration complete: $migrated_count workflows migrated");
        if (!empty($errors)) {
            Diagnostics::log("Migration errors: " . implode("; ", $errors));
        }

        return [
            'success' => empty($errors),
            'migrated' => $migrated_count,
            'errors' => $errors
        ];
    }

    /**
     * Ensure the priority column exists on installations created before
     * workflow execution ordering was added. Do not add an index here; workflow
     * tables are small and avoiding an extra migration lock is preferable.
     */
    private static function ensure_priority_column(): void
    {
        if (self::$priority_column_checked) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $column = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
                "SHOW COLUMNS FROM $table_name LIKE %s",
                'priority'
            )
        );

        if (!$column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix; the default is an integer class constant, not input
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN priority int(11) NOT NULL DEFAULT " . self::DEFAULT_PRIORITY . " AFTER enabled");
        }

        self::$priority_column_checked = true;
    }

    /**
     * Hydrate workflow from database row (decode JSON, backward compatibility)
     */
    private static function hydrate_workflow($row)
    {
        return [
            'id' => $row['workflow_id'],  // Map workflow_id to 'id' for backward compatibility
            'name' => $row['name'],
            'description' => $row['description'],
            'language' => $row['language'],
            'target_language' => $row['language'],  // Alias for backward compatibility
            'enabled' => (bool)$row['enabled'],
            'priority' => isset($row['priority']) ? (int) $row['priority'] : self::DEFAULT_PRIORITY,

            'triggers' => json_decode($row['triggers'], true) ?: [],
            'steps' => json_decode($row['steps'], true) ?: [],
            'output_actions' => json_decode($row['output_actions'], true) ?: [],

            'attribution_user' => $row['attribution_user_id'],

            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'created_by' => $row['created_by']
        ];
    }

    /**
     * Get all workflows
     *
     * @return array Array of workflow definitions
     */
    public function get_all_workflows()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        self::ensure_priority_column();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY priority ASC, name ASC", ARRAY_A);

        if (!$results) {
            return [];
        }

        // Decode JSON fields and apply backward compatibility
        $workflows = [];
        foreach ($results as $row) {
            $workflows[] = self::hydrate_workflow($row);
        }

        return $workflows;
    }

    /**
     * Get workflows for a specific language
     *
     * Returns workflows that either:
     * - Match the specified language exactly
     * - Have no language specified (applies to all languages)
     *
     * @param string $language Language code
     * @return array Array of workflows for the language
     */
    public function get_workflows_for_language($language)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        self::ensure_priority_column();

        // Get workflows for this specific language OR workflows with empty language (all languages)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
                "SELECT * FROM $table_name WHERE language = %s OR language = '' OR language IS NULL ORDER BY priority ASC, name ASC",
                $language
            ),
            ARRAY_A
        );

        if (!$results) {
            return [];
        }

        $workflows = [];
        foreach ($results as $row) {
            $workflows[] = self::hydrate_workflow($row);
        }

        return $workflows;
    }

    /**
     * Get a specific workflow by ID
     *
     * @param string $workflow_id Workflow ID
     * @return array|null Workflow definition or null if not found
     */
    public function get_workflow($workflow_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
            $wpdb->prepare("SELECT * FROM $table_name WHERE workflow_id = %s", $workflow_id),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return self::hydrate_workflow($row);
    }

    /**
     * Save a workflow (insert or update)
     *
     * @param array $workflow Workflow definition
     * @return array Result with 'success' and 'errors'
     */
    public function save_workflow($workflow)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        self::ensure_priority_column();

        // Validate workflow structure
        $validation = $this->validate_workflow($workflow);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $workflow_id = $workflow['id'];

        // Check if workflow exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
            $wpdb->prepare("SELECT id FROM $table_name WHERE workflow_id = %s", $workflow_id)
        );

        // Prepare data for insert/update
        $data = [
            'workflow_id' => $workflow_id,
            'name' => $workflow['name'] ?? 'Unnamed Workflow',
            'description' => $workflow['description'] ?? '',
            'language' => $workflow['language'] ?? $workflow['target_language'] ?? '',
            'enabled' => isset($workflow['enabled']) ? (int)$workflow['enabled'] : 1,
            'priority' => isset($workflow['priority']) ? (int) $workflow['priority'] : self::DEFAULT_PRIORITY,

            'triggers' => wp_json_encode($workflow['triggers'] ?? []),
            'steps' => wp_json_encode($workflow['steps'] ?? []),
            'output_actions' => wp_json_encode($workflow['output_actions'] ?? []),

            'attribution_user_id' => $workflow['attribution_user'] ?? null,
            'updated_at' => current_time('mysql')
        ];

        if ($exists) {
            // Update existing workflow
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table_name,
                $data,
                ['workflow_id' => $workflow_id]
            );

            $success = $result !== false;
        } else {
            // Insert new workflow
            $data['created_at'] = current_time('mysql');
            $data['created_by'] = get_current_user_id();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert($table_name, $data);

            $success = $result !== false;
        }

        $errors = [];
        if (!$success) {
            $errors[] = $wpdb->last_error ?: 'Unknown error saving workflow';
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * Delete a workflow
     *
     * @param string $workflow_id Workflow ID
     * @return bool Success status
     */
    public function delete_workflow($workflow_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($table_name, ['workflow_id' => $workflow_id]);

        return $result !== false;
    }

    /**
     * Duplicate a workflow
     * 
     * @param string $workflow_id Source workflow ID
     * @param string $new_name New workflow name
     * @return string|false New workflow ID or false on failure
     */
    public function duplicate_workflow($workflow_id, $new_name = '')
    {
        $workflow = $this->get_workflow($workflow_id);
        if (!$workflow) {
            return false;
        }

        // Generate new ID and name
        $new_id = 'workflow_' . uniqid();
        $workflow['id'] = $new_id;
        $workflow['name'] = $new_name ?: ($workflow['name'] . ' (Copy)');

        // Save the duplicated workflow
        if ($this->save_workflow($workflow)) {
            return $new_id;
        }

        return false;
    }

    /**
     * Validate workflow structure
     * 
     * @param array $workflow Workflow to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validate_workflow($workflow)
    {
        $errors = [];

        // Check required fields
        if (!isset($workflow['id']) || empty($workflow['id'])) {
            $errors[] = 'Workflow ID is required';
        }

        if (!isset($workflow['name']) || empty($workflow['name'])) {
            $errors[] = 'Workflow name is required';
        }

        // Language is optional - empty means "all languages"
        // No validation error for missing language

        if (!isset($workflow['steps']) || !is_array($workflow['steps'])) {
            $errors[] = 'Workflow must have steps array';
        } elseif (empty($workflow['steps'])) {
            $errors[] = 'Workflow must have at least one step';
        }

        // Validate steps
        if (isset($workflow['steps']) && is_array($workflow['steps'])) {
            foreach ($workflow['steps'] as $index => $step) {
                $step_errors = $this->validate_step($step, $index);
                $errors = array_merge($errors, $step_errors);
            }
        }

        // Check for duplicate step IDs
        if (isset($workflow['steps']) && is_array($workflow['steps'])) {
            $step_ids = [];
            foreach ($workflow['steps'] as $step) {
                if (isset($step['id'])) {
                    if (in_array($step['id'], $step_ids)) {
                        $errors[] = "Duplicate step ID: {$step['id']}";
                    }
                    $step_ids[] = $step['id'];
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate individual step
     * 
     * @param array $step Step configuration
     * @param int $index Step index
     * @return array Array of error messages
     */
    private function validate_step($step, $index)
    {
        $errors = [];

        // Check required fields
        if (!isset($step['id']) || empty($step['id'])) {
            $errors[] = "Step {$index}: ID is required";
        }

        if (!isset($step['type']) || empty($step['type'])) {
            $errors[] = "Step {$index}: Type is required";
        }

        if (!isset($step['name']) || empty($step['name'])) {
            $errors[] = "Step {$index}: Name is required";
        }

        // Validate specific step types
        if (isset($step['type'])) {
            switch ($step['type']) {
                case 'ai_assistant':
                    if (!isset($step['system_prompt']) || empty($step['system_prompt'])) {
                        $errors[] = "Step {$index}: System prompt is required for AI assistant steps";
                    }
                    break;

                    // Add validation for other step types as they are implemented
            }
        }

        return $errors;
    }

    /**
     * Get post-processing settings
     * 
     * @return array Settings array
     */
    public function get_settings()
    {
        return get_option(self::SETTINGS_OPTION_NAME, [
            'enabled' => true,
            'max_execution_time' => 300, // 5 minutes
            'retry_attempts' => 3,
            'log_level' => 'info'
        ]);
    }

    /**
     * Save post-processing settings
     * 
     * @param array $settings Settings to save
     * @return bool Success status
     */
    public function save_settings($settings)
    {
        $current_settings = $this->get_settings();
        $updated_settings = array_merge($current_settings, $settings);

        return update_option(self::SETTINGS_OPTION_NAME, $updated_settings);
    }

    /**
     * Export workflows to JSON
     * 
     * @param array $workflow_ids Array of workflow IDs to export (empty for all)
     * @return string JSON export data
     */
    public function export_workflows($workflow_ids = [])
    {
        $workflows = $this->get_all_workflows();

        if (!empty($workflow_ids)) {
            $workflows = array_filter($workflows, function ($workflow) use ($workflow_ids) {
                return isset($workflow['id']) && in_array($workflow['id'], $workflow_ids);
            });
        }

        $export_data = [
            'version' => '1.0',
            'export_date' => current_time('Y-m-d H:i:s'),
            'workflows' => array_values($workflows)
        ];

        return wp_json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import workflows from JSON
     * 
     * @param string $json_data JSON import data
     * @param bool $overwrite Whether to overwrite existing workflows
     * @return array Import result with 'success', 'imported', 'skipped', 'errors'
     */
    public function import_workflows($json_data, $overwrite = false)
    {
        $result = [
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        $import_data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['errors'][] = 'Invalid JSON format';
            return $result;
        }

        if (!isset($import_data['workflows']) || !is_array($import_data['workflows'])) {
            $result['errors'][] = 'No workflows found in import data';
            return $result;
        }

        foreach ($import_data['workflows'] as $workflow) {
            // Generate new ID if workflow already exists and not overwriting
            if (!$overwrite && $this->get_workflow($workflow['id'])) {
                $workflow['id'] = 'workflow_' . uniqid();
                $workflow['name'] = $workflow['name'] . ' (Imported)';
            }

            // Validate workflow
            $validation = $this->validate_workflow($workflow);
            if (!$validation['valid']) {
                $result['errors'][] = "Workflow '{$workflow['name']}': " . implode(', ', $validation['errors']);
                $result['skipped']++;
                continue;
            }

            // Save workflow
            if ($this->save_workflow($workflow)) {
                $result['imported']++;
            } else {
                $result['errors'][] = "Failed to save workflow '{$workflow['name']}'";
                $result['skipped']++;
            }
        }

        $result['success'] = $result['imported'] > 0;
        return $result;
    }

    /**
     * Get workflow statistics
     * 
     * @return array Statistics about workflows
     */
    public function get_workflow_statistics()
    {
        $workflows = $this->get_all_workflows();

        $stats = [
            'total_workflows' => count($workflows),
            'enabled_workflows' => 0,
            'disabled_workflows' => 0,
            'languages' => [],
            'step_types' => []
        ];

        foreach ($workflows as $workflow) {
            // Count enabled/disabled
            if (isset($workflow['enabled']) && $workflow['enabled']) {
                $stats['enabled_workflows']++;
            } else {
                $stats['disabled_workflows']++;
            }

            // Count languages
            if (isset($workflow['language'])) {
                $lang = $workflow['language'];
                $stats['languages'][$lang] = ($stats['languages'][$lang] ?? 0) + 1;
            }

            // Count step types
            if (isset($workflow['steps']) && is_array($workflow['steps'])) {
                foreach ($workflow['steps'] as $step) {
                    if (isset($step['type'])) {
                        $type = $step['type'];
                        $stats['step_types'][$type] = ($stats['step_types'][$type] ?? 0) + 1;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Clean up orphaned workflow data
     * Deletes workflows with invalid structure from database
     *
     * @return int Number of items cleaned up
     */
    public function cleanup_orphaned_data()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $cleaned = 0;

        // Get all workflows from database
        $workflows = $this->get_all_workflows();

        foreach ($workflows as $workflow) {
            $validation = $this->validate_workflow($workflow);
            if (!$validation['valid']) {
                // Delete invalid workflow
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->delete($table_name, ['workflow_id' => $workflow['id']]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get workflows using a specific assistant
     * For Phase 1 - assistants system
     *
     * @param int|string $assistant_id Assistant ID to search for
     * @return array Workflows using this assistant
     */
    public function get_workflows_using_assistant($assistant_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Search for assistant_id in steps JSON
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix constant
                "SELECT * FROM $table_name WHERE steps LIKE %s",
                '%"assistant_id":' . $wpdb->esc_like($assistant_id) . '%'
            ),
            ARRAY_A
        );

        if (!$results) {
            return [];
        }

        $workflows = [];
        foreach ($results as $row) {
            $workflow = self::hydrate_workflow($row);

            // Verify assistant is actually used (not just substring match)
            foreach ($workflow['steps'] as $step) {
                if (isset($step['assistant_id']) && $step['assistant_id'] == $assistant_id) {
                    $workflows[] = $workflow;
                    break;
                }
            }
        }

        return $workflows;
    }
}

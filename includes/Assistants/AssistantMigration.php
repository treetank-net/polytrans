<?php

/**
 * Assistant Migration Manager
 * 
 * Handles automatic migration of legacy workflow steps to managed assistants.
 * Runs on plugin activation/update to ensure consistency between system assistants
 * and workflow configurations.
 */

namespace PolyTrans\Assistants;

if (!defined('ABSPATH')) {
    exit;
}

use PolyTrans\PostProcessing\Managers\WorkflowStorageManager;
use PolyTrans\Core\LogsManager;


class AssistantMigration
{
    /**
     * Run migration
     * 
     * Migrates all ai_assistant steps to managed_assistant steps by:
     * 1. Creating managed assistants from ai_assistant configurations
     * 2. Updating workflow steps to reference the new managed assistants
     * 3. Preserving all output actions and settings
     * 
     * @return array Migration result with statistics
     */
    public static function migrate_workflows_to_managed_assistants()
    {
        $stats = [
            'workflows_processed' => 0,
            'steps_migrated' => 0,
            'assistants_created' => 0,
            'errors' => []
        ];

        LogsManager::log("Starting workflow migration to managed assistants", 'info');

        try {
            // Get all workflows
            $storage_manager = new WorkflowStorageManager();
            $workflows = $storage_manager->get_all_workflows();

            if (empty($workflows)) {
                return $stats;
            }

            foreach ($workflows as $workflow) {
                self::migrate_workflow_array($workflow, $stats, $storage_manager);
            }

            // Log migration summary
            LogsManager::log(
                "Assistant migration completed: {$stats['workflows_processed']} workflows processed, {$stats['steps_migrated']} steps migrated, {$stats['assistants_created']} assistants created",
                'info',
                $stats
            );
        } catch (\Exception $e) {
            $stats['errors'][] = "Migration failed: {$e->getMessage()}";
            LogsManager::log("Assistant migration failed: {$e->getMessage()}", 'error');
        }

        return $stats;
    }

    /**
     * Migrate one workflow to managed assistants.
     *
     * @param string $workflow_id Workflow ID.
     * @return array Migration result with statistics.
     */
    public static function migrate_workflow_to_managed_assistants($workflow_id)
    {
        $stats = [
            'workflows_processed' => 0,
            'steps_migrated' => 0,
            'assistants_created' => 0,
            'errors' => []
        ];

        LogsManager::log("Starting single workflow migration to managed assistants", 'info', ['workflow_id' => $workflow_id]);

        try {
            $storage_manager = new WorkflowStorageManager();
            $workflow = $storage_manager->get_workflow($workflow_id);

            if (!$workflow) {
                $stats['errors'][] = __('Workflow not found.', 'treetank-trans');
                return $stats;
            }

            self::migrate_workflow_array($workflow, $stats, $storage_manager);

            LogsManager::log(
                "Single workflow migration completed: {$stats['workflows_processed']} workflows processed, {$stats['steps_migrated']} steps migrated, {$stats['assistants_created']} assistants created",
                'info',
                $stats
            );
        } catch (\Exception $e) {
            $stats['errors'][] = "Migration failed: {$e->getMessage()}";
            LogsManager::log("Single workflow migration failed: {$e->getMessage()}", 'error', ['workflow_id' => $workflow_id]);
        }

        return $stats;
    }

    /**
     * Migrate legacy AI assistant steps in a workflow array.
     *
     * @param array $workflow Workflow data.
     * @param array $stats Migration statistics, updated by reference.
     * @param WorkflowStorageManager $storage_manager Workflow storage manager.
     * @return void
     */
    private static function migrate_workflow_array($workflow, &$stats, $storage_manager)
    {
        $workflow_modified = false;
        $stats['workflows_processed']++;

        if (empty($workflow['steps']) || !is_array($workflow['steps'])) {
            return;
        }

        foreach ($workflow['steps'] as $step_index => &$step) {
            $step_type = $step['type'] ?? 'unknown';

            // Only migrate ai_assistant steps
            if ($step_type !== 'ai_assistant') {
                continue;
            }

            try {
                // Create managed assistant from ai_assistant config
                $assistant_id = self::create_managed_assistant_from_step($step, $workflow);

                if ($assistant_id) {
                    // Update step to use managed assistant
                    $workflow['steps'][$step_index] = self::convert_step_to_managed($step, $assistant_id);
                    $stats['steps_migrated']++;
                    $stats['assistants_created']++;
                    $workflow_modified = true;

                    LogsManager::log(
                        "Migrated workflow '{$workflow['name']}' step '{$step['name']}' to managed assistant (ID: {$assistant_id})",
                        'info',
                        ['workflow_id' => $workflow['id'], 'step_index' => $step_index, 'assistant_id' => $assistant_id]
                    );
                } else {
                    $error_msg = "Failed to create managed assistant for step '{$step['name']}' in workflow '{$workflow['name']}'";
                    $stats['errors'][] = $error_msg;
                    LogsManager::log($error_msg, 'error');
                }
            } catch (\Exception $e) {
                $error_msg = "Failed to migrate step '{$step['name']}' in workflow '{$workflow['name']}': {$e->getMessage()}";
                $stats['errors'][] = $error_msg;
                LogsManager::log($error_msg, 'error');
            }
        }
        unset($step);

        if ($workflow_modified) {
            $storage_manager->save_workflow($workflow);
        }
    }

    /**
     * Create managed assistant from ai_assistant step configuration
     * 
     * @param array $step ai_assistant step configuration
     * @param array $workflow Parent workflow (for context in naming)
     * @return int|false Assistant ID or false on failure
     */
    private static function create_managed_assistant_from_step($step, $workflow)
    {
        // Build assistant name from workflow and step names
        $workflow_name = $workflow['name'] ?? 'Unnamed Workflow';
        $step_name = $step['name'] ?? 'Unnamed Step';
        $assistant_name = "[Migrated] {$workflow_name} - {$step_name}";

        // Extract configuration from step
        $system_prompt = $step['system_prompt'] ?? '';
        $user_message = $step['user_message'] ?? '';

        // Convert legacy {variable} syntax to Twig {{ variable }} if needed
        if (strpos($system_prompt, '{{') === false && strpos($system_prompt, '{%') === false) {
            $system_prompt = preg_replace('/\{([a-zA-Z0-9_\.]+)\}/', '{{ $1 }}', $system_prompt);
        }
        if (strpos($user_message, '{{') === false && strpos($user_message, '{%') === false) {
            $user_message = preg_replace('/\{([a-zA-Z0-9_\.]+)\}/', '{{ $1 }}', $user_message);
        }

        // Extract model (default to gpt-4o-mini if not specified)
        $model = !empty($step['model']) ? $step['model'] : 'gpt-4o-mini';

        // Extract response format
        $response_format = $step['expected_format'] ?? 'text';

        // Extract configuration
        $config = [
            'temperature' => $step['temperature'] ?? 0.7
        ];

        // Add migration metadata
        $config['migrated_from'] = [
            'workflow_id' => $workflow['id'] ?? null,
            'workflow_name' => $workflow_name,
            'step_name' => $step_name,
            'migration_date' => current_time('mysql')
        ];

        // Create assistant data matching Assistant Manager structure
        // Note: Assistant Manager expects system_prompt and api_parameters
        $api_parameters = [
            'model' => $model,
            'temperature' => $config['temperature']
        ];

        // Carry over reasoning effort for models that use it instead of temperature
        if (!empty($step['reasoning_effort'])) {
            $api_parameters['reasoning_effort'] = (string) $step['reasoning_effort'];
        }

        $assistant_data = [
            'name' => $assistant_name,
            'provider' => 'openai',
            'system_prompt' => $system_prompt, // Keep system prompt separate
            'user_message_template' => $user_message, // Keep user message separate
            'api_parameters' => json_encode($api_parameters),
            'expected_format' => $response_format,
            'output_variables' => null
        ];

        // Check if similar assistant already exists (to avoid duplicates)
        $existing_assistant = self::find_existing_assistant($assistant_name, $system_prompt, $user_message);
        if ($existing_assistant) {
            return $existing_assistant['id'];
        }

        // Create new assistant
        $result = AssistantManager::create_assistant($assistant_data);

        // Check if result is WP_Error
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();
            LogsManager::log(
                "Failed to create assistant '{$assistant_name}': {$error_message}",
                'error',
                ['error_data' => $error_data]
            );
            return false;
        }

        return $result;
    }

    /**
     * Build prompt template from system prompt and user message
     * 
     * Converts legacy {variable} syntax to Twig {{ variable }} syntax
     * 
     * @param string $system_prompt System prompt
     * @param string $user_message User message template
     * @return string Combined prompt template
     */
    private static function build_prompt_template($system_prompt, $user_message)
    {
        $template = '';

        // Add system prompt as context
        if (!empty($system_prompt)) {
            $template .= "# System Instructions\n\n";
            $template .= $system_prompt . "\n\n";
        }

        // Add user message
        if (!empty($user_message)) {
            $template .= "# Task\n\n";
            $template .= $user_message;
        }

        // Convert legacy {variable} syntax to Twig {{ variable }}
        // But only if not already using Twig syntax
        if (strpos($template, '{{') === false && strpos($template, '{%') === false) {
            $template = preg_replace('/\{([a-zA-Z0-9_\.]+)\}/', '{{ $1 }}', $template);
        }

        return $template;
    }

    /**
     * Convert ai_assistant step to managed_assistant step
     * 
     * @param array $step Original step configuration
     * @param int $assistant_id Managed assistant ID
     * @return array Updated step configuration
     */
    private static function convert_step_to_managed($step, $assistant_id)
    {
        // Keep only essential fields for managed_assistant step
        $converted_step = [
            'id' => $step['id'] ?? uniqid('step_'),
            'name' => $step['name'] ?? 'Unnamed Step',
            'type' => 'managed_assistant',
            'enabled' => $step['enabled'] ?? true,
            'assistant_id' => $assistant_id,
            'output_actions' => $step['output_actions'] ?? []
        ];

        // Add migration metadata
        $converted_step['_migration_info'] = [
            'original_type' => 'ai_assistant',
            'migrated_at' => current_time('mysql'),
            'original_config' => [
                'model' => $step['model'] ?? null,
                'temperature' => $step['temperature'] ?? null,
                'expected_format' => $step['expected_format'] ?? null
            ]
        ];

        return $converted_step;
    }

    /**
     * Find existing assistant with same name and prompts
     * 
     * @param string $name Assistant name
     * @param string $system_prompt System prompt
     * @param string $user_message User message template
     * @return array|null Existing assistant or null
     */
    private static function find_existing_assistant($name, $system_prompt, $user_message)
    {
        $all_assistants = AssistantManager::get_all_assistants();

        foreach ($all_assistants as $assistant) {
            // Match by name, system prompt and user message
            if (
                $assistant['name'] === $name
                && $assistant['system_prompt'] === $system_prompt
                && $assistant['user_message_template'] === $user_message
            ) {
                return $assistant;
            }
        }

        return null;
    }

    /**
     * Check if migration is needed
     * 
     * @return bool True if there are ai_assistant steps to migrate
     */
    public static function is_migration_needed()
    {
        try {
            $storage_manager = new WorkflowStorageManager();
            $workflows = $storage_manager->get_all_workflows();

            foreach ($workflows as $workflow) {
                if (empty($workflow['steps'])) {
                    continue;
                }

                foreach ($workflow['steps'] as $step) {
                    if (($step['type'] ?? '') === 'ai_assistant') {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            LogsManager::log("Failed to check migration status: {$e->getMessage()}", 'error');
        }

        return false;
    }

    /**
     * Get migration status
     * 
     * @return array Migration status with counts
     */
    public static function get_migration_status()
    {
        $status = [
            'ai_assistant_steps' => 0,
            'managed_assistant_steps' => 0,
            'predefined_assistant_steps' => 0,
            'total_workflows' => 0,
            'migration_needed' => false
        ];

        try {
            $storage_manager = new WorkflowStorageManager();
            $workflows = $storage_manager->get_all_workflows();
            $status['total_workflows'] = count($workflows);

            foreach ($workflows as $workflow) {
                if (empty($workflow['steps'])) {
                    continue;
                }

                foreach ($workflow['steps'] as $step) {
                    $type = $step['type'] ?? '';

                    switch ($type) {
                        case 'ai_assistant':
                            $status['ai_assistant_steps']++;
                            $status['migration_needed'] = true;
                            break;
                        case 'managed_assistant':
                            $status['managed_assistant_steps']++;
                            break;
                        case 'predefined_assistant':
                            $status['predefined_assistant_steps']++;
                            break;
                    }
                }
            }
        } catch (\Exception $e) {
            LogsManager::log("Failed to get migration status: {$e->getMessage()}", 'error');
        }

        return $status;
    }
}

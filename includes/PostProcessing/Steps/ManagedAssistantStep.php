<?php

/**
 * Managed AI Assistant Workflow Step
 * 
 * Uses the centralized AI Assistants Management System (Phase 1).
 * Executes assistants configured via Admin UI with Twig variable interpolation.
 * 
 * This step type uses the new Assistant Executor which provides:
 * - Centralized assistant management
 * - Multi-provider support (OpenAI, Claude, Gemini)
 * - Twig template engine for variable interpolation
 * - Comprehensive error handling
 */

namespace PolyTrans\PostProcessing\Steps;

use PolyTrans\PostProcessing\WorkflowStepInterface;
use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantExecutor;

if (!defined('ABSPATH')) {
    exit;
}

class ManagedAssistantStep implements WorkflowStepInterface
{
    /**
     * Get the step type identifier
     */
    public function get_type()
    {
        return 'managed_assistant';
    }

    /**
     * Get the step name/title
     */
    public function get_name()
    {
        return __('Managed AI Assistant', 'polytrans');
    }

    /**
     * Get the step description
     */
    public function get_description()
    {
        return __('Use a centrally managed AI assistant with Twig variable interpolation', 'polytrans');
    }

    /**
     * Execute the workflow step
     */
    public function execute($context, $step_config)
    {
        $start_time = microtime(true);

        try {
            // Get assistant ID from config
            $assistant_id = $step_config['assistant_id'] ?? 0;
            if (empty($assistant_id)) {
                return [
                    'success' => false,
                    'error' => 'Assistant ID is required'
                ];
            }

            // Verify assistant exists
            $assistant = AssistantManager::get_assistant($assistant_id);
            if (!$assistant) {
                return [
                    'success' => false,
                    'error' => "Assistant with ID {$assistant_id} not found"
                ];
            }

            $assistant_config = $assistant;
            $step_id = (string) ($step_config['id'] ?? '');
            $prompt_overrides = $this->get_prompt_overrides($context, $step_id, $assistant_id);

            if (!empty($prompt_overrides)) {
                if (array_key_exists('system_prompt', $prompt_overrides)) {
                    $assistant_config['system_prompt'] = (string) $prompt_overrides['system_prompt'];
                }
                if (array_key_exists('user_message_template', $prompt_overrides)) {
                    $assistant_config['user_message_template'] = (string) $prompt_overrides['user_message_template'];
                }
                if (array_key_exists('expected_output_schema', $prompt_overrides)) {
                    $assistant_config['expected_output_schema'] = (string) $prompt_overrides['expected_output_schema'];
                }
            }

            // Execute assistant with context as variables
            $result = !empty($prompt_overrides)
                ? AssistantExecutor::execute_with_config($assistant_config, $context)
                : AssistantExecutor::execute($assistant_id, $context);

            $execution_time = microtime(true) - $start_time;

            // Check if result is WP_Error
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'execution_time' => $execution_time,
                    'assistant_id' => $assistant_id,
                    'assistant_name' => $assistant['name']
                ];
            }

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Assistant execution failed',
                    'execution_time' => $execution_time,
                    'assistant_id' => $assistant_id,
                    'assistant_name' => $assistant['name']
                ];
            }

            // Get the AI output
            $ai_output = $result['output'] ?? $result['data'] ?? null;

            // Validate AI output is not empty (fallback check - primary validation in AssistantExecutor)
            if ($ai_output === null || (is_string($ai_output) && trim($ai_output) === '')) {
                \PolyTrans\Core\LogsManager::log(
                    "Assistant '{$assistant['name']}' returned empty output - step rejected",
                    'error',
                    ['assistant_id' => $assistant_id]
                );
                return [
                    'success' => false,
                    'error' => 'AI returned empty response - step rejected to prevent data loss',
                    'execution_time' => $execution_time,
                    'assistant_id' => $assistant_id,
                    'assistant_name' => $assistant['name']
                ];
            }

            // Parse output using schema if defined
            $parsed_data = ['ai_response' => $ai_output];
            $parse_warnings = [];
            $auto_actions = [];

            if (!empty($assistant_config['expected_output_schema']) && ($assistant_config['expected_format'] ?? '') === 'json') {
                // Interpolate schema through Twig (allows dynamic field generation)
                $schema = $assistant_config['expected_output_schema'];
                if (is_string($schema)) {
                    $variable_manager = new \PolyTrans\PostProcessing\VariableManager();
                    $interpolated_schema = $variable_manager->interpolate_template($schema, $context);
                    $schema = json_decode($interpolated_schema, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        \PolyTrans\Core\LogsManager::log(
                            "Schema interpolation resulted in invalid JSON: " . json_last_error_msg(),
                            'error',
                            ['schema_preview' => substr($interpolated_schema, 0, 500)]
                        );
                        $schema = [];
                    }
                }

                $parser = new \PolyTrans_JSON_Response_Parser();
                $parse_result = $parser->parse_with_schema($ai_output, $schema);

                if ($parse_result['success']) {
                    // Use parsed structured data
                    $parsed_data = $parse_result['data'];
                    $parse_warnings = $parse_result['warnings'] ?? [];

                    // Generate auto-actions from mappings
                    if (!empty($parse_result['mappings'])) {
                        $auto_actions = $this->generate_auto_actions($parse_result['mappings'], $parsed_data);

                        PolyTrans_Logs_Manager::log(
                            "Assistant '{$assistant['name']}' generated " . count($auto_actions) . " auto-actions from schema mappings",
                            'info',
                            ['assistant_id' => $assistant_id, 'auto_actions' => $auto_actions]
                        );
                    }

                    // Log warnings if any
                    if (!empty($parse_warnings)) {
                        PolyTrans_Logs_Manager::log(
                            "Assistant '{$assistant['name']}' response parsing warnings: " . implode(', ', $parse_warnings),
                            'warning',
                            ['assistant_id' => $assistant_id, 'step_type' => 'managed_assistant']
                        );
                    }
                } else {
                    // Parsing failed, log error but continue with raw output
                    PolyTrans_Logs_Manager::log(
                        "Assistant '{$assistant['name']}' response parsing failed: " . $parse_result['error'],
                        'error',
                        ['assistant_id' => $assistant_id, 'raw_output' => substr($ai_output, 0, 500)]
                    );
                    // Fallback to wrapping raw output
                    $parsed_data = ['ai_response' => $ai_output];
                }
            }

            // Return successful result
            return [
                'success' => true,
                'data' => $parsed_data,  // Structured data from parser or wrapped raw output
                'auto_actions' => $auto_actions,  // Auto-generated actions from schema mappings
                'execution_time' => $execution_time,
                'assistant_id' => $assistant_id,
                'assistant_name' => $assistant['name'],
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
                'usage' => $result['usage'] ?? [],
                'parse_warnings' => $parse_warnings,  // Include parsing warnings
                'interpolated_system_prompt' => $result['interpolated_system_prompt'] ?? null,
                'interpolated_user_message' => $result['interpolated_user_message'] ?? null,
                'tokens_used' => $result['usage'] ?? null,
                'raw_response' => $result['raw_response'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start_time
            ];
        }
    }

    /**
     * Get temporary prompt overrides for workflow tester/refinement runs.
     *
     * @param array $context Workflow execution context
     * @param string $step_id Workflow step ID
     * @param int $assistant_id Managed assistant ID
     * @return array
     */
    private function get_prompt_overrides($context, $step_id, $assistant_id)
    {
        $overrides = is_array($context['__assistant_prompt_overrides'] ?? null)
            ? $context['__assistant_prompt_overrides']
            : [];

        if ($step_id !== '' && isset($overrides[$step_id]) && is_array($overrides[$step_id])) {
            return $overrides[$step_id];
        }

        $assistant_key = 'assistant_' . (int) $assistant_id;
        if (isset($overrides[$assistant_key]) && is_array($overrides[$assistant_key])) {
            return $overrides[$assistant_key];
        }

        return [];
    }

    /**
     * Generate auto-actions from schema mappings
     * 
     * @param array $mappings Schema mappings with target definitions
     * @param array $data Parsed data from AI response
     * @return array Auto-generated actions
     */
    private function generate_auto_actions($mappings, $data)
    {
        $actions = [];

        foreach ($mappings as $field_path => $mapping) {
            $target = $mapping['target'];
            $value = $this->get_nested_value($data, $field_path);

            // Skip if value is null and field is not required
            if ($value === null && !($mapping['required'] ?? false)) {
                continue;
            }

            // Parse target format: "post.title", "meta.seo_title", "taxonomy.category.term"
            $target_parts = explode('.', $target, 2);
            $target_type = $target_parts[0];
            $target_key = $target_parts[1] ?? null;

            switch ($target_type) {
                case 'post':
                    // Post field: post.title, post.content, post.excerpt
                    if ($target_key) {
                        $actions[] = [
                            'type' => 'update_post_field',
                            'field' => $target_key,
                            'value' => $value,
                            'source' => $field_path
                        ];
                    }
                    break;

                case 'meta':
                    // Post meta: meta.seo_title, meta.seo_description
                    if ($target_key) {
                        $actions[] = [
                            'type' => 'update_post_meta',
                            'meta_key' => $target_key,
                            'value' => $value,
                            'source' => $field_path
                        ];
                    }
                    break;

                case 'taxonomy':
                    // Taxonomy: taxonomy.category.term_name
                    if ($target_key) {
                        $taxonomy_parts = explode('.', $target_key, 2);
                        $actions[] = [
                            'type' => 'assign_taxonomy',
                            'taxonomy' => $taxonomy_parts[0],
                            'term' => $taxonomy_parts[1] ?? $value,
                            'value' => $value,
                            'source' => $field_path
                        ];
                    }
                    break;
            }
        }

        return $actions;
    }

    /**
     * Get nested value from array using dot notation
     * 
     * @param array $data Data array
     * @param string $path Dot notation path (e.g., "meta.seo_title")
     * @return mixed Value or null if not found
     */
    private function get_nested_value($data, $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Validate step configuration
     */
    public function validate_config($step_config)
    {
        $errors = [];

        // Check required fields
        if (!isset($step_config['assistant_id'])) {
            $errors[] = 'Assistant ID is required (field not set)';
        } elseif (empty($step_config['assistant_id']) && $step_config['assistant_id'] !== 0) {
            $errors[] = 'Assistant ID is required (field is empty)';
        } else {
            // Verify assistant exists
            $assistant = AssistantManager::get_assistant($step_config['assistant_id']);
            if (!$assistant) {
                $errors[] = 'Selected assistant does not exist (ID: ' . $step_config['assistant_id'] . ')';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get available output variables this step can produce
     */
    public function get_output_variables()
    {
        return [
            'ai_response',
            'data',
            'result'
        ];
    }

    /**
     * Get required input variables for this step
     */
    public function get_required_variables()
    {
        // No strict requirements - depends on assistant's prompt template
        return [];
    }

    /**
     * Get step configuration schema for UI generation
     */
    public function get_config_schema()
    {
        // Get all available assistants
        $assistants = AssistantManager::get_all_assistants();
        $assistant_options = ['' => __('Select an assistant...', 'polytrans')];

        foreach ($assistants as $assistant) {
            $label = $assistant['name'];
            if (!empty($assistant['provider'])) {
                $label .= ' (' . ucfirst($assistant['provider']) . ' - ' . $assistant['model'] . ')';
            }
            $assistant_options[$assistant['id']] = $label;
        }

        return [
            'assistant_id' => [
                'type' => 'select',
                'label' => __('AI Assistant', 'polytrans'),
                'description' => __('Select a managed AI assistant configured in the Assistants menu', 'polytrans'),
                'options' => $assistant_options,
                'required' => true
            ],
            'info' => [
                'type' => 'info',
                'content' => __('This step uses assistants configured in PolyTrans > AI Assistants. The assistant\'s prompt template will be rendered with Twig using the workflow context variables.', 'polytrans')
            ]
        ];
    }

    /**
     * Get additional info for the step (shown in workflow editor)
     */
    public function get_step_info($step_config)
    {
        $assistant_id = $step_config['assistant_id'] ?? 0;
        if (empty($assistant_id)) {
            return __('No assistant selected', 'polytrans');
        }

        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            return __('Assistant not found', 'polytrans');
        }

        $info = '<strong>' . esc_html($assistant['name']) . '</strong><br>';
        $info .= __('Provider:', 'polytrans') . ' ' . esc_html(ucfirst($assistant['provider'])) . '<br>';
        $info .= __('Model:', 'polytrans') . ' ' . esc_html($assistant['model']) . '<br>';
        $info .= __('Response Format:', 'polytrans') . ' ' . esc_html($assistant['response_format']);

        return $info;
    }
}

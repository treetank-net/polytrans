<?php

namespace PolyTrans\PostProcessing;

use PolyTrans\PostProcessing\WorkflowStepInterface;
use PolyTrans\PostProcessing\VariableProviderInterface;
use PolyTrans\PostProcessing\Managers\WorkflowStorageManager;
use PolyTrans\PostProcessing\Providers\PostDataProvider;
use PolyTrans\PostProcessing\Providers\MetaDataProvider;
use PolyTrans\PostProcessing\Providers\ContextDataProvider;
use PolyTrans\PostProcessing\Providers\ArticlesDataProvider;
use PolyTrans\PostProcessing\Steps\AiAssistantStep;
use PolyTrans\PostProcessing\Steps\PredefinedAssistantStep;
use PolyTrans\PostProcessing\Steps\ManagedAssistantStep;

/**
 * Workflow Manager
 * 
 * Main coordinator for post-processing workflows. Handles workflow
 * registration, execution triggering, and coordination between components.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WorkflowManager
{
    private static $instance = null;
    private Managers\WorkflowStorageManager $storage_manager;
    private WorkflowExecutor $executor;
    private VariableManager $variable_manager;
    private array $data_providers = [];
    private array $workflow_steps = [];

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Initialize workflow components
     */
    private function init_components()
    {
        // Initialize managers
        // Note: Workflow classes are autoloaded

        $this->storage_manager = new WorkflowStorageManager();
        $this->executor = new WorkflowExecutor();
        $this->variable_manager = new VariableManager();

        // Load data providers
        $this->load_data_providers();

        // Register default workflow steps
        $this->register_default_steps();
    }

    /**
     * Load data providers
     */
    private function load_data_providers()
    {
        $provider_files = [
            'providers/class-post-data-provider.php',
            'providers/class-meta-data-provider.php',
            'providers/class-context-data-provider.php',
            'providers/class-articles-data-provider.php'
        ];

        foreach ($provider_files as $file) {
            $file_path = plugin_dir_path(__FILE__) . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // Register providers
        $this->register_data_provider(new PostDataProvider());
        $this->register_data_provider(new MetaDataProvider());
        $this->register_data_provider(new ContextDataProvider());
        $this->register_data_provider(new ArticlesDataProvider());
    }

    /**
     * Register default workflow step types
     */
    private function register_default_steps()
    {
        // Register AI Assistant step (custom prompts)
        $this->register_workflow_step(new AiAssistantStep());

        // Register Predefined Assistant step (OpenAI Assistants API)
        $this->register_workflow_step(new PredefinedAssistantStep());

        // Register Managed Assistant step (Phase 1 - centralized management)
        $this->register_workflow_step(new ManagedAssistantStep());
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks()
    {
        // Hook into translation completion
        add_action('polytrans_translation_completed', [$this, 'trigger_workflows'], 10, 3);

        // Hook for manual workflow execution (old - synchronous)
        add_action('wp_ajax_polytrans_execute_workflow', [$this, 'ajax_execute_workflow']);

        // Hook for manual workflow execution (new - async)
        add_action('wp_ajax_polytrans_execute_workflow_manual', [$this, 'ajax_execute_workflow_manual']);
        add_action('wp_ajax_polytrans_check_execution_status', [$this, 'ajax_check_execution_status']);
        add_action('wp_ajax_polytrans_get_workflows_for_post', [$this, 'ajax_get_workflows_for_post']);

        // Hook for workflow testing
        add_action('wp_ajax_polytrans_test_workflow', [$this, 'ajax_test_workflow']);
    }

    /**
     * Register a data provider
     * 
     * @param VariableProviderInterface $provider
     */
    public function register_data_provider($provider)
    {
        if ($provider instanceof VariableProviderInterface) {
            $this->data_providers[$provider->get_provider_id()] = $provider;
        }
    }

    /**
     * Register a workflow step type
     * 
     * @param WorkflowStepInterface $step
     */
    public function register_workflow_step($step)
    {
        if ($step instanceof WorkflowStepInterface) {
            $this->workflow_steps[$step->get_type()] = $step;
        }
    }

    /**
     * Get all registered data providers
     * 
     * @return array
     */
    public function get_data_providers()
    {
        return $this->data_providers;
    }

    /**
     * Get all registered workflow step types
     * 
     * @return array
     */
    public function get_workflow_steps()
    {
        return $this->workflow_steps;
    }

    /**
     * Trigger workflows after translation completion
     * 
     * @param int $original_post_id
     * @param int $translated_post_id
     * @param string $target_language
     */
    public function trigger_workflows($original_post_id, $translated_post_id, $target_language)
    {
        try {

            // Get workflows for this language
            $workflows = $this->storage_manager->get_workflows_for_language($target_language);

            if (empty($workflows)) {
                \PolyTrans_Logs_Manager::log("No workflows configured for language '{$target_language}'", 'info', [
                    'source' => 'workflow_manager',
                    'original_post_id' => $original_post_id,
                    'translated_post_id' => $translated_post_id,
                    'target_language' => $target_language,
                    'total_workflows' => 0
                ]);
                return;
            }

            // Create execution context
            $context = [
                'original_post_id' => $original_post_id,
                'translated_post_id' => $translated_post_id,
                'source_language' => $this->detect_source_language($original_post_id),
                'target_language' => $target_language,
                'trigger' => 'translation_completed'
            ];

            // Analyze workflow conditions
            $executed_count = 0;
            $skipped_disabled = 0;
            $skipped_manual_only = 0;
            $skipped_conditions = 0;
            $skipped_no_trigger = 0;

            foreach ($workflows as $workflow) {

                if ($this->should_execute_workflow($workflow, $context)) {
                    $this->schedule_workflow_execution($workflow, $context);
                    $executed_count++;
                } else {
                    // Determine skip reason for summary
                    if (!isset($workflow['enabled']) || !$workflow['enabled']) {
                        $skipped_disabled++;
                    } elseif (isset($workflow['triggers']['manual_only']) && $workflow['triggers']['manual_only']) {
                        $skipped_manual_only++;
                    } elseif (!isset($workflow['triggers']['on_translation_complete']) || !$workflow['triggers']['on_translation_complete']) {
                        $skipped_no_trigger++;
                    } else {
                        $skipped_conditions++;
                    }
                }
            }

            \PolyTrans_Logs_Manager::log("Workflow summary for '{$target_language}': {$executed_count} executed, " .
                ($skipped_disabled + $skipped_manual_only + $skipped_conditions + $skipped_no_trigger) . " skipped", 'info', [
                'source' => 'workflow_manager',
                'original_post_id' => $original_post_id,
                'translated_post_id' => $translated_post_id,
                'target_language' => $target_language,
                'total_workflows' => count($workflows),
                'executed_count' => $executed_count,
                'skipped_disabled' => $skipped_disabled,
                'skipped_manual_only' => $skipped_manual_only,
                'skipped_no_trigger' => $skipped_no_trigger,
                'skipped_conditions' => $skipped_conditions
            ]);
        } catch (\Throwable $e) {
            \PolyTrans_Logs_Manager::log("Error when processing workflows for post $original_post_id: " . $e->getMessage(), 'error', [
                'source' => 'workflow_manager',
                'original_post_id' => $original_post_id,
                'translated_post_id' => $translated_post_id,
                'target_language' => $target_language,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Check if workflow should be executed for given context
     * 
     * @param array $workflow
     * @param array $context
     * @return bool
     */
    private function should_execute_workflow($workflow, $context)
    {
        $workflow_name = $workflow['name'] ?? 'Unknown';

        // Check if workflow is enabled
        if (!isset($workflow['enabled']) || !$workflow['enabled']) {
            \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' is disabled", 'info', [
                'source' => 'workflow_manager',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow['id'] ?? null
            ]);
            return false;
        }

        // Check trigger conditions
        $triggers = $workflow['triggers'] ?? [];

        if ($context['trigger'] === 'translation_completed') {
            if (!isset($triggers['on_translation_complete']) || !$triggers['on_translation_complete']) {
                \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' not configured for translation completion trigger", 'info', [
                    'source' => 'workflow_manager',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow['id'] ?? null
                ]);
                return false;
            }
        }

        // Check for manual_only flag
        if (isset($triggers['manual_only']) && $triggers['manual_only']) {
            \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' is set to manual execution only", 'info', [
                'source' => 'workflow_manager',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow['id'] ?? null
            ]);
            return false;
        }

        // Check additional conditions (post type, categories, etc.)
        if (isset($triggers['conditions']) && !empty($triggers['conditions'])) {
            $conditions_met = $this->evaluate_workflow_conditions($triggers['conditions'], $context);
            if (!$conditions_met) {
                \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' conditions not met", 'info', [
                    'source' => 'workflow_manager',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow['id'] ?? null
                ]);
                return false;
            }
        }

        \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' passed all conditions", 'info', [
            'source' => 'workflow_manager',
            'workflow_name' => $workflow_name,
            'workflow_id' => $workflow['id'] ?? null
        ]);
        return true;
    }

    /**
     * Evaluate workflow trigger conditions
     * 
     * @param array $conditions
     * @param array $context
     * @return bool
     */
    private function evaluate_workflow_conditions($conditions, $context)
    {
        // For manual workflows, use translated_post_id if original_post_id is not provided
        $post_id = $context['original_post_id'] ?? $context['translated_post_id'] ?? null;

        if (!$post_id) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        // Check post type condition
        if (isset($conditions['post_type']) && !empty($conditions['post_type'])) {
            if (!in_array($post->post_type, $conditions['post_type'])) {
                return false;
            }
        }

        // Check category condition
        if (isset($conditions['category']) && !empty($conditions['category'])) {
            $post_categories = wp_get_post_categories($post->ID, ['fields' => 'slugs']);
            if (empty(array_intersect($conditions['category'], $post_categories))) {
                return false;
            }
        }

        // Additional conditions can be added here

        return true;
    }

    /**
     * Schedule workflow execution (background processing)
     * 
     * @param array $workflow
     * @param array $context
     */
    private function schedule_workflow_execution($workflow, $context)
    {
        // For now, execute immediately
        // In production, this should be queued for background processing
        $translated_post_id = isset($context['translated_post_id']) ? (int) $context['translated_post_id'] : 0;

        if ($translated_post_id > 0) {
            PostDataProvider::invalidate_post_cache($translated_post_id);
        }

        $this->execute_workflow($workflow, $context);

        if ($translated_post_id > 0) {
            PostDataProvider::invalidate_post_cache($translated_post_id);
        }
    }

    /**
     * Execute a workflow
     * 
     * @param array $workflow
     * @param array $context
     * @param bool $test_mode Whether to run in test mode
     * @return array Execution result
     */
    public function execute_workflow($workflow, $context, $test_mode = false)
    {
        $workflow_name = $workflow['name'] ?? 'Unknown';
        $workflow_id = $workflow['id'] ?? 'unknown';

        \PolyTrans_Logs_Manager::log("Starting execution of workflow '{$workflow_name}' (ID: {$workflow_id})", 'info', [
            'source' => 'workflow_manager',
            'workflow_name' => $workflow_name,
            'workflow_id' => $workflow_id,
            'post_id' => $context['translated_post_id'] ?? null,
            'original_post_id' => $context['original_post_id'] ?? null,
            'translated_post_id' => $context['translated_post_id'] ?? null,
            'target_language' => $context['target_language'] ?? null,
            'trigger' => $context['trigger'] ?? null,
            'test_mode' => $test_mode
        ]);

        try {
            // Build variable context
            $variable_context = $this->variable_manager->build_context($context, $this->data_providers);

            // Execute workflow using executor
            $result = $this->executor->execute($workflow, $variable_context, $test_mode);

            // Log execution (only in production mode)
            if (!$test_mode) {
                $this->log_workflow_execution($workflow, $context, $result);
            }

            if ($result['success']) {
                \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' execution completed successfully", 'info', [
                    'source' => 'workflow_manager',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow_id,
                    'post_id' => $context['translated_post_id'] ?? null
                ] + $this->build_execution_log_context($context, $result, $test_mode, $variable_context));
            } else {
                $error_msg = $result['error'] ?? 'Unknown error';
                \PolyTrans_Logs_Manager::log("Workflow '{$workflow_name}' execution failed: {$error_msg}", 'error', [
                    'source' => 'workflow_manager',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow_id,
                    'error' => $error_msg,
                    'post_id' => $context['translated_post_id'] ?? null
                ] + $this->build_execution_log_context($context, $result, $test_mode, $variable_context));
            }

            return $result;
        } catch (\Throwable $e) {
            \PolyTrans_Logs_Manager::log("Exception during workflow '{$workflow_name}' execution: " . $e->getMessage(), 'error', [
                'source' => 'workflow_manager',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow_id,
                'exception' => $e->getMessage(),
                'post_id' => $context['translated_post_id'] ?? null
            ]);
            \PolyTrans_Logs_Manager::log("Exception stack trace: " . $e->getTraceAsString(), 'debug', [
                'source' => 'workflow_manager',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow_id,
                'trace' => $e->getTraceAsString()
            ]);
            $error_result = [
                'success' => false,
                'error' => $e->getMessage(),
                'workflow_id' => $workflow['id'] ?? 'unknown'
            ];

            $this->log_workflow_execution($workflow, $context, $error_result);

            return $error_result;
        }
    }

    private function detect_source_language(int $original_post_id): string
    {
        if ($original_post_id > 0 && function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($original_post_id);
            if ($lang) {
                return (string) $lang;
            }
        }
        return '';
    }

    /**
     * Build compact diagnostic context for workflow completion logs.
     */
    private function build_execution_log_context($context, $result, $test_mode, $variable_context = null): array
    {
        $step_summary = $this->summarize_step_results($result['step_results'] ?? []);
        $total_output_actions = 0;
        $total_changes = 0;

        foreach ($step_summary as $step) {
            $total_output_actions += (int) ($step['output_actions_processed'] ?? 0);
            $total_changes += (int) ($step['changes_count'] ?? 0);
        }

        $context_keys = is_array($context) ? array_keys($context) : [];
        $variable_keys = is_array($variable_context) ? array_keys($variable_context) : [];

        return [
            'original_post_id' => $context['original_post_id'] ?? null,
            'translated_post_id' => $context['translated_post_id'] ?? null,
            'target_language' => $context['target_language'] ?? null,
            'trigger' => $context['trigger'] ?? null,
            'test_mode' => $test_mode,
            'execution_time' => isset($result['execution_time']) ? round((float) $result['execution_time'], 3) : 0,
            'steps_executed' => (int) ($result['steps_executed'] ?? 0),
            'step_summary' => $step_summary,
            'total_output_actions_processed' => $total_output_actions,
            'total_changes_count' => $total_changes,
            'context_keys' => $context_keys,
            'variable_count' => count($variable_keys),
            'variable_keys' => $variable_keys,
            'has_original_post_data' => is_array($variable_context) && isset($variable_context['original']),
            'has_translated_post_data' => is_array($variable_context) && isset($variable_context['translated']),
            'translated_meta_keys' => $this->extract_meta_keys($variable_context['translated']['meta'] ?? null),
            'final_context_keys' => isset($result['final_context']) && is_array($result['final_context'])
                ? array_keys($result['final_context'])
                : []
        ];
    }

    /**
     * Reduce full step results to fields useful in logs without storing prompt or response payloads.
     */
    private function summarize_step_results($step_results): array
    {
        if (!is_array($step_results)) {
            return [];
        }

        $summary = [];
        foreach ($step_results as $index => $step_result) {
            if (!is_array($step_result)) {
                continue;
            }

            $data = $step_result['data'] ?? [];
            $output_processing = $step_result['output_processing'] ?? [];
            $changes = is_array($output_processing) && isset($output_processing['changes']) && is_array($output_processing['changes'])
                ? $output_processing['changes']
                : [];

            $summary[] = [
                'step_number' => $index + 1,
                'step_id' => $step_result['step_id'] ?? null,
                'step_name' => $step_result['step_name'] ?? null,
                'step_type' => $step_result['step_type'] ?? null,
                'success' => (bool) ($step_result['success'] ?? false),
                'execution_time' => isset($step_result['execution_time']) ? round((float) $step_result['execution_time'], 3) : 0,
                'output_variables' => is_array($data) ? array_keys($data) : [],
                'output_actions_processed' => is_array($output_processing) ? (int) ($output_processing['processed_actions'] ?? 0) : 0,
                'changes_count' => count($changes),
                'change_targets' => $this->extract_change_targets($changes),
                'output_errors' => is_array($output_processing) ? ($output_processing['errors'] ?? []) : [],
                'error' => $step_result['error'] ?? null,
                'tokens_used' => $step_result['tokens_used'] ?? null
            ];
        }

        return $summary;
    }

    private function extract_change_targets(array $changes): array
    {
        $targets = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $type = $change['type'] ?? 'unknown';
            $target = $change['target'] ?? $change['field'] ?? null;
            $targets[] = $target ? "{$type}:{$target}" : $type;
        }

        return $targets;
    }

    private function extract_meta_keys($meta): array
    {
        return is_array($meta) ? array_keys($meta) : [];
    }

    /**
     * Log workflow execution
     * 
     * @param array $workflow
     * @param array $context
     * @param array $result
     */
    private function log_workflow_execution($workflow, $context, $result)
    {
        $log_data = [
            'action' => 'workflow_execution',
            'workflow_id' => $workflow['id'] ?? 'unknown',
            'workflow_name' => $workflow['name'] ?? 'Unknown',
            'context' => $context,
            'success' => $result['success'] ?? false,
            'steps_executed' => $result['steps_executed'] ?? 0,
            'execution_time' => $result['execution_time'] ?? 0,
            'error' => $result['error'] ?? null
        ];

        // Use existing logging system
        if (class_exists('\PolyTrans_Logs_Manager')) {
            $logs_manager = new \PolyTrans_Logs_Manager();
            $logs_manager->log('info', 'Post-processing workflow executed', $log_data);
        }
    }

    /**
     * AJAX handler for manual workflow execution
     */
    public function ajax_execute_workflow()
    {
        // Verify nonce and permissions
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $workflow_id = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $original_post_id = intval($_POST['original_post_id'] ?? 0);
        $translated_post_id = intval($_POST['translated_post_id'] ?? 0);
        $target_language = sanitize_text_field(wp_unslash($_POST['target_language'] ?? ''));

        if (empty($workflow_id) || empty($original_post_id) || empty($translated_post_id)) {
            wp_send_json_error('Missing required parameters');
            return;
        }

        // Get workflow
        $workflow = $this->storage_manager->get_workflow($workflow_id);
        if (!$workflow) {
            wp_send_json_error('Workflow not found');
            return;
        }

        // Execute workflow
        $context = [
            'original_post_id' => $original_post_id,
            'translated_post_id' => $translated_post_id,
            'source_language' => $this->detect_source_language($original_post_id),
            'target_language' => $target_language,
            'trigger' => 'manual'
        ];

        $result = $this->execute_workflow($workflow, $context);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for workflow testing
     */
    public function ajax_test_workflow()
    {
        // Verify nonce and permissions
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }

        // Check if this is a status check request
        if (isset($_POST['check_status']) && sanitize_text_field(wp_unslash($_POST['check_status']))) {
            $test_id = isset($_POST['test_id']) ? sanitize_text_field(wp_unslash($_POST['test_id'])) : '';
            $result = get_transient('polytrans_workflow_test_' . $test_id);

            if ($result === false) {
                wp_send_json_error(['message' => 'Test not found or expired']);
                return;
            }

            if ($result['status'] === 'running') {
                wp_send_json_success(['status' => 'running']);
            } else {
                // Test completed, delete the transient and return result
                delete_transient('polytrans_workflow_test_' . $test_id);
                wp_send_json_success(['status' => 'completed', 'result' => $result['data']]);
            }
            return;
        }

        // Get test data from request
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Complex nested array, sanitized per-field during workflow execution
        $workflow_data = wp_unslash($_POST['workflow'] ?? []);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Complex nested array, sanitized per-field during workflow execution
        $test_context = wp_unslash($_POST['test_context'] ?? []);

        // Validate workflow data
        if (empty($workflow_data)) {
            wp_send_json_error('No workflow data provided');
            return;
        }

        // Generate unique test ID
        $test_id = uniqid('test_', true);

        // Store initial status
        set_transient('polytrans_workflow_test_' . $test_id, [
            'status' => 'running',
            'started_at' => time()
        ], 5 * MINUTE_IN_SECONDS);

        // Prepare args for background process
        $bg_args = [
            'test_id' => $test_id,
            'workflow_data' => $workflow_data,
            'test_context' => $test_context
        ];

        // Spawn background process
        if (class_exists('\PolyTrans_Background_Processor')) {
            $spawned = \PolyTrans_Background_Processor::spawn($bg_args, 'workflow-test');

            if ($spawned) {
                wp_send_json_success([
                    'test_id' => $test_id,
                    'status' => 'started',
                    'message' => 'Test started in background process, polling for results...'
                ]);
            } else {
                // Fallback: run synchronously if background spawn failed
                try {
                    $workflow = [
                        'id' => $test_id,
                        'name' => 'Test Workflow',
                        'target_language' => $test_context['target_language'] ?? 'en',
                        'enabled' => true,
                        'steps' => $workflow_data['steps'] ?? []
                    ];

                    $result = $this->execute_workflow($workflow, $test_context, true);

                    wp_send_json_success($result);
                } catch (\Exception $e) {
                    wp_send_json_error(['error' => $e->getMessage()]);
                }
            }
        } else {
            wp_send_json_error(['error' => 'Background processor not available']);
        }
    }

    /**
     * Get storage manager
     * 
     * @return PolyTrans_Workflow_Storage_Manager
     */
    public function get_storage_manager()
    {
        return $this->storage_manager;
    }

    /**
     * Get executor
     * 
     * @return PolyTrans_Workflow_Executor
     */
    public function get_executor()
    {
        return $this->executor;
    }

    /**
     * Get variable manager
     * 
     * @return PolyTrans_Variable_Manager
     */
    public function get_variable_manager()
    {
        return $this->variable_manager;
    }

    /**
     * AJAX handler for manual workflow execution (async with background process)
     */
    public function ajax_execute_workflow_manual()
    {
        // Verify nonce and permissions
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }

        $workflow_id = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $original_post_id = intval($_POST['original_post_id'] ?? 0);
        $translated_post_id = intval($_POST['translated_post_id'] ?? 0);
        $target_language = sanitize_text_field(wp_unslash($_POST['target_language'] ?? ''));

        // Validate required parameters
        // Note: original_post_id is optional for manual workflows
        if (empty($workflow_id) || empty($translated_post_id)) {
            wp_send_json_error(['error' => 'Missing required parameters']);
            return;
        }

        // If original_post_id not provided, use translated_post_id
        if (empty($original_post_id)) {
            $original_post_id = $translated_post_id;
        }

        // Get workflow
        $workflow = $this->storage_manager->get_workflow($workflow_id);
        if (!$workflow) {
            wp_send_json_error(['error' => 'Workflow not found']);
            return;
        }

        // Check for existing execution lock
        $lock_key = 'polytrans_workflow_lock_' . $workflow_id . '_' . $translated_post_id;
        $existing_lock = get_transient($lock_key);

        if ($existing_lock) {
            $elapsed = time() - ($existing_lock['started_at'] ?? 0);

            // If lock is recent (< 5 minutes), reject
            if ($elapsed < 300) {
                wp_send_json_error([
                    'error' => 'already_running',
                    'message' => 'This workflow is already running on this post',
                    'execution_id' => $existing_lock['execution_id'] ?? '',
                    'started_at' => $existing_lock['started_at'] ?? 0,
                    'elapsed' => $elapsed
                ]);
                return;
            }

            // Lock is stale, clear it
            delete_transient($lock_key);
        }

        // Generate unique execution ID
        $execution_id = uniqid('exec_', true);
        $started_at = time();

        // Create execution lock
        set_transient($lock_key, [
            'execution_id' => $execution_id,
            'started_at' => $started_at,
            'workflow_id' => $workflow_id,
            'post_id' => $translated_post_id
        ], 5 * MINUTE_IN_SECONDS);

        // Store initial status
        set_transient('polytrans_workflow_exec_' . $execution_id, [
            'status' => 'running',
            'workflow_id' => $workflow_id,
            'post_id' => $translated_post_id,
            'started_at' => $started_at
        ], 10 * MINUTE_IN_SECONDS);

        // Prepare args for background process
        $bg_args = [
            'execution_id' => $execution_id,
            'workflow_id' => $workflow_id,
            'original_post_id' => $original_post_id,
            'translated_post_id' => $translated_post_id,
            'target_language' => $target_language,
            'started_at' => $started_at
        ];

        // Spawn background process
        if (class_exists('\PolyTrans_Background_Processor')) {
            $spawned = \PolyTrans_Background_Processor::spawn($bg_args, 'workflow-execute');

            if ($spawned) {
                wp_send_json_success([
                    'execution_id' => $execution_id,
                    'status' => 'started',
                    'message' => 'Workflow execution started in background'
                ]);
            } else {
                // Fallback: run synchronously if background spawn failed
                \PolyTrans_Logs_Manager::log('Background process spawn failed, falling back to synchronous execution', 'warning', [
                    'execution_id' => $execution_id,
                    'workflow_id' => $workflow_id
                ]);

                try {
                    $context = [
                        'original_post_id' => $original_post_id,
                        'translated_post_id' => $translated_post_id,
                        'source_language' => $this->detect_source_language($original_post_id),
                        'target_language' => $target_language,
                        'trigger' => 'manual'
                    ];

                    $result = $this->execute_workflow($workflow, $context, false);

                    // Store result immediately
                    set_transient('polytrans_workflow_exec_' . $execution_id, [
                        'status' => 'completed',
                        'started_at' => $started_at,
                        'completed_at' => time(),
                        'result' => $result
                    ], 10 * MINUTE_IN_SECONDS);

                    // Clear lock
                    delete_transient($lock_key);

                    wp_send_json_success([
                        'execution_id' => $execution_id,
                        'status' => 'completed',
                        'result' => $result
                    ]);
                } catch (\Exception $e) {
                    delete_transient($lock_key);
                    wp_send_json_error(['error' => $e->getMessage()]);
                }
            }
        } else {
            delete_transient($lock_key);
            wp_send_json_error(['error' => 'Background processor not available']);
        }
    }

    /**
     * AJAX handler for checking execution status
     */
    public function ajax_check_execution_status()
    {
        // Verify nonce and permissions
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('edit_posts')) {
            wp_die('Insufficient permissions');
        }

        $execution_id = sanitize_text_field(wp_unslash($_POST['execution_id'] ?? ''));

        if (empty($execution_id)) {
            wp_send_json_error(['message' => 'Missing execution ID']);
            return;
        }

        $result = get_transient('polytrans_workflow_exec_' . $execution_id);

        if ($result === false) {
            wp_send_json_error(['message' => 'Execution not found or expired']);
            return;
        }

        if ($result['status'] === 'running') {
            $elapsed = time() - ($result['started_at'] ?? 0);
            wp_send_json_success([
                'status' => 'running',
                'started_at' => $result['started_at'],
                'elapsed' => $elapsed
            ]);
        } else {
            // Execution completed, delete the transient and return result
            delete_transient('polytrans_workflow_exec_' . $execution_id);

            $elapsed = ($result['completed_at'] ?? time()) - ($result['started_at'] ?? 0);
            wp_send_json_success([
                'status' => 'completed',
                'started_at' => $result['started_at'],
                'completed_at' => $result['completed_at'] ?? time(),
                'elapsed' => $elapsed,
                'result' => $result['result'] ?? []
            ]);
        }
    }

    /**
     * AJAX handler for getting executable workflows for a post
     */
    public function ajax_get_workflows_for_post()
    {
        // Verify nonce and permissions
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $post_id = intval($_POST['post_id'] ?? 0);

        if (!$post_id) {
            wp_send_json_error(['message' => 'Missing post ID']);
            return;
        }

        // Get post language
        $post_lang = pll_get_post_language($post_id);

        if (!$post_lang) {
            wp_send_json_success(['workflows' => []]);
            return;
        }

        // Get all workflows
        $all_workflows = $this->storage_manager->get_all_workflows();
        $executable_workflows = [];

        foreach ($all_workflows as $workflow) {
            // Include workflows for this specific language OR workflows with no language (all languages)
            $workflow_lang = $workflow['target_language'] ?? '';
            if ($workflow_lang !== '' && $workflow_lang !== $post_lang) {
                continue;
            }

            // Check if currently executing
            $lock_key = 'polytrans_workflow_lock_' . $workflow['id'] . '_' . $post_id;
            $lock = get_transient($lock_key);

            $executing = false;
            $execution_id = null;
            $elapsed = 0;

            if ($lock) {
                $elapsed = time() - ($lock['started_at'] ?? 0);
                // Only consider executing if lock is recent
                if ($elapsed < 300) {
                    $executing = true;
                    $execution_id = $lock['execution_id'] ?? null;
                }
            }

            $executable_workflows[] = [
                'id' => $workflow['id'],
                'name' => $workflow['name'],
                'language' => $workflow['target_language'],
                'steps' => count($workflow['steps'] ?? []),
                'executing' => $executing,
                'execution_id' => $execution_id,
                'elapsed' => $elapsed
            ];
        }

        wp_send_json_success(['workflows' => $executable_workflows]);
    }
}

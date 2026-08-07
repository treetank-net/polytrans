<?php

namespace PolyTrans\PostProcessing;

use PolyTrans\PostProcessing\WorkflowStepInterface;
use PolyTrans\PostProcessing\Steps\AiAssistantStep;
use PolyTrans\PostProcessing\Steps\PredefinedAssistantStep;
use PolyTrans\PostProcessing\Steps\ManagedAssistantStep;

/**
 * Workflow Executor
 * 
 * Handles the execution of individual workflow steps and manages
 * the flow of data between steps.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WorkflowExecutor
{
    private $step_registry = [];
    private $output_processor;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->register_default_steps();
        $this->output_processor = WorkflowOutputProcessor::get_instance();
    }

    /**
     * Register default step types
     */
    private function register_default_steps()
    {
        // AI Assistant step
        // Note: Step classes are autoloaded
        $this->register_step(new AiAssistantStep());

        // Predefined Assistant step
        $this->register_step(new PredefinedAssistantStep());

        // Managed Assistant step (Phase 1 - centralized management)
        $this->register_step(new ManagedAssistantStep());
    }

    /**
     * Register a workflow step type
     * 
     * @param WorkflowStepInterface $step
     */
    public function register_step($step)
    {
        if ($step instanceof WorkflowStepInterface) {
            $this->step_registry[$step->get_type()] = $step;
        }
    }

    /**
     * Execute a complete workflow
     * 
     * @param array $workflow Workflow definition
     * @param array $context Variable context
     * @param bool $test_mode Whether to run in test mode (returns change objects instead of executing)
     * @return array Execution result
     */
    public function execute($workflow, $context, $test_mode = false)
    {
        $start_time = microtime(true);
        $steps_executed = 0;
        $step_results = [];
        $execution_context = $context; // Copy context for modification

        // Validate input parameters
        if (!is_array($workflow)) {
            return [
                'success' => false,
                'error' => 'Workflow must be an array, ' . gettype($workflow) . ' given',
                'steps_executed' => 0,
                'execution_time' => 0,
                'test_mode' => $test_mode
            ];
        }

        if (!is_array($context)) {
            return [
                'success' => false,
                'error' => 'Context must be an array, ' . gettype($context) . ' given',
                'steps_executed' => 0,
                'execution_time' => 0,
                'test_mode' => $test_mode
            ];
        }

        // Log workflow execution start
        $workflow_name = $workflow['name'] ?? 'Unknown Workflow';
        $workflow_id = $workflow['id'] ?? 'unknown';
        $mode_text = $test_mode ? ' (TEST MODE)' : '';

        try {
            // Validate workflow first
            $validation = $this->validate_workflow($workflow);
            if (!$validation['valid']) {
                $error_msg = 'Workflow validation failed: ' . implode(', ', $validation['errors']);
                \PolyTrans_Logs_Manager::log("Cannot start '{$workflow_name}' (ID: {$workflow_id}){$mode_text}: {$error_msg}", 'error', [
                    'source' => 'workflow_executor',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow_id,
                    'test_mode' => $test_mode,
                    'validation_errors' => $validation['errors'],
                    'variable_count' => is_array($context) ? count($context) : 0,
                    'variables' => is_array($context) ? array_keys($context) : []
                ]);
                return [
                    'success' => false,
                    'error' => $error_msg,
                    'steps_executed' => 0,
                    'execution_time' => 0,
                    'test_mode' => $test_mode
                ];
            }

            // Log workflow start with validation success
            $context_count = is_array($context) ? count($context) : 0;
            $steps_count = isset($workflow['steps']) && is_array($workflow['steps']) ? count($workflow['steps']) : 0;
            \PolyTrans_Logs_Manager::log("Starting execution of '{$workflow_name}' (ID: {$workflow_id}){$mode_text} with {$context_count} variables, {$steps_count} steps", 'info', [
                'source' => 'workflow_executor',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow_id,
                'test_mode' => $test_mode,
                'variable_count' => $context_count,
                'variables' => is_array($context) ? array_keys($context) : [],
                'step_count' => $steps_count,
                'validation_passed' => true
            ]);

            // Execute each step
            foreach ($workflow['steps'] as $step_index => $step_config) {
                $step_name = $step_config['name'] ?? "Step " . ($step_index + 1);
                $step_id = $step_config['id'] ?? "step_{$step_index}";
                $step_type = $step_config['type'] ?? 'unknown';

                // Skip disabled steps
                if (isset($step_config['enabled']) && !$step_config['enabled']) {
                    \PolyTrans_Logs_Manager::log("Skipping step #" . ($steps_executed + 1) . ": '{$step_name}' (disabled)", 'info', [
                        'source' => 'workflow_executor',
                        'workflow_name' => $workflow_name,
                        'workflow_id' => $workflow_id,
                        'step_name' => $step_name,
                        'step_id' => $step_id,
                        'step_type' => $step_type,
                        'reason' => 'disabled'
                    ]);
                    continue;
                }

                // Execute step
                $step_start_time = microtime(true);
                $step_result = $this->execute_step($step_config, $execution_context);
                $step_execution_time = microtime(true) - $step_start_time;

                $steps_executed++;

                // Record what the step spent. Done for failed steps too: a step that
                // errored after the model replied still cost money.
                $this->record_step_usage($step_result, $step_config, $execution_context, $workflow, $test_mode);

                // Process output actions if step succeeded
                $output_processing_info = '';
                $output_processing_errors = [];
                $output_result = null;

                if ($step_result['success'] && isset($step_config['output_actions']) && !empty($step_config['output_actions'])) {
                    $output_result = $this->output_processor->process_step_outputs(
                        $step_result,
                        $step_config['output_actions'],
                        $execution_context,
                        $test_mode,
                        $workflow
                    );

                    if ($output_result['success']) {
                        $actions_processed = $output_result['processed_actions'] ?? 0;
                        $output_processing_info = " (processed {$actions_processed} actions)";

                        // Update execution context with changes (both test mode and production mode)
                        if (isset($output_result['updated_context'])) {
                            $execution_context = $output_result['updated_context'];
                        }
                    } else {
                        $output_processing_errors = $output_result['errors'] ?? ['Unknown error'];
                        $output_processing_info = " (action processing failed)";
                    }
                }

                // Log consolidated step result with all information
                if ($step_result['success'] && empty($output_processing_errors)) {
                    $output_info = '';
                    $output_vars = [];
                    if (isset($step_result['data']) && is_array($step_result['data'])) {
                        $output_vars = array_keys($step_result['data']);
                        $output_info = ' (' . count($output_vars) . ' outputs)';
                    }
                    \PolyTrans_Logs_Manager::log("Executed step #" . $steps_executed . ": '{$step_name}' ({$step_type}) in " . round($step_execution_time, 3) . "s{$output_info}{$output_processing_info}", 'info', [
                        'source' => 'workflow_executor',
                        'workflow_name' => $workflow_name,
                        'workflow_id' => $workflow_id,
                        'step_name' => $step_name,
                        'step_id' => $step_id,
                        'step_type' => $step_type,
                        'step_number' => $steps_executed,
                        'execution_time' => round($step_execution_time, 3),
                        'output_actions_count' => isset($step_config['output_actions']) && is_array($step_config['output_actions']) ? count($step_config['output_actions']) : 0,
                        'success' => true,
                        'tokens_used' => $step_result['tokens_used'] ?? null
                    ]);
                } else {
                    $error_msg = $step_result['error'] ?? 'Unknown error';
                    if (!empty($output_processing_errors)) {
                        $error_msg .= '; Output processing: ' . implode(', ', $output_processing_errors);
                    }

                    // Determine what happens next
                    $continue_on_error = $step_config['continue_on_error'] ?? false;
                    $next_action = $continue_on_error ? ' (continuing)' : ' (stopping)';

                    // Get actual output variables for debugging
                    $actual_output_vars = [];
                    if (isset($step_result['data']) && is_array($step_result['data'])) {
                        $actual_output_vars = array_keys($step_result['data']);
                    }

                    \PolyTrans_Logs_Manager::log("Failed step #" . $steps_executed . ": '{$step_name}' ({$step_type}) - {$error_msg}{$next_action}", 'error', [
                        'source' => 'workflow_executor',
                        'workflow_name' => $workflow_name,
                        'workflow_id' => $workflow_id,
                        'step_name' => $step_name,
                        'step_id' => $step_id,
                        'step_type' => $step_type,
                        'step_number' => $steps_executed,
                        'execution_time' => round($step_execution_time, 3),
                        'error' => $step_result['error'] ?? 'Unknown error',
                        'output_processing_errors' => $output_processing_errors,
                        'continue_on_error' => $continue_on_error,
                        'success' => false,
                        // Include debugging info about what AI actually output
                        'actual_output_variables' => $actual_output_vars,
                        'ai_response' => isset($step_result['raw_response']) ? substr($step_result['raw_response'], 0, 1000) . '...' : null,
                        'tokens_used' => $step_result['tokens_used'] ?? null,
                        'step_data' => $step_result['data'] ?? null
                    ]);
                }

                $step_results[] = [
                    'step_id' => $step_id,
                    'step_name' => $step_name,
                    'step_type' => $step_type,
                    'step_config' => $step_config, // Include full step configuration
                    'input_variables' => $this->extract_input_variables($execution_context),
                    'success' => $step_result['success'],
                    'data' => $step_result['data'] ?? null,
                    'error' => $step_result['error'] ?? null,
                    'execution_time' => $step_execution_time,
                    // Include interpolated prompts if available
                    'interpolated_system_prompt' => $step_result['interpolated_system_prompt'] ?? null,
                    'interpolated_user_message' => $step_result['interpolated_user_message'] ?? null,
                    'tokens_used' => $step_result['tokens_used'] ?? null,
                    'raw_response' => $step_result['raw_response'] ?? null,
                    // Include output processing if it was done
                    'output_processing' => isset($output_result) ? $output_result : null
                ];

                // If step failed and we're not continuing on error, stop execution
                if (!$step_result['success']) {
                    $continue_on_error = $step_config['continue_on_error'] ?? false;
                    if (!$continue_on_error) {
                        break;
                    }
                }

                // Merge step output into execution context for next steps
                if ($step_result['success'] && isset($step_result['data'])) {
                    $this->merge_step_output($execution_context, $step_config, $step_result['data']);
                }
            }

            $execution_time = microtime(true) - $start_time;

            // Determine overall success
            $overall_success = true;
            $failed_steps = [];
            foreach ($step_results as $result) {
                if (!$result['success']) {
                    $overall_success = false;
                    $failed_steps[] = $result['step_name'];
                }
            }

            // Log workflow completion
            if ($overall_success) {
                \PolyTrans_Logs_Manager::log("'{$workflow_name}' completed successfully in " . round($execution_time, 3) . "s. Executed {$steps_executed} steps.", 'info', [
                    'source' => 'workflow_executor',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow_id,
                    'execution_time' => round($execution_time, 3),
                    'steps_executed' => $steps_executed,
                    'success' => true
                ]);
            } else {
                $failed_list = implode(', ', $failed_steps);
                \PolyTrans_Logs_Manager::log("'{$workflow_name}' completed with errors in " . round($execution_time, 3) . "s. Failed steps: {$failed_list}", 'error', [
                    'source' => 'workflow_executor',
                    'workflow_name' => $workflow_name,
                    'workflow_id' => $workflow_id,
                    'execution_time' => round($execution_time, 3),
                    'steps_executed' => $steps_executed,
                    'failed_steps' => $failed_steps,
                    'success' => false
                ]);
            }

            return [
                'success' => $overall_success,
                'steps_executed' => $steps_executed,
                'execution_time' => $execution_time,
                'step_results' => $step_results,
                'final_context' => $execution_context,
                'test_mode' => $test_mode
            ];
        } catch (\Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $error_msg = "Exception during workflow execution: " . $e->getMessage();
            \PolyTrans_Logs_Manager::log("'{$workflow_name}' failed with exception after " . round($execution_time, 3) . "s: {$error_msg}", 'error', [
                'source' => 'workflow_executor',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow_id,
                'execution_time' => round($execution_time, 3),
                'exception' => $e->getMessage(),
                'success' => false
            ]);
            \PolyTrans_Logs_Manager::log("Stack trace: " . $e->getTraceAsString(), 'debug', [
                'source' => 'workflow_executor',
                'workflow_name' => $workflow_name,
                'workflow_id' => $workflow_id,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'steps_executed' => $steps_executed,
                'execution_time' => $execution_time,
                'step_results' => $step_results
            ];
        }
    }

    /**
     * Execute a single workflow step
     * 
     * @param array $step_config Step configuration
     * @param array $context Execution context
     * @return array Step execution result
     */
    private function execute_step($step_config, $context)
    {
        $start_time = microtime(true);
        $step_name = $step_config['name'] ?? 'Unnamed Step';
        $step_id = $step_config['id'] ?? 'unknown';

        try {
            // Get step type
            $step_type = $step_config['type'] ?? '';
            if (empty($step_type)) {
                $error_msg = 'Step type not specified';
                \PolyTrans_Logs_Manager::log("Step '{$step_name}' (ID: {$step_id}) error: {$error_msg}", 'error', [
                    'source' => 'workflow_executor',
                    'step_name' => $step_name,
                    'step_id' => $step_id,
                    'error' => $error_msg
                ]);
                return [
                    'success' => false,
                    'error' => 'Step type not specified',
                    'execution_time' => microtime(true) - $start_time
                ];
            }

            // Get step handler
            if (!isset($this->step_registry[$step_type])) {
                $error_msg = "Unknown step type: {$step_type}";
                \PolyTrans_Logs_Manager::log("Step '{$step_name}' (ID: {$step_id}) error: {$error_msg}", 'error', [
                    'source' => 'workflow_executor',
                    'step_name' => $step_name,
                    'step_id' => $step_id,
                    'step_type' => $step_type,
                    'error' => $error_msg
                ]);
                return [
                    'success' => false,
                    'error' => $error_msg,
                    'execution_time' => microtime(true) - $start_time
                ];
            }

            $step_handler = $this->step_registry[$step_type];

            // Validate step configuration
            $validation = $step_handler->validate_config($step_config);
            if (!$validation['valid']) {
                $error_msg = 'Step configuration validation failed: ' . implode(', ', $validation['errors']);
                \PolyTrans_Logs_Manager::log("Step '{$step_name}' (ID: {$step_id}) validation error: {$error_msg}", 'error', [
                    'source' => 'workflow_executor',
                    'step_name' => $step_name,
                    'step_id' => $step_id,
                    'validation_errors' => $validation['errors'],
                    'error' => $error_msg
                ]);
                return [
                    'success' => false,
                    'error' => $error_msg,
                    'execution_time' => microtime(true) - $start_time
                ];
            }
            // Check required variables
            $required_vars = $step_handler->get_required_variables();
            $missing_vars = [];
            foreach ($required_vars as $var_name) {
                if (!$this->variable_exists_in_context($var_name, $context)) {
                    $missing_vars[] = $var_name;
                }
            }

            if (!empty($missing_vars)) {
                return [
                    'success' => false,
                    'error' => 'Missing required variables: ' . implode(', ', $missing_vars),
                    'execution_time' => microtime(true) - $start_time
                ];
            }

            // Execute step
            $result = $step_handler->execute($context, $step_config);
            $result['execution_time'] = microtime(true) - $start_time;

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start_time
            ];
        }
    }

    /**
     * Record a step's token usage and estimated cost.
     *
     * Steps that do not call a model return no usage payload, and nothing is
     * recorded for them.
     *
     * @param array $step_config Step configuration.
     * @param array $step_result Result returned by the step.
     * @param array $context     Execution context.
     * @param array $workflow    Workflow definition.
     * @param bool  $test_mode   Whether this is a test run.
     * @return void
     */
    private function record_step_usage($step_result, $step_config, $context, $workflow, $test_mode)
    {
        $usage = $step_result['usage'] ?? null;

        if (empty($usage) || !is_array($usage)) {
            return;
        }

        \PolyTrans\Core\UsageRecorder::record([
            'provider' => $step_result['provider'] ?? '',
            'model' => $step_result['model'] ?? '',
            'usage' => $usage,
            'effort' => $step_result['effort'] ?? null,
            'activity' => 'workflow_step',
            'step' => $step_config['name'] ?? ($step_config['id'] ?? ($step_config['type'] ?? 'step')),
            'workflow_id' => $workflow['id'] ?? null,
            'post_id' => $context['translated_post_id'] ?? null,
            'source_post_id' => $context['original_post_id'] ?? null,
            'target_language' => $context['target_language'] ?? null,
            // A test run costs real money, so the row is written; the post's own
            // totals are left alone, since no published post came of it.
            'skip_post_meta' => (bool) $test_mode,
        ]);
    }

    /**
     * Check if a variable exists in the context (supports dot notation)
     *
     * @param string $var_name Variable name (supports dot notation like 'original_post.title')
     * @param array $context Execution context
     * @return bool
     */
    private function variable_exists_in_context($var_name, $context)
    {
        $parts = explode('.', $var_name);
        $current = $context;

        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return false;
            }
            $current = $current[$part];
        }

        return true;
    }

    /**
     * Merge step output into execution context
     * 
     * @param array &$context Execution context (passed by reference)
     * @param array $step_config Step configuration
     * @param mixed $step_output Step output data
     */
    private function merge_step_output(&$context, $step_config, $step_output)
    {
        $step_id = $step_config['id'] ?? 'unknown_step';

        // Initialize previous_steps if not exists
        if (!isset($context['previous_steps'])) {
            $context['previous_steps'] = [];
        }

        // Store step output
        $context['previous_steps'][$step_id] = $step_output;

        // If step specifies output variables, extract them to top level
        if (isset($step_config['output_variables']) && is_array($step_config['output_variables'])) {
            foreach ($step_config['output_variables'] as $var_name) {
                if (is_array($step_output) && isset($step_output[$var_name])) {
                    $context[$var_name] = $step_output[$var_name];
                }
            }
        }
    }

    /**
     * Validate workflow structure
     * 
     * @param array $workflow Workflow to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    private function validate_workflow($workflow)
    {
        $errors = [];

        // Check basic structure
        if (empty($workflow['steps']) || !is_array($workflow['steps'])) {
            $errors[] = 'Workflow must have at least one step';
        }

        // Validate each step
        foreach ($workflow['steps'] as $index => $step) {
            $step_errors = $this->validate_step_structure($step, $index);
            $errors = array_merge($errors, $step_errors);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate individual step structure
     * 
     * @param array $step Step configuration
     * @param int $index Step index
     * @return array Array of error messages
     */
    private function validate_step_structure($step, $index)
    {
        $errors = [];

        // Check required fields
        if (!isset($step['type']) || empty($step['type'])) {
            $errors[] = "Step {$index}: Type is required";
        }

        if (!isset($step['id']) || empty($step['id'])) {
            $errors[] = "Step {$index}: ID is required";
        }

        // Check if step type is registered
        if (isset($step['type']) && !isset($this->step_registry[$step['type']])) {
            $errors[] = "Step {$index}: Unknown step type '{$step['type']}'";
        }

        return $errors;
    }

    /**
     * Get registered step types
     * 
     * @return array Array of registered step handlers
     */
    public function get_registered_steps()
    {
        return $this->step_registry;
    }

    /**
     * Get step handler by type
     * 
     * @param string $type Step type
     * @return WorkflowStepInterface|null
     */
    public function get_step_handler($type)
    {
        return $this->step_registry[$type] ?? null;
    }

    /**
     * Extract relevant input variables for display purposes
     */
    private function extract_input_variables($context)
    {
        $input_vars = [];

        // Core post variables
        if (isset($context['title'])) {
            $input_vars['title'] = $context['title'];
        }
        if (isset($context['content'])) {
            $input_vars['content'] = strlen($context['content']) > 300
                ? substr($context['content'], 0, 300) . '...'
                : $context['content'];
        }
        if (isset($context['excerpt'])) {
            $input_vars['excerpt'] = $context['excerpt'];
        }
        if (isset($context['author_name'])) {
            $input_vars['author_name'] = $context['author_name'];
        }
        if (isset($context['date'])) {
            $input_vars['date'] = $context['date'];
        }

        // Post meta variables
        if (isset($context['translated_post']['meta']) && is_array($context['translated_post']['meta'])) {
            $input_vars['meta'] = $context['translated_post']['meta'];
        }

        // Context variables
        if (isset($context['target_language'])) {
            $input_vars['target_language'] = $context['target_language'];
        }
        if (isset($context['source_language'])) {
            $input_vars['source_language'] = $context['source_language'];
        }
        if (isset($context['translation_service'])) {
            $input_vars['translation_service'] = $context['translation_service'];
        }
        if (isset($context['quality_score'])) {
            $input_vars['quality_score'] = $context['quality_score'];
        }
        if (isset($context['word_count'])) {
            $input_vars['word_count'] = $context['word_count'];
        }

        return $input_vars;
    }
}

<?php

/**
 * Predefined AI Assistant Workflow Step
 * 
 * Handles communication with predefined OpenAI assistants configured in settings.
 */

namespace PolyTrans\PostProcessing\Steps;

use PolyTrans\PostProcessing\WorkflowStepInterface;
use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantExecutor;
use PolyTrans\Core\AIAssistantClientFactory;
use PolyTrans\Providers\AIAssistantClientInterface;

if (!defined('ABSPATH')) {
    exit;
}

class PredefinedAssistantStep implements WorkflowStepInterface
{
    /**
     * Get the step type identifier
     */
    public function get_type()
    {
        return 'predefined_assistant';
    }

    /**
     * Get the step name/title
     */
    public function get_name()
    {
        return __('Predefined AI Assistant', 'polytrans');
    }

    /**
     * Get the step description
     */
    public function get_description()
    {
        return __('Use a predefined AI assistant from any enabled provider with pre-configured settings', 'polytrans');
    }

    /**
     * Execute the workflow step
     */
    public function execute($context, $step_config)
    {
        $start_time = microtime(true);

        try {
            // Get assistant ID
            $assistant_id = $step_config['assistant_id'] ?? '';
            if (empty($assistant_id)) {
                return [
                    'success' => false,
                    'error' => 'Assistant ID is required'
                ];
            }

            // Get user message and interpolate variables
            $user_message = $step_config['user_message'] ?? '';
            if (empty($user_message)) {
                return [
                    'success' => false,
                    'error' => 'User message is required'
                ];
            }

            // Interpolate variables in user message
            $variable_manager = new \PolyTrans_Variable_Manager();
            $interpolated_user_message = $variable_manager->interpolate_template($user_message, $context);

            // Detect assistant type and route accordingly
            if (strpos($assistant_id, 'managed_') === 0) {
                // Managed Assistant - use AssistantExecutor
                $numeric_id = (int) str_replace('managed_', '', $assistant_id);
                $assistant = AssistantManager::get_assistant($numeric_id);
                
                if (!$assistant) {
                    return [
                        'success' => false,
                        'error' => "Managed Assistant not found (ID: {$numeric_id})",
                        'interpolated_user_message' => $interpolated_user_message
                    ];
                }
                
                // Execute managed assistant with context
                $executor = new AssistantExecutor();
                $result = $executor->execute($numeric_id, $context);
                
                if (is_wp_error($result)) {
                    return [
                        'success' => false,
                        'error' => $result->get_error_message(),
                        'interpolated_user_message' => $interpolated_user_message
                    ];
                }
                
                if (!$result['success']) {
                    return [
                        'success' => false,
                        'error' => $result['error'] ?? 'Managed Assistant execution failed',
                        'interpolated_user_message' => $interpolated_user_message
                    ];
                }
                
                // Process response from managed assistant
                $ai_output = $result['output'] ?? '';
                $processed_response = $this->process_assistant_response($ai_output, $step_config);
                
                $execution_time = microtime(true) - $start_time;
                
                return [
                    'success' => true,
                    'data' => $processed_response,
                    'execution_time' => $execution_time,
                    'raw_response' => $ai_output,
                    'interpolated_user_message' => $interpolated_user_message,
                    'assistant_id' => $assistant_id,
                    'tokens_used' => $result['usage'] ?? 0,
                    'usage' => $result['usage'] ?? [],
                    'provider' => $result['provider'] ?? null,
                    'model' => $result['model'] ?? null
                ];
            } elseif (strpos($assistant_id, 'provider_') === 0) {
                // Translation Provider - not supported in workflow steps
                return [
                    'success' => false,
                    'error' => 'Translation providers cannot be used in workflow steps. Use AI assistants instead.',
                    'interpolated_user_message' => $interpolated_user_message
                ];
            } else {
                // Provider API Assistant (asst_xxx, project_xxx, etc.)
                $settings = get_option('polytrans_settings', []);
                
                // Create appropriate client using factory
                $client = AIAssistantClientFactory::create($assistant_id, $settings);
                
                if (!$client) {
                    $provider_id = AIAssistantClientFactory::get_provider_id($assistant_id);
                    $provider_name = $provider_id ? ucfirst($provider_id) : 'Unknown provider';
                    
                    return [
                        'success' => false,
                        'error' => "$provider_name assistant client could not be created. Please check API key configuration.",
                        'interpolated_user_message' => $interpolated_user_message
                    ];
                }
                
                // For workflow, we need to execute assistant with custom user message
                // Since AIAssistantClientInterface is designed for translation, we'll use OpenAI client directly for now
                // Future: extend interface to support custom messages
                if ($client->get_provider_id() === 'openai') {
                    // Use OpenAI client directly for workflow execution
                    $ai_response = $this->call_openai_assistant_api($assistant_id, $interpolated_user_message, $settings);
                } else {
                    // Future: support other providers
                    return [
                        'success' => false,
                        'error' => 'Provider ' . $client->get_provider_id() . ' is not yet supported in workflow steps',
                        'interpolated_user_message' => $interpolated_user_message
                    ];
                }
            }

            if (!$ai_response['success']) {
                return [
                    'success' => false,
                    'error' => $ai_response['error'] ?? 'AI Assistant API call failed',
                    'interpolated_user_message' => $interpolated_user_message
                ];
            }

            // Process response
            $processed_response = $this->process_assistant_response($ai_response['data'], $step_config);

            $execution_time = microtime(true) - $start_time;

            return [
                'success' => true,
                'data' => $processed_response,
                'execution_time' => $execution_time,
                'raw_response' => $ai_response['data'],
                'interpolated_user_message' => $interpolated_user_message,
                'assistant_id' => $assistant_id,
                'tokens_used' => $ai_response['tokens_used'] ?? 0,
                'usage' => $ai_response['usage'] ?? [],
                'provider' => $ai_response['provider'] ?? null,
                'model' => $ai_response['model'] ?? null
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start_time,
                'interpolated_user_message' => $interpolated_user_message ?? ''
            ];
        }
    }

    /**
     * Validate step configuration
     */
    public function validate_config($step_config)
    {
        $errors = [];

        // Check required fields
        if (!isset($step_config['assistant_id']) || empty($step_config['assistant_id'])) {
            $errors[] = 'Assistant ID is required';
        }

        if (!isset($step_config['user_message']) || empty($step_config['user_message'])) {
            $errors[] = 'User message is required';
        }

        // Validate expected format if provided
        if (isset($step_config['expected_format']) && !in_array($step_config['expected_format'], ['text', 'json'])) {
            $errors[] = 'Expected format must be either "text" or "json"';
        }

        // Validate output variables if provided
        if (isset($step_config['output_variables']) && !is_array($step_config['output_variables']) && !is_string($step_config['output_variables'])) {
            $errors[] = 'Output variables must be an array or string';
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
            'assistant_response',  // Always available - raw response
            'processed_content'    // Always available - for plain text or fallback
        ];
    }

    /**
     * Get configuration schema for UI
     */
    public function get_config_schema()
    {
        // Assistants are loaded dynamically via AJAX in the workflow editor
        // Return empty options - JavaScript will populate them
        return [
            'assistant_id' => [
                'type' => 'select',
                'label' => 'AI Assistant',
                'required' => true,
                'options' => ['' => __('Loading assistants...', 'polytrans')],
                'data_attributes' => [
                    'load-via-ajax' => 'true'
                ]
            ],
            'user_message' => [
                'type' => 'textarea',
                'label' => 'User Message Template',
                'required' => true,
                'placeholder' => 'Review this content:\nTitle: {title}\nContent: {content}'
            ],
            'expected_format' => [
                'type' => 'select',
                'label' => 'Expected Response Format',
                'required' => false,
                'options' => [
                    'text' => 'Plain Text',
                    'json' => 'JSON Object'
                ],
                'default' => 'text'
            ],
            'output_variables' => [
                'type' => 'text',
                'label' => 'Output Variables (for JSON format)',
                'description' => 'Comma-separated variable names to extract from response'
            ]
        ];
    }


    /**
     * Call OpenAI assistant API (for workflow execution)
     */
    private function call_openai_assistant_api($assistant_id, $user_message, $settings)
    {
        try {
        $api_key = $settings['openai_api_key'] ?? '';
            $base_url = $settings['openai_base_url'] ?? 'https://api.openai.com/v1';

        if (empty($api_key)) {
        return [
                    'success' => false,
                    'error' => 'OpenAI API key not configured'
        ];
    }

            // Create OpenAI client
            $client = new \PolyTrans_OpenAI_Client($api_key, $base_url);

            // Create thread with initial message
            $thread_response = $client->create_thread([
                    [
                        'role' => 'user',
                        'content' => $user_message
                    ]
            ]);
            
            if (!$thread_response['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create thread: ' . $thread_response['error']
                ];
            }

            $thread_id = $thread_response['thread_id'];

            // Run assistant
            $run_response = $client->run_assistant($thread_id, $assistant_id);
            if (!$run_response['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to run assistant: ' . $run_response['error']
                ];
            }

            $run_id = $run_response['run_id'];

            // Wait for completion
            $completion_response = $client->wait_for_run_completion($thread_id, $run_id);
            if (!$completion_response['success']) {
                return [
                    'success' => false,
                    'error' => $completion_response['error'],
                    'error_code' => $completion_response['error_code'] ?? null,
                    'status' => $completion_response['status'] ?? 'failed'
                ];
            }

            // Get the latest assistant message
            $message_response = $client->get_latest_assistant_message($thread_id);
            if (!$message_response['success']) {
                return [
                    'success' => false,
                    'error' => $message_response['error']
                ];
            }

            // The completed run object carries the token counts and the model that
            // actually served it - the assistant config only names a default.
            $run = $completion_response['data'] ?? [];
            $usage = is_array($run) ? ($run['usage'] ?? []) : [];

            return [
                'success' => true,
                'data' => $message_response['content'],
                'tokens_used' => (int) ($usage['total_tokens'] ?? 0),
                'usage' => $usage,
                'provider' => 'openai',
                'model' => is_array($run) ? ($run['model'] ?? '') : ''
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Assistant API call failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process assistant response
     */
    private function process_assistant_response($response_content, $step_config)
    {
        $result = ['assistant_response' => $response_content];
        $expected_format = $step_config['expected_format'] ?? 'text';

        // Handle JSON format
        if ($expected_format === 'json') {
            $json_data = json_decode($response_content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                // If output variables are specified, extract them
                $output_variables = $step_config['output_variables'] ?? '';
                if (!empty($output_variables)) {
                    // Convert comma-separated string to array
                    if (is_string($output_variables)) {
                        $output_variables = array_map('trim', explode(',', $output_variables));
                    }

                    // Extract specified variables
                    foreach ($output_variables as $var_name) {
                        if (!empty($var_name) && isset($json_data[$var_name])) {
                            $result[$var_name] = $json_data[$var_name];
                        }
                    }
                } else {
                    // If no specific variables, include all JSON data
                    $result = array_merge($result, $json_data);
                }
            } else {
                // JSON parsing failed, treat as text
                $result['processed_content'] = $response_content;
            }
        } else {
            // Plain text format
            $result['processed_content'] = $response_content;
        }

        return $result;
    }

    /**
     * Get required variables for this step
     */
    public function get_required_variables()
    {
        return [
            // Only require basic variables that are commonly available
            'title',
            'content'
        ];
    }
}

<?php

/**
 * AI Assistant Workflow Step
 * 
 * Handles communication with AI assistants (like OpenAI) for post-processing tasks.
 */

namespace PolyTrans\PostProcessing\Steps;

use PolyTrans\PostProcessing\WorkflowStepInterface;

if (!defined('ABSPATH')) {
    exit;
}

class AiAssistantStep implements WorkflowStepInterface
{
    /**
     * Get the step type identifier
     */
    public function get_type()
    {
        return 'ai_assistant';
    }

    /**
     * Get the step name/title
     */
    public function get_name()
    {
        return __('AI Assistant', 'polytrans');
    }

    /**
     * Get the step description
     */
    public function get_description()
    {
        return __('Process content using an AI assistant with custom system prompt and user message templates', 'polytrans');
    }

    /**
     * Execute the workflow step
     */
    public function execute($context, $step_config)
    {
        $start_time = microtime(true);

        try {
            // Get system prompt and interpolate variables
            $system_prompt = $step_config['system_prompt'] ?? '';
            if (empty($system_prompt)) {
                return [
                    'success' => false,
                    'error' => 'System prompt is required'
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

            // Interpolate variables in both prompts
            $variable_manager = new \PolyTrans_Variable_Manager();
            $interpolated_system_prompt = $variable_manager->interpolate_template($system_prompt, $context);
            $interpolated_user_message = $variable_manager->interpolate_template($user_message, $context);

            // Get AI provider settings
            $provider_settings = $this->get_ai_provider_settings($step_config);
            if (!$provider_settings) {
                return [
                    'success' => false,
                    'error' => 'No AI provider with chat capability is configured. Please configure at least one provider (OpenAI, Claude, or Gemini) in settings.',
                    'interpolated_system_prompt' => $interpolated_system_prompt,
                    'interpolated_user_message' => $interpolated_user_message
                ];
            }
            
            // Log warning if provider was auto-selected
            $selected_provider = $step_config['provider'] ?? '';
            if (empty($selected_provider)) {
                \PolyTrans\Core\LogsManager::log(
                    sprintf(
                        'AI Assistant step: No provider selected, using auto-selected provider: %s',
                        $provider_settings['provider']
                    ),
                    'warning',
                    [
                        'step_config' => $step_config,
                        'selected_provider' => $provider_settings['provider']
                    ]
                );
            }

            // Prepare AI request with separate system and user messages
            $ai_request = $this->prepare_ai_request($interpolated_system_prompt, $interpolated_user_message, $step_config, $provider_settings);

            // Make AI API call
            $ai_response = $this->call_ai_api($ai_request, $provider_settings);

            if (!$ai_response['success']) {
                return [
                    'success' => false,
                    'error' => $ai_response['error'] ?? 'AI API call failed',
                    'interpolated_system_prompt' => $interpolated_system_prompt,
                    'interpolated_user_message' => $interpolated_user_message
                ];
            }

            // Process response based on expected format
            $expected_format = $step_config['expected_format'] ?? 'text';
            $processed_response = $this->process_ai_response($ai_response['data'], $expected_format);

            $execution_time = microtime(true) - $start_time;

            return [
                'success' => true,
                'data' => $processed_response,
                'interpolated_prompts' => [
                    'system_prompt' => $interpolated_system_prompt,
                    'user_message' => $interpolated_user_message
                ],
                'execution_time' => $execution_time,
                'raw_response' => $ai_response['data'],
                'interpolated_system_prompt' => $interpolated_system_prompt,
                'interpolated_user_message' => $interpolated_user_message,
                'tokens_used' => $ai_response['tokens_used'] ?? 0
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $start_time,
                'interpolated_system_prompt' => $interpolated_system_prompt ?? '',
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
        if (!isset($step_config['system_prompt']) || empty($step_config['system_prompt'])) {
            $errors[] = 'System prompt is required';
        }

        if (!isset($step_config['user_message']) || empty($step_config['user_message'])) {
            $errors[] = 'User message is required';
        }

        // Validate expected format
        $valid_formats = ['text', 'json'];
        $expected_format = $step_config['expected_format'] ?? 'text';
        if (!in_array($expected_format, $valid_formats)) {
            $errors[] = 'Expected format must be one of: ' . implode(', ', $valid_formats);
        }

        // Validate model if provided
        if (isset($step_config['model']) && !empty($step_config['model'])) {
            $valid_models = $this->get_all_available_models();
            if (!in_array($step_config['model'], $valid_models)) {
                $errors[] = 'Invalid model. Must be one of the available OpenAI models.';
            }
        }

        // Validate output variables if provided
        if (isset($step_config['output_variables']) && !is_array($step_config['output_variables'])) {
            $errors[] = 'Output variables must be an array';
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
            'processed_content',
            'suggestions',
            'score',
            'feedback'
        ];
    }

    /**
     * Get required input variables for this step
     */
    public function get_required_variables()
    {
        // No strict requirements - depends on system prompt
        return [];
    }

    /**
     * Get step configuration schema for UI generation
     */
    public function get_config_schema()
    {
        return [
            'system_prompt' => [
                'type' => 'textarea',
                'label' => __('System Prompt', 'polytrans'),
                'description' => __('Instructions for the AI assistant. Use {variable_name} to include data from context.', 'polytrans'),
                'required' => true,
                'rows' => 6
            ],
            'user_message' => [
                'type' => 'textarea',
                'label' => __('User Message Template', 'polytrans'),
                'description' => __('Template for the user message. Use {variable_name} to include data from context.', 'polytrans'),
                'required' => true,
                'rows' => 4,
                'placeholder' => 'Title: {title}\nContent: {content}\nTarget Language: {target_language}\n\nPlease review this translated content and provide your analysis.'
            ],
            'model' => [
                'type' => 'select',
                'label' => __('AI Model', 'polytrans'),
                'description' => __('OpenAI model to use for this step (overrides global setting)', 'polytrans'),
                'options' => [
                    '' => __('Use Global Setting', 'polytrans'),
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                    'gpt-4' => 'GPT-4',
                    'gpt-4-turbo' => 'GPT-4 Turbo',
                    'gpt-4o' => 'GPT-4o',
                    'gpt-4o-mini' => 'GPT-4o Mini'
                ],
                'default' => ''
            ],
            'expected_format' => [
                'type' => 'select',
                'label' => __('Expected Response Format', 'polytrans'),
                'description' => __('How the AI should format its response', 'polytrans'),
                'options' => [
                    'text' => __('Plain Text', 'polytrans'),
                    'json' => __('JSON Object', 'polytrans')
                ],
                'default' => 'text'
            ],
            'output_variables' => [
                'type' => 'text',
                'label' => __('Output Variables', 'polytrans'),
                'description' => __('Comma-separated list of variable names to extract from JSON response', 'polytrans'),
                'placeholder' => 'reviewed_content, suggestions, score'
            ],
            'temperature' => [
                'type' => 'number',
                'label' => __('Temperature', 'polytrans'),
                'description' => __('AI creativity level (0.0 = focused, 1.0 = creative)', 'polytrans'),
                'min' => 0.0,
                'max' => 1.0,
                'step' => 0.1,
                'default' => 0.7
            ]
        ];
    }

    /**
     * Get AI provider settings
     */
    private function get_ai_provider_settings($step_config = [])
    {
        $polytrans_settings = get_option('polytrans_settings', []);
        $selected_provider = $step_config['provider'] ?? '';
        
        // If provider is selected, use it
        if (!empty($selected_provider)) {
            return $this->get_provider_settings($selected_provider, $step_config, $polytrans_settings);
        }
        
        // Fallback: Get random enabled provider with chat capability
        $available_providers = $this->get_available_chat_providers($polytrans_settings);
        
        if (empty($available_providers)) {
            return false;
        }
        
        // Log warning that we're using a random provider
        $random_provider = $available_providers[array_rand($available_providers)];
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
        error_log(sprintf(
            '[PolyTrans] AI Assistant step: No provider selected, using random enabled provider: %s',
            $random_provider
        ));
        
        return $this->get_provider_settings($random_provider, $step_config, $polytrans_settings);
    }
    
    /**
     * Get settings for a specific provider
     */
    private function get_provider_settings($provider_id, $step_config, $polytrans_settings)
    {
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($provider_id);
        
        if (!$provider) {
            return false;
        }
        
        // Get provider manifest to find API key setting
        $settings_provider_class = $provider->get_settings_provider_class();
        if (!$settings_provider_class || !class_exists($settings_provider_class)) {
            return false;
        }
        
        $settings_provider = new $settings_provider_class();
        if (!method_exists($settings_provider, 'get_provider_manifest')) {
            return false;
        }
        
        $manifest = $settings_provider->get_provider_manifest($polytrans_settings);
        $api_key_setting = $manifest['api_key_setting'] ?? '';
        
        if (empty($api_key_setting)) {
            return false;
        }
        
        $api_key = $polytrans_settings[$api_key_setting] ?? '';
        if (empty($api_key)) {
            return false;
        }
        
        // Get model - check step config first, then provider-specific setting
        $model = $step_config['model'] ?? '';
        
        // If no model in step config, try provider-specific setting
        if (empty($model)) {
            $model_setting = $provider_id . '_model';
            $model = $polytrans_settings[$model_setting] ?? '';
        }
        
        // Get base URL
        $base_url_setting = $provider_id . '_base_url';
        $base_url = $polytrans_settings[$base_url_setting] ?? '';
        
        // Provider-specific defaults
        switch ($provider_id) {
            case 'openai':
                if (empty($model)) {
                    $model = $polytrans_settings['openai_model'] ?? 'gpt-4o-mini';
                }
                if (empty($base_url)) {
                    $base_url = $polytrans_settings['openai_base_url'] ?? 'https://api.openai.com/v1';
                }
                break;
            case 'claude':
                if (empty($base_url)) {
                    $base_url = 'https://api.anthropic.com/v1';
                }
                break;
            case 'gemini':
                if (empty($base_url)) {
                    $base_url = 'https://generativelanguage.googleapis.com/v1beta';
                }
                break;
        }
        
        return [
            'provider' => $provider_id,
            'api_key' => $api_key,
            'model' => $model,
            'base_url' => $base_url,
        ];
    }
    
    /**
     * Get available providers with chat capability
     */
    private function get_available_chat_providers($settings)
    {
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $all_providers = $registry->get_providers();
        
        $chat_providers = [];
        
        foreach ($all_providers as $provider_id => $provider) {
            // Check if provider is enabled
            if (!in_array($provider_id, $enabled_providers)) {
                continue;
            }
            
            // Check if provider supports chat capability
            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                if (method_exists($settings_provider, 'get_provider_manifest')) {
                    $manifest = $settings_provider->get_provider_manifest($settings);
                    $capabilities = $manifest['capabilities'] ?? [];
                    
                    // Only include providers with 'chat' capability
                    if (in_array('chat', $capabilities)) {
                        // Check if API key is configured
                        $api_key_setting = $manifest['api_key_setting'] ?? '';
                        if (!empty($api_key_setting)) {
                            $api_key = $settings[$api_key_setting] ?? '';
                            if (!empty($api_key)) {
                                $chat_providers[] = $provider_id;
                            }
                        }
                    }
                }
            }
        }
        
        return $chat_providers;
    }

    /**
     * Prepare AI request
     */
    private function prepare_ai_request($system_prompt, $user_message, $step_config, $provider_settings)
    {
        // Ensure temperature is a float
        $temperature = $step_config['temperature'] ?? 0.7;
        if (is_string($temperature)) {
            $temperature = floatval($temperature);
        }
        // Clamp temperature to valid range (provider-specific)
        $provider_id = $provider_settings['provider'] ?? 'openai';
        $max_temp = ($provider_id === 'openai') ? 2.0 : 1.0;
        $temperature = max(0.0, min($max_temp, $temperature));

        // Build messages array
        $messages = [];
        
        // Add system message if provider supports it
        if (!empty($system_prompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt
            ];
        }
        
        // Add user message
        $messages[] = [
            'role' => 'user',
            'content' => $user_message
        ];

        // For JSON format, ensure system prompt includes JSON instruction
        if (($step_config['expected_format'] ?? 'text') === 'json') {
            // Only add JSON instruction if not already present in system prompt
            if (stripos($system_prompt, 'json') === false) {
                if (!empty($messages) && isset($messages[0]['content'])) {
                    $messages[0]['content'] .= "\n\nPlease respond with valid JSON only.";
                }
            }
        }

        return [
            'messages' => $messages,
            'temperature' => $temperature,
            'model' => $provider_settings['model'] ?? '',
            'max_tokens' => $step_config['max_tokens'] ?? null,
        ];
    }

    /**
     * Call AI API using ChatClientInterface
     */
    private function call_ai_api($request, $provider_settings)
    {
        $provider_id = $provider_settings['provider'] ?? 'openai';
        $settings = get_option('polytrans_settings', []);
        
        // Use ChatClientFactory to get the appropriate client
        $chat_client = \PolyTrans\Core\ChatClientFactory::create($provider_id, $settings);
        
        if (!$chat_client) {
            return [
                'success' => false,
                'error' => sprintf('Chat client for provider "%s" could not be created. Please check API key configuration.', $provider_id)
            ];
        }
        
        // Prepare parameters for chat_completion
        $parameters = [
            'temperature' => $request['temperature'] ?? 0.7,
        ];
        
        // Add model if provided
        if (!empty($request['model'])) {
            $parameters['model'] = $request['model'];
        }
        
        // Add max_tokens if provided
        if (!empty($request['max_tokens'])) {
            $parameters['max_tokens'] = $request['max_tokens'];
        }
        
        // Call chat_completion
        $response = $chat_client->chat_completion($request['messages'], $parameters);
        
        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'AI API call failed'
            ];
        }
        
        // Extract content using the client's extract_content method
        $content = $chat_client->extract_content($response['data']);
        
        if ($content === null) {
            return [
                'success' => false,
                'error' => 'Failed to extract content from AI response'
            ];
        }
        
        // Try to extract tokens_used from response data if available
        $tokens_used = 0;
        if (isset($response['data']) && is_array($response['data'])) {
            // OpenAI format: usage.total_tokens
            if (isset($response['data']['usage']['total_tokens'])) {
                $tokens_used = (int) $response['data']['usage']['total_tokens'];
            }
            // Claude format: usage.input_tokens + usage.output_tokens
            elseif (isset($response['data']['usage']['input_tokens']) || isset($response['data']['usage']['output_tokens'])) {
                $tokens_used = (int) ($response['data']['usage']['input_tokens'] ?? 0) + (int) ($response['data']['usage']['output_tokens'] ?? 0);
            }
        }
        
        return [
            'success' => true,
            'data' => $content,
            'tokens_used' => $tokens_used
        ];
    }

    /**
     * Process AI response based on expected format
     */
    private function process_ai_response($response_text, $expected_format)
    {
        if ($expected_format === 'json') {
            $variable_manager = new \PolyTrans_Variable_Manager();
            $parsed_json = $variable_manager->parse_json_response($response_text);

            if (!empty($parsed_json)) {
                return $parsed_json;
            } else {
                // If JSON parsing failed, return as text
                return [
                    'ai_response' => $response_text,
                    '_parsing_error' => 'Failed to parse as JSON'
                ];
            }
        }

        // For text format, return as simple response
        return [
            'ai_response' => $response_text
        ];
    }

    /**
     * Get all available OpenAI models (using same source as OpenAI settings provider)
     */
    private function get_all_available_models($selected_model = null)
    {
        // Get the OpenAI settings provider to ensure we use the same model list
        $provider = new \PolyTrans_OpenAI_Settings_Provider();
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('get_grouped_models');
        $method->setAccessible(true);
        $grouped_models = $method->invoke($provider, $selected_model);

        $models = [];
        foreach ($grouped_models as $group => $group_models) {
            $models = array_merge($models, array_keys($group_models));
        }
        return $models;
    }
}

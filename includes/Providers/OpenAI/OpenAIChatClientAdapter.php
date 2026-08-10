<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Providers\ChatClientInterface;

/**
 * OpenAI Chat Client Adapter
 * Implements ChatClientInterface for OpenAI Chat Completions API
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAIChatClientAdapter implements ChatClientInterface
{
    private $api_key;
    private $base_url;

    /**
     * Lazily created delegate for models or effort levels that need /responses.
     *
     * @var OpenAIResponsesClientAdapter|null
     */
    private $responses_adapter = null;

    public function __construct($api_key, $base_url = 'https://api.openai.com/v1')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }

    /**
     * Get the /responses delegate.
     *
     * This adapter stays the single entry point for OpenAI - the factory only knows
     * the provider, not the model, so the endpoint choice has to happen here, where
     * the model and requested effort are both known.
     *
     * @return OpenAIResponsesClientAdapter
     */
    private function get_responses_adapter()
    {
        if ($this->responses_adapter === null) {
            $this->responses_adapter = new OpenAIResponsesClientAdapter($this->api_key, $this->base_url);
        }

        return $this->responses_adapter;
    }

    public function get_provider_id()
    {
        return 'openai';
    }
    
    public function chat_completion($messages, $parameters)
    {
        // Model must be provided - no fallback
        $model = $parameters['model'] ?? '';
        if (empty($model)) {
            // Try to get model from settings
            $settings = get_option('polytrans_settings', []);
            $model = $settings['openai_model'] ?? '';
        }

        // Model is required - return error if not set
        if (empty($model)) {
            return [
                'success' => false,
                'data' => null,
                'error' => __('OpenAI model is not selected. Please select a model in settings.', 'polytrans'),
                'error_code' => 'model_not_selected',
            ];
        }

        // Reasoning models reject temperature and expect reasoning_effort instead.
        // The same call also decides the endpoint: the `-pro` and `-codex` models
        // live only on /responses, and so does the `max` effort level.
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', $model, $parameters);

        if ($prepared['surface'] === ModelCapabilities::SURFACE_RESPONSES) {
            return $this->get_responses_adapter()->chat_completion($messages, $parameters);
        }

        $parameters_out = $prepared['parameters'];

        // Reasoning models rejected `max_tokens` outright ("use max_completion_tokens
        // instead"), so a workflow step with a token limit failed with a 400 before
        // the model ever ran. Classic models keep the original key.
        if (
            $prepared['reasoning'] !== null
            && isset($parameters_out['max_tokens'])
            && !isset($parameters_out['max_completion_tokens'])
        ) {
            $parameters_out['max_completion_tokens'] = $parameters_out['max_tokens'];
            unset($parameters_out['max_tokens']);
        }

        // Build request body
        $body = array_merge(
            [
                'messages' => $messages,
            ],
            $parameters_out,
            ['model' => $model]
        );

        if ($prepared['reasoning'] !== null) {
            $body[$prepared['reasoning']['param']] = $prepared['reasoning']['value'];
        }

        // Get API timeout from settings (default: 180 seconds)
        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        $api_timeout = max(30, min(600, $api_timeout)); // Clamp between 30-600 seconds
        
        // Make API request with retry on timeout
        $max_attempts = 2; // Initial attempt + 1 retry
        $last_response = null;
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_post(
                $this->base_url . '/chat/completions',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key,
                    ],
                    'body' => wp_json_encode($body),
                    'timeout' => $api_timeout,
                ]
            );
            
            // Check for timeout errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                
                // If timeout and we have attempts left, retry
                if ($attempt < $max_attempts &&
                    (strpos(strtolower($error_message), 'timeout') !== false ||
                     strpos(strtolower($error_message), 'timed out') !== false)) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                    error_log(sprintf(
                        '[PolyTrans OpenAI Chat] Request timeout on attempt %d/%d, retrying...',
                        $attempt,
                        $max_attempts
                    ));
                    $last_response = $response;
                    continue; // Retry
                }
                
                // Non-timeout error or all attempts exhausted
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $error_message
                ];
            }
            
            // Success - break out of retry loop
            break;
        }
        
        // Use last response if we exhausted attempts
        if (is_wp_error($response) && $last_response) {
            $response = $last_response;
        }
        
        // Handle errors (after retries)
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'data' => null,
                'error' => $response->get_error_message()
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body_data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Handle API errors
        if ($status_code !== 200) {
            $error_message = $body_data['error']['message'] ?? 'Unknown API error';
            $error_code = $status_code === 429 ? 'rate_limit' : 'api_error';
            
            return [
                'success' => false,
                'data' => null,
                'error' => $error_message,
                'error_code' => $error_code,
                'status' => $status_code,
                'retry_after' => wp_remote_retrieve_header($response, 'retry-after'),
            ];
        }
        
        return [
            'success' => true,
            'data' => $body_data,
            'error' => null
        ];
    }
    
    public function extract_content($response)
    {
        // A delegated request answers in the /responses shape (`output` items rather
        // than `choices`), and the caller still holds this adapter.
        if (is_array($response) && !isset($response['choices']) && isset($response['output'])) {
            return $this->get_responses_adapter()->extract_content($response);
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return null;
        }

        return $response['choices'][0]['message']['content'];
    }
}

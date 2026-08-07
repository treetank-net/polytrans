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
    
    public function __construct($api_key, $base_url = 'https://api.openai.com/v1')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
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
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', $model, $parameters);

        // Build request body
        $body = array_merge(
            [
                'messages' => $messages,
            ],
            $prepared['parameters'],
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
        if (!isset($response['choices'][0]['message']['content'])) {
            return null;
        }
        
        return $response['choices'][0]['message']['content'];
    }
}


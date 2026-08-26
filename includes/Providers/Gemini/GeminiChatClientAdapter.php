<?php

namespace PolyTrans\Providers\Gemini;

use PolyTrans\Providers\ChatClientInterface;
use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Core\Http\HttpClient;
use PolyTrans\Core\Http\HttpResponse;

/**
 * Gemini Chat Client Adapter
 * Implements ChatClientInterface for Google Gemini API
 */

if (!defined('ABSPATH')) {
    exit;
}

class GeminiChatClientAdapter implements ChatClientInterface
{
    private $api_key;
    private $base_url;
    private $http_client;
    
    public function __construct($api_key, $base_url = 'https://generativelanguage.googleapis.com/v1beta')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
        
        // Get API timeout from settings (default: 180 seconds)
        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        $api_timeout = max(30, min(600, $api_timeout)); // Clamp between 30-600 seconds
        
        // Initialize HTTP client with configurable timeout
        // Note: Gemini uses query string for API key, not headers
        $this->http_client = new HttpClient($this->base_url, $api_timeout);
        $this->http_client->set_header('Content-Type', 'application/json');
    }
    
    public function get_provider_id()
    {
        return 'gemini';
    }
    
    public function chat_completion($messages, $parameters)
    {
        // Convert OpenAI format to Gemini format
        // Gemini uses: contents array with parts (text) + systemInstruction (optional)
        $system_instruction = '';
        $gemini_contents = [];
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            
            if ($role === 'system') {
                $system_instruction = $content;
            } elseif ($role === 'user' || $role === 'assistant') {
                // Convert role: OpenAI uses 'assistant', Gemini uses 'model'
                $gemini_role = ($role === 'assistant') ? 'model' : 'user';
                
                $gemini_contents[] = [
                    'role' => $gemini_role,
                    'parts' => [
                        ['text' => $content]
                    ]
                ];
            }
        }
        
        // Model must be provided - no fallback
        $model = $parameters['model'] ?? '';
        if (empty($model)) {
            // Try to get model from settings
            $settings = get_option('polytrans_settings', []);
            $model = $settings['gemini_model'] ?? '';
        }
        
        // Model is required - return error if not set
        if (empty($model)) {
            return [
                'success' => false,
                'data' => null,
                'error' => __('Gemini model is not selected. Please select a model in settings.', 'treetank-trans'),
                'error_code' => 'model_not_selected',
            ];
        }
        
        // Build request body
        $body = [
            'contents' => $gemini_contents,
        ];
        
        // Add system instruction if present
        if (!empty($system_instruction)) {
            $body['systemInstruction'] = [
                'parts' => [
                    ['text' => $system_instruction]
                ]
            ];
        }
        
        // Resolve temperature / reasoning effort for this specific model.
        // Gemini expresses effort as thinkingConfig.thinkingLevel (older
        // configurations may still resolve to a thinkingBudget).
        $prepared = ModelCapabilities::prepare_chat_parameters('gemini', $model, $parameters);
        $resolved = $prepared['parameters'];
        $reasoning = $prepared['reasoning'];

        // Add generation config (temperature, maxOutputTokens, etc.)
        $generation_config = [];
        if (isset($resolved['temperature'])) {
            $generation_config['temperature'] = $resolved['temperature'];
        }
        if (isset($resolved['max_tokens'])) {
            $generation_config['maxOutputTokens'] = $resolved['max_tokens'];
        }
        if (isset($resolved['top_p'])) {
            $generation_config['topP'] = $resolved['top_p'];
        }
        if (isset($resolved['top_k'])) {
            $generation_config['topK'] = $resolved['top_k'];
        }

        if ($reasoning !== null) {
            $thinking_config = [];
            if ($reasoning['mode'] === ModelCapabilities::MODE_THINKING_BUDGET) {
                $thinking_config['thinkingBudget'] = (int) $reasoning['value'];
            } else {
                $thinking_config[$reasoning['param']] = $reasoning['value'];
            }
            $generation_config['thinkingConfig'] = $thinking_config;
        }

        if (!empty($generation_config)) {
            $body['generationConfig'] = $generation_config;
        }
        
        // Get API timeout from settings (default: 180 seconds)
        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        $api_timeout = max(30, min(600, $api_timeout)); // Clamp between 30-600 seconds
        
        // Make API request
        // Gemini uses query string for API key
        $url = '/models/' . urlencode($model) . ':generateContent?key=' . urlencode($this->api_key);
        
        // HttpClient will handle retry on timeout
        $response = $this->http_client->post($url, $body, [
            'timeout' => $api_timeout,
            'retry_on_timeout' => true,
        ]);
        
        // Handle errors
        if ($response->is_error()) {
            return [
                'success' => false,
                'data' => null,
                'error' => $response->get_error_message(),
                'error_code' => $response->get_status_code() === 429 ? 'rate_limit' : 'api_error',
                'status' => $response->get_status_code(),
            ];
        }
        
        $body_data = $response->get_json(true);
        
        return [
            'success' => true,
            'data' => $body_data,
            'error' => null
        ];
    }
    
    public function extract_content($response)
    {
        // Gemini format: candidates[0].content.parts[0].text
        if (!isset($response['candidates']) || !is_array($response['candidates']) || empty($response['candidates'])) {
            return null;
        }
        
        $candidate = $response['candidates'][0];
        if (!isset($candidate['content']['parts']) || !is_array($candidate['content']['parts'])) {
            return null;
        }
        
        // Find first text part
        foreach ($candidate['content']['parts'] as $part) {
            if (isset($part['text'])) {
                return $part['text'];
            }
        }
        
        return null;
    }
}

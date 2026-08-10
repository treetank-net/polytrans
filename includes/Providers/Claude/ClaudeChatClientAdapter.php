<?php

namespace PolyTrans\Providers\Claude;

use PolyTrans\Providers\ChatClientInterface;
use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Core\Http\HttpClient;
use PolyTrans\Core\Http\HttpResponse;

/**
 * Claude Chat Client Adapter
 * Implements ChatClientInterface for Claude Messages API
 */

if (!defined('ABSPATH')) {
    exit;
}

class ClaudeChatClientAdapter implements ChatClientInterface
{
    private $api_key;
    private $base_url;
    private $api_version;
    private $http_client;
    
    public function __construct($api_key, $base_url = 'https://api.anthropic.com/v1', $api_version = '2023-06-01')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
        $this->api_version = $api_version;
        
        // Get API timeout from settings (default: 180 seconds)
        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        $api_timeout = max(30, min(600, $api_timeout)); // Clamp between 30-600 seconds
        
        // Initialize HTTP client with configurable timeout
        $this->http_client = new HttpClient($this->base_url, $api_timeout);
        $this->http_client
            ->set_api_key($api_key, 'x-api-key')
            ->set_header('anthropic-version', $api_version)
            ->set_header('Content-Type', 'application/json');
    }
    
    public function get_provider_id()
    {
        return 'claude';
    }
    
    public function chat_completion($messages, $parameters)
    {
        // Convert OpenAI format to Claude format
        // Claude uses: messages array (user/assistant only) + separate system field
        $system_message = '';
        $claude_messages = [];
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            
            if ($role === 'system') {
                $system_message = $content;
            } elseif ($role === 'user' || $role === 'assistant') {
                $claude_messages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }
        
        // Build request body
        // Model must be provided - no fallback
        $model = $parameters['model'] ?? '';
        if (empty($model)) {
            // Try to get model from settings
            $settings = get_option('polytrans_settings', []);
            $model = $settings['claude_model'] ?? '';
        }
        
        // Model is required - return error if not set
        if (empty($model)) {
            return [
                'success' => false,
                'data' => null,
                'error' => __('Claude model is not selected. Please select a model in settings.', 'polytrans'),
                'error_code' => 'model_not_selected',
            ];
        }
        
        // Resolve temperature / reasoning effort for this specific model.
        // Newer models take `output_config.effort`; models without effort support
        // express it as an extended-thinking token budget instead.
        $prepared = ModelCapabilities::prepare_chat_parameters('claude', $model, $parameters);
        $resolved = $prepared['parameters'];
        $reasoning = $prepared['reasoning'];

        $max_tokens = absint($resolved['max_tokens'] ?? 4096);
        if ($max_tokens <= 0) {
            $max_tokens = 4096;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'messages' => $claude_messages,
        ];

        // Add system message if present
        if (!empty($system_message)) {
            $body['system'] = $system_message;
        }

        if ($reasoning !== null) {
            if ($reasoning['mode'] === ModelCapabilities::MODE_THINKING_BUDGET) {
                // Extended thinking: budget_tokens must leave room inside max_tokens.
                $budget = (int) $reasoning['value'];
                if ($budget > 0) {
                    $body['thinking'] = [
                        'type' => 'enabled',
                        'budget_tokens' => max(1024, $budget),
                    ];
                    if ($body['max_tokens'] <= $body['thinking']['budget_tokens']) {
                        $body['max_tokens'] = $body['thinking']['budget_tokens'] + $max_tokens;
                    }
                }
            } else {
                // Effort is a nested field: {"output_config": {"effort": "high"}}.
                $body = $this->set_nested_parameter($body, $reasoning['param'], $reasoning['value']);
            }
        }

        // Add other parameters (temperature, top_p, etc.)
        if (isset($resolved['temperature'])) {
            $body['temperature'] = $resolved['temperature'];
        }
        $thinking_enabled = isset($body['thinking']);
        if (isset($resolved['top_p']) && !$thinking_enabled) {
            $body['top_p'] = $resolved['top_p'];
        }
        if (isset($resolved['top_k']) && !$thinking_enabled) {
            // Claude rejects top_k while extended thinking is enabled.
            $body['top_k'] = $resolved['top_k'];
        }

        // Get API timeout from settings (default: 180 seconds)
        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        $api_timeout = max(30, min(600, $api_timeout)); // Clamp between 30-600 seconds
        
        // Make API request (HttpClient will handle retry on timeout)
        $response = $this->http_client->post('/messages', $body, [
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
    
    /**
     * Write a possibly dotted parameter path into a request body.
     *
     * @param array  $body  Request body.
     * @param string $path  Parameter path, e.g. "output_config.effort".
     * @param mixed  $value Value to set.
     * @return array
     */
    private function set_nested_parameter(array $body, $path, $value)
    {
        $segments = explode('.', (string) $path);
        $target = &$body;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $target[$segment] = $value;
                break;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }
        unset($target);

        return $body;
    }

    public function extract_content($response)
    {
        // Claude format: content[0].text
        if (!isset($response['content']) || !is_array($response['content'])) {
            return null;
        }
        
        // Find first text block
        foreach ($response['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }
        
        return null;
    }
}

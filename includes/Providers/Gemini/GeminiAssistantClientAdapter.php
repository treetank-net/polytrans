<?php

namespace PolyTrans\Providers\Gemini;

use PolyTrans\Providers\AIAssistantClientInterface;
use PolyTrans\PostProcessing\JsonResponseParser;
use PolyTrans\Core\LogsManager;

/**
 * Gemini Assistant Client Adapter
 * Implements AIAssistantClientInterface for Google Gemini Agents API
 */

if (!defined('ABSPATH')) {
    exit;
}

class GeminiAssistantClientAdapter implements AIAssistantClientInterface
{
    private $api_key;
    private $base_url;
    
    public function __construct($api_key, $base_url = 'https://generativelanguage.googleapis.com/v1beta')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }
    
    public function get_provider_id()
    {
        return 'gemini';
    }
    
    public function supports_assistant_id($assistant_id)
    {
        // Gemini agents use format: agent_xxx or agents/xxx
        return strpos($assistant_id, 'agent_') === 0 || strpos($assistant_id, 'agents/') === 0;
    }
    
    public function execute_assistant($assistant_id, $content, $source_lang, $target_lang)
    {
        LogsManager::log("Gemini Agent: executing $assistant_id ($source_lang -> $target_lang)", "info");
        
        // Prepare the content for translation as JSON
        $content_to_translate = [
            'title' => $content['title'] ?? '',
            'content' => $content['content'] ?? '',
            'excerpt' => $content['excerpt'] ?? '',
            'meta' => $content['meta'] ?? [],
            'featured_image' => $content['featured_image'] ?? null
        ];
        
        $prompt = "Please translate the following JSON content from $source_lang to $target_lang. Return only a JSON object with the same structure but translated content:\n\n" . 
                  json_encode($content_to_translate, JSON_PRETTY_PRINT);
        
        // Convert agent ID format (agent_xxx -> agents/xxx)
        $agent_path = $assistant_id;
        if (strpos($agent_path, 'agent_') === 0) {
            $agent_path = 'agents/' . substr($agent_path, 7);
        }
        
        // Gemini Agents API: Create a session and send message
        // Note: Gemini Agents API might work differently - this is a simplified implementation
        // For now, we'll use the chat API with the agent context
        
        // Build request body
        $body = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
        ];
        
        // Make API request to agent endpoint
        // Note: Actual Gemini Agents API endpoint might be different
        $response = wp_remote_post(
            $this->base_url . '/' . $agent_path . ':generateContent?key=' . urlencode($this->api_key),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
                'timeout' => 120,
            ]
        );
        
        // Handle errors
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to execute Gemini agent: ' . $response->get_error_message()
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body_data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Handle API errors
        if ($status_code !== 200) {
            $error_message = 'Unknown API error';
            
            if (isset($body_data['error'])) {
                if (is_array($body_data['error'])) {
                    $error_message = $body_data['error']['message'] ?? $body_data['error']['status'] ?? 'Unknown API error';
                } else {
                    $error_message = $body_data['error'];
                }
            }
            
            return [
                'success' => false,
                'translated_content' => null,
                'error' => $error_message
            ];
        }
        
        // Token counts and the model that actually served the request. Gemini reports
        // thinking tokens outside the candidate count, so pricing needs the raw block.
        $usage_fields = [
            'usage' => $body_data['usageMetadata'] ?? [],
            'provider' => 'gemini',
            'model' => $body_data['modelVersion'] ?? $agent_path,
        ];

        // Extract content from response
        $translated_text = null;
        if (isset($body_data['candidates'][0]['content']['parts'][0]['text'])) {
            $translated_text = $body_data['candidates'][0]['content']['parts'][0]['text'];
        }

        if (empty($translated_text)) {
            return array_merge([
                'success' => false,
                'translated_content' => null,
                'error' => 'No content returned from Gemini agent'
            ], $usage_fields);
        }
        
        // Parse JSON response
        $parser = new JsonResponseParser();
        $parsed = $parser->parse($translated_text);
        
        if (!$parsed['success']) {
            return array_merge([
                'success' => false,
                'translated_content' => null,
                'error' => 'Failed to parse Gemini agent response: ' . ($parsed['error'] ?? 'Invalid JSON')
            ], $usage_fields);
        }

        return array_merge([
            'success' => true,
            'translated_content' => $parsed['data'],
            'error' => null
        ], $usage_fields);
    }
}

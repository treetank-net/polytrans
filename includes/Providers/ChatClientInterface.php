<?php

namespace PolyTrans\Providers;

/**
 * Chat Client Interface
 * Defines the contract for chat/completion API clients (OpenAI, Claude, Gemini, etc.)
 * Used for managed assistants that use chat API instead of dedicated Assistants API
 */

if (!defined('ABSPATH')) {
    exit;
}

interface ChatClientInterface
{
    /**
     * Send chat completion request
     * 
     * @param array $messages Array of messages with 'role' and 'content'
     * @param array $parameters API parameters (model, temperature, max_tokens, etc.)
     * @return array Response with 'success' => bool, 'data' => array|WP_Error, 'error' => string|null
     */
    public function chat_completion($messages, $parameters);
    
    /**
     * Get provider ID
     * 
     * @return string Provider ID (openai, claude, gemini, etc.)
     */
    public function get_provider_id();
    
    /**
     * Extract content from API response
     * Different providers return responses in different formats
     * 
     * @param array $response Raw API response
     * @return string|null Content or null if not found
     */
    public function extract_content($response);
}

<?php

namespace PolyTrans\Core;

use PolyTrans\Providers\AIAssistantClientInterface;
use PolyTrans\Providers\OpenAI\OpenAIAssistantClientAdapter;
use PolyTrans\Providers\Gemini\GeminiAssistantClientAdapter;

/**
 * AI Assistant Client Factory
 * Creates appropriate AI assistant client based on assistant ID format
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIAssistantClientFactory
{
    /**
     * Create AI assistant client for given assistant ID
     * 
     * @param string $assistant_id Assistant ID (asst_xxx, project_xxx, etc.)
     * @param array $settings Translation settings
     * @return AIAssistantClientInterface|null Client instance or null if not supported
     */
    public static function create($assistant_id, $settings)
    {
        // Allow external plugins to provide their own clients via filter
        $client = apply_filters('polytrans_assistant_client_factory_create', null, $assistant_id, $settings);
        if ($client instanceof AIAssistantClientInterface) {
            return $client;
        }
        
        // Built-in providers
        if (strpos($assistant_id, 'asst_') === 0) {
            // OpenAI assistant
            $api_key = $settings['openai_api_key'] ?? '';
            if (empty($api_key)) {
                return null;
            }
            return new OpenAIAssistantClientAdapter($api_key);
        }
        
        // Future: Claude support (1.6.2)
        // Note: Claude does NOT have an Assistants API - prompts created in UI are not accessible via API
        // Claude must use Managed Assistants via ChatClientFactory instead
        
        // Gemini Agents API (1.6.5)
        // Gemini has Agents API (different from OpenAI Assistants)
        if (strpos($assistant_id, 'agent_') === 0 || strpos($assistant_id, 'agents/') === 0) {
            $api_key = $settings['gemini_api_key'] ?? '';
            if (empty($api_key)) {
                return null;
            }
            return new \PolyTrans\Providers\Gemini\GeminiAssistantClientAdapter($api_key);
        }
        
        return null;
    }
    
    /**
     * Get provider ID from assistant ID
     * 
     * @param string $assistant_id Assistant ID
     * @return string|null Provider ID or null if unknown format
     */
    public static function get_provider_id($assistant_id)
    {
        // Allow external plugins to provide their own provider ID detection
        $provider_id = apply_filters('polytrans_assistant_client_factory_get_provider_id', null, $assistant_id);
        if ($provider_id !== null) {
            return $provider_id;
        }
        
        // Built-in providers
        if (strpos($assistant_id, 'asst_') === 0) {
            return 'openai';
        }
        // Claude support ready for 1.6.2:
        // if (strpos($assistant_id, 'project_') === 0) {
        //     return 'claude';
        // }
        // Gemini Agents API (1.6.5)
        if (strpos($assistant_id, 'agent_') === 0 || strpos($assistant_id, 'agents/') === 0) {
            return 'gemini';
        }
        if (strpos($assistant_id, 'tuned_model_') === 0) {
            return 'gemini';
        }
        
        return null;
    }
}

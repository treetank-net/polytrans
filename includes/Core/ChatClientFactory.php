<?php

namespace PolyTrans\Core;

use PolyTrans\Providers\ChatClientInterface;
use PolyTrans\Providers\OpenAI\OpenAIChatClientAdapter;

/**
 * Chat Client Factory
 * Creates appropriate chat client based on provider ID
 * Used for managed assistants that use chat API
 */

if (!defined('ABSPATH')) {
    exit;
}

class ChatClientFactory
{
    /**
     * Create chat client for given provider
     * 
     * @param string $provider_id Provider ID (openai, claude, gemini, etc.)
     * @param array $settings Translation settings (for API keys)
     * @return ChatClientInterface|null Client instance or null if not supported
     */
    public static function create($provider_id, $settings)
    {
        // All clients (including OpenAI) are registered via filter
        // This allows full extensibility - OpenAI is treated like any external plugin
        $client = apply_filters('polytrans_chat_client_factory_create', null, $provider_id, $settings);
        if ($client instanceof ChatClientInterface) {
            return $client;
        }
        
        // No built-in providers - everything goes through filters
        // This ensures consistent behavior and makes it easy to add new providers
        return null;
    }
}

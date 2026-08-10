<?php

namespace PolyTrans\Providers;

/**
 * AI Assistant Client Interface
 * Defines the contract for AI assistant API clients (OpenAI, Claude, Gemini, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

interface AIAssistantClientInterface
{
    /**
     * Execute assistant translation
     * 
     * @param string $assistant_id Assistant ID (asst_xxx, project_xxx, etc.)
     * @param array $content Content to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @return array Translation result ['success' => bool, 'translated_content' => array|null, 'error' => string|null]
     */
    public function execute_assistant($assistant_id, $content, $source_lang, $target_lang);
    
    /**
     * Get provider ID
     * 
     * @return string Provider ID (openai, claude, gemini, etc.)
     */
    public function get_provider_id();
    
    /**
     * Check if assistant ID format is supported by this client
     * 
     * @param string $assistant_id Assistant ID to check
     * @return bool True if this client can handle this assistant ID
     */
    public function supports_assistant_id($assistant_id);
}

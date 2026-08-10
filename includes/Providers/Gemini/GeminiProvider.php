<?php

namespace PolyTrans\Providers\Gemini;

use PolyTrans\Providers\TranslationProviderInterface;

/**
 * Gemini Provider
 * Implements TranslationProviderInterface for Google Gemini API
 * Note: Gemini doesn't have direct translation API, so this is mainly for managed assistants and agents
 */

if (!defined('ABSPATH')) {
    exit;
}

class GeminiProvider implements TranslationProviderInterface
{
    public function get_id()
    {
        return 'gemini';
    }
    
    public function get_name()
    {
        return __('Gemini', 'polytrans');
    }
    
    public function get_description()
    {
        return __('AI-powered translation with Google Gemini. Requires Gemini API key and configured assistants or agents.', 'polytrans');
    }
    
    public function get_settings_provider_class()
    {
        return GeminiSettingsProvider::class;
    }
    
    public function has_settings_ui()
    {
        return true;
    }
    
    public function translate($content, $source_lang, $target_lang, $settings)
    {
        // Gemini doesn't have direct translation API
        // Translation is done via managed assistants or agents using chat API
        // This method is kept for interface compliance but won't be used for direct translation
        return [
            'success' => false,
            'error' => 'Gemini does not support direct translation. Please use managed assistants or agents.',
            'error_code' => 'not_supported'
        ];
    }
    
    public function is_configured($settings)
    {
        return !empty($settings['gemini_api_key'] ?? '');
    }
    
    public function get_supported_languages()
    {
        // Gemini supports all languages (via AI translation)
        // Return empty array to indicate all languages are supported
        return [];
    }
}

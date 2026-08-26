<?php

namespace PolyTrans\Providers\Claude;

use PolyTrans\Providers\TranslationProviderInterface;

/**
 * Claude Provider
 * Implements TranslationProviderInterface for Claude API
 * Note: Claude doesn't have direct translation API, so this is mainly for managed assistants
 */

if (!defined('ABSPATH')) {
    exit;
}

class ClaudeProvider implements TranslationProviderInterface
{
    public function get_id()
    {
        return 'claude';
    }
    
    public function get_name()
    {
        return __('Claude', 'treetank-trans');
    }
    
    public function get_description()
    {
        return __('AI-powered translation with Claude. Requires Claude API key and configured assistants.', 'treetank-trans');
    }
    
    public function get_settings_provider_class()
    {
        return ClaudeSettingsProvider::class;
    }
    
    public function has_settings_ui()
    {
        return true;
    }
    
    public function translate($content, $source_lang, $target_lang, $settings)
    {
        // Claude doesn't have direct translation API
        // Translation is done via managed assistants using chat API
        // This method is kept for interface compliance but won't be used for direct translation
        return [
            'success' => false,
            'error' => 'Claude does not support direct translation. Please use managed assistants.',
            'error_code' => 'not_supported'
        ];
    }
    
    public function is_configured($settings)
    {
        return !empty($settings['claude_api_key'] ?? '');
    }
    
    public function get_supported_languages()
    {
        // Claude supports all languages (via AI translation)
        // Return empty array to indicate all languages are supported
        return [];
    }
}

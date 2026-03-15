<?php

namespace PolyTrans\Core;

use PolyTrans\Assistants\AssistantManager;

/**
 * Path Validator
 * Universal validator for translation paths and provider/assistant mappings
 * Validates that all providers and assistants in paths are available and enabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class PathValidator
{
    /**
     * Validate assistant/provider ID
     * 
     * @param string $assistant_id Provider/assistant ID (provider_google, managed_123, asst_xxx)
     * @param array $settings Translation settings
     * @return array ['valid' => bool, 'error' => string|null, 'warnings' => array]
     */
    public static function validate_assistant_id($assistant_id, $settings)
    {
        $warnings = [];
        
        if (empty($assistant_id)) {
            return [
                'valid' => false,
                'error' => 'Empty assistant/provider ID',
                'warnings' => []
            ];
        }
        
        // Check provider_* IDs
        if (strpos($assistant_id, 'provider_') === 0) {
            return self::validate_provider($assistant_id, $settings);
        }
        
        // Check managed_* IDs
        if (strpos($assistant_id, 'managed_') === 0) {
            return self::validate_managed_assistant($assistant_id, $settings);
        }
        
        // Check OpenAI API assistant IDs (asst_xxx)
        if (strpos($assistant_id, 'asst_') === 0) {
            return self::validate_openai_assistant($assistant_id, $settings);
        }
        
        // Unknown format
        return [
            'valid' => false,
            'error' => "Unknown assistant/provider ID format: $assistant_id",
            'warnings' => []
        ];
    }
    
    /**
     * Validate translation provider
     */
    private static function validate_provider($assistant_id, $settings)
    {
        $provider_id = str_replace('provider_', '', $assistant_id);
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];

        if (!in_array($provider_id, $enabled_providers)) {
            return [
                'valid' => false,
                'error' => "Translation provider '$provider_id' is not enabled. Please enable it in Translation Settings.",
                'warnings' => []
            ];
        }
        
        // Check if provider exists in registry
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($provider_id);
        
        if (!$provider) {
            return [
                'valid' => false,
                'error' => "Translation provider '$provider_id' not found in registry",
                'warnings' => []
            ];
        }
        
        // Check if provider is configured
        if (!$provider->is_configured($settings)) {
            return [
                'valid' => false,
                'error' => "Translation provider '$provider_id' is not properly configured",
                'warnings' => []
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'warnings' => []
        ];
    }
    
    /**
     * Validate managed assistant
     */
    private static function validate_managed_assistant($assistant_id, $settings)
    {
        $numeric_id = (int) str_replace('managed_', '', $assistant_id);
        
        if ($numeric_id <= 0) {
            return [
                'valid' => false,
                'error' => "Invalid managed assistant ID: $assistant_id",
                'warnings' => []
            ];
        }
        
        $assistant = AssistantManager::get_assistant($numeric_id);
        
        if (!$assistant) {
            return [
                'valid' => false,
                'error' => "Managed assistant not found (ID: $numeric_id). It may have been deleted.",
                'warnings' => []
            ];
        }
        
        // Check if assistant is active
        if (($assistant['status'] ?? 'active') !== 'active') {
            return [
                'valid' => false,
                'error' => "Managed assistant '{$assistant['name']}' (ID: $numeric_id) is inactive",
                'warnings' => []
            ];
        }
        
        // Check if assistant's provider is enabled and configured
        $assistant_provider = $assistant['provider'] ?? 'openai';
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        
        // Check if provider is enabled
        if (!in_array($assistant_provider, $enabled_providers)) {
            return [
                'valid' => false,
                'error' => "Managed assistant '{$assistant['name']}' uses provider '$assistant_provider', but it is not enabled. Please enable it in Translation Settings.",
                'warnings' => []
            ];
        }
        
        // Get provider manifest to check API key requirements
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($assistant_provider);
        
        if ($provider) {
            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                if (method_exists($settings_provider, 'get_provider_manifest')) {
                    $manifest = $settings_provider->get_provider_manifest($settings);
                    
                    // Check if provider requires API key and if it's configured
                    if (!empty($manifest['api_key_setting'])) {
                        $api_key = $settings[$manifest['api_key_setting']] ?? '';
                        
                        if (empty($api_key)) {
                            $provider_name = $provider->get_name();
                            return [
                                'valid' => false,
                                'error' => "Managed assistant '{$assistant['name']}' uses $provider_name, but API key is not configured. Please configure it in the provider's settings tab.",
                                'warnings' => []
                            ];
                        }
                    }
                }
            }
        }
        
        return [
            'valid' => true,
            'error' => null,
            'warnings' => []
        ];
    }
    
    /**
     * Validate provider API assistant (e.g., OpenAI asst_xxx, Claude project_xxx, etc.)
     * Uses factory to detect provider from assistant ID format
     */
    private static function validate_openai_assistant($assistant_id, $settings)
    {
        // Detect provider from assistant ID using factory
        $provider_id = \PolyTrans\Core\AIAssistantClientFactory::get_provider_id($assistant_id);
        
        if (!$provider_id) {
            return [
                'valid' => false,
                'error' => "Unknown assistant ID format: $assistant_id",
                'warnings' => []
            ];
        }
        
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];

        // Check if provider is enabled
        if (!in_array($provider_id, $enabled_providers)) {
            $registry = \PolyTrans_Provider_Registry::get_instance();
            $provider = $registry->get_provider($provider_id);
            $provider_name = $provider ? $provider->get_name() : $provider_id;
            
            return [
                'valid' => false,
                'error' => "$provider_name assistant '$assistant_id' cannot be used because $provider_name is not enabled. Please enable it in Translation Settings.",
                'warnings' => []
            ];
        }
        
        // Try to create client to check if API key is configured
        $client = \PolyTrans\Core\AIAssistantClientFactory::create($assistant_id, $settings);
        
        if (!$client) {
            $registry = \PolyTrans_Provider_Registry::get_instance();
            $provider = $registry->get_provider($provider_id);
            $provider_name = $provider ? $provider->get_name() : $provider_id;
            
            return [
                'valid' => false,
                'error' => "$provider_name assistant '$assistant_id' cannot be used because API key is not configured. Please configure it in the provider's settings tab.",
                'warnings' => []
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'warnings' => []
        ];
    }
    
    /**
     * Validate all assistants in a mapping
     * 
     * @param array $assistants_mapping Assistant mapping array [pair_key => assistant_id]
     * @param array $settings Translation settings
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function validate_assistants_mapping($assistants_mapping, $settings)
    {
        $errors = [];
        $warnings = [];
        
        if (!is_array($assistants_mapping)) {
            return [
                'valid' => false,
                'errors' => ['Invalid assistants mapping format'],
                'warnings' => []
            ];
        }
        
        foreach ($assistants_mapping as $pair_key => $assistant_id) {
            if (empty($assistant_id)) {
                continue; // Skip empty mappings
            }
            
            $validation = self::validate_assistant_id($assistant_id, $settings);
            
            if (!$validation['valid']) {
                $errors[$pair_key] = $validation['error'];
            }
            
            if (!empty($validation['warnings'])) {
                $warnings[$pair_key] = $validation['warnings'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Validate a translation path
     * Checks if all steps in the path have valid provider/assistant mappings
     * 
     * @param array $path Path array, e.g. ['pl', 'en', 'fr']
     * @param array $assistants_mapping Assistant mapping array
     * @param array $settings Translation settings
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public static function validate_path($path, $assistants_mapping, $settings)
    {
        $errors = [];
        $warnings = [];
        
        if (count($path) < 2) {
            return [
                'valid' => false,
                'errors' => ['Path must have at least 2 language codes'],
                'warnings' => []
            ];
        }
        
        // Validate each step in the path
        for ($i = 0; $i < count($path) - 1; $i++) {
            $step_source = $path[$i];
            $step_target = $path[$i + 1];
            $assistant_key = $step_source . '_to_' . $step_target;
            
            $assistant_id = $assistants_mapping[$assistant_key] ?? null;
            
            if (empty($assistant_id)) {
                $errors[$assistant_key] = "No provider/assistant configured for step $step_source -> $step_target";
                continue;
            }
            
            $validation = self::validate_assistant_id($assistant_id, $settings);
            
            if (!$validation['valid']) {
                $errors[$assistant_key] = $validation['error'];
            }
            
            if (!empty($validation['warnings'])) {
                $warnings[$assistant_key] = $validation['warnings'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}


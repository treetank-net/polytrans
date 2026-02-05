<?php

namespace PolyTrans\Core;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantExecutor;
use PolyTrans\Providers\AIAssistantClientInterface;

/**
 * Translation Path Executor
 * Universal handler for translation paths that respects path rules and provider/assistant mappings
 * Works independently of the default translation provider
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationPathExecutor
{
    /**
     * Execute translation following path rules
     * 
     * @param array $content Content to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param array $settings Translation settings
     * @return array Translation result ['success' => bool, 'translated_content' => array, 'error' => string]
     */
    public static function execute($content, $source_lang, $target_lang, $settings)
    {
        // Use universal names with backward compatibility
        $path_rules = $settings['translation_path_rules'] ?? $settings['openai_path_rules'] ?? [];
        $assistants_mapping = $settings['assistants_mapping'] ?? $settings['openai_assistants'] ?? [];
        
        // Resolve translation path
        $path = self::resolve_path($source_lang, $target_lang, $path_rules);
        
        \PolyTrans_Logs_Manager::log(
            "TranslationPathExecutor: Resolved path: " . implode(' -> ', $path),
            "info"
        );
        
        // Validate entire path before execution (optional - shows all errors at once)
        $path_validation = \PolyTrans\Core\PathValidator::validate_path($path, $assistants_mapping, $settings);
        
        if (!$path_validation['valid'] && !empty($path_validation['errors'])) {
            $error_messages = [];
            foreach ($path_validation['errors'] as $pair => $error) {
                $error_messages[] = "$pair: $error";
            }
            $error_msg = "Path validation failed: " . implode('; ', $error_messages);
            \PolyTrans_Logs_Manager::log($error_msg, "error");
            
            return [
                'success' => false,
                'translated_content' => null,
                'error' => $error_msg
            ];
        }
        
        // Execute translation step by step
        $content_to_translate = $content;
        
        for ($i = 0; $i < count($path) - 1; $i++) {
            $step_source = $path[$i];
            $step_target = $path[$i + 1];
            $assistant_key = $step_source . '_to_' . $step_target;
            
            \PolyTrans_Logs_Manager::log(
                "TranslationPathExecutor: Step $i: $step_source -> $step_target",
                "info"
            );
            
            // Get provider/assistant for this step
            $assistant_id = $assistants_mapping[$assistant_key] ?? null;
            
            if (!$assistant_id || empty($assistant_id)) {
                // Fallback: try to find any available assistant for this direction
                $available_assistants = array_filter($assistants_mapping, function ($assistant) {
                    return !empty($assistant);
                });
                
                if (!empty($available_assistants)) {
                    $assistant_id = reset($available_assistants);
                    $used_direction = array_search($assistant_id, $assistants_mapping);
                    \PolyTrans_Logs_Manager::log(
                        "No specific mapping for $assistant_key, using fallback: $used_direction ($assistant_id)",
                        "info"
                    );
                } else {
                    $error_msg = "No provider/assistant configured for translation step ($step_source -> $step_target). Please configure a provider or assistant for '$assistant_key' in Language Paths.";
                    \PolyTrans_Logs_Manager::log($error_msg, "error");
                    return [
                        'success' => false,
                        'translated_content' => null,
                        'error' => $error_msg
                    ];
                }
            }
            
            // Validate assistant/provider before executing step
            $validation = \PolyTrans\Core\PathValidator::validate_assistant_id($assistant_id, $settings);
            
            if (!$validation['valid']) {
                $error_msg = "Invalid provider/assistant for step $step_source -> $step_target: " . $validation['error'];
                \PolyTrans_Logs_Manager::log($error_msg, "error");
                return [
                    'success' => false,
                    'translated_content' => null,
                    'error' => $error_msg
                ];
            }
            
            // Execute translation step
            $result = self::execute_step($content_to_translate, $step_source, $step_target, $assistant_id, $settings);
            
            if (!$result['success']) {
                \PolyTrans_Logs_Manager::log(
                    "TranslationPathExecutor: Step $i failed ($step_source -> $step_target): " . $result['error'],
                    "error"
                );
                return $result;
            }
            
            $content_to_translate = $result['translated_content'];
            \PolyTrans_Logs_Manager::log(
                "TranslationPathExecutor: Step $i completed ($step_source -> $step_target)",
                "info"
            );
        }
        
        return [
            'success' => true,
            'translated_content' => $content_to_translate,
            'error' => null
        ];
    }
    
    /**
     * Resolve translation path using path rules
     * Returns an array of language codes representing the path
     * 
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param array $path_rules Path rules configuration
     * @return array Path array, e.g. ['pl', 'en', 'fr'] or ['pl', 'fr']
     */
    private static function resolve_path($source_lang, $target_lang, $path_rules)
    {
        // Find the most specific rule, breaking ties by last-in-list
        $best_rule = null;
        $best_score = 0;
        $best_index = -1;
        
        foreach ($path_rules as $idx => $rule) {
            $score = 0;
            
            if ($rule['source'] === $source_lang && $rule['target'] === $target_lang) {
                $score = 3; // exact match
            } elseif (
                ($rule['source'] === $source_lang && $rule['target'] === 'all') ||
                ($rule['source'] === 'all' && $rule['target'] === $target_lang)
            ) {
                $score = 2; // semi-wildcard
            } elseif ($rule['source'] === 'all' && $rule['target'] === 'all') {
                $score = 1; // full wildcard
            }
            
            if ($score > 0 && ($score > $best_score || ($score === $best_score && $idx > $best_index))) {
                $best_rule = $rule;
                $best_score = $score;
                $best_index = $idx;
            }
        }
        
        if ($best_rule) {
            $intermediate = isset($best_rule['intermediate']) ? trim($best_rule['intermediate']) : '';
            
            if ($intermediate === '' || strtolower($intermediate) === 'none' || 
                $intermediate === $source_lang || $intermediate === $target_lang) {
                return [$source_lang, $target_lang];
            } else {
                return [$source_lang, $intermediate, $target_lang];
            }
        }
        
        // No rule found, use direct path
        return [$source_lang, $target_lang];
    }
    
    /**
     * Execute a single translation step
     * 
     * @param array $content Content to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param string $assistant_id Provider/assistant ID (provider_google, managed_123, asst_xxx)
     * @param array $settings Translation settings
     * @return array Translation result
     */
    private static function execute_step($content, $source_lang, $target_lang, $assistant_id, $settings)
    {
        \PolyTrans_Logs_Manager::log(
            "TranslationPathExecutor: Executing step with $assistant_id ($source_lang -> $target_lang)",
            "info"
        );
        
        // Detect type and route accordingly
        if (strpos($assistant_id, 'provider_') === 0) {
            // Translation Provider (e.g., provider_google)
            return self::execute_with_provider($content, $source_lang, $target_lang, $assistant_id, $settings);
                } elseif (strpos($assistant_id, 'managed_') === 0) {
                    // Managed Assistant
                    return self::execute_with_managed_assistant($content, $source_lang, $target_lang, $assistant_id);
                } else {
                    // Provider API Assistant (asst_xxx, project_xxx, etc.)
                    return self::execute_with_provider_assistant($content, $source_lang, $target_lang, $assistant_id, $settings);
                }
    }
    
    /**
     * Execute translation using a translation provider
     */
    private static function execute_with_provider($content, $source_lang, $target_lang, $assistant_id, $settings)
    {
        $provider_id = str_replace('provider_', '', $assistant_id);
        
        \PolyTrans_Logs_Manager::log(
            "TranslationPathExecutor: Using provider: $provider_id",
            "info"
        );
        
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($provider_id);
        
        if (!$provider) {
            return [
                'success' => false,
                'error' => "Translation provider '$provider_id' not found",
                'error_code' => 'provider_not_found'
            ];
        }
        
        if (!$provider->is_configured($settings)) {
            return [
                'success' => false,
                'error' => "Translation provider '$provider_id' is not properly configured",
                'error_code' => 'provider_not_configured'
            ];
        }
        
        return $provider->translate($content, $source_lang, $target_lang, $settings);
    }
    
    /**
     * Execute translation using a managed assistant
     */
    private static function execute_with_managed_assistant($content, $source_lang, $target_lang, $assistant_id)
    {
        $numeric_id = (int) str_replace('managed_', '', $assistant_id);
        
        $assistant = AssistantManager::get_assistant($numeric_id);
        
        if (!$assistant) {
            return [
                'success' => false,
                'error' => "Managed Assistant not found (ID: {$numeric_id})",
                'error_code' => 'assistant_not_found'
            ];
        }
        
        $context = [
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'translated' => $content,
            'original' => $content,
        ];
        
        $executor = new AssistantExecutor();
        $result = $executor->execute($numeric_id, $context);
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message(),
                'error_code' => $result->get_error_code()
            ];
        }
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error from Managed Assistant',
                'error_code' => 'managed_assistant_execution_failed'
            ];
        }
        
        $ai_output = $result['output'] ?? '';
        
        // Parse JSON output if needed
        if (!empty($assistant['expected_output_schema']) && $assistant['expected_format'] === 'json') {
            if (is_string($ai_output)) {
                $decoded = json_decode($ai_output, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ai_output = $decoded;
                }
            }
        }
        
        // If output is already an array, use it directly
        if (is_array($ai_output)) {
            return [
                'success' => true,
                'translated_content' => $ai_output
            ];
        }
        
        // Otherwise, try to parse as JSON
        if (is_string($ai_output)) {
            $decoded = json_decode($ai_output, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return [
                    'success' => true,
                    'translated_content' => $decoded
                ];
            }
        }
        
        return [
            'success' => false,
            'error' => 'Invalid output format from Managed Assistant',
            'error_code' => 'invalid_output_format'
        ];
    }
    
    /**
     * Execute translation using provider API Assistant (OpenAI, Claude, Gemini, etc.)
     */
    private static function execute_with_provider_assistant($content, $source_lang, $target_lang, $assistant_id, $settings)
    {
        // Create appropriate client using factory
        $client = \PolyTrans\Core\AIAssistantClientFactory::create($assistant_id, $settings);
        
        if (!$client) {
            $provider_id = \PolyTrans\Core\AIAssistantClientFactory::get_provider_id($assistant_id);
            $provider_name = $provider_id ? ucfirst($provider_id) : 'Unknown provider';
            
            return [
                'success' => false,
                'translated_content' => null,
                'error' => "$provider_name assistant client could not be created. Please check API key configuration.",
                'error_code' => 'client_creation_failed'
            ];
        }
        
        // Execute using the client interface
        return $client->execute_assistant($assistant_id, $content, $source_lang, $target_lang);
    }
}


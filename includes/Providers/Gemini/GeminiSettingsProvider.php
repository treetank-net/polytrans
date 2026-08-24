<?php

namespace PolyTrans\Providers\Gemini;

use PolyTrans\Providers\SettingsProviderInterface;
use PolyTrans\Providers\ReasoningEffortField;
use PolyTrans\Core\Http\HttpClient;
use PolyTrans\Core\Http\HttpResponse;
use PolyTrans\Core\Diagnostics;

/**
 * Gemini Settings Provider
 * Implements SettingsProviderInterface for Gemini configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class GeminiSettingsProvider implements SettingsProviderInterface
{
    public function get_provider_id()
    {
        return 'gemini';
    }
    
    public function get_tab_label()
    {
        return __('Gemini Configuration', 'polytrans');
    }
    
    public function get_tab_description()
    {
        return __('Configure your Gemini API key and settings for AI-powered translations.', 'polytrans');
    }
    
    public function get_required_js_files()
    {
        return []; // Uses universal JS system via data attributes
    }
    
    public function get_required_css_files()
    {
        return [];
    }
    
    public function get_settings_keys()
    {
        return [
            'gemini_api_key',
            'gemini_model',
            'gemini_reasoning_effort',
        ];
    }
    
    public function render_settings_ui(array $settings, array $languages, array $language_names)
    {
        // Gemini uses universal UI - no custom implementation needed!
        // Universal UI will automatically render:
        // - API Key field (from manifest: api_key_setting)
        // - Model selection (from manifest: capabilities include 'chat')
        return;
    }
    
    public function validate_settings(array $posted_data)
    {
        $validated = [];
        
        if (isset($posted_data['gemini_api_key'])) {
            $validated['gemini_api_key'] = sanitize_text_field($posted_data['gemini_api_key']);
        }
        
        if (isset($posted_data['gemini_model'])) {
            $model = sanitize_text_field($posted_data['gemini_model']);
            if (empty($model)) {
                $validated['gemini_model'] = '';
            } else {
                $allowed_models = $this->get_all_available_models();
                if (in_array($model, $allowed_models)) {
                    $validated['gemini_model'] = $model;
                } else {
                    // Invalid model - set to empty (user must select valid model)
                    $validated['gemini_model'] = '';
                }
            }
        }

        if (isset($posted_data['gemini_reasoning_effort'])) {
            $validated['gemini_reasoning_effort'] = ReasoningEffortField::sanitize(
                'gemini',
                $validated['gemini_model'] ?? ($posted_data['gemini_model'] ?? ''),
                $posted_data['gemini_reasoning_effort']
            );
        }

        return $validated;
    }

    public function get_default_settings()
    {
        return [
            'gemini_api_key' => '',
            'gemini_model' => '', // None selected by default
            'gemini_reasoning_effort' => '', // Defer to the provider's own default
        ];
    }
    
    public function is_configured(array $settings)
    {
        return !empty($settings['gemini_api_key'] ?? '');
    }
    
    public function get_configuration_status(array $settings)
    {
        if (!$this->is_configured($settings)) {
            return 'Gemini API key not configured';
        }
        return '';
    }
    
    public function enqueue_assets()
    {
        // Uses universal JS system - no custom assets needed
    }
    
    public function get_ajax_handlers()
    {
        return []; // Uses universal endpoints
    }
    
    public function register_ajax_handlers()
    {
        // Uses universal endpoints - no custom handlers needed
    }
    
    public function get_provider_manifest(array $settings)
    {
        $api_key = $settings['gemini_api_key'] ?? '';
        
        return [
            'provider_id' => 'gemini',
            'capabilities' => ['assistants', 'chat', 'system_prompt'], // Gemini has Agents API, chat API with system prompt support
            'assistants_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/agents', // Agents API endpoint
            'chat_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'models_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'auth_type' => 'api_key',
            'auth_header' => 'x-goog-api-key',
            'api_key_setting' => 'gemini_api_key',
            'api_key_configured' => !empty($api_key) && current_user_can('manage_options'),
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ];
    }
    
    public function validate_api_key(string $api_key): bool
    {
        if (empty($api_key)) {
            return false;
        }
        
        // Validate by calling /models endpoint
        try {
            $client = new HttpClient('https://generativelanguage.googleapis.com/v1beta', 10);
            $url = '/models?key=' . urlencode($api_key);
            $response = $client->get($url);
            
            if ($response->is_error()) {
                Diagnostics::log('Gemini API validation error: ' . $response->get_error_message());
                return false;
            }
            
            // 200 = valid key and successful request
            if ($response->get_status_code() === 200) {
                $data = $response->get_json(true);
                
                // Check if we have models data
                if (isset($data['models']) && is_array($data['models']) && !empty($data['models'])) {
                    return true;
                }
                
                // Empty models list might still mean valid key
                return true;
            }
            
            // Log for debugging
            Diagnostics::log('Gemini API validation failed. Status: ' . $response->get_status_code());
            
            return false;
        } catch (\Exception $e) {
            Diagnostics::log('Gemini API validation exception: ' . $e->getMessage());
            return false;
        }
    }

    public function load_assistants(array $settings): array
    {
        $api_key = $settings['gemini_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        $assistants = [];
        
        // Load Gemini Agents from Agents API
        try {
            $client = new HttpClient('https://generativelanguage.googleapis.com/v1beta', 10);
            $url = '/agents?key=' . urlencode($api_key);
            $response = $client->get($url);
            
            if ($response->is_error()) {
                Diagnostics::log('Failed to fetch Gemini agents: ' . $response->get_error_message());
                return [];
            }
            
            if ($response->get_status_code() === 200) {
                $data = $response->get_json(true);
                
                // Gemini Agents API returns agents in 'agents' array
                if (isset($data['agents']) && is_array($data['agents'])) {
                    foreach ($data['agents'] as $agent) {
                        $agent_id = $agent['name'] ?? $agent['id'] ?? '';
                        $agent_name = $agent['displayName'] ?? $agent['name'] ?? 'Unnamed Agent';
                        $description = $agent['description'] ?? '';
                        
                        if (empty($agent_id)) {
                            continue;
                        }
                        
                        // Extract agent ID from full name (e.g., "agents/123456" -> "agent_123456")
                        if (strpos($agent_id, 'agents/') === 0) {
                            $agent_id = 'agent_' . substr($agent_id, 7);
                        }
                        
                        $assistants[] = [
                            'id' => $agent_id,
                            'name' => $agent_name,
                            'description' => $description,
                            'model' => $agent['model'] ?? 'N/A',
                            'provider' => 'gemini',
                        ];
                    }
                }
            } else {
                Diagnostics::log('Gemini agents API returned status ' . $status_code);
            }
        } catch (\Exception $e) {
            Diagnostics::log('Exception fetching Gemini agents: ' . $e->getMessage());
        }
        
        return $assistants;
    }
    
    public function load_models(array $settings): array
    {
        $api_key = $settings['gemini_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        // Check cache first (1 hour cache)
        $cache_key = 'polytrans_gemini_models_' . md5($api_key);
        $cached_models = get_transient($cache_key);
        if ($cached_models !== false && is_array($cached_models)) {
            return $cached_models;
        }
        
        // Fetch models from Gemini API /models endpoint
        try {
            $client = new HttpClient('https://generativelanguage.googleapis.com/v1beta', 10);
            $url = '/models?key=' . urlencode($api_key);
            $response = $client->get($url);
            
            if (is_wp_error($response)) {
                Diagnostics::log('Failed to fetch Gemini models: ' . $response->get_error_message());
                return [];
            }

            if ($response->get_status_code() !== 200) {
                Diagnostics::log('Gemini models API returned status ' . $response->get_status_code());
                return [];
            }

            $data = $response->get_json(true);

            if (!isset($data['models']) || !is_array($data['models'])) {
                Diagnostics::log('Gemini models API response missing data.');
                return [];
            }
            
            // Group models by family
            $grouped = [];

            // Capability hints reported by the API (temperature window, thinking support)
            $api_metadata = [];

            foreach ($data['models'] as $model) {
                $model_id = $model['name'] ?? '';
                
                if (empty($model_id)) {
                    continue;
                }
                
                // Extract model ID from full name (e.g., "models/gemini-pro" -> "gemini-pro")
                if (strpos($model_id, 'models/') === 0) {
                    $model_id = substr($model_id, 7);
                }
                
                // Only include Gemini models (filter out other models)
                if (strpos($model_id, 'gemini') === false) {
                    continue;
                }
                
                // Filter out image/video generation models - only include models that support generateContent
                // Gemini API returns supportedGenerationMethods array (e.g., ['generateContent', 'generateImage'])
                // Note: Gemini uses camelCase, not UPPER_SNAKE_CASE like some other APIs
                // We only want models that support generateContent (text/chat)
                $supported_methods = $model['supportedGenerationMethods'] ?? [];
                
                // Check for generateContent (camelCase) - Gemini API format
                $has_generate_content = false;
                if (is_array($supported_methods)) {
                    // Check both camelCase (Gemini) and UPPER_SNAKE_CASE (for compatibility)
                    $has_generate_content = in_array('generateContent', $supported_methods) || 
                                           in_array('GENERATE_CONTENT', $supported_methods);
                }
                
                if (!$has_generate_content) {
                    // Skip models that don't support text generation (image/video only models)
                    continue;
                }
                
                // Additional filter: exclude known image/video model patterns
                // Nano Banana, Nano Banana Pro, and similar are image generation models
                if (strpos($model_id, 'nano-banana') !== false || 
                    strpos($model_id, 'image') !== false || 
                    strpos($model_id, 'video') !== false ||
                    strpos($model_id, 'imagen') !== false) {
                    continue;
                }
                
                // Determine group based on model ID
                $group = $this->get_model_group($model_id);
                if (empty($group)) {
                    continue;
                }
                
                if (!isset($grouped[$group])) {
                    $grouped[$group] = [];
                }
                
                $display_name = $model['displayName'] ?? $model_id;
                $label = $this->get_model_label($model_id, $display_name);
                $grouped[$group][$model_id] = $label;

                // Gemini's ListModels exposes the supported temperature window and,
                // for newer models, whether the model thinks at all.
                $model_meta = [];
                foreach (['temperature', 'maxTemperature', 'topP', 'topK', 'thinking'] as $meta_key) {
                    if (array_key_exists($meta_key, $model)) {
                        $model_meta[$meta_key] = $model[$meta_key];
                    }
                }
                if (!empty($model_meta)) {
                    $api_metadata[strtolower($model_id)] = $model_meta;
                }
            }

            // Sort models within each group
            foreach ($grouped as $group => &$models) {
                ksort($models);
            }
            unset($models);

            if (!empty($api_metadata)) {
                \PolyTrans\Core\ModelCapabilities::store_api_metadata('gemini', $api_metadata);
            }

            // Cache models for 1 hour
            if (!empty($grouped)) {
                set_transient($cache_key, $grouped, HOUR_IN_SECONDS);
                return $grouped;
            }
            
            Diagnostics::log('Gemini models API returned no valid models after filtering.');
            return [];
        } catch (\Exception $e) {
            Diagnostics::log('Exception fetching Gemini models: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get model group name based on model ID
     * 
     * @param string $model_id Model ID (e.g., 'gemini-pro', 'gemini-1.5-pro')
     * @return string Group name or empty string
     */
    private function get_model_group($model_id)
    {
        // Gemini 2.x family
        if (strpos($model_id, 'gemini-2') !== false) {
            return 'Gemini 2 Models';
        }
        
        // Gemini 1.5 family
        if (strpos($model_id, 'gemini-1.5') !== false) {
            return 'Gemini 1.5 Models';
        }
        
        // Gemini 1.0 family (Pro, Ultra)
        if (strpos($model_id, 'gemini-pro') !== false || strpos($model_id, 'gemini-ultra') !== false) {
            return 'Gemini 1.0 Models';
        }
        
        // Generic Gemini fallback
        if (strpos($model_id, 'gemini') !== false) {
            return 'Gemini Models';
        }
        
        return '';
    }
    
    /**
     * Get model label for display
     * 
     * @param string $model_id Model ID
     * @param string $display_name Display name from API (optional)
     * @return string Label
     */
    private function get_model_label($model_id, $display_name = '')
    {
        // Use display name if available
        if (!empty($display_name)) {
            return $display_name;
        }
        
        // Generate label from model ID
        $label = str_replace('gemini-', 'Gemini ', $model_id);
        $label = str_replace('-', ' ', $label);
        $label = ucwords($label);
        
        return $label;
    }
    
    /**
     * Get all available models (for validation)
     * 
     * @return array Array of model IDs
     */
    private function get_all_available_models()
    {
        $settings = get_option('polytrans_settings', []);
        $grouped_models = $this->load_models($settings);
        
        $all_models = [];
        foreach ($grouped_models as $group => $models) {
            $all_models = array_merge($all_models, array_keys($models));
        }
        
        return $all_models;
    }
}

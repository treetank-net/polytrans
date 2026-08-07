<?php

namespace PolyTrans\Providers\Claude;

use PolyTrans\Providers\SettingsProviderInterface;
use PolyTrans\Providers\ReasoningEffortField;
use PolyTrans\Core\Http\HttpClient;
use PolyTrans\Core\Http\HttpResponse;

/**
 * Claude Settings Provider
 * Implements SettingsProviderInterface for Claude configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class ClaudeSettingsProvider implements SettingsProviderInterface
{
    public function get_provider_id()
    {
        return 'claude';
    }
    
    public function get_tab_label()
    {
        return __('Claude Configuration', 'polytrans');
    }
    
    public function get_tab_description()
    {
        return __('Configure your Claude API key and settings for AI-powered translations.', 'polytrans');
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
            'claude_api_key',
            'claude_model',
            'claude_reasoning_effort',
        ];
    }
    
    public function render_settings_ui(array $settings, array $languages, array $language_names)
    {
        // Claude uses universal UI - no custom implementation needed!
        // Universal UI will automatically render:
        // - API Key field (from manifest: api_key_setting)
        // - Model selection (from manifest: capabilities include 'chat')
        return;
    }
    
    public function validate_settings(array $posted_data)
    {
        $validated = [];
        
        if (isset($posted_data['claude_api_key'])) {
            $validated['claude_api_key'] = sanitize_text_field($posted_data['claude_api_key']);
        }
        
        if (isset($posted_data['claude_model'])) {
            $validated['claude_model'] = sanitize_text_field($posted_data['claude_model']);
        }

        if (isset($posted_data['claude_reasoning_effort'])) {
            $validated['claude_reasoning_effort'] = ReasoningEffortField::sanitize(
                'claude',
                $validated['claude_model'] ?? ($posted_data['claude_model'] ?? ''),
                $posted_data['claude_reasoning_effort']
            );
        }

        return $validated;
    }

    public function get_default_settings()
    {
        return [
            'claude_api_key' => '',
            'claude_model' => '', // None selected by default
            'claude_reasoning_effort' => '', // Defer to the provider's own default
        ];
    }
    
    public function is_configured(array $settings)
    {
        return !empty($settings['claude_api_key'] ?? '');
    }
    
    public function get_configuration_status(array $settings)
    {
        if (!$this->is_configured($settings)) {
            return 'Claude API key not configured';
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
        $api_key = $settings['claude_api_key'] ?? '';
        
        return [
            'provider_id' => 'claude',
            'capabilities' => ['chat', 'system_prompt'], // Claude has chat API with system prompt support, but NO Assistants API - use managed assistants
            'chat_endpoint' => 'https://api.anthropic.com/v1/messages',
            'models_endpoint' => 'https://api.anthropic.com/v1/models',
            'auth_type' => 'api_key',
            'auth_header' => 'x-api-key',
            'api_key_setting' => 'claude_api_key',
            'api_key_configured' => !empty($api_key) && current_user_can('manage_options'),
            'base_url' => 'https://api.anthropic.com/v1',
        ];
    }
    
    public function validate_api_key(string $api_key): bool
    {
        if (empty($api_key)) {
            return false;
        }
        
        // Validate by calling /models endpoint (similar to OpenAI)
        // This is more efficient than making a /messages request
        try {
            $client = new HttpClient('https://api.anthropic.com/v1', 10);
            $client->set_api_key($api_key, 'x-api-key')
                   ->set_header('anthropic-version', '2023-06-01');
            
            $response = $client->get('/models');
            
            if ($response->is_error()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans Claude API validation error: ' . $response->get_error_message());
                return false;
            }
            
            // 200 = valid key and successful request
            // 401 = invalid/unauthorized key
            // 403 = forbidden (key valid but no access)
            
            if ($response->get_status_code() === 200) {
                // Verify we got actual models data
                $data = $response->get_json(true);
                
                // Check if we have models data
                if (isset($data['data']) && is_array($data['data']) && !empty($data['data'])) {
                    return true;
                }
                
                // Empty models list might still mean valid key
                return true;
            }
            
            // Log for debugging
            $response_body = wp_remote_retrieve_body($response);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log('PolyTrans Claude API validation failed. Status: ' . $status_code . ', Response: ' . substr($response_body, 0, 200));
            
            return false;
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log('PolyTrans Claude API validation exception: ' . $e->getMessage());
            return false;
        }
    }

    public function load_assistants(array $settings): array
    {
        $api_key = $settings['claude_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        $assistants = [];
        
        // Try different possible endpoints for Claude Prompts
        // Prompts are stored in organization and can have system/user prompts
        $possible_endpoints = [
            'https://api.anthropic.com/admin/v1/prompts',  // Admin API
            'https://api.anthropic.com/v1/organizations/prompts',  // Organization-specific
            'https://api.anthropic.com/v1/prompts',  // Standard API (already tried, returns 404)
        ];
        
        foreach ($possible_endpoints as $endpoint_url) {
            try {
                $response = wp_remote_get($endpoint_url, [
                    'headers' => [
                        'x-api-key' => $api_key,
                        'anthropic-version' => '2023-06-01',
                    ],
                    'timeout' => 10,
                ]);
                
                if (!is_wp_error($response)) {
                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($response->get_status_code() === 200) {
                        $data = $response->get_json(true);
                        
                        // Log response for debugging
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                        error_log('PolyTrans: Claude prompts API (' . $endpoint_url . ') response received');
                        
                        // Claude Prompts API might return prompts in 'data' array or directly
                        $prompts = $data['data'] ?? $data['prompts'] ?? (is_array($data) && !isset($data['error']) ? $data : []);
                        
                        if (is_array($prompts)) {
                            foreach ($prompts as $prompt) {
                                $prompt_id = $prompt['id'] ?? $prompt['prompt_id'] ?? '';
                                $prompt_name = $prompt['name'] ?? $prompt['title'] ?? 'Unnamed Prompt';
                                $description = $prompt['description'] ?? '';
                                
                                if (empty($prompt_id)) {
                                    continue;
                                }
                                
                                $assistants[] = [
                                    'id' => $prompt_id, // Claude Prompts use prompt IDs
                                    'name' => $prompt_name,
                                    'description' => $description,
                                    'model' => $prompt['model'] ?? 'N/A',
                                    'provider' => 'claude',
                                ];
                            }
                            
                            if (!empty($assistants)) {
                                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                                error_log('PolyTrans: Loaded ' . count($assistants) . ' Claude prompts from ' . $endpoint_url);
                                return $assistants;
                            }
                        }
                    } else if ($response->get_status_code() !== 404) {
                        // Log non-404 errors (404 means endpoint doesn't exist, which is expected for some endpoints)
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                        error_log('PolyTrans: Claude prompts API (' . $endpoint_url . ') returned status ' . $response->get_status_code());
                    }
                } else {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                    error_log('PolyTrans: Failed to fetch Claude prompts from ' . $endpoint_url . ': ' . $response->get_error_message());
                }
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Exception fetching Claude prompts from ' . $endpoint_url . ': ' . $e->getMessage());
            }
        }

        // If no prompts found, log that we tried all endpoints
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
        error_log('PolyTrans: No Claude prompts found. Tried endpoints: ' . implode(', ', $possible_endpoints));
        
        // Fallback: Try Claude Projects API (older approach, might be deprecated)
        try {
            $response = $client->get('https://api.anthropic.com/v1/projects');
            
            if ($response->is_error()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Failed to fetch Claude projects: ' . $response->get_error_message());
                return $assistants; // Return whatever we got from prompts
            }
            
            if ($response->get_status_code() === 200) {
                $data = $response->get_json(true);
                
                // Claude Projects API returns projects in 'data' array
                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $project) {
                        $project_id = $project['id'] ?? '';
                        $project_name = $project['name'] ?? 'Unnamed Project';
                        $description = $project['description'] ?? '';
                        
                        if (empty($project_id)) {
                            continue;
                        }
                        
                        $assistants[] = [
                            'id' => $project_id, // Claude Projects use project IDs (e.g., 'proj_xxx')
                            'name' => $project_name,
                            'description' => $description,
                            'model' => $project['model'] ?? 'N/A',
                            'provider' => 'claude',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log('PolyTrans: Exception fetching Claude projects: ' . $e->getMessage());
        }

        return $assistants;
    }
    
    public function load_models(array $settings): array
    {
        $api_key = $settings['claude_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        // Check cache first (1 hour cache)
        $cache_key = 'polytrans_claude_models_' . md5($api_key);
        $cached_models = get_transient($cache_key);
        if ($cached_models !== false && is_array($cached_models)) {
            return $cached_models;
        }
        
        // Fetch models from Claude API /models endpoint
        try {
            $client = new HttpClient('https://api.anthropic.com/v1', 10);
            $client->set_api_key($api_key, 'x-api-key')
                   ->set_header('anthropic-version', '2023-06-01');
            
            $response = $client->get('/models');
            
            if ($response->is_error()) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Failed to fetch Claude models: ' . $response->get_error_message());
                return [];
            }

            if ($response->get_status_code() !== 200) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Claude models API returned status ' . $response->get_status_code());
                return [];
            }

            $data = $response->get_json(true);

            if (!isset($data['data']) || !is_array($data['data'])) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                error_log('PolyTrans: Claude models API response missing data.');
                return [];
            }
            
            // Group models by family (similar to OpenAI approach)
            $grouped = [];

            // Capability hints reported by the API (effort levels, thinking modes)
            $api_metadata = [];
            $one_year_ago = new \DateTime();
            $one_year_ago->modify('-1 year');
            
            $models_processed = 0;
            $models_filtered_out = 0;
            
            foreach ($data['data'] as $model) {
                $model_id = $model['id'] ?? '';
                $created_at = $model['created_at'] ?? null;
                
                if (empty($model_id)) {
                    continue;
                }
                
                $models_processed++;

                $model_meta = $this->extract_capability_metadata($model);
                if (!empty($model_meta)) {
                    $api_metadata[strtolower($model_id)] = $model_meta;
                }

                // Only include Claude 3.0+ models (filter out Claude 2.x)
                // Accept: claude-3-*, claude-opus-*, claude-sonnet-*, claude-haiku-*
                if (strpos($model_id, 'claude-3') === false && 
                    strpos($model_id, 'claude-opus') === false && 
                    strpos($model_id, 'claude-sonnet') === false && 
                    strpos($model_id, 'claude-haiku') === false) {
                    $models_filtered_out++;
                    continue;
                }
                
                // Filter out models older than 1 year
                if ($created_at) {
                    try {
                        // Handle ISO 8601 datetime string (e.g., "2024-10-22T12:00:00Z")
                        if (is_string($created_at)) {
                            $created_date = new \DateTime($created_at);
                        } elseif (is_numeric($created_at)) {
                            // Unix timestamp
                            $created_date = new \DateTime();
                            $created_date->setTimestamp($created_at);
                        } else {
                            $created_date = null;
                        }
                        
                        if ($created_date && $created_date < $one_year_ago) {
                            $models_filtered_out++;
                            continue;
                        }
                    } catch (\Exception $e) {
                        // If date parsing fails, include the model anyway
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                        error_log('PolyTrans: Failed to parse Claude model created_at: ' . $e->getMessage());
                    }
                }
                
                // Determine group based on model ID
                $group = $this->get_model_group($model_id);
                if (empty($group)) {
                    $models_filtered_out++;
                    continue;
                }
                
                if (!isset($grouped[$group])) {
                    $grouped[$group] = [];
                }
                
                $display_name = $model['display_name'] ?? $model_id;
                $label = $this->get_model_label($model_id, $display_name);
                $grouped[$group][$model_id] = $label;
            }
            
            // Log for debugging
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log(sprintf(
                'PolyTrans: Claude models API - Processed: %d, Filtered out: %d, Included: %d',
                $models_processed,
                $models_filtered_out,
                count($grouped, COUNT_RECURSIVE) - count($grouped)
            ));
            
            // Sort models within each group (newest first, by model ID)
            foreach ($grouped as $group => &$models) {
                krsort($models);
            }
            unset($models);

            if (!empty($api_metadata)) {
                \PolyTrans\Core\ModelCapabilities::store_api_metadata('claude', $api_metadata);
            }

            // Cache models for 1 hour
            if (!empty($grouped)) {
                set_transient($cache_key, $grouped, HOUR_IN_SECONDS);
                return $grouped;
            }
            
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log('PolyTrans: Claude models API returned no valid models after filtering.');
            return [];
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log('PolyTrans: Exception fetching Claude models: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Translate an Anthropic /v1/models entry into ModelCapabilities metadata.
     *
     * The API reports capabilities in a machine readable form, e.g.
     *
     *     "capabilities": {
     *         "effort": {"supported": true, "low": {"supported": true}, ...},
     *         "thinking": {"supported": true, "types": {
     *             "enabled": {"supported": false}, "adaptive": {"supported": true}
     *         }}
     *     }
     *
     * @param array $model Raw model entry.
     * @return array Metadata in the ModelCapabilities::store_api_metadata() schema.
     */
    private function extract_capability_metadata(array $model)
    {
        $capabilities = $model['capabilities'] ?? null;

        if (!is_array($capabilities)) {
            return [];
        }

        $meta = [];

        if (isset($capabilities['effort']) && is_array($capabilities['effort'])) {
            $effort = $capabilities['effort'];
            $supported = !empty($effort['supported']);

            $levels = [];
            if ($supported) {
                // Levels are nested objects keyed by the value the API expects.
                foreach ($effort as $level => $flag) {
                    if ($level === 'supported' || !is_array($flag)) {
                        continue;
                    }
                    if (!empty($flag['supported'])) {
                        $levels[] = $level;
                    }
                }
            }

            $meta['effort'] = [
                'supported' => $supported && !empty($levels),
                'levels' => $levels,
                'param' => 'output_config.effort',
            ];
        }

        if (isset($capabilities['thinking']) && is_array($capabilities['thinking'])) {
            $thinking = $capabilities['thinking'];

            if (empty($thinking['supported'])) {
                $meta['thinking'] = false;
            } else {
                $types = isset($thinking['types']) && is_array($thinking['types']) ? $thinking['types'] : [];
                $meta['thinking'] = [
                    'adaptive' => !empty($types['adaptive']['supported']),
                    'enabled' => !empty($types['enabled']['supported']),
                ];
            }
        }

        if (isset($model['max_tokens']) && is_numeric($model['max_tokens'])) {
            $meta['max_tokens'] = (int) $model['max_tokens'];
        }

        return $meta;
    }

    /**
     * Get model group name based on model ID
     *
     * @param string $model_id Model ID (e.g., 'claude-3-5-sonnet-20241022', 'claude-opus-4.1')
     * @return string Group name or empty string
     */
    private function get_model_group($model_id)
    {
        // Claude 3.5 family (Sonnet 3.5, Haiku 3.5)
        if (strpos($model_id, 'claude-3-5') !== false) {
            return 'Claude 3.5 Models';
        }
        
        // Claude 4.x family (Opus 4.1, Sonnet 4, Haiku 4.5)
        if (strpos($model_id, 'claude-opus-4') !== false || strpos($model_id, 'claude-opus-4.1') !== false) {
            return 'Claude Opus 4 Models';
        }
        if (strpos($model_id, 'claude-sonnet-4') !== false) {
            return 'Claude Sonnet 4 Models';
        }
        if (strpos($model_id, 'claude-haiku-4') !== false || strpos($model_id, 'claude-haiku-4.5') !== false) {
            return 'Claude Haiku 4 Models';
        }
        
        // Claude 3.x family (Opus, Sonnet, Haiku)
        if (strpos($model_id, 'claude-3-opus') !== false) {
            return 'Claude 3 Opus Models';
        }
        if (strpos($model_id, 'claude-3-sonnet') !== false) {
            return 'Claude 3 Sonnet Models';
        }
        if (strpos($model_id, 'claude-3-haiku') !== false) {
            return 'Claude 3 Haiku Models';
        }
        
        // Generic Claude 3 fallback (shouldn't happen with proper filtering)
        if (strpos($model_id, 'claude-3') !== false) {
            return 'Claude 3 Models';
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
        $label = str_replace('claude-', 'Claude ', $model_id);
        $label = str_replace('-', ' ', $label);
        $label = ucwords($label);
        
        // Add "Latest" suffix for newest models
        if (strpos($model_id, 'claude-3-5-sonnet') !== false && strpos($model_id, '20241022') !== false) {
            $label .= ' (Latest)';
        }
        
        return $label;
    }
    
}


<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Providers\SettingsProviderInterface;
use PolyTrans\Providers\ProviderRegistry;
use PolyTrans\Assistants\AssistantManager;

/**
 * OpenAI Settings Provider
 * Handles settings UI and management for the OpenAI translation provider
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAISettingsProvider implements SettingsProviderInterface
{
    /**
     * Get the settings provider identifier
     */
    public function get_provider_id()
    {
        return 'openai';
    }

    /**
     * Get the tab label for this provider's settings
     */
    public function get_tab_label()
    {
        return __('OpenAI Configuration', 'polytrans');
    }

    /**
     * Get the tab description
     */
    public function get_tab_description()
    {
        return __('Configure your OpenAI API key and translation assistants for AI-powered translations.', 'polytrans');
    }

    /**
     * Get required JavaScript files
     */
    public function get_required_js_files()
    {
        return [
            'assets/js/translator/openai-integration.js'
        ];
    }

    /**
     * Get required CSS files
     */
    public function get_required_css_files()
    {
        return [
            'assets/css/translator/openai-integration.css'
        ];
    }

    /**
     * Get provider-specific settings keys
     */
    public function get_settings_keys()
    {
        return [
            'openai_api_key',
            'openai_source_language',
            'openai_assistants',
            'openai_model'
        ];
    }

    /**
     * Render the provider settings UI
     */
    public function render_settings_ui(array $settings, array $languages, array $language_names)
    {
        $openai_api_key = $settings['openai_api_key'] ?? '';
        $openai_source_language = $settings['openai_source_language'] ?? ($languages[0] ?? 'en');
        $openai_assistants = $settings['openai_assistants'] ?? [];
        $openai_path_rules = $settings['openai_path_rules'] ?? [
            [
                'source' => 'all',
                'target' => 'all',
                'intermediate' => 'en',
            ]
        ];
        $openai_model = $settings['openai_model'] ?? '';

        // Add language data for JS via enqueued script
        $lang_name_map = [];
        foreach ($languages as $i => $lang) {
            $lang_name_map[$lang] = $language_names[$i] ?? strtoupper($lang);
        }
        wp_add_inline_script(
            'polytrans-settings',
            'window.POLYTRANS_LANGS = ' . wp_json_encode(array_values($languages)) . ';' .
            'window.POLYTRANS_LANG_NAMES = ' . wp_json_encode($lang_name_map) . ';',
            'before'
        );
?>
        <div class="openai-config-section universal-provider-config-section" data-provider-id="openai">
            <h2><?php echo esc_html($this->get_tab_label()); ?></h2>
            <p><?php echo esc_html($this->get_tab_description()); ?></p>

            <!-- API Key Section -->
            <div class="openai-api-key-section">
                <h3><?php esc_html_e('API Key', 'polytrans'); ?></h3>
                <div style="display:flex;gap:0.5em;align-items:center;max-width:600px;">
                    <input type="password"
                        id="openai-api-key"
                        name="openai_api_key"
                        data-provider="openai"
                        data-field="api-key"
                        value="<?php echo esc_attr($openai_api_key); ?>"
                        style="width:100%"
                        placeholder="sk-..."
                        autocomplete="off" />
                    <button type="button" data-provider="openai" data-action="validate-key" id="validate-openai-key" class="button"><?php esc_html_e('Validate', 'polytrans'); ?></button>
                    <button type="button" data-provider="openai" data-action="toggle-visibility" id="toggle-openai-key-visibility" class="button">👁</button>
                </div>
                <div data-provider="openai" data-field="validation-message" id="openai-validation-message" style="margin-top:0.5em;"></div>
                <small><?php esc_html_e('Enter your OpenAI API key. It will be validated before saving.', 'polytrans'); ?></small>
            </div>

            <!-- Model Selection Section -->
            <div class="openai-model-section" style="margin-top:2em;">
                <h3><?php esc_html_e('Default Model', 'polytrans'); ?></h3>
                <?php $this->render_model_selection($openai_model); ?>
                <div data-provider="openai" data-field="model-message" id="openai-model-message" style="margin-top:0.5em;"></div>
                <br><small><?php esc_html_e('Default OpenAI model to use for translations and AI Assistant steps. This can be overridden per workflow step.', 'polytrans'); ?></small>
            </div>

            <div style="margin-top:2em; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                <p style="margin: 0;">
                    <strong><?php esc_html_e('Note:', 'polytrans'); ?></strong>
                    <?php esc_html_e('Assistant Mapping and Translation Path Rules have been moved to the', 'polytrans'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=polytrans-settings#language-paths-settings')); ?>">
                        <?php esc_html_e('Language Paths', 'polytrans'); ?>
                    </a>
                    <?php esc_html_e('tab for better organization.', 'polytrans'); ?>
                </p>
            </div>
        </div>

    <?php
        // Add inline CSS via enqueued style
        wp_add_inline_style(
            'polytrans-settings',
            '.language-pair-group h4 { margin-top: 0; margin-bottom: 0.5em; color: #333; } ' .
            '.openai-assistant-input { font-family: monospace; font-size: 12px; }'
        );
    }

    /**
     * Validate and sanitize provider-specific settings
     */
    public function validate_settings(array $posted_data)
    {
        $validated = [];

        // Validate API key
        if (isset($posted_data['openai_api_key'])) {
            $validated['openai_api_key'] = sanitize_text_field($posted_data['openai_api_key']);
        }

        // Validate source language
        if (isset($posted_data['openai_source_language'])) {
            $validated['openai_source_language'] = sanitize_text_field($posted_data['openai_source_language']);
        }

        // Validate assistants
        if (isset($posted_data['openai_assistants']) && is_array($posted_data['openai_assistants'])) {
            $validated['openai_assistants'] = [];
            foreach ($posted_data['openai_assistants'] as $pair => $assistant_id) {
                $pair = sanitize_text_field($pair);
                $assistant_id = sanitize_text_field($assistant_id);

                // Only save non-empty assistant IDs
                if (!empty($assistant_id)) {
                    $validated['openai_assistants'][$pair] = $assistant_id;
                }
            }
        }

        // Validate translation path rules
        if (isset($posted_data['openai_path_rules']) && is_array($posted_data['openai_path_rules'])) {
            $validated['openai_path_rules'] = [];
            foreach ($posted_data['openai_path_rules'] as $rule) {
                if (!is_array($rule)) continue;
                $source = isset($rule['source']) ? sanitize_text_field($rule['source']) : 'all';
                $target = isset($rule['target']) ? sanitize_text_field($rule['target']) : 'all';
                $intermediate = isset($rule['intermediate']) ? sanitize_text_field($rule['intermediate']) : 'none';
                // Only save rules with at least source and target
                if ($source !== '' && $target !== '') {
                    $validated['openai_path_rules'][] = [
                        'source' => $source,
                        'target' => $target,
                        'intermediate' => $intermediate,
                    ];
                }
            }
        }

        // Validate model selection
        if (isset($posted_data['openai_model'])) {
            $model = sanitize_text_field($posted_data['openai_model']);
            // Allow empty model (None selected) - will use default from get_default_settings()
            if (empty($model)) {
                $validated['openai_model'] = '';
            } else {
                $allowed_models = $this->get_all_available_models();
                if (in_array($model, $allowed_models)) {
                    $validated['openai_model'] = $model;
                } else {
                    // Invalid model - set to empty (user must select valid model)
                    $validated['openai_model'] = '';
                }
            }
        }

        return $validated;
    }

    /**
     * Get default settings
     */
    public function get_default_settings()
    {
        return [
            'openai_api_key' => '',
            'openai_source_language' => 'en',
            'openai_assistants' => [],
            'openai_model' => '', // None selected by default
        ];
    }

    /**
     * Check if the provider settings are properly configured
     */
    public function is_configured(array $settings)
    {
        $api_key = $settings['openai_api_key'] ?? '';
        $assistants = $settings['openai_assistants'] ?? [];

        // Check if we have an API key and at least one assistant configured
        if (empty($api_key)) {
            return false;
        }

        // Check if we have at least one non-empty assistant (language pair)
        $has_assistant = false;
        foreach ($assistants as $language_pair => $assistant_id) {
            if (!empty($assistant_id)) {
                $has_assistant = true;
                break;
            }
        }

        return $has_assistant;
    }

    /**
     * Get configuration status message
     */
    public function get_configuration_status(array $settings)
    {
        $api_key = $settings['openai_api_key'] ?? '';
        $assistants = $settings['openai_assistants'] ?? [];

        if (empty($api_key)) {
            return __('OpenAI API key is required.', 'polytrans');
        }

        $assistant_count = count(array_filter($assistants, function ($id) {
            return !empty($id);
        }));
        if ($assistant_count === 0) {
            return __('At least one translation assistant must be configured.', 'polytrans');
        }

        return ''; // Properly configured
    }

    /**
     * Enqueue additional scripts and styles
     */
    public function enqueue_assets()
    {
        // OpenAI now uses the universal provider settings system
        // No need to enqueue openai-integration.js/css anymore
        // The universal system (provider-settings-universal.js) handles everything
        // This method is kept for interface compatibility but does nothing
        return;
    }

    /**
     * Get AJAX handlers that this provider needs to register
     */
    public function get_ajax_handlers()
    {
        return [
            'polytrans_validate_openai_key' => [
                'callback' => [$this, 'ajax_validate_openai_key'],
                'is_static' => false
            ],
            'polytrans_load_assistants' => [
                'callback' => [$this, 'ajax_load_openai_assistants'],
                'is_static' => false
            ],
            'polytrans_get_ai_models' => [
                'callback' => [$this, 'ajax_get_openai_models'],
                'is_static' => false
            ],
            'polytrans_get_providers_config' => [
                'callback' => [$this, 'ajax_get_providers_config'],
                'is_static' => false
            ]
        ];
    }

    /**
     * Register provider-specific AJAX handlers
     */
    public function register_ajax_handlers()
    {
        $handlers = $this->get_ajax_handlers();

        foreach ($handlers as $action => $handler_info) {
            $callback = $handler_info['callback'];
            $is_static = $handler_info['is_static'] ?? false;

            // Register for logged-in users
            add_action("wp_ajax_{$action}", $callback);

            // If this should be available for non-logged-in users, uncomment the line below
            // add_action("wp_ajax_nopriv_{$action}", $callback);
        }
    }

    /**
     * Render the assistant mapping table
     */
    private function render_assistant_mapping_table($languages, $language_names, $openai_assistants, $openai_source_language)
    {
        // For now, we'll generate language pairs directly since we don't have the integration class
        // This will need to be updated to use the actual OpenAI integration if available
        $language_pairs = $this->get_language_pairs($languages);

        if (empty($language_pairs)) {
            echo '<p><em>' . esc_html__('No language pairs available. Please configure languages first.', 'polytrans') . '</em></p>';
            return;
        }

    ?>
        <table class="widefat fixed striped" id="openai-assistants-table">
            <thead>
                <tr>
                    <th style="width:30%;"><?php esc_html_e('Translation Pair', 'polytrans'); ?></th>
                    <th style="width:30%;"><?php esc_html_e('Translation Path', 'polytrans'); ?></th>
                    <th style="width:40%;"><?php esc_html_e('Assistant', 'polytrans'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($language_pairs as $pair): ?>
                    <?php
                    $source_name = $this->get_language_name($pair['source'], $languages, $language_names);
                    $target_name = $this->get_language_name($pair['target'], $languages, $language_names);
                    $assistant_key = $pair['key'];
                    $selected_assistant = $openai_assistants[$assistant_key] ?? '';
                    ?>
                    <tr data-pair="<?php echo esc_attr($pair['key']); ?>"
                        data-source="<?php echo esc_attr($pair['source']); ?>"
                        data-target="<?php echo esc_attr($pair['target']); ?>"
                        class="language-pair-row" style="display:none;">
                        <td>
                            <strong><?php echo esc_html($source_name); ?> → <?php echo esc_html($target_name); ?></strong>
                        </td>
                        <td>
                            <span class="translation-path-direct"><?php esc_html_e('Direct', 'polytrans'); ?></span>
                            <br><small class="translation-path-details">
                                <?php echo esc_html($source_name . ' → ' . $target_name); ?>
                            </small>
                        </td>
                        <td>
                            <!-- Hidden input to preserve the actual saved value -->
                            <input type="hidden"
                                name="openai_assistants[<?php echo esc_attr($assistant_key); ?>]"
                                id="assistant_hidden_<?php echo esc_attr($assistant_key); ?>"
                                value="<?php echo esc_attr($selected_assistant); ?>"
                                class="assistant-hidden-input" />

                            <!-- Visible select for user interaction -->
                            <select class="assistant-select"
                                id="assistant_select_<?php echo esc_attr($assistant_key); ?>"
                                style="width:100%;max-width:350px;"
                                data-pair="<?php echo esc_attr($assistant_key); ?>"
                                data-selected="<?php echo esc_attr($selected_assistant); ?>"
                                data-hidden-input="assistant_hidden_<?php echo esc_attr($assistant_key); ?>">
                                <option value=""><?php esc_html_e('Not selected', 'polytrans'); ?></option>
                                <!-- Options will be populated via JavaScript when assistants are loaded -->
                            </select>

                        </td>
                    </tr>
                <?php endforeach; ?>

                <tr class="no-relevant-pairs-message" style="display:none;">
                    <td colspan="3" style="text-align:center; color:#888; font-style:italic; padding:20px;">
                        <?php esc_html_e('No relevant language pairs available. Please check your source/target language selections and OpenAI source language configuration.', 'polytrans'); ?>
                    </td>
                </tr>
            </tbody>
        </table>
<?php
    }

    /**
     * Generate language pairs for assistant mapping
     */
    private function get_language_pairs($languages)
    {
        $language_pairs = [];
        foreach ($languages as $source_lang) {
            foreach ($languages as $target_lang) {
                if ($source_lang !== $target_lang) {
                    $language_pairs[] = [
                        'source' => $source_lang,
                        'target' => $target_lang,
                        'key' => $source_lang . '_to_' . $target_lang
                    ];
                }
            }
        }
        return $language_pairs;
    }

    /**
     * Get language display name
     */
    private function get_language_name($lang_code, $languages, $language_names)
    {
        $index = array_search($lang_code, $languages);
        return $index !== false ? ($language_names[$index] ?? strtoupper($lang_code)) : strtoupper($lang_code);
    }

    /**
     * Fetch models from OpenAI API and group them
     * 
     * @param string|null $api_key API key to use (if null, uses settings)
     * @return array Grouped models or empty array on error
     */
    private function fetch_models_from_api($api_key = null)
    {
        if (empty($api_key)) {
            $settings = get_option('polytrans_settings', []);
            $api_key = $settings['openai_api_key'] ?? '';
        }

        if (empty($api_key)) {
            return [];
        }

        try {
            $client = new OpenAIClient($api_key);
            $models_data = $client->get_models();

            if (empty($models_data) || !is_array($models_data)) {
                return [];
            }

            // Group models by prefix
            $grouped = [];
            foreach ($models_data as $model) {
                $model_id = $model['id'] ?? '';
                if (empty($model_id)) {
                    continue;
                }

                // Only include chat completion models (gpt-*)
                if (strpos($model_id, 'gpt-') !== 0) {
                    continue;
                }

                // Determine group
                $group = $this->get_model_group($model_id);
                if (empty($group)) {
                    continue;
                }

                if (!isset($grouped[$group])) {
                    $grouped[$group] = [];
                }

                $label = $this->get_model_label($model_id);
                $grouped[$group][$model_id] = $label;
            }

            // Sort models within each group (newest first)
            foreach ($grouped as $group => &$models) {
                krsort($models);
            }

            return $grouped;
        } catch (\Exception $e) {
            \PolyTrans\Core\LogsManager::log(
                "Failed to fetch models from OpenAI API: " . $e->getMessage(),
                "error",
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * Get model group name based on model ID
     * 
     * @param string $model_id Model ID (e.g., 'gpt-5', 'gpt-4o', 'gpt-4-turbo')
     * @return string Group name or empty string
     */
    private function get_model_group($model_id)
    {
        // Check GPT-5 models first (before GPT-4o to avoid conflicts)
        if (strpos($model_id, 'gpt-5o') === 0) {
            return 'GPT-5o Models';
        } elseif (strpos($model_id, 'gpt-5-turbo') === 0 || (strpos($model_id, 'gpt-5-') === 0 && strpos($model_id, 'gpt-5o') !== 0)) {
            // Check if it's a turbo variant
            if (strpos($model_id, 'gpt-5-turbo') === 0 || preg_match('/^gpt-5-\d/', $model_id)) {
                return 'GPT-5 Turbo Models';
            }
            return 'GPT-5 Models';
        } elseif ($model_id === 'gpt-5' || strpos($model_id, 'gpt-5') === 0) {
            return 'GPT-5 Models';
        } elseif (strpos($model_id, 'gpt-4o') === 0) {
            return 'GPT-4o Models';
        } elseif (strpos($model_id, 'gpt-4-turbo') === 0 || strpos($model_id, 'gpt-4-') === 0) {
            // Check if it's a turbo variant
            if (strpos($model_id, 'gpt-4-turbo') === 0 || strpos($model_id, 'gpt-4-0') === 0 || strpos($model_id, 'gpt-4-1') === 0) {
                return 'GPT-4 Turbo Models';
            }
            return 'GPT-4 Models';
        } elseif (strpos($model_id, 'gpt-3.5') === 0) {
            return 'GPT-3.5 Turbo Models';
        }

        return '';
    }

    /**
     * Get human-readable label for a model
     * 
     * @param string $model_id Model ID
     * @return string Label
     */
    private function get_model_label($model_id)
    {
        // Map common models to friendly names
        $labels = [
            'gpt-5' => 'GPT-5 (Latest)',
            'gpt-5o' => 'GPT-5o (Latest)',
            'gpt-5o-mini' => 'GPT-5o Mini (Fast & Cost-effective)',
            'gpt-5-turbo' => 'GPT-5 Turbo (Latest)',
            'gpt-4o' => 'GPT-4o (Latest)',
            'gpt-4o-mini' => 'GPT-4o Mini (Fast & Cost-effective)',
            'gpt-4-turbo' => 'GPT-4 Turbo (Latest)',
            'gpt-4' => 'GPT-4 (Latest)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Latest)',
            'gpt-3.5-turbo-16k' => 'GPT-3.5 Turbo 16K (Latest)',
        ];

        if (isset($labels[$model_id])) {
            return $labels[$model_id];
        }

        // For versioned models, extract date or version info
        // GPT-5 models
        if (preg_match('/^gpt-5o-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-5o (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-5o-mini-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-5o Mini (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-5-turbo-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-5 Turbo (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-5-(\d{6})-preview$/', $model_id, $matches)) {
            return 'GPT-5 Turbo (' . $matches[1] . '-preview)';
        }
        if (preg_match('/^gpt-5-(\d{4})$/', $model_id, $matches)) {
            return 'GPT-5 (' . $matches[1] . ')';
        }
        // GPT-4 models
        if (preg_match('/^gpt-4o-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-4o (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-4o-mini-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-4o Mini (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-4-turbo-(\d{4}-\d{2}-\d{2})$/', $model_id, $matches)) {
            return 'GPT-4 Turbo (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-4-(\d{6})-preview$/', $model_id, $matches)) {
            return 'GPT-4 Turbo (' . $matches[1] . '-preview)';
        }
        if (preg_match('/^gpt-4-(\d{4})$/', $model_id, $matches)) {
            return 'GPT-4 (' . $matches[1] . ')';
        }
        // GPT-3.5 models
        if (preg_match('/^gpt-3\.5-turbo-(\d{6})$/', $model_id, $matches)) {
            return 'GPT-3.5 Turbo (' . $matches[1] . ')';
        }
        if (preg_match('/^gpt-3\.5-turbo-16k-(\d{6})$/', $model_id, $matches)) {
            return 'GPT-3.5 Turbo 16K (' . $matches[1] . ')';
        }

        // Fallback: capitalize and format
        return ucfirst(str_replace('-', ' ', $model_id));
    }

    /**
     * Get fallback models (hardcoded list for backward compatibility)
     * 
     * @return array Grouped fallback models
     */
    private function get_fallback_models()
    {
        return [
            'GPT-5 Models' => [
                'gpt-5' => 'GPT-5 (Latest)',
                'gpt-5o' => 'GPT-5o (Latest)',
                'gpt-5o-mini' => 'GPT-5o Mini (Fast & Cost-effective)',
                'gpt-5-turbo' => 'GPT-5 Turbo (Latest)',
            ],
            'GPT-4o Models (Latest)' => [
                'gpt-4o' => 'GPT-4o (Latest)',
                'gpt-4o-mini' => 'GPT-4o Mini (Fast & Cost-effective)',
                'gpt-4o-2024-11-20' => 'GPT-4o (2024-11-20)',
                'gpt-4o-2024-08-06' => 'GPT-4o (2024-08-06)',
                'gpt-4o-2024-05-13' => 'GPT-4o (2024-05-13)',
                'gpt-4o-mini-2024-07-18' => 'GPT-4o Mini (2024-07-18)',
            ],
            'GPT-4 Turbo Models' => [
                'gpt-4-turbo' => 'GPT-4 Turbo (Latest)',
                'gpt-4-turbo-2024-04-09' => 'GPT-4 Turbo (2024-04-09)',
                'gpt-4-turbo-preview' => 'GPT-4 Turbo Preview',
                'gpt-4-0125-preview' => 'GPT-4 Turbo (0125-preview)',
                'gpt-4-1106-preview' => 'GPT-4 Turbo (1106-preview)',
            ],
            'GPT-4 Models' => [
                'gpt-4' => 'GPT-4 (Latest)',
                'gpt-4-0613' => 'GPT-4 (0613)',
                'gpt-4-0314' => 'GPT-4 (0314)',
            ],
            'GPT-3.5 Turbo Models' => [
                'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Latest)',
                'gpt-3.5-turbo-0125' => 'GPT-3.5 Turbo (0125)',
                'gpt-3.5-turbo-1106' => 'GPT-3.5 Turbo (1106)',
                'gpt-3.5-turbo-0613' => 'GPT-3.5 Turbo (0613)',
                'gpt-3.5-turbo-16k' => 'GPT-3.5 Turbo 16K (Latest)',
                'gpt-3.5-turbo-16k-0613' => 'GPT-3.5 Turbo 16K (0613)',
            ],
        ];
    }

    /**
     * Get grouped models for UI display - SINGLE SOURCE OF TRUTH
     * Fetches from API if available, falls back to hardcoded list
     * Ensures backward compatibility by always including currently selected model
     * 
     * @param string|null $selected_model Currently selected model ID (for backward compatibility)
     * @return array Grouped model options
     */
    public function get_grouped_models($selected_model = null)
    {
        // Fetch from API (with cache)
        $settings = get_option('polytrans_settings', []);
        $grouped = $this->load_models($settings);
        
        // Backward compatibility: ensure selected model is always present (if it exists in API)
        if (!empty($selected_model) && !empty($grouped)) {
            $found = false;
            foreach ($grouped as $group => $models) {
                if (isset($models[$selected_model])) {
                    $found = true;
                    break;
                }
            }

            // If selected model not found in API results, try to add it (only if we have API data)
            if (!$found) {
                $group = $this->get_model_group($selected_model);
                if (!empty($group)) {
                    if (!isset($grouped[$group])) {
                        $grouped[$group] = [];
                    }
                    $grouped[$group][$selected_model] = $this->get_model_label($selected_model);
                }
            }
        }

        return $grouped;
    }

    /**
     * Get all available OpenAI models (flattened from grouped models)
     */
    private function get_all_available_models()
    {
        $models = [];
        foreach ($this->get_grouped_models() as $group => $group_models) {
            $models = array_merge($models, array_keys($group_models));
        }
        return $models;
    }

    /**
     * Render model selection dropdown
     */
    private function render_model_selection($selected_model)
    {
        $grouped_models = $this->get_grouped_models($selected_model);

        echo '<select name="openai_model" id="openai-model" style="max-width:300px;" data-provider="openai" data-field="model" data-selected-model="' . esc_attr($selected_model ?? '') . '">';
        
        // Always add "None selected" option first
        $none_selected = empty($selected_model) ? 'selected' : '';
        echo '<option value="" ' . esc_attr($none_selected) . '>' . esc_html__('None selected', 'polytrans') . '</option>';

        foreach ($grouped_models as $group_name => $models) {
            echo '<optgroup label="' . esc_attr($group_name) . '">';
            foreach ($models as $model_value => $model_label) {
                $selected = selected($selected_model, $model_value, false);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns safe HTML attribute
                echo '<option value="' . esc_attr($model_value) . '" ' . $selected . '>' . esc_html($model_label) . '</option>';
            }
            echo '</optgroup>';
        }

        echo '</select>';
        echo '<button type="button" data-provider="openai" data-action="refresh-models" id="refresh-openai-models" class="button" style="margin-left: 0.5em;" title="' . esc_attr__('Refresh models from OpenAI API', 'polytrans') . '">' . esc_html__('Refresh', 'polytrans') . '</button>';
    }

    /**
     * Validate OpenAI API key using the OpenAI API
     */
    private function validate_openai_api_key($api_key)
    {
        $client = new OpenAIClient($api_key);
        $models = $client->get_models();

        return !empty($models);
    }

    /**
     * AJAX handler for validating OpenAI API key
     */
    public function ajax_validate_openai_key()
    {
        // Check nonce
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce_check = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'polytrans_openai_nonce');
        }

        if (!$nonce_check) {
            wp_send_json_error(__('Security check failed.', 'polytrans'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'polytrans'));
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        if (empty($api_key)) {
            wp_send_json_error(__('API key is required.', 'polytrans'));
        }

        // Validate the OpenAI API key
        $is_valid_key = $this->validate_openai_api_key($api_key);

        if ($is_valid_key) {
            wp_send_json_success(__('API key is valid!', 'polytrans'));
        } else {
            wp_send_json_error(__('Invalid API key.', 'polytrans'));
        }
    }

    /**
     * AJAX handler for loading assistants (both Managed and OpenAI API)
     */
    public function ajax_load_openai_assistants()
    {
        // Check nonce - accept both polytrans_openai_nonce and polytrans_workflows_nonce
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
            // Try OpenAI nonce first (for settings page)
            $nonce_check = wp_verify_nonce($nonce, 'polytrans_openai_nonce');
            // If that fails, try workflows nonce (for workflow editor)
            if (!$nonce_check) {
                $nonce_check = wp_verify_nonce($nonce, 'polytrans_workflows_nonce');
            }
        }

        if (!$nonce_check) {
            wp_send_json_error(__('Security check failed.', 'polytrans'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'polytrans'));
        }

        // Get API key from POST or fallback to settings
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $settings = get_option('polytrans_settings', []);
        if (empty($api_key)) {
            $api_key = $settings['openai_api_key'] ?? '';
        }

        // Check if managed assistants should be excluded (for workflow predefined assistant step)
        $exclude_managed = isset($_POST['exclude_managed']) && sanitize_text_field(wp_unslash($_POST['exclude_managed'])) === 'true';
        // Check if translation providers should be excluded (for workflow predefined assistant step)
        $exclude_providers = isset($_POST['exclude_providers']) && sanitize_text_field(wp_unslash($_POST['exclude_providers'])) === 'true';

        // Get enabled providers (needed for filtering managed assistants and OpenAI assistants)
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        if (!is_array($enabled_providers)) {
            $enabled_providers = []; // Fallback to default
        }

        // Prepare grouped assistants/providers structure
        $grouped_assistants = [
            'providers' => [],  // Translation providers - only if not excluded
            'managed' => [],    // Managed Assistants (only if not excluded)
            'openai' => [],     // OpenAI API Assistants
            'claude' => [],     // Future: Claude Projects
            'gemini' => []      // Future: Gemini Tuned Models
        ];

        // 0. Load Enabled Translation Providers (only those that can translate directly)
        // Skip if excluded (for workflow predefined assistant step - only AI assistants, not translation providers)
        if (!$exclude_providers) {
            // OpenAI is NOT included here - it only provides assistants, not direct translation
            $registry = ProviderRegistry::get_instance();
            $all_providers = $registry->get_providers();
            
            foreach ($all_providers as $provider_id => $provider) {
                // Only include enabled providers that can translate directly
                // Skip OpenAI and Claude - they're assistant-only, not translation providers
                if ($provider_id === 'openai' || $provider_id === 'claude') {
                    continue; // OpenAI and Claude don't have translation endpoints, only assistants
                }
                
                if (in_array($provider_id, $enabled_providers)) {
                    $grouped_assistants['providers'][] = [
                        'id' => 'provider_' . $provider_id, // Prefix to distinguish from assistants
                        'name' => $provider->get_name(),
                        'description' => $provider->get_description(),
                        'model' => 'N/A', // Providers don't have models
                        'provider' => $provider_id
                    ];
                }
            }
        }

        // 1. Load Managed Assistants from local database (only if not excluded)
        // Filter: only show assistants from enabled providers
        if (!$exclude_managed) {
            $managed_assistants = AssistantManager::get_all_assistants();
            if (!empty($managed_assistants)) {
            foreach ($managed_assistants as $assistant) {
                $assistant_provider = $assistant['provider'] ?? 'openai';
                
                // Filter managed assistants based on enabled providers
                // Check if the assistant's provider is enabled in enabled_translation_providers
                if (!in_array($assistant_provider, $enabled_providers)) {
                    continue; // Skip assistants from disabled providers
                }
                
                // Additional check for OpenAI: also require API key to be configured
                if ($assistant_provider === 'openai' && empty($api_key)) {
                    continue; // Skip OpenAI assistants if API key not configured
                }
                
                $model_display = 'No model';
                
                // Try to get model from api_parameters
                if (!empty($assistant['api_parameters'])) {
                    $api_params = is_string($assistant['api_parameters']) 
                        ? json_decode($assistant['api_parameters'], true) 
                        : $assistant['api_parameters'];

                    if (is_array($api_params) && !empty($api_params['model'])) {
                        $model_display = $api_params['model'];
                    }
                }
                
                // Fallback: use global setting indicator
                if ($model_display === 'No model' || empty($model_display)) {
                    $model_display = 'Global Setting';
                }

                $grouped_assistants['managed'][] = [
                    'id' => 'managed_' . $assistant['id'], // Prefix to distinguish from OpenAI IDs
                    'name' => $assistant['name'],
                    'description' => $assistant['description'] ?? '',
                    'model' => $model_display,
                    'provider' => $assistant_provider
                ];
            }
            }
        }

        // 2. Load OpenAI API Assistants (if OpenAI is enabled AND API key provided)
        $openai_enabled = in_array('openai', $enabled_providers);
        if ($openai_enabled && !empty($api_key)) {
            try {
                $client = new OpenAIClient($api_key);
                $openai_assistants = $client->get_all_assistants();

                if (!empty($openai_assistants) && is_array($openai_assistants)) {
                    foreach ($openai_assistants as $assistant) {
                        $grouped_assistants['openai'][] = [
                            'id' => $assistant['id'], // Keep original asst_xxx format
                            'name' => $assistant['name'] ?? 'Unnamed Assistant',
                            'description' => $assistant['description'] ?? '',
                            'model' => $assistant['model'] ?? 'gpt-4',
                            'provider' => 'openai'
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail the entire request
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('PolyTrans: Failed to load OpenAI assistants: ' . $e->getMessage());
            }
        }

        // Note: Claude does not have an Assistants API (prompts created in UI are not accessible via API)
        // Users must create Managed Assistants for Claude instead
        // Gemini has Agents API and will be loaded when implemented

        // Return grouped structure
        wp_send_json_success($grouped_assistants);
    }

    /**
     * AJAX handler for fetching OpenAI models
     * @deprecated Use polytrans_get_provider_models with provider_id='openai' instead
     * Kept for backward compatibility
     */
    public function ajax_get_openai_models()
    {
        // Check nonce
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce_check = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'polytrans_openai_nonce');
        }

        if (!$nonce_check) {
            wp_send_json_error(__('Security check failed.', 'polytrans'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'polytrans'));
        }

        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $selected_model = sanitize_text_field(wp_unslash($_POST['selected_model'] ?? ''));

        // Get models (will use API if key provided, otherwise fallback)
        $grouped_models = $this->get_grouped_models($selected_model);

        wp_send_json_success([
            'models' => $grouped_models,
            'selected_model' => $selected_model
        ]);
    }

    /**
     * Validate API key for this provider
     * @param string $api_key API key to validate
     * @return bool True if valid, false otherwise
     */
    public function validate_api_key(string $api_key): bool
    {
        return $this->validate_openai_api_key($api_key);
    }
    
    /**
     * Load assistants from provider API
     * @param array $settings Current settings (for API keys, etc.)
     * @return array Array of assistants [['id' => 'asst_xxx', 'name' => '...', 'model' => '...', 'provider' => '...'], ...]
     */
    public function load_assistants(array $settings): array
    {
        $api_key = $settings['openai_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        try {
            $client = new OpenAIClient($api_key);
            $assistants_data = $client->get_all_assistants();
            
            if (empty($assistants_data) || !is_array($assistants_data)) {
                return [];
            }
            
            $assistants = [];
            foreach ($assistants_data as $assistant) {
                $assistants[] = [
                    'id' => $assistant['id'] ?? '',
                    'name' => $assistant['name'] ?? 'Unnamed Assistant',
                    'description' => $assistant['description'] ?? '',
                    'model' => $assistant['model'] ?? 'gpt-4o-mini',
                    'provider' => 'openai',
                ];
            }
            
            return $assistants;
        } catch (\Exception $e) {
            \PolyTrans\Core\LogsManager::log(
                "Failed to load OpenAI assistants: " . $e->getMessage(),
                "error",
                ['exception' => $e->getMessage()]
            );
            return [];
        }
    }
    
    /**
     * Load available models from provider API
     * @param array $settings Current settings (for API keys, etc.)
     * @return array Grouped models ['Group Name' => ['model_id' => 'Model Name', ...], ...] or empty array if not supported
     */
    public function load_models(array $settings): array
    {
        $api_key = $settings['openai_api_key'] ?? '';
        
        if (empty($api_key)) {
            return [];
        }
        
        // Check cache first (1 hour cache)
        $cache_key = 'polytrans_openai_models_' . md5($api_key);
        $cached_models = get_transient($cache_key);
        if ($cached_models !== false && is_array($cached_models)) {
            return $cached_models;
        }
        
        $models = $this->fetch_models_from_api($api_key);
        
        // Cache models for 1 hour
        if (!empty($models)) {
            set_transient($cache_key, $models, HOUR_IN_SECONDS);
        }
        
        return $models;
    }
    
    /**
     * Get provider manifest with capabilities and configuration
     */
    public function get_provider_manifest(array $settings)
    {
        $api_key = $settings['openai_api_key'] ?? '';
        
        return [
            'provider_id' => 'openai',
            'capabilities' => ['assistants', 'chat', 'system_prompt'], // OpenAI provides both Assistants API and Chat API with system prompt support
            'assistants_endpoint' => 'https://api.openai.com/v1/assistants',
            'chat_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'models_endpoint' => 'https://api.openai.com/v1/models',
            'auth_type' => 'bearer',
            'auth_header' => 'Authorization',
            'api_key_setting' => 'openai_api_key',
            'api_key_configured' => !empty($api_key) && current_user_can('manage_options'), // Only show to admins
            'base_url' => 'https://api.openai.com/v1',
            // Note: 'system_prompt' capability is included in capabilities array
        ];
    }

    /**
     * AJAX handler for getting all providers configuration/manifests
     */
    public function ajax_get_providers_config()
    {
        // Check nonce
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce_check = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'polytrans_openai_nonce');
        }

        if (!$nonce_check) {
            wp_send_json_error(__('Security check failed.', 'polytrans'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'polytrans'));
        }

        $settings = get_option('polytrans_settings', []);
        $registry = ProviderRegistry::get_instance();
        $providers = $registry->get_providers();
        
        $manifests = [];
        foreach ($providers as $provider_id => $provider) {
            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                if (method_exists($settings_provider, 'get_provider_manifest')) {
                    $manifests[$provider_id] = $settings_provider->get_provider_manifest($settings);
                }
            }
        }

        wp_send_json_success($manifests);
    }
}

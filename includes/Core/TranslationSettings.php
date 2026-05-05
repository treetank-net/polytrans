<?php

namespace PolyTrans\Core;

use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\Templating\TemplateRenderer;

/**
 * Translation Settings Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationSettings
{

    private $langs;
    private $lang_names;
    private $statuses;
    
    /**
     * Get translation path rules with backward compatibility
     * Checks for 'translation_path_rules' first, falls back to 'openai_path_rules'
     */
    private static function get_path_rules($settings)
    {
        return $settings['translation_path_rules'] ?? $settings['openai_path_rules'] ?? [];
    }
    
    /**
     * Get assistants mapping with backward compatibility
     * Checks for 'assistants_mapping' first, falls back to 'openai_assistants'
     */
    private static function get_assistants_mapping($settings)
    {
        return $settings['assistants_mapping'] ?? $settings['openai_assistants'] ?? [];
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
        $this->lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];
        $this->statuses = [
            'publish' => __('Publish', 'polytrans'),
            'draft' => __('Draft', 'polytrans'),
            'pending' => __('Pending Review', 'polytrans'),
            'source' => __('Same as source', 'polytrans'),
        ];
        
        // Register universal AJAX endpoints
        // Note: Also registered in SettingsMenu::__construct() for early availability
        add_action('wp_ajax_polytrans_validate_provider_key', [$this, 'ajax_validate_provider_key']);
    }
    
    /**
     * Static wrapper for AJAX handler (called from SettingsMenu)
     */
    public static function ajax_validate_provider_key_static()
    {
        $instance = new self();
        $instance->ajax_validate_provider_key();
    }
    
    /**
     * Universal AJAX handler for validating provider API keys
     */
    public function ajax_validate_provider_key()
    {
        // Check nonce - accept multiple nonce types for compatibility
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
            // Try different nonce types (in order of preference):
            // 1. polytrans_nonce (from SettingsMenu - Universal JS)
            // 2. polytrans_settings (form nonce)
            // 3. polytrans_openai_nonce (backward compatibility)
            // 4. polytrans_workflows_nonce (backward compatibility)
            $nonce_check = wp_verify_nonce($nonce, 'polytrans_nonce') ||
                          wp_verify_nonce($nonce, 'polytrans_settings') ||
                          wp_verify_nonce($nonce, 'polytrans_openai_nonce') ||
                          wp_verify_nonce($nonce, 'polytrans_workflows_nonce');
        }
        
        if (!$nonce_check) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("PolyTrans: Nonce check failed. Nonce: " . sanitize_text_field(wp_unslash($_POST['nonce'] ?? 'not set')) . ", Action: " . sanitize_text_field(wp_unslash($_POST['action'] ?? 'not set')));
            wp_send_json_error(__('Security check failed.', 'polytrans'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'polytrans'));
            return;
        }
        
        $provider_id = sanitize_text_field(wp_unslash($_POST['provider_id'] ?? ''));
        $api_key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        
        if (empty($provider_id)) {
            wp_send_json_error(__('Provider ID is required.', 'polytrans'));
            return;
        }
        
        if (empty($api_key)) {
            wp_send_json_error(__('API key is required.', 'polytrans'));
            return;
        }
        
        // Get provider from registry
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($provider_id);
        
        if (!$provider) {
            /* translators: %s: provider identifier */
            wp_send_json_error(sprintf(__('Provider "%s" not found.', 'polytrans'), $provider_id));
            return;
        }
        
        // Get settings provider
        $settings_provider_class = $provider->get_settings_provider_class();
        if (!$settings_provider_class || !class_exists($settings_provider_class)) {
            /* translators: %s: provider identifier */
            wp_send_json_error(sprintf(__('Settings provider not found for "%s".', 'polytrans'), $provider_id));
            return;
        }
        
        $settings_provider = new $settings_provider_class();
        
        // Try to validate using method or hook
        $is_valid = false;
        $error_message = '';
        
        // Method 1: Use validate_api_key() method if available
        if (method_exists($settings_provider, 'validate_api_key')) {
            try {
                $is_valid = $settings_provider->validate_api_key($api_key);
                if (!$is_valid) {
                    $error_message = __('Invalid API key.', 'polytrans');
                }
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log("PolyTrans: Failed to validate API key for {$provider_id}: " . $e->getMessage());
                $error_message = __('Validation failed due to an error: ', 'polytrans') . $e->getMessage();
                wp_send_json_error($error_message);
                return;
            }
        } else {
            // Method 2: Use hook for external plugins
            $is_valid = apply_filters("polytrans_validate_api_key_{$provider_id}", false, $api_key);
            
            // Fallback: basic check (not empty)
            if ($is_valid === false) {
                $is_valid = !empty($api_key);
                if (!$is_valid) {
                    $error_message = __('API key cannot be empty.', 'polytrans');
                }
            }
        }
        
        if ($is_valid) {
            wp_send_json_success(__('API key is valid!', 'polytrans'));
        } else {
            wp_send_json_error($error_message ?: __('Invalid API key.', 'polytrans'));
        }
    }

    /**
     * Render the settings page
     */
    public function render()
    {
        $settings = get_option('polytrans_settings', []);

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('polytrans_settings')) {
            $this->save_settings($settings);
        }

        $this->output_page($settings);
    }

    /**
     * Save settings
     */
    private function save_settings(&$settings)
    {
        // Verify nonce for security
        if (!check_admin_referer('polytrans_settings')) {
            wp_die(esc_html__('Security check failed.', 'polytrans'));
        }

        $registry = \PolyTrans_Provider_Registry::get_instance();

        // Handle enabled translation providers (checkboxes)
        $settings['enabled_translation_providers'] = isset($_POST['enabled_translation_providers']) 
            ? array_map('sanitize_text_field', wp_unslash($_POST['enabled_translation_providers'])) 
            : []; // Default to empty if none selected
        
        // Keep backward compatibility: set translation_provider to first enabled provider
        $settings['translation_provider'] = !empty($settings['enabled_translation_providers']) 
            ? $settings['enabled_translation_providers'][0]
            : '';
        $settings['translation_transport_mode'] = sanitize_text_field(wp_unslash($_POST['translation_transport_mode'] ?? 'external'));
        $settings['translation_endpoint'] = esc_url_raw(wp_unslash($_POST['translation_endpoint'] ?? ''));
        $settings['translation_receiver_endpoint'] = esc_url_raw(wp_unslash($_POST['translation_receiver_endpoint'] ?? ''));
        $settings['translation_receiver_secret'] = sanitize_text_field(wp_unslash($_POST['translation_receiver_secret'] ?? ''));
        $settings['translation_receiver_secret_method'] = sanitize_text_field(wp_unslash($_POST['translation_receiver_secret_method'] ?? 'header_bearer'));
        $settings['translation_receiver_secret_custom_header'] = sanitize_text_field(wp_unslash($_POST['translation_receiver_secret_custom_header'] ?? 'x-polytrans-secret'));
        $settings['edit_link_base_url'] = esc_url_raw(wp_unslash($_POST['edit_link_base_url'] ?? ''));
        $settings['api_timeout'] = absint(wp_unslash($_POST['api_timeout'] ?? 180));
        // Ensure timeout is within reasonable bounds (30-600 seconds)
        $settings['api_timeout'] = max(30, min(600, $settings['api_timeout']));
        $settings['enable_db_logging'] = isset($_POST['enable_db_logging']) ? '1' : '0';
        $settings['allowed_sources'] = isset($_POST['allowed_sources']) ? array_map('sanitize_text_field', wp_unslash($_POST['allowed_sources'])) : [];
        $settings['allowed_targets'] = isset($_POST['allowed_targets']) ? array_map('sanitize_text_field', wp_unslash($_POST['allowed_targets'])) : [];
        $settings['source_language'] = sanitize_text_field(wp_unslash($_POST['source_language'] ?? 'pl'));
        $settings['base_tags'] = sanitize_textarea_field(wp_unslash($_POST['base_tags'] ?? ''));
        $settings['enable_tag_grouping'] = isset($_POST['enable_tag_grouping']) ? '1' : '0';

        // Field whitelist for dirty check (one pattern per line)
        $settings['dirty_check_field_whitelist'] = sanitize_textarea_field(wp_unslash($_POST['dirty_check_field_whitelist'] ?? ''));
        $settings['logs_retention_days'] = max(1, min(365, intval($_POST['logs_retention_days'] ?? 30)));

        $prompt_template_keys = [
            PromptRefinementSettings::ASSISTANT_EVALUATOR_SYSTEM_KEY,
            PromptRefinementSettings::ASSISTANT_EVALUATOR_KEY,
            PromptRefinementSettings::ASSISTANT_ADJUSTER_SYSTEM_KEY,
            PromptRefinementSettings::ASSISTANT_ADJUSTER_KEY,
            PromptRefinementSettings::WORKFLOW_EVALUATOR_SYSTEM_KEY,
            PromptRefinementSettings::WORKFLOW_EVALUATOR_KEY,
            PromptRefinementSettings::WORKFLOW_ADJUSTER_SYSTEM_KEY,
            PromptRefinementSettings::WORKFLOW_ADJUSTER_KEY,
            PromptRefinementSettings::DESCRIPTION_GENERATOR_SYSTEM_KEY,
            PromptRefinementSettings::CRITERIA_GENERATOR_SYSTEM_KEY,
            PromptRefinementSettings::ASSISTANT_DESCRIPTION_GENERATOR_KEY,
            PromptRefinementSettings::WORKFLOW_DESCRIPTION_GENERATOR_KEY,
            PromptRefinementSettings::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY,
            PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY,
        ];
        foreach ($prompt_template_keys as $prompt_template_key) {
            $settings[$prompt_template_key] = array_key_exists($prompt_template_key, $_POST)
                ? (string) wp_unslash($_POST[$prompt_template_key]) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-authored prompt templates must preserve Twig/JSON syntax.
                : '';
        }

        // Handle provider-specific settings
        // IMPORTANT: Save settings for ALL providers with settings UI, not just the selected provider
        // This allows users to configure multiple providers (e.g., OpenAI for assistants, Google for default translation)
        $selected_provider = $registry->get_provider($settings['translation_provider']);
        $providers = $registry->get_providers();
        
        // Process settings for all providers that have settings UI
        foreach ($providers as $provider_id => $provider) {
            $settings_provider_class = $provider->get_settings_provider_class();
            if (!$settings_provider_class || !class_exists($settings_provider_class)) {
                continue;
            }
            
            $settings_provider = new $settings_provider_class();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Provider's validate_settings() handles sanitization
            $provider_settings = $settings_provider->validate_settings(wp_unslash($_POST));
            
            // For OpenAI: always save path rules and assistants (used by TranslationPathExecutor)
            // Also save API key and model if provided (needed for assistants and managed assistants)
            if ($provider_id === 'openai') {
                // Save all OpenAI settings (API key, model, path rules, assistants)
                if (isset($provider_settings['openai_api_key'])) {
                    $settings['openai_api_key'] = $provider_settings['openai_api_key'];
                }
                if (isset($provider_settings['openai_model'])) {
                    $settings['openai_model'] = $provider_settings['openai_model'];
                }
                
                // Save path rules in both formats (new universal + old OpenAI-specific for backward compatibility)
                if (isset($provider_settings['openai_path_rules'])) {
                    $path_rules = $provider_settings['openai_path_rules'];
                    $settings['translation_path_rules'] = $path_rules; // New universal name
                    $settings['openai_path_rules'] = $path_rules; // Old name for backward compatibility
                }
                
                // Save assistants mapping in both formats (new universal + old OpenAI-specific for backward compatibility)
                if (isset($provider_settings['openai_assistants'])) {
                    $assistants_mapping = $provider_settings['openai_assistants'];
                    
                    // Validate assistants using PathValidator before saving
                    $validation = \PolyTrans\Core\PathValidator::validate_assistants_mapping(
                        $assistants_mapping,
                        $settings
                    );
                    
                    if (!$validation['valid'] && !empty($validation['errors'])) {
                        // Show admin notice with validation errors
                        $error_messages = [];
                        foreach ($validation['errors'] as $pair => $error) {
                            $error_messages[] = "$pair: $error";
                        }
                        add_settings_error(
                            'polytrans_settings',
                            'path_validation_error',
                            __('Some provider/assistant mappings are invalid: ', 'polytrans') . implode('; ', $error_messages),
                            'error'
                        );
                    }
                    
                    // Save even if there are errors (user can fix them later)
                    $settings['assistants_mapping'] = $assistants_mapping; // New universal name
                    $settings['openai_assistants'] = $assistants_mapping; // Old name for backward compatibility
                }
            } else {
                // For other providers: merge all settings
                $settings = array_merge($settings, $provider_settings);
            }
        }

        foreach ($this->langs as $lang) {
            $settings[$lang] = [
                'status' => sanitize_text_field(wp_unslash($_POST['status'][$lang] ?? 'draft')),
                'reviewer' => sanitize_text_field(wp_unslash($_POST['reviewer'][$lang] ?? 'none')),
            ];
        }

        $settings['reviewer_email'] = wp_kses_post(wp_unslash($_POST['reviewer_email'] ?? ''));
        $settings['author_email'] = wp_kses_post(wp_unslash($_POST['author_email'] ?? ''));
        $settings['reviewer_email_title'] = wp_kses_post(wp_unslash($_POST['reviewer_email_title'] ?? ''));
        $settings['author_email_title'] = wp_kses_post(wp_unslash($_POST['author_email_title'] ?? ''));

        // Notification timing (when to send emails)
        $notification_timing = sanitize_text_field(wp_unslash($_POST['notification_timing'] ?? 'after_workflows'));
        if (in_array($notification_timing, ['immediate', 'after_workflows'], true)) {
            $settings['notification_timing'] = $notification_timing;
        } else {
            $settings['notification_timing'] = 'after_workflows';
        }

        // Notification filters
        
        $settings['notification_allowed_domains'] = \PolyTrans\Core\NotificationFilter::sanitize_domains(
            sanitize_textarea_field(wp_unslash($_POST['notification_allowed_domains'] ?? ''))
        );

        update_option('polytrans_settings', $settings);
        echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'polytrans') . '</p></div>';
    }

    /**
     * Output the settings page HTML
     */
    private function output_page($settings)
    {
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $translation_provider = $settings['translation_provider'] ?? '';
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        $translation_endpoint = $settings['translation_endpoint'] ?? '';
        $translation_receiver_endpoint = $settings['translation_receiver_endpoint'] ?? '';
        $allowed_sources = $settings['allowed_sources'] ?? [];
        $allowed_targets = $settings['allowed_targets'] ?? [];
        $source_language = $settings['source_language'] ?? 'pl';
        $base_tags = $settings['base_tags'] ?? '';
        $reviewer_email = $settings['reviewer_email'] ?? '';
        $reviewer_email_title = $settings['reviewer_email_title'] ?? '';
        $author_email = $settings['author_email'] ?? '';
        $author_email_title = $settings['author_email_title'] ?? '';

        // Get available providers and their settings providers
        // Skip providers with empty settings UI (like Google)
        $providers = $registry->get_providers();
        $settings_providers = [];
        $provider_custom_ui = [];
        foreach ($providers as $provider_id => $provider) {
            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider_instance = new $settings_provider_class();
                
                // Skip Google - it has no settings UI
                if ($provider_id === 'google') {
                    // Still enqueue assets if needed, but don't add to tabs
                    continue;
                }
                
                // Check if provider is used (enabled OR has API key configured)
                if (!$this->is_provider_used($provider_id, $settings_provider_instance, $settings, $enabled_providers)) {
                    continue; // Skip unused providers
                }
                
                $settings_providers[$provider_id] = $settings_provider_instance;
                
                // Try to get custom UI from provider (if it has one)
                $custom_ui = '';
                try {
                    ob_start();
                    $settings_provider_instance->render_settings_ui($settings, $this->langs, $this->lang_names);
                    $custom_ui = ob_get_clean();
                } catch (\Exception $e) {
                    // Provider doesn't have custom UI or error occurred
                    ob_end_clean();
                    $custom_ui = '';
                }
                
                // Store custom UI if provider returned meaningful output
                if (!empty(trim($custom_ui))) {
                    $provider_custom_ui[$provider_id] = $custom_ui;
                }
                
                // Always enqueue OpenAI assets since they're used in workflows regardless of main provider
                // For other providers, only enqueue if they're the selected provider
                if ($provider_id === 'openai' || $provider_id === $translation_provider) {
                    $settings_provider_instance->enqueue_assets();
                }
            }
        }

        // Prepare template context
        $template_context = [
            'providers' => $providers,
            'settings_providers' => $settings_providers,
            'provider_custom_ui' => $provider_custom_ui,
            'enabled_providers' => $enabled_providers,
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
            'statuses' => $this->statuses,
            'allowed_sources' => $allowed_sources,
            'allowed_targets' => $allowed_targets,
            'source_language' => $source_language,
            'base_tags' => $base_tags,
            'reviewer_email' => $reviewer_email,
            'reviewer_email_title' => $reviewer_email_title,
            'author_email' => $author_email,
            'author_email_title' => $author_email_title,
            'translation_endpoint' => $translation_endpoint,
            'translation_receiver_endpoint' => $translation_receiver_endpoint,
            'prompt_refinement_templates' => PromptRefinementSettings::current($settings),
            'prompt_refinement_default_templates' => PromptRefinementSettings::defaults(),
            'settings' => $settings,
        ];
        
        // Add languages data for JS (used in path rules management)
        wp_add_inline_script(
            'polytrans-settings',
            'window.polytransLanguages = ' . wp_json_encode(array_combine($template_context['langs'], $template_context['lang_names'])) . ';',
            'before'
        );
        
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/page.twig', $template_context);
    }

    /**
     * Render language configuration table
     */
    private function render_language_config_table($settings)
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/language-config-table.twig', [
            'settings' => $settings,
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
            'statuses' => $this->statuses,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render email settings section
     */
    private function render_email_settings($reviewer_email, $reviewer_email_title, $author_email, $author_email_title)
    {
        $settings = get_option('polytrans_settings', []);
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/email-settings.twig', [
            'reviewer_email' => $reviewer_email,
            'reviewer_email_title' => $reviewer_email_title,
            'author_email' => $author_email,
            'author_email_title' => $author_email_title,
            'settings' => $settings,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render advanced settings section
     */
    private function render_advanced_settings($translation_endpoint, $translation_receiver_endpoint, $settings)
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/advanced-settings.twig', [
            'translation_endpoint' => $translation_endpoint,
            'translation_receiver_endpoint' => $translation_receiver_endpoint,
            'settings' => $settings,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render tag settings section
     */
    private function render_tag_settings($source_language, $base_tags)
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        $settings = get_option('polytrans_settings', []);
        echo TemplateRenderer::render('admin/settings/tabs/tag-settings.twig', [
            'source_language' => $source_language,
            'base_tags' => $base_tags,
            'enable_tag_grouping' => ($settings['enable_tag_grouping'] ?? '0') === '1',
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render Language Paths settings tab
     */
    private function render_language_pairs_settings($settings)
    {
        $assistants_mapping = self::get_assistants_mapping($settings);
        $path_rules = self::get_path_rules($settings);
        $language_pairs = $this->get_language_pairs();
        
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/language-paths.twig', [
            'settings' => $settings,
            'assistants_mapping' => $assistants_mapping,
            'path_rules' => $path_rules,
            'language_pairs' => $language_pairs,
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render assistant mapping table
     */
    private function render_assistant_mapping_table($assistants_mapping)
    {
        if (empty($this->langs)) {
            echo '<p><em>' . esc_html__('No languages configured. Please configure languages in Basic Settings first.', 'polytrans') . '</em></p>';
            return;
        }

        $language_pairs = $this->get_language_pairs();

        if (empty($language_pairs)) {
            echo '<p><em>' . esc_html__('No language pairs available.', 'polytrans') . '</em></p>';
            return;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/assistant-mapping-table.twig', [
            'assistants_mapping' => $assistants_mapping,
            'language_pairs' => $language_pairs,
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render path rules table
     */
    private function render_path_rules_table($rules)
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/path-rules-table.twig', [
            'rules' => $rules,
            'langs' => $this->langs,
            'lang_names' => $this->lang_names,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Get language pairs
     */
    private function get_language_pairs()
    {
        $pairs = [];
        foreach ($this->langs as $source) {
            foreach ($this->langs as $target) {
                if ($source !== $target) {
                    $pairs[] = [
                        'source' => $source,
                        'target' => $target,
                        'key' => $source . '_to_' . $target
                    ];
                }
            }
        }
        return $pairs;
    }

    /**
     * Get language name
     */
    private function get_language_name($code)
    {
        $index = array_search($code, $this->langs);
        if ($index !== false && isset($this->lang_names[$index])) {
            return $this->lang_names[$index];
        }
        return strtoupper($code);
    }
    
    /**
     * Check if provider settings should be shown (only if enabled)
     * 
     * @param string $provider_id Provider ID
     * @param \PolyTrans\Providers\SettingsProviderInterface $settings_provider Settings provider instance
     * @param array $settings Current settings
     * @param array $enabled_providers List of enabled provider IDs
     * @return bool True if provider settings should be shown
     */
    private function is_provider_used($provider_id, $settings_provider, $settings, $enabled_providers)
    {
        // Show settings only if provider is enabled
        // If disabled, settings are hidden even if provider is used in paths/assistants
        return in_array($provider_id, $enabled_providers);
    }
    
    /**
     * Render universal provider settings UI based on manifest
     * This is used when provider doesn't implement custom render_settings_ui()
     * 
     * @param string $provider_id Provider ID
     * @param \PolyTrans\Providers\SettingsProviderInterface $settings_provider Settings provider instance
     * @param array $settings Current settings
     */
    private function render_universal_provider_ui($provider_id, $settings_provider, $settings)
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/tabs/universal-provider-ui.twig', [
            'provider_id' => $provider_id,
            'settings_provider' => $settings_provider,
            'settings' => $settings,
        ], false);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

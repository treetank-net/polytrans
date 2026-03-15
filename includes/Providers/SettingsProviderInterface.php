<?php

/**
 * Settings Provider Interface
 * Defines the contract for provider settings UI and management
 */

namespace PolyTrans\Providers;

if (!defined('ABSPATH')) {
    exit;
}

interface SettingsProviderInterface
{
    /**
     * Get the settings provider identifier (should match the translation provider ID)
     * @return string
     */
    public function get_provider_id();

    /**
     * Get the tab label for this provider's settings
     * @return string
     */
    public function get_tab_label();

    /**
     * Get the tab description (optional)
     * @return string
     */
    public function get_tab_description();

    /**
     * Get required JavaScript files for this settings provider
     * @return array Array of JS file paths (relative to plugin directory or URLs)
     */
    public function get_required_js_files();

    /**
     * Get required CSS files for this settings provider
     * @return array Array of CSS file paths (relative to plugin directory or URLs)
     */
    public function get_required_css_files();

    /**
     * Get provider-specific settings keys that this provider manages
     * @return array Array of setting keys that belong to this provider
     */
    public function get_settings_keys();

    /**
     * Render the provider settings UI
     * 
     * If not implemented or returns empty output, universal UI will be used
     * based on provider manifest (API key + model selection if applicable).
     * 
     * @param array $settings Current settings
     * @param array $languages Available languages
     * @param array $language_names Language display names
     * @return void
     */
    public function render_settings_ui(array $settings, array $languages, array $language_names);

    /**
     * Validate and sanitize provider-specific settings from POST data
     * @param array $posted_data Raw POST data
     * @return array Sanitized settings array with keys from get_settings_keys()
     */
    public function validate_settings(array $posted_data);

    /**
     * Get default settings for this provider
     * @return array Default settings array
     */
    public function get_default_settings();

    /**
     * Check if the provider settings are properly configured
     * @param array $settings Current settings
     * @return bool True if properly configured, false otherwise
     */
    public function is_configured(array $settings);

    /**
     * Get configuration status message
     * @param array $settings Current settings
     * @return string Status message (empty if configured properly)
     */
    public function get_configuration_status(array $settings);

    /**
     * Enqueue additional scripts and styles (called when settings page is loaded)
     * @return void
     */
    public function enqueue_assets();

    /**
     * Get AJAX handlers that this provider needs to register
     * @return array Array of AJAX handler definitions [action => [callback, is_static]]
     */
    public function get_ajax_handlers();

    /**
     * Register provider-specific AJAX handlers
     * This method is called during plugin initialization
     * @return void
     */
    public function register_ajax_handlers();

    /**
     * Get provider manifest with capabilities and configuration
     * @param array $settings Current settings (for API keys, etc.)
     * @return array Manifest array with:
     *   - capabilities: array of strings - what this provider can do:
     *     * 'translation' - direct translation API
     *     * 'chat' - chat/completion API (all AI models, can be used for managed assistants)
     *     * 'assistants' - dedicated Assistants API (e.g., OpenAI Assistants, Gemini Agents)
     *       * Note: Not all providers have Assistants API - Claude and DeepSeek only support managed assistants via chat API
     *     * 'system_prompt' - provider supports system prompt in chat API (if 'chat' is present, 'system_prompt' indicates support)
     *       * If 'chat' is present but 'system_prompt' is not, managed assistants can only use user_message_template, not system_prompt
     *   - assistants_endpoint: URL to fetch assistants (if supports 'assistants' capability)
     *   - chat_endpoint: URL for chat/completion (if supports 'chat' or 'assistants' capability)
     *   - models_endpoint: URL to fetch available models (if supports 'chat' or 'assistants' capability)
     *   - auth_type: 'bearer', 'api_key', 'none'
     *   - auth_header: header name for auth (e.g., 'Authorization')
     *   - api_key_setting: setting key for API key (e.g., 'openai_api_key')
     *   - api_key_configured: bool - whether API key is set (for admin only)
     * 
     * Examples:
     *   - Google: ['translation'] (no chat API, no system prompt, no assistants)
     *   - OpenAI: ['assistants', 'chat', 'system_prompt'] (has Assistants API, chat API with system prompt support)
     *   - DeepSeek: ['chat', 'system_prompt'] (chat API with system prompt support, but NO Assistants API - use managed assistants)
     *   - Claude: ['chat', 'system_prompt'] (chat API with system prompt support, but NO Assistants API - use managed assistants)
     *   - Gemini: ['assistants', 'chat', 'system_prompt'] (has Agents API, chat API with system prompt support)
     */
    public function get_provider_manifest(array $settings);
    
    /**
     * Validate API key for this provider
     * @param string $api_key API key to validate
     * @return bool True if valid, false otherwise
     */
    public function validate_api_key(string $api_key): bool;
    
    /**
     * Load assistants from provider API
     * @param array $settings Current settings (for API keys, etc.)
     * @return array Array of assistants [['id' => 'asst_xxx', 'name' => '...', 'model' => '...', 'provider' => '...'], ...]
     */
    public function load_assistants(array $settings): array;
    
    /**
     * Load available models from provider API (optional)
     * @param array $settings Current settings (for API keys, etc.)
     * @return array Grouped models ['Group Name' => ['model_id' => 'Model Name', ...], ...] or empty array if not supported
     */
    public function load_models(array $settings): array;
}

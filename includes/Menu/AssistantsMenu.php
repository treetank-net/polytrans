<?php

/**
 * AI Assistants Menu
 *
 * Handles the admin menu page for managing AI assistants.
 * Part of Phase 1: AI Assistants Management System.
 */

namespace PolyTrans\Menu;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantExecutor;
use PolyTrans\Assistants\AssistantMigration;
use PolyTrans\Templating\TemplateRenderer;
use PolyTrans\Providers\SettingsProviderInterface;

if (!defined('ABSPATH')) {
    exit;
}

class AssistantsMenu
{
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_polytrans_save_assistant', [$this, 'ajax_save_assistant']);
        add_action('wp_ajax_polytrans_delete_assistant', [$this, 'ajax_delete_assistant']);
        add_action('wp_ajax_polytrans_get_assistant', [$this, 'ajax_get_assistant']);
        add_action('wp_ajax_polytrans_migrate_workflows', [$this, 'ajax_migrate_workflows']);
        add_action('wp_ajax_polytrans_get_provider_models', [$this, 'ajax_get_provider_models']);
        add_action('wp_ajax_polytrans_test_assistant', [$this, 'ajax_test_assistant']);
        add_action('wp_ajax_polytrans_get_recent_posts_for_assistant_test', [$this, 'ajax_get_recent_posts_for_assistant_test']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'polytrans',
            __('AI Assistants', 'polytrans'),
            __('AI Assistants', 'polytrans'),
            'manage_options',
            'polytrans-assistants',
            [$this, 'render_assistants_page']
        );
    }

    /**
     * Enqueue assets for assistant management
     */
    public function enqueue_assets($hook_suffix)
    {
        // Enqueue only on assistants page
        if (strpos($hook_suffix, 'polytrans-assistants') === false) {
            return;
        }

        // Enqueue prompt editor module (reusable component)
        wp_enqueue_script(
            'polytrans-prompt-editor',
            POLYTRANS_PLUGIN_URL . 'assets/js/prompt-editor.js',
            ['jquery'],
            POLYTRANS_VERSION,
            true
        );

        wp_enqueue_script(
            'polytrans-assistants',
            POLYTRANS_PLUGIN_URL . 'assets/js/assistants-admin.js',
            ['jquery', 'wp-util', 'polytrans-prompt-editor'],
            POLYTRANS_VERSION,
            true
        );

        // Enqueue postprocessing CSS for shared prompt editor styles
        wp_enqueue_style(
            'polytrans-postprocessing',
            POLYTRANS_PLUGIN_URL . 'assets/css/postprocessing-admin.css',
            [],
            POLYTRANS_VERSION
        );

        wp_enqueue_style(
            'polytrans-assistants',
            POLYTRANS_PLUGIN_URL . 'assets/css/assistants-admin.css',
            ['polytrans-postprocessing'],
            POLYTRANS_VERSION
        );

        // Get current model and provider from assistant being edited (if any)
        $current_model = '';
        $current_provider = 'openai';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        if (isset($_GET['action']) && sanitize_text_field(wp_unslash($_GET['action'])) === 'edit' && isset($_GET['id'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
            $assistant_id = intval($_GET['id']);
            $assistant = AssistantManager::get_assistant($assistant_id);
            if ($assistant) {
                if (!empty($assistant['model'])) {
                    $current_model = $assistant['model'];
                }
                if (!empty($assistant['provider'])) {
                    $current_provider = $assistant['provider'];
                }
            }
        }

        // Localize script
        wp_localize_script('polytrans-assistants', 'polytransAssistants', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('polytrans_assistants'),
            'models' => $this->get_model_options($current_provider, $current_model),
            'selected_model' => $current_model,
            'current_provider' => $current_provider,
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this assistant?', 'polytrans'),
                'saveSuccess' => __('Assistant saved successfully.', 'polytrans'),
                'saveError' => __('Failed to save assistant.', 'polytrans'),
                'deleteSuccess' => __('Assistant deleted successfully.', 'polytrans'),
                'deleteError' => __('Failed to delete assistant.', 'polytrans'),
                'loading' => __('Loading...', 'polytrans'),
                'requiredField' => __('This field is required.', 'polytrans'),
            ],
            'providers' => [
                'openai' => [
                    'label' => __('OpenAI', 'polytrans'),
                    'models' => ['gpt-4', 'gpt-4-turbo-preview', 'gpt-3.5-turbo']
                ],
                'claude' => [
                    'label' => __('Claude (Anthropic)', 'polytrans'),
                    'models' => ['claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku']
                ],
                'gemini' => [
                    'label' => __('Gemini (Google)', 'polytrans'),
                    'models' => ['gemini-pro', 'gemini-pro-vision']
                ]
            ],
            'responseFormats' => [
                'text' => __('Text', 'polytrans'),
                'json' => __('JSON', 'polytrans')
            ]
        ]);
    }

    /**
     * Enqueue assets for assistant editor
     *
     * @param array $assistant Assistant data
     * @param array $models Available models
     * @param string $current_provider Current provider ID
     * @param string $current_model Current model ID
     * @param array $provider_manifests Provider manifests
     * @param array $providers_js Providers data for JS
     * @return void
     */
    private function enqueue_editor_assets($assistant, $models, $current_provider, $current_model, $provider_manifests, $providers_js)
    {
        // Enqueue prompt editor module (reusable component)
        wp_enqueue_script(
            'polytrans-prompt-editor',
            POLYTRANS_PLUGIN_URL . 'assets/js/prompt-editor.js',
            ['jquery'],
            POLYTRANS_VERSION,
            true
        );

        wp_enqueue_script(
            'polytrans-assistants',
            POLYTRANS_PLUGIN_URL . 'assets/js/assistants-admin.js',
            ['jquery', 'wp-util', 'polytrans-prompt-editor'],
            POLYTRANS_VERSION,
            true
        );

        // Enqueue postprocessing CSS for shared prompt editor styles
        wp_enqueue_style(
            'polytrans-postprocessing',
            POLYTRANS_PLUGIN_URL . 'assets/css/postprocessing-admin.css',
            [],
            POLYTRANS_VERSION
        );

        wp_enqueue_style(
            'polytrans-assistants',
            POLYTRANS_PLUGIN_URL . 'assets/css/assistants-admin.css',
            ['polytrans-postprocessing'],
            POLYTRANS_VERSION
        );

        // Localize script
        wp_localize_script('polytrans-assistants', 'polytransAssistants', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('polytrans_assistants'),
            'models' => $models,
            'selected_model' => $current_model,
            'current_provider' => $current_provider,
            'providerManifests' => $provider_manifests,
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this assistant?', 'polytrans'),
                'saveSuccess' => __('Assistant saved successfully.', 'polytrans'),
                'saveError' => __('Failed to save assistant.', 'polytrans'),
                'deleteSuccess' => __('Assistant deleted successfully.', 'polytrans'),
                'deleteError' => __('Failed to delete assistant.', 'polytrans'),
                'loading' => __('Loading...', 'polytrans'),
                'requiredField' => __('This field is required.', 'polytrans'),
            ],
            'providers' => $providers_js,
            'responseFormats' => [
                'text' => __('Text', 'polytrans'),
                'json' => __('JSON', 'polytrans')
            ]
        ]);

        // Add inline script with assistant data
        wp_add_inline_script('polytrans-assistants', 'window.polytransAssistantData = ' . wp_json_encode($assistant) . ';', 'after');
    }

    /**
     * Render assistants management page
     */
    public function render_assistants_page()
    {
        // Get current action
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $assistant_id = isset($_GET['assistant_id']) ? intval($_GET['assistant_id']) : 0;

        switch ($action) {
            case 'edit':
            case 'new':
                $this->render_assistant_editor($assistant_id, $action === 'new');
                break;

            case 'test':
                $this->render_assistant_tester($assistant_id);
                break;

            default:
                $this->render_assistant_list();
                break;
        }
    }

    /**
     * Render assistant tester
     *
     * @param int $assistant_id Assistant ID
     * @return void
     */
    private function render_assistant_tester($assistant_id)
    {
        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            wp_die(esc_html__('Assistant not found.', 'polytrans'));
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/assistants/tester.twig', [
            'assistant' => $assistant,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render assistant list
     */
    private function render_assistant_list()
    {
        $assistants = AssistantManager::get_all_assistants();
        $migration_status = AssistantMigration::get_migration_status();

        // Get enabled providers to check if provider is disabled
        $settings = get_option('polytrans_settings', []);
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];

        // Map assistant data for display
        foreach ($assistants as &$assistant) {
            // Extract model from api_parameters
            $assistant['model'] = $assistant['api_parameters']['model'] ?? '';
            // Map expected_format to response_format
            $assistant['response_format'] = $assistant['expected_format'] ?? 'text';

            // Check if model is empty and if there's a default model in settings
            $assistant['has_default_model'] = false;
            if (empty($assistant['model'])) {
                $provider_id = $assistant['provider'];
                $model_setting_key = $provider_id . '_model';
                $default_model = $settings[$model_setting_key] ?? '';
                $assistant['has_default_model'] = !empty($default_model);
            }
        }
        unset($assistant); // Break reference

        // Render using Twig template
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/assistants/list.twig', [
            'assistants' => $assistants,
            'migration_status' => $migration_status,
            'enabled_providers' => $enabled_providers,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render assistant editor
     */
    private function render_assistant_editor($assistant_id, $is_new = false)
    {
        // Get assistant data
        if ($is_new) {
            $assistant = [
                'id' => 0,
                'name' => '',
                'provider' => 'openai',
                'model' => '',
                'system_prompt' => '',
                'user_message_template' => '',
                'response_format' => 'text',
                'expected_output_schema' => null,
                'config' => [
                    'temperature' => 0.7
                ]
            ];
        } else {
            $assistant = AssistantManager::get_assistant($assistant_id);
            if (!$assistant) {
                wp_die(esc_html__('Assistant not found.', 'polytrans'));
            }

            // Map expected_format to response_format for UI consistency
            if (!isset($assistant['response_format']) && isset($assistant['expected_format'])) {
                $assistant['response_format'] = $assistant['expected_format'];
            }

            // Map api_parameters to config for UI consistency
            if (isset($assistant['api_parameters']) && is_array($assistant['api_parameters'])) {
                $assistant['config'] = [
                    'temperature' => $assistant['api_parameters']['temperature'] ?? 0.7
                ];
                // Also map model for easier access
                if (isset($assistant['api_parameters']['model'])) {
                    $assistant['model'] = $assistant['api_parameters']['model'];
                }
            }
        }

        // Get available providers that support assistants
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $settings = get_option('polytrans_settings', []);
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        $all_providers = $registry->get_providers();

        $available_assistant_providers = [];
        $provider_manifests = [];
        foreach ($all_providers as $provider_id => $provider) {
            if (!in_array($provider_id, $enabled_providers)) {
                continue;
            }

            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                if (method_exists($settings_provider, 'get_provider_manifest')) {
                    $manifest = $settings_provider->get_provider_manifest($settings);
                    $capabilities = $manifest['capabilities'] ?? [];
                    if (in_array('chat', $capabilities) || in_array('assistants', $capabilities)) {
                        $available_assistant_providers[$provider_id] = [
                            'id' => $provider_id,
                            'name' => $provider->get_name(),
                        ];
                        $provider_manifests[$provider_id] = [
                            'capabilities' => $capabilities,
                            'supports_system_prompt' => in_array('system_prompt', $capabilities),
                        ];
                    }
                }
            }
        }

        // If no providers found, fallback to hardcoded list
        if (empty($available_assistant_providers)) {
            $available_assistant_providers = [
                'openai' => ['id' => 'openai', 'name' => 'OpenAI'],
                'claude' => ['id' => 'claude', 'name' => 'Claude'],
                'gemini' => null,
            ];
        }

        // Get models for current provider
        $current_provider = $assistant['provider'] ?? 'openai';
        $current_model = $assistant['model'] ?? '';
        $models = $this->get_model_options($current_provider, $current_model);

        // Prepare providers list for JS (legacy format)
        $providers_js = [
            'openai' => [
                'label' => __('OpenAI', 'polytrans'),
                'models' => ['gpt-4', 'gpt-4-turbo-preview', 'gpt-3.5-turbo']
            ],
            'claude' => [
                'label' => __('Claude (Anthropic)', 'polytrans'),
                'models' => ['claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku']
            ],
            'gemini' => [
                'label' => __('Gemini (Google)', 'polytrans'),
                'models' => ['gemini-pro', 'gemini-pro-vision']
            ]
        ];

        // Enqueue assets before rendering (WordPress requires assets to be enqueued before page output)
        $this->enqueue_editor_assets($assistant, $models, $current_provider, $current_model, $provider_manifests, $providers_js);

        // Format expected_output_schema as JSON string if it's an array
        $expected_output_schema_json = '';
        if (!empty($assistant['expected_output_schema'])) {
            if (is_array($assistant['expected_output_schema'])) {
                $expected_output_schema_json = wp_json_encode($assistant['expected_output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                // Already a JSON string, try to decode and re-encode for formatting
                $decoded = json_decode($assistant['expected_output_schema'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $expected_output_schema_json = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    // If it's already a formatted JSON string, use it as-is
                    $expected_output_schema_json = $assistant['expected_output_schema'];
                }
            }
        }

        // Render using Twig template
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/assistants/editor.twig', [
            'assistant' => $assistant,
            'is_new' => $is_new,
            'available_assistant_providers' => $available_assistant_providers,
            'provider_manifests' => $provider_manifests,
            'models' => $models,
            'current_provider' => $current_provider,
            'current_model' => $current_model,
            'providers' => $providers_js,
            'expected_output_schema_json' => $expected_output_schema_json,
            'polytrans_plugin_url' => POLYTRANS_PLUGIN_URL,
            'polytrans_version' => POLYTRANS_VERSION,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * AJAX: Save assistant
     */
    public function ajax_save_assistant()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $system_prompt = isset($_POST['system_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['system_prompt'])) : '';
        $user_message_template = isset($_POST['user_message_template']) ? sanitize_textarea_field(wp_unslash($_POST['user_message_template'])) : '';
        $response_format = isset($_POST['response_format']) ? sanitize_text_field(wp_unslash($_POST['response_format'])) : 'text';
        $expected_output_schema = isset($_POST['expected_output_schema']) ? sanitize_textarea_field(wp_unslash($_POST['expected_output_schema'])) : null;
        $config = isset($_POST['config']) ? array_map('sanitize_text_field', wp_unslash($_POST['config'])) : [];

        // Check if provider supports system prompt
        $supports_system_prompt = true; // Default to true for backward compatibility
        $settings = get_option('polytrans_settings', []);
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider_obj = $registry->get_provider($provider);
        if ($provider_obj) {
            $settings_provider_class = $provider_obj->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                    if (method_exists($settings_provider, 'get_provider_manifest')) {
                        $manifest = $settings_provider->get_provider_manifest($settings);
                        $capabilities = $manifest['capabilities'] ?? [];
                        // Check for system_prompt capability, fallback to supports_system_prompt for backward compatibility
                        $supports_system_prompt = in_array('system_prompt', $capabilities) || ($manifest['supports_system_prompt'] ?? true);
                    }
            }
        }

        // Validate required fields
        // System prompt is only required if provider supports it
        if (empty($name) || empty($provider)) {
            wp_send_json_error(['message' => __('Required fields are missing.', 'polytrans')]);
        }

        // If provider doesn't support system prompt, ensure it's empty
        if (!$supports_system_prompt) {
            $system_prompt = ''; // Clear system prompt if provider doesn't support it
        } elseif (empty($system_prompt)) {
            // Only require system prompt if provider supports it
            wp_send_json_error(['message' => __('System Instructions are required for this provider.', 'polytrans')]);
        }

        // Prepare API parameters
        $api_parameters = [
            'model' => $model,
            'temperature' => $config['temperature'] ?? 0.7
        ];

        // Prepare assistant data matching Assistant Manager structure
        $assistant_data = [
            'name' => $name,
            'provider' => $provider,
            'system_prompt' => $system_prompt,
            'user_message_template' => $user_message_template,
            'api_parameters' => wp_json_encode($api_parameters),
            'expected_format' => $response_format,
            'expected_output_schema' => $expected_output_schema,
            'output_variables' => null
        ];

        // Save or update assistant
        try {
            if ($assistant_id > 0) {
                $result = AssistantManager::update_assistant($assistant_id, $assistant_data);
            } else {
                $result = AssistantManager::create_assistant($assistant_data);
                $assistant_id = $result;
            }

            if ($result) {
                wp_send_json_success([
                    'message' => __('Assistant saved successfully.', 'polytrans'),
                    'assistant_id' => $assistant_id
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to save assistant.', 'polytrans')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX: Delete assistant
     */
    public function ajax_delete_assistant()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Invalid assistant ID.', 'polytrans')]);
        }

        try {
            $result = AssistantManager::delete_assistant($assistant_id);

            if ($result) {
                wp_send_json_success(['message' => __('Assistant deleted successfully.', 'polytrans')]);
            } else {
                wp_send_json_error(['message' => __('Failed to delete assistant.', 'polytrans')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * AJAX: Get assistant
     */
    public function ajax_get_assistant()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Invalid assistant ID.', 'polytrans')]);
        }

        try {
            $assistant = AssistantManager::get_assistant($assistant_id);

            if ($assistant) {
                wp_send_json_success(['assistant' => $assistant]);
            } else {
                wp_send_json_error(['message' => __('Assistant not found.', 'polytrans')]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }


    /**
     * AJAX: Migrate workflows to managed assistants
     */
    public function ajax_migrate_workflows()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        try {
            $stats = AssistantMigration::migrate_workflows_to_managed_assistants();

            if (!empty($stats['errors'])) {
                wp_send_json_error([
                    'message' => __('Migration completed with errors.', 'polytrans'),
                    'stats' => $stats
                ]);
            } else {
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %1$d: number of steps migrated, %2$d: number of assistants created */
                        __('Migration completed successfully! Migrated %1$d steps and created %2$d assistants.', 'polytrans'),
                        $stats['steps_migrated'],
                        $stats['assistants_created']
                    ),
                    'stats' => $stats
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => esc_html($e->getMessage())]);
        }
    }

    /**
     * Get model options for select dropdown based on provider
     *
     * @param string|null $provider_id Provider ID (e.g., 'openai', 'claude')
     * @param string|null $selected_model Currently selected model (for backward compatibility)
     * @return array Grouped model options
     */
    private function get_model_options($provider_id = null, $selected_model = null, $force_refresh = false)
    {
        // Default to OpenAI for backward compatibility
        if (empty($provider_id)) {
            $provider_id = 'openai';
        }

        $registry = \PolyTrans_Provider_Registry::get_instance();
        $provider = $registry->get_provider($provider_id);

        if (!$provider) {
            return $this->get_fallback_models();
        }

        $settings_provider_class = $provider->get_settings_provider_class();
        if (!$settings_provider_class || !class_exists($settings_provider_class)) {
            return $this->get_fallback_models();
        }

        try {
            $settings_provider = new $settings_provider_class();
            $settings = get_option('polytrans_settings', []);

            // Check if provider implements SettingsProviderInterface and has load_models method
            if ($settings_provider instanceof SettingsProviderInterface) {
                if (method_exists($settings_provider, 'load_models')) {
                    // Pass force_refresh parameter if method signature supports it
                    // For now, we'll check if cache should be cleared before calling
                    if ($force_refresh) {
                        // Clear cache for this provider before loading
                        $api_key_setting = $this->get_api_key_setting_key($provider_id);
                        $api_key = $settings[$api_key_setting] ?? '';
                        if (!empty($api_key)) {
                            $cache_key = 'polytrans_' . $provider_id . '_models_' . md5($api_key);
                            delete_transient($cache_key);
                        }
                    }
                    $models = $settings_provider->load_models($settings);
                    if (!empty($models) && is_array($models)) {
                        return $models;
                    }
                }
            }

            // Legacy: Check if provider has get_grouped_models method (for backward compatibility)
            if (method_exists($settings_provider, 'get_grouped_models')) {
                return $settings_provider->get_grouped_models($selected_model);
            }
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[PolyTrans] Failed to get models for provider $provider_id: " . $e->getMessage());
        }

        // Provider-specific fallback based on provider_id
        return $this->get_fallback_models($provider_id);
    }

    /**
     * Get API key setting key for provider
     *
     * @param string $provider_id Provider ID
     * @return string API key setting key
     */
    private function get_api_key_setting_key($provider_id)
    {
        return $provider_id . '_api_key';
    }

    /**
     * Get fallback model options
     *
     * @param string|null $provider_id Provider ID to get provider-specific fallback
     * @return array Fallback model options (empty - no hardcoded models)
     */
    private function get_fallback_models($provider_id = null)
    {
        // No fallback models - return empty array
        // Models must be loaded from API
        return [];
    }

    /**
     * AJAX handler for getting provider models
     */
    public function ajax_get_provider_models()
    {
        // Check nonce - accept multiple nonce types for compatibility
        // Universal JS uses polytrans_nonce, AssistantsMenu uses polytrans_assistants
        $nonce_check = false;
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
            // Try different nonce types:
            // 1. polytrans_assistants (from AssistantsMenu)
            // 2. polytrans_nonce (from SettingsMenu - Universal JS)
            // 3. polytrans_openai_nonce (backward compatibility)
            $nonce_check = wp_verify_nonce($nonce, 'polytrans_assistants') ||
                          wp_verify_nonce($nonce, 'polytrans_nonce') ||
                          wp_verify_nonce($nonce, 'polytrans_openai_nonce');
        }

        if (!$nonce_check) {
            wp_send_json_error(__('Security check failed.', 'polytrans'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have sufficient permissions to access this page.', 'polytrans'));
            return;
        }

        $provider_id = isset($_POST['provider_id']) ? sanitize_text_field(wp_unslash($_POST['provider_id'])) : 'openai';
        $selected_model = isset($_POST['selected_model']) ? sanitize_text_field(wp_unslash($_POST['selected_model'])) : '';
        $force_refresh = isset($_POST['force_refresh']) && sanitize_text_field(wp_unslash($_POST['force_refresh'])) === '1';

        // Get models for the specified provider
        $models = $this->get_model_options($provider_id, $selected_model, $force_refresh);

        wp_send_json_success([
            'models' => $models,
            'selected_model' => $selected_model
        ]);
    }

    /**
     * AJAX: Test managed assistant with a single translation-like input.
     */
    public function ajax_test_assistant()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $source_language = isset($_POST['source_language']) ? sanitize_text_field(wp_unslash($_POST['source_language'])) : 'en';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : 'pl';
        $selected_post_id = isset($_POST['selected_post_id']) ? intval($_POST['selected_post_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Invalid assistant ID.', 'polytrans')]);
        }

        if ($selected_post_id <= 0 && trim(wp_strip_all_tags($content)) === '') {
            wp_send_json_error(['message' => __('Test content is required.', 'polytrans')]);
        }

        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            wp_send_json_error(['message' => __('Assistant not found.', 'polytrans')]);
        }

        if ($selected_post_id > 0) {
            $context = $this->build_assistant_test_context_from_post($selected_post_id, $source_language, $target_language);
            if ($context === null) {
                wp_send_json_error(['message' => __('Selected post not found.', 'polytrans')]);
            }
        } else {
            if (trim(wp_strip_all_tags($content)) === '') {
                wp_send_json_error(['message' => __('Test content is required.', 'polytrans')]);
            }
            $context = $this->build_assistant_test_context($title, $content, $source_language, $target_language);
        }

        $start_time = microtime(true);
        $result = AssistantExecutor::execute($assistant_id, $context);
        $execution_time = microtime(true) - $start_time;

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
            ]);
        }

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['error'] ?? __('Assistant execution failed.', 'polytrans'),
            ]);
        }

        wp_send_json_success([
            'assistant_id' => $assistant_id,
            'assistant_name' => $assistant['name'],
            'provider' => $result['provider'] ?? $assistant['provider'],
            'model' => $result['model'] ?? ($assistant['api_parameters']['model'] ?? ''),
            'expected_format' => $assistant['expected_format'] ?? 'text',
            'output' => $result['output'] ?? '',
            'usage' => $result['usage'] ?? [],
            'execution_time' => $execution_time,
            'interpolated_system_prompt' => $result['interpolated_system_prompt'] ?? null,
            'interpolated_user_message' => $result['interpolated_user_message'] ?? null,
            'context' => $this->build_assistant_test_context_compact($context),
            'context_full' => $context,
        ]);
    }

    /**
     * AJAX: Load recent posts for assistant tester (same shape as workflow tester).
     */
    public function ajax_get_recent_posts_for_assistant_test()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $language = isset($_POST['language']) ? sanitize_text_field(wp_unslash($_POST['language'])) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_polytrans_original_post_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_polytrans_original_post_id',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ];

        if ($language && function_exists('pll_get_post_language')) {
            $args['lang'] = $language;
            $args['tax_query'][] = [
                'taxonomy' => 'language',
                'field' => 'slug',
                'terms' => $language,
            ];
        }

        $posts = get_posts($args);
        $results = [];
        foreach ($posts as $post) {
            $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);
            $original_post_id = get_post_meta($post->ID, '_polytrans_original_post_id', true);
            $is_translation = !empty($original_post_id);

            $custom_fields = [];
            $common_meta_keys = [
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                'custom_field_example',
                '_featured_text',
                '_subtitle'
            ];
            foreach ($common_meta_keys as $meta_key) {
                $meta_value = get_post_meta($post->ID, $meta_key, true);
                if (!empty($meta_value)) {
                    $custom_fields[$meta_key] = $meta_value;
                }
            }

            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_date' => $post->post_date,
                'is_translation' => $is_translation,
                'original_post_id' => $original_post_id,
                'meta' => $custom_fields,
                'description' => wp_trim_words($excerpt, 15) . '...'
            ];
        }

        wp_send_json_success(['posts' => $results]);
    }

    /**
     * Build a translation-shaped context for assistant tests.
     *
     * @param string $title Source title
     * @param string $content Source content
     * @param string $source_language Source language code
     * @param string $target_language Target language code
     * @return array
     */
    private function build_assistant_test_context($title, $content, $source_language, $target_language)
    {
        $plain_content = wp_strip_all_tags($content);
        $excerpt = function_exists('wp_trim_words') ? wp_trim_words($plain_content, 35, '...') : substr($plain_content, 0, 240);
        $slug = sanitize_title($title);
        $word_count = str_word_count($plain_content);
        $char_count = strlen($plain_content);

        $post_data = [
            'ID' => 0,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_name' => $slug,
            'post_status' => 'test',
            'post_type' => 'post',
            'id' => 0,
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'slug' => $slug,
            'status' => 'test',
            'type' => 'post',
            'meta' => [],
            'word_count' => $word_count,
            'character_count' => $char_count,
        ];

        return [
            'source_language' => $source_language,
            'target_language' => $target_language,
            'translation_service' => 'managed_assistant_test',
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            // Keep real translation-shaped objects for prompts using translated.title/content/meta
            'original' => $post_data,
            'translated' => $post_data,
            // Keep raw string aliases for prompts that interpolate plain text fields
            'original_text' => $content,
            'translated_text' => $content,
            'original_post' => $post_data,
            'translated_post' => $post_data,
            // Structured namespace for future eval/refinement prompts.
            'payload' => [
                'post' => $post_data,
                'translation' => [
                    'source_language' => $source_language,
                    'target_language' => $target_language,
                    'service' => 'managed_assistant_test',
                ],
                'runtime' => [
                    'test_mode' => true,
                ],
            ],
            'test_mode' => true,
        ];
    }

    /**
     * Build assistant test context from a real WordPress post including metadata.
     *
     * @param int $post_id Post ID
     * @param string $source_language Source language code
     * @param string $target_language Target language code
     * @return array|null
     */
    private function build_assistant_test_context_from_post($post_id, $source_language, $target_language)
    {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        $plain_content = wp_strip_all_tags($post->post_content);
        $excerpt = !empty($post->post_excerpt)
            ? $post->post_excerpt
            : (function_exists('wp_trim_words') ? wp_trim_words($plain_content, 35, '...') : substr($plain_content, 0, 240));

        $meta = $this->get_post_meta_for_assistant_test($post_id);
        $slug = !empty($post->post_name) ? $post->post_name : sanitize_title($post->post_title);
        $word_count = str_word_count($plain_content);
        $char_count = strlen($plain_content);

        $post_data = [
            'ID' => (int) $post->ID,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $excerpt,
            'post_name' => $slug,
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $excerpt,
            'slug' => $slug,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'meta' => $meta,
            'word_count' => $word_count,
            'character_count' => $char_count,
        ];

        return [
            'source_language' => $source_language,
            'target_language' => $target_language,
            'translation_service' => 'managed_assistant_test',
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $excerpt,
            'original' => $post_data,
            'translated' => $post_data,
            'original_text' => $post->post_content,
            'translated_text' => $post->post_content,
            'original_post' => $post_data,
            'translated_post' => $post_data,
            'payload' => [
                'post' => $post_data,
                'translation' => [
                    'source_language' => $source_language,
                    'target_language' => $target_language,
                    'service' => 'managed_assistant_test',
                ],
                'runtime' => [
                    'test_mode' => true,
                ],
            ],
            'test_mode' => true,
        ];
    }

    /**
     * Collect post meta for assistant test context.
     *
     * @param int $post_id Post ID
     * @return array
     */
    private function get_post_meta_for_assistant_test($post_id)
    {
        $all_meta = get_post_meta($post_id);
        $meta_data = [];

        foreach ($all_meta as $key => $values) {
            // Skip WordPress internal editing/locking metadata.
            if (strpos($key, '_wp_') === 0 || strpos($key, '_edit_') === 0) {
                continue;
            }

            $value = (is_array($values) && count($values) === 1) ? $values[0] : $values;

            // Keep prompt-friendly scalar values; JSON-encode nested structures.
            if (is_array($value) || is_object($value)) {
                $meta_data[$key] = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $meta_data[$key] = $value;
            }
        }

        return $meta_data;
    }

    /**
     * Build a compact context for UI display without duplicated compatibility aliases.
     *
     * @param array $context Full execution context
     * @return array
     */
    private function build_assistant_test_context_compact($context)
    {
        return [
            'source_language' => $context['source_language'] ?? '',
            'target_language' => $context['target_language'] ?? '',
            'translation_service' => $context['translation_service'] ?? '',
            'payload' => $context['payload'] ?? [],
            'test_mode' => !empty($context['test_mode']),
        ];
    }
}

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
use PolyTrans\Assistants\Testing\AssistantRefinementService;
use PolyTrans\PromptRefinement\DescriptionGeneratorService;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\Templating\TemplateRenderer;
use PolyTrans\Providers\SettingsProviderInterface;
use PolyTrans\Testing\PostTestContextBuilder;
use PolyTrans\Testing\RecentPostsProvider;

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
        add_action('wp_ajax_polytrans_run_assistant_refinement_post', [$this, 'ajax_run_assistant_refinement_post']);
        add_action('wp_ajax_polytrans_evaluate_assistant_run', [$this, 'ajax_evaluate_assistant_run']);
        add_action('wp_ajax_polytrans_refine_assistant_post', [$this, 'ajax_refine_assistant_post']);
        add_action('wp_ajax_polytrans_adjust_assistant_prompt', [$this, 'ajax_adjust_assistant_prompt']);
        add_action('wp_ajax_polytrans_apply_assistant_prompt_pack', [$this, 'ajax_apply_assistant_prompt_pack']);
        add_action('wp_ajax_polytrans_generate_assistant_description', [$this, 'ajax_generate_assistant_description']);
        add_action('wp_ajax_polytrans_save_assistant_description', [$this, 'ajax_save_assistant_description']);
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
            ],
            'descriptionPrompts' => [
                'system' => PromptRefinementSettings::descriptionGeneratorSystem(),
                'assistant' => PromptRefinementSettings::assistantDescriptionGenerator(),
            ],
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
            ],
            'descriptionPrompts' => [
                'system' => PromptRefinementSettings::descriptionGeneratorSystem(),
                'assistant' => PromptRefinementSettings::assistantDescriptionGenerator(),
            ],
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

        wp_add_inline_script(
            'polytrans-assistants',
            'window.polytransAssistantData = ' . wp_json_encode($assistant) . ';',
            'after'
        );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/assistants/tester.twig', [
            'assistant' => $assistant,
            'default_evaluator_system_prompt' => PromptRefinementSettings::assistantEvaluatorSystem(),
            'default_evaluator_prompt_template' => PromptRefinementSettings::assistantEvaluator(),
            'default_adjuster_system_prompt' => PromptRefinementSettings::assistantAdjusterSystem(),
            'default_adjuster_prompt_template' => PromptRefinementSettings::assistantAdjuster(),
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
                'description' => '',
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
        $description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
        $expected_output_schema = null;
        if (isset($_POST['expected_output_schema'])) {
            $expected_output_schema = sanitize_textarea_field(wp_unslash($_POST['expected_output_schema']));
            if (trim($expected_output_schema) === '') {
                $expected_output_schema = null;
            }
        }
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
            'description' => $description,
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
            }

            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'errors' => $result->get_error_data(),
                ]);
            }

            if ($result) {
                if ($assistant_id <= 0) {
                    $assistant_id = (int) $result;
                }

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
            $context = PostTestContextBuilder::fromPost($selected_post_id, $source_language, $target_language);
            if ($context === null) {
                wp_send_json_error(['message' => __('Selected post not found.', 'polytrans')]);
            }
        } else {
            if (trim(wp_strip_all_tags($content)) === '') {
                wp_send_json_error(['message' => __('Test content is required.', 'polytrans')]);
            }
            $context = PostTestContextBuilder::fromText($title, $content, $source_language, $target_language);
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
            'context' => PostTestContextBuilder::compact($context),
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

        wp_send_json_success(['posts' => RecentPostsProvider::getRecentPosts($language, $limit)]);
    }

    /**
     * AJAX: Execute assistant step for one refinement post and persist the run in transient storage.
     */
    public function ajax_run_assistant_refinement_post()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $selected_post_id = isset($_POST['selected_post_id']) ? intval($_POST['selected_post_id']) : 0;
        $source_language = isset($_POST['source_language']) ? sanitize_text_field(wp_unslash($_POST['source_language'])) : 'en';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : 'pl';
        $override_system_prompt = array_key_exists('override_system_prompt', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_user_message_template = array_key_exists('override_user_message_template', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_expected_output_schema = array_key_exists('override_expected_output_schema', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;

        $result = (new AssistantRefinementService())->runPost(
            $assistant_id,
            $selected_post_id,
            $source_language,
            $target_language,
            $override_system_prompt,
            $override_user_message_template,
            $override_expected_output_schema
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Evaluate a previously executed assistant run by run_id.
     */
    public function ajax_evaluate_assistant_run()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        $evaluator_system_prompt = isset($_POST['evaluator_system_prompt']) ? wp_unslash($_POST['evaluator_system_prompt']) : '';
        $evaluator_prompt_template = isset($_POST['evaluator_prompt_template']) ? wp_unslash($_POST['evaluator_prompt_template']) : '';

        $result = (new AssistantRefinementService())->evaluateRun(
            $assistant_id,
            $run_id,
            $criteria,
            $prompt_objective,
            $evaluator_prompt_template,
            is_string($evaluator_system_prompt) ? $evaluator_system_prompt : ''
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Backward-compatible endpoint combining assistant execution + evaluation.
     */
    public function ajax_refine_assistant_post()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $selected_post_id = isset($_POST['selected_post_id']) ? intval($_POST['selected_post_id']) : 0;
        $source_language = isset($_POST['source_language']) ? sanitize_text_field(wp_unslash($_POST['source_language'])) : 'en';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : 'pl';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        $evaluator_system_prompt = isset($_POST['evaluator_system_prompt']) ? wp_unslash($_POST['evaluator_system_prompt']) : '';
        $evaluator_prompt_template = isset($_POST['evaluator_prompt_template']) ? wp_unslash($_POST['evaluator_prompt_template']) : '';
        $override_system_prompt = array_key_exists('override_system_prompt', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_user_message_template = array_key_exists('override_user_message_template', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_expected_output_schema = array_key_exists('override_expected_output_schema', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['override_expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;

        $result = (new AssistantRefinementService())->refinePost(
            $assistant_id,
            $selected_post_id,
            $source_language,
            $target_language,
            $criteria,
            $prompt_objective,
            $evaluator_prompt_template,
            is_string($evaluator_system_prompt) ? $evaluator_system_prompt : '',
            $override_system_prompt,
            $override_user_message_template,
            $override_expected_output_schema
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Build prompt adjustment proposal from per-post refinement evaluations.
     */
    public function ajax_adjust_assistant_prompt()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        $adjuster_system_prompt = isset($_POST['adjuster_system_prompt']) ? wp_unslash($_POST['adjuster_system_prompt']) : '';
        $adjuster_prompt_template = isset($_POST['adjuster_prompt_template']) ? wp_unslash($_POST['adjuster_prompt_template']) : '';
        $evaluations_payload = $_POST['evaluations'] ?? '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
        $current_system_prompt = array_key_exists('current_system_prompt', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['current_system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $current_user_message_template = array_key_exists('current_user_message_template', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['current_user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $current_expected_output_schema = array_key_exists('current_expected_output_schema', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? wp_unslash($_POST['current_expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;

        $result = (new AssistantRefinementService())->adjustPrompt(
            $assistant_id,
            $criteria,
            $prompt_objective,
            $adjuster_prompt_template,
            is_string($adjuster_system_prompt) ? $adjuster_system_prompt : '',
            $evaluations_payload,
            $current_system_prompt,
            $current_user_message_template,
            $current_expected_output_schema
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Apply prompt pack to assistant configuration.
     */
    public function ajax_apply_assistant_prompt_pack()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $system_prompt = array_key_exists('system_prompt', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? (string) wp_unslash($_POST['system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $user_message_template = array_key_exists('user_message_template', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? (string) wp_unslash($_POST['user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $expected_output_schema = array_key_exists('expected_output_schema', $_POST) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
            ? (string) wp_unslash($_POST['expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '{}';

        $result = (new AssistantRefinementService())->applyPromptPack(
            $assistant_id,
            $system_prompt,
            $user_message_template,
            $expected_output_schema
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Generate concise assistant description from assistant prompts.
     */
    public function ajax_generate_assistant_description()
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $stored_assistant = $assistant_id > 0 ? AssistantManager::get_assistant($assistant_id) : [];
        if (!is_array($stored_assistant)) {
            $stored_assistant = [];
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : (string) ($stored_assistant['provider'] ?? 'openai');
        $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $api_parameters = is_array($stored_assistant['api_parameters'] ?? null) ? $stored_assistant['api_parameters'] : [];
        if ($model !== '') {
            $api_parameters['model'] = $model;
        }
        $api_parameters['temperature'] = 0.2;

        $assistant = array_merge($stored_assistant, [
            'id' => $assistant_id,
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : (string) ($stored_assistant['name'] ?? ''),
            'description' => isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : (string) ($stored_assistant['description'] ?? ''),
            'provider' => $provider,
            'system_prompt' => isset($_POST['system_prompt']) ? (string) wp_unslash($_POST['system_prompt']) : (string) ($stored_assistant['system_prompt'] ?? ''),
            'user_message_template' => isset($_POST['user_message_template']) ? (string) wp_unslash($_POST['user_message_template']) : (string) ($stored_assistant['user_message_template'] ?? ''),
            'expected_format' => isset($_POST['response_format']) ? sanitize_text_field(wp_unslash($_POST['response_format'])) : (string) ($stored_assistant['expected_format'] ?? 'text'),
            'expected_output_schema' => isset($_POST['expected_output_schema']) ? (string) wp_unslash($_POST['expected_output_schema']) : ($stored_assistant['expected_output_schema'] ?? null),
            'api_parameters' => $api_parameters,
        ]);

        $system_prompt_template = isset($_POST['description_system_prompt']) ? (string) wp_unslash($_POST['description_system_prompt']) : '';
        $prompt_template = isset($_POST['description_prompt_template']) ? (string) wp_unslash($_POST['description_prompt_template']) : '';

        $result = (new DescriptionGeneratorService())->generateAssistantDescription(
            $assistant,
            $system_prompt_template,
            $prompt_template
        );

        $this->send_assistant_refinement_result($result);
    }

    /**
     * AJAX: Persist only the assistant description.
     */
    public function ajax_save_assistant_description(): void
    {
        check_ajax_referer('polytrans_assistants', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        $description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Assistant must be saved before its description can be updated.', 'polytrans')]);
        }

        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!is_array($assistant)) {
            wp_send_json_error(['message' => __('Assistant not found.', 'polytrans')]);
        }

        $assistant['description'] = $description;
        $result = AssistantManager::update_assistant($assistant_id, $assistant);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'errors' => $result->get_error_data(),
            ]);
        }

        wp_send_json_success([
            'message' => __('Assistant description saved.', 'polytrans'),
            'assistant_id' => $assistant_id,
            'description' => $description,
        ]);
    }

    private function send_assistant_refinement_result($result): void
    {
        if (is_wp_error($result)) {
            $payload = [
                'message' => $result->get_error_message(),
                'error_code' => $result->get_error_code(),
            ];

            $error_data = $result->get_error_data();
            if ($error_data !== null) {
                $payload['errors'] = $error_data;
            }

            wp_send_json_error($payload);
        }

        wp_send_json_success($result);
    }
}

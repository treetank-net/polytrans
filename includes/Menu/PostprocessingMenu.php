<?php

/**
 * Post-Processing Workflows Menu
 * 
 * Handles the admin menu page for managing post-processing workflows.
 */

namespace PolyTrans\Menu;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantMigration;
use PolyTrans\Core\AsyncJobRunner;
use PolyTrans\PostProcessing\Testing\WorkflowRefinementService;
use PolyTrans\PromptRefinement\DescriptionGeneratorService;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\Templating\TemplateRenderer;
use function PolyTrans\Core\sanitize_prompt_template;
use function PolyTrans\Core\sanitize_input_deep;

if (!defined('ABSPATH')) {
    exit;
}

class PostprocessingMenu
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
        add_action('wp_ajax_polytrans_save_workflow', [$this, 'ajax_save_workflow']);
        add_action('wp_ajax_polytrans_delete_workflow', [$this, 'ajax_delete_workflow']);
        add_action('wp_ajax_polytrans_toggle_workflow', [$this, 'ajax_toggle_workflow']);
        add_action('wp_ajax_polytrans_duplicate_workflow', [$this, 'ajax_duplicate_workflow']);
        add_action('wp_ajax_polytrans_get_workflow', [$this, 'ajax_get_workflow']);
        add_action('wp_ajax_polytrans_migrate_workflow', [$this, 'ajax_migrate_workflow']);
        add_action('wp_ajax_polytrans_get_post_data', [$this, 'ajax_get_post_data']);
        add_action('wp_ajax_polytrans_run_workflow_refinement_post', [$this, 'ajax_run_workflow_refinement_post']);
        add_action('wp_ajax_polytrans_evaluate_workflow_refinement_run', [$this, 'ajax_evaluate_workflow_refinement_run']);
        add_action('wp_ajax_polytrans_adjust_workflow_prompt', [$this, 'ajax_adjust_workflow_prompt']);
        add_action('wp_ajax_polytrans_apply_workflow_prompt_pack', [$this, 'ajax_apply_workflow_prompt_pack']);
        add_action('wp_ajax_polytrans_generate_workflow_description', [$this, 'ajax_generate_workflow_description']);
        add_action('wp_ajax_polytrans_generate_workflow_criteria', [$this, 'ajax_generate_workflow_criteria']);
        add_action('wp_ajax_polytrans_save_workflow_description', [$this, 'ajax_save_workflow_description']);
        // Async job dispatch + poll (avoids 504 on slow AJAX endpoints)
        add_action('wp_ajax_polytrans_async_worker', [AsyncJobRunner::class, 'executeWorker']);
        add_action('wp_ajax_nopriv_polytrans_async_worker', [AsyncJobRunner::class, 'executeWorker']);
        add_action('wp_ajax_polytrans_dispatch_async_job', [$this, 'ajax_dispatch_async_job']);
        add_action('wp_ajax_polytrans_poll_async_job', [$this, 'ajax_poll_async_job']);
        add_filter('polytrans_async_job_execute', [$this, 'handle_async_job'], 10, 3);

        // Deprecated - use polytrans_load_assistants instead
        add_action('wp_ajax_polytrans_load_openai_assistants_for_workflow', [$this, 'ajax_load_openai_assistants_for_workflow']);
        add_action('wp_ajax_polytrans_load_managed_assistants', [$this, 'ajax_load_managed_assistants']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'polytrans',
            __('Post-Processing Workflows', 'treetank-trans'),
            __('Post-Processing', 'treetank-trans'),
            'edit_posts',
            'polytrans-workflows',
            [$this, 'render_workflow_page']
        );

        add_submenu_page(
            'polytrans',
            __('Execute Workflow', 'treetank-trans'),
            __('Execute Workflow', 'treetank-trans'),
            'edit_posts',
            'polytrans-execute-workflow',
            [$this, 'render_execute_workflow_page']
        );
    }

    /**
     * Enqueue assets for workflow management
     */
    public function enqueue_assets($hook_suffix)
    {
        // Enqueue for workflow management page
        if (strpos($hook_suffix, 'polytrans-workflows') !== false) {
            // Enqueue prompt editor module (reusable component)
            wp_enqueue_script(
                'polytrans-prompt-editor',
                POLYTRANS_PLUGIN_URL . 'assets/js/prompt-editor.js',
                ['jquery'],
                POLYTRANS_VERSION,
                true
            );

            wp_enqueue_script(
                'polytrans-workflows',
                POLYTRANS_PLUGIN_URL . 'assets/js/postprocessing-admin.js',
                ['jquery', 'wp-util', 'polytrans-prompt-editor'],
                POLYTRANS_VERSION,
                true
            );

            wp_enqueue_style(
                'polytrans-workflows',
                POLYTRANS_PLUGIN_URL . 'assets/css/postprocessing-admin.css',
                [],
                POLYTRANS_VERSION
            );

            // Enqueue user autocomplete assets
            wp_enqueue_script(
                'polytrans-user-autocomplete',
                POLYTRANS_PLUGIN_URL . 'assets/js/core/user-autocomplete.js',
                ['jquery-ui-autocomplete'],
                POLYTRANS_VERSION,
                true
            );
            wp_enqueue_style('jquery-ui-autocomplete');

            // Localize script
            $settings = get_option('polytrans_settings', []);
            $selected_model = $settings['openai_model'] ?? 'gpt-4o-mini';
            // Get available chat providers for AI Assistant step
            $chat_providers = $this->get_chat_providers_for_js();
            $openai_models = $this->get_openai_models($selected_model);

            // Temperature vs reasoning effort knowledge for the step editor
            $model_capabilities = [];
            $default_models = [];
            $capability_providers = array_unique(array_merge(['openai'], array_keys($chat_providers)));
            foreach ($capability_providers as $provider_id) {
                $provider_models = ($provider_id === 'openai') ? $openai_models : [];
                $default_model = $settings[$provider_id . '_model'] ?? '';
                if (!empty($default_model)) {
                    $provider_models[__('Global setting', 'treetank-trans')][$default_model] = $default_model;
                    $default_models[$provider_id] = $default_model;
                }
                $model_capabilities[$provider_id] = \PolyTrans\Core\ModelCapabilities::get_capabilities_payload(
                    $provider_id,
                    $provider_models
                );
            }

            wp_localize_script('polytrans-workflows', 'polytransWorkflows', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_workflows_nonce'),
                'openai_nonce' => wp_create_nonce('polytrans_openai_nonce'),
                'models' => $openai_models,
                'selected_model' => $selected_model,
                'chatProviders' => $chat_providers,
                'modelCapabilities' => $model_capabilities,
                'defaultModels' => $default_models,
                'strings' => [
                    'confirmDelete' => __('Are you sure you want to delete this workflow?', 'treetank-trans'),
                    'confirmDuplicate' => __('Create a copy of this workflow?', 'treetank-trans'),
                    'saveSuccess' => __('Workflow saved successfully!', 'treetank-trans'),
                    'saveError' => __('Error saving workflow.', 'treetank-trans'),
                    'deleteSuccess' => __('Workflow deleted successfully!', 'treetank-trans'),
                    'deleteError' => __('Error deleting workflow.', 'treetank-trans'),
                    'testSuccess' => __('Test completed successfully!', 'treetank-trans'),
                    'testError' => __('Test failed.', 'treetank-trans'),
                    'loading' => __('Loading...', 'treetank-trans'),
                    'addStep' => __('Add Step', 'treetank-trans'),
                    'removeStep' => __('Remove Step', 'treetank-trans'),
                    'moveUp' => __('Move Up', 'treetank-trans'),
                    'moveDown' => __('Move Down', 'treetank-trans'),
                    'clearSelection' => __('Clear', 'treetank-trans'),
                    'noProviderSelected' => __('No provider selected. A random enabled provider will be used.', 'treetank-trans'),
                    'allLanguages' => __('All languages', 'treetank-trans'),
                    'allLanguagesOption' => __('— All languages —', 'treetank-trans'),
                    'allLanguagesDescription' => __('Select a specific language or "All languages" to run this workflow for any translation target', 'treetank-trans'),
                    'enableWorkflow' => __('Enable workflow', 'treetank-trans'),
                    'disableWorkflow' => __('Disable workflow', 'treetank-trans'),
                    'enabled' => __('Enabled', 'treetank-trans'),
                    'disabled' => __('Disabled', 'treetank-trans'),
                    'confirmMigrateWorkflow' => __('This will migrate legacy AI assistant steps in this workflow to managed assistants. Unsaved editor changes will not be included. Continue?', 'treetank-trans'),
                    'migrateWorkflow' => __('Migrate this workflow', 'treetank-trans'),
                    'migratingWorkflow' => __('Migrating...', 'treetank-trans'),
                    'migrationError' => __('Migration failed. Please check logs.', 'treetank-trans'),
                    'temperature' => __('Temperature', 'treetank-trans'),
                    'temperatureHint' => __('AI creativity level (lower = focused, higher = creative)', 'treetank-trans'),
                    'reasoningEffort' => __('Reasoning Effort', 'treetank-trans'),
                    'effortProviderDefault' => __('Provider default', 'treetank-trans'),
                ],
                'descriptionPrompts' => [
                    'system' => PromptRefinementSettings::descriptionGeneratorSystem(),
                    'criteriaSystem' => PromptRefinementSettings::criteriaGeneratorSystem(),
                    'workflow' => PromptRefinementSettings::workflowDescriptionGenerator(),
                    'workflowStep' => PromptRefinementSettings::workflowStepDescriptionGenerator(),
                    'workflowCriteria' => PromptRefinementSettings::workflowCriteriaGenerator(),
                ],
            ]);

            // Localize user autocomplete script
            wp_localize_script('polytrans-user-autocomplete', 'PolyTransUserAutocomplete', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_nonce'),
                'i18n' => [
                    'no_results' => esc_html__('No users found.', 'treetank-trans'),
                    'searching' => esc_html__('Searching users...', 'treetank-trans'),
                    'clear_selection' => esc_html__('Clear selection', 'treetank-trans'),
                    'type_to_search' => esc_html__('Type to search users...', 'treetank-trans'),
                    'min_chars' => esc_html__('Type at least 2 characters to search.', 'treetank-trans'),
                ]
            ]);
        }

        // Enqueue for execute workflow page
        if (strpos($hook_suffix, 'polytrans-execute-workflow') !== false) {
            wp_enqueue_script(
                'polytrans-execute-workflow',
                POLYTRANS_PLUGIN_URL . 'assets/js/postprocessing/execute-workflow.js',
                ['jquery', 'wp-util'],
                POLYTRANS_VERSION,
                true
            );

            wp_enqueue_style(
                'polytrans-execute-workflow',
                POLYTRANS_PLUGIN_URL . 'assets/css/postprocessing-admin.css',
                [],
                POLYTRANS_VERSION
            );

            // Get available languages
            $langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
            $lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];

            wp_localize_script('polytrans-execute-workflow', 'polytransExecuteWorkflow', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_workflows_nonce'),
                'languages' => array_combine($langs, $lang_names),
                'strings' => [
                    'loading' => __('Loading...', 'treetank-trans'),
                    'searching' => __('Searching...', 'treetank-trans'),
                    'selectWorkflow' => __('Select workflow...', 'treetank-trans'),
                    'selectPost' => __('Select a post...', 'treetank-trans'),
                    'noWorkflows' => __('No workflows available', 'treetank-trans'),
                    'noPosts' => __('No posts found', 'treetank-trans'),
                    'executing' => __('Executing...', 'treetank-trans'),
                    'verifying' => __('Verifying...', 'treetank-trans'),
                    'verify' => __('Verify', 'treetank-trans'),
                    'execute' => __('Execute Workflow', 'treetank-trans'),
                    'executeAnother' => __('Execute Another Workflow', 'treetank-trans'),
                    'viewPost' => __('View Post', 'treetank-trans'),
                    'editPost' => __('Edit Post', 'treetank-trans'),
                    'success' => __('Success!', 'treetank-trans'),
                    'failed' => __('Failed', 'treetank-trans'),
                    'error' => __('Error', 'treetank-trans'),
                    'alreadyRunning' => __('This workflow is already running on this post.', 'treetank-trans'),
                    'workflowNotFound' => __('Selected workflow does not exist.', 'treetank-trans'),
                    'postNotFound' => __('Selected post does not exist.', 'treetank-trans'),
                    'noTranslation' => __('This post does not have a translation in the selected language.', 'treetank-trans'),
                    'languageMismatch' => __('Post translation language does not match workflow language.', 'treetank-trans'),
                    'permissionDenied' => __('You do not have permission to execute workflows on this post.', 'treetank-trans'),
                    'timeout' => __('Execution timed out. Please check logs for details.', 'treetank-trans'),
                    'allLanguages' => __('All languages', 'treetank-trans'),
                ]
            ]);
        }
    }

    /**
     * Render workflow management page
     */
    public function render_workflow_page()
    {
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        // Get current action
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $workflow_id = isset($_GET['workflow_id']) ? sanitize_text_field(wp_unslash($_GET['workflow_id'])) : '';

        switch ($action) {
            case 'edit':
            case 'new':
                $this->render_workflow_editor($workflow_id, $action === 'new');
                break;

            case 'test':
                $this->render_workflow_tester($workflow_id);
                break;

            default:
                $this->render_workflow_list();
                break;
        }
    }

    /**
     * Render workflow list
     */
    private function render_workflow_list()
    {
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();
        $workflows = $storage_manager->get_all_workflows();
        $statistics = $storage_manager->get_workflow_statistics();

        // Get available languages
        $langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
        $lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/workflows/list.twig', [
            'workflows' => $workflows,
            'statistics' => $statistics,
            'langs' => $langs,
            'lang_names' => $lang_names,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render workflow editor
     */
    private function render_workflow_editor($workflow_id, $is_new = false)
    {
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        // Get workflow data
        if ($is_new) {
            $workflow = [
                'id' => 'workflow_' . uniqid(),
                'name' => '',
                'description' => '',
                'language' => 'en',
                'enabled' => true,
                'triggers' => [
                    'on_translation_complete' => true,
                    'manual_only' => false,
                    'conditions' => []
                ],
                'steps' => []
            ];
        } else {
            $workflow = $storage_manager->get_workflow($workflow_id);
            if (!$workflow) {
                wp_die(esc_html__('Workflow not found.', 'treetank-trans'));
            }

            // Ensure workflow has proper default values for any missing fields
            $workflow = $this->normalize_workflow_data($workflow);
        }

        // Get available languages
        $langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
        $lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];

        // Add user label for attribution user if set
        if (!empty($workflow['attribution_user'])) {
            $attribution_user = get_user_by('id', $workflow['attribution_user']);
            if ($attribution_user) {
                $workflow['attribution_user_label'] = $attribution_user->display_name . ' (' . $attribution_user->user_email . ')';
            } else {
                // User not found, clear the invalid user ID
                $workflow['attribution_user'] = null;
            }
        }

        // Pass workflow data to JavaScript via enqueued script
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowData = ' . wp_json_encode($workflow) . ';' .
            'window.polytransLanguages = ' . wp_json_encode(array_combine($langs, $lang_names)) . ';',
            'before'
        );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/workflows/editor.twig', [
            'is_new' => $is_new,
            'workflow' => $workflow,
            'languages' => array_combine($langs, $lang_names),
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render workflow tester
     */
    private function render_workflow_tester($workflow_id)
    {
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        $workflow = $storage_manager->get_workflow($workflow_id);
        if (!$workflow) {
            wp_die(esc_html__('Workflow not found.', 'treetank-trans'));
        }

        // Pass workflow test data to JavaScript via enqueued script
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowTestData = ' . wp_json_encode($workflow) . ';',
            'before'
        );
        $managed_assistant_descriptions = [];
        foreach (AssistantManager::get_all_assistants() as $assistant) {
            $managed_assistant_descriptions[(string) ($assistant['id'] ?? '')] = (string) ($assistant['description'] ?? '');
        }
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowManagedAssistantDescriptions = ' . wp_json_encode($managed_assistant_descriptions) . ';',
            'before'
        );
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowRefinementDefaults = ' . wp_json_encode([
                'evaluatorSystemPrompt' => PromptRefinementSettings::workflowEvaluatorSystem(),
                'evaluatorPromptTemplate' => PromptRefinementSettings::workflowEvaluator(),
                'adjusterSystemPrompt' => PromptRefinementSettings::workflowAdjusterSystem(),
                'adjusterPromptTemplate' => PromptRefinementSettings::workflowAdjuster(),
            ]) . ';',
            'before'
        );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/workflows/tester.twig', [
            'workflow' => $workflow,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render execute workflow page
     */
    public function render_execute_workflow_page()
    {
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        // Get URL parameters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $workflow_id = isset($_GET['workflow_id']) ? sanitize_text_field(wp_unslash($_GET['workflow_id'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $language_filter = isset($_GET['language']) ? sanitize_text_field(wp_unslash($_GET['language'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page rendering parameter, no state change
        $locked = isset($_GET['lock']) && sanitize_text_field(wp_unslash($_GET['lock'])) === '1';

        // Get all workflows (only enabled ones)
        $all_workflows_raw = $storage_manager->get_all_workflows();
        $all_workflows = array_values(array_filter($all_workflows_raw, function ($workflow) use ($language_filter) {
            $is_enabled = isset($workflow['enabled']) && $workflow['enabled'];

            // If language filter is set, also filter by language
            if ($language_filter && !empty($workflow['language'])) {
                return $is_enabled && $workflow['language'] === $language_filter;
            }

            return $is_enabled;
        }));

        // Pre-selected workflow data
        $selected_workflow = null;
        if ($workflow_id) {
            $selected_workflow = $storage_manager->get_workflow($workflow_id);
        }

        // Pre-selected post data
        $selected_post = null;
        $selected_post_data = null;
        if ($post_id) {
            $selected_post = get_post($post_id);
            if ($selected_post) {
                $selected_post_data = [
                    'ID' => $selected_post->ID,
                    'post_title' => $selected_post->post_title,
                    'post_type' => $selected_post->post_type
                ];
            }
        }

        // Get available languages
        $langs = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['pl', 'en', 'it'];
        $lang_names = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'name']) : ['Polish', 'English', 'Italian'];

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/workflows/execute.twig', [
            'all_workflows' => $all_workflows,
            'workflow_id' => $workflow_id,
            'selected_workflow' => $selected_workflow,
            'post_id' => $post_id,
            'selected_post' => $selected_post,
            'language_filter' => $language_filter,
            'locked' => $locked,
            'langs' => $langs,
            'lang_names' => $lang_names,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        // Output JavaScript data via enqueued script
        wp_add_inline_script(
            'polytrans-execute-workflow',
            'window.polytransExecuteWorkflowData = ' . wp_json_encode([
                'workflows' => $all_workflows,
                'selectedWorkflow' => $selected_workflow,
                'selectedPost' => $selected_post_data,
                'locked' => $locked,
                'workflowId' => $workflow_id,
                'postId' => $post_id,
                'languageFilter' => $language_filter,
            ]) . ';',
            'before'
        );
    }

    /**
     * AJAX: Save workflow
     */
    public function ajax_save_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow_data = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];

        if (empty($workflow_data)) {
            wp_send_json_error('No workflow data provided');
            return;
        }

        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        // Sanitize workflow data
        $workflow = $this->sanitize_workflow_data($workflow_data);
        $saveResult = $storage_manager->save_workflow($workflow);

        if ($saveResult['success']) {
            wp_send_json_success([
                'message' => __('Workflow saved successfully!', 'treetank-trans'),
                'workflow_id' => $workflow['id']
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to save workflow.', 'treetank-trans'),
                'errors' => $saveResult['errors'] ?? []
            ]);
        }
    }

    /**
     * AJAX: Delete workflow
     */
    public function ajax_delete_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error('Workflow ID required');
            return;
        }

        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        if ($storage_manager->delete_workflow($workflow_id)) {
            wp_send_json_success('Workflow deleted successfully');
        } else {
            wp_send_json_error('Failed to delete workflow');
        }
    }

    /**
     * AJAX: Toggle workflow enabled status
     */
    public function ajax_toggle_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error('Workflow ID required');
            return;
        }

        $workflow_manager = \PolyTrans\PostProcessing\WorkflowManager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        $workflow = $storage_manager->get_workflow($workflow_id);
        if (!$workflow) {
            wp_send_json_error('Workflow not found');
            return;
        }

        // Toggle enabled status
        $workflow['enabled'] = !$workflow['enabled'];

        $result = $storage_manager->save_workflow($workflow);
        if ($result['success']) {
            wp_send_json_success([
                'enabled' => $workflow['enabled'],
                'message' => $workflow['enabled']
                    ? __('Workflow enabled', 'treetank-trans')
                    : __('Workflow disabled', 'treetank-trans')
            ]);
        } else {
            wp_send_json_error('Failed to update workflow');
        }
    }

    /**
     * AJAX: Duplicate workflow
     */
    public function ajax_duplicate_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';
        $new_name = isset($_POST['new_name']) ? sanitize_text_field(wp_unslash($_POST['new_name'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error('Workflow ID required');
            return;
        }

        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();

        $new_workflow_id = $storage_manager->duplicate_workflow($workflow_id, $new_name);

        if ($new_workflow_id) {
            wp_send_json_success([
                'message' => __('Workflow duplicated successfully!', 'treetank-trans'),
                'new_workflow_id' => $new_workflow_id
            ]);
        } else {
            wp_send_json_error('Failed to duplicate workflow');
        }
    }

    /**
     * AJAX: Get workflow data
     */
    public function ajax_get_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error('Workflow ID required');
            return;
        }

        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();
        $workflow = $storage_manager->get_workflow($workflow_id);

        if ($workflow) {
            wp_send_json_success($workflow);
        } else {
            wp_send_json_error('Workflow not found');
        }
    }

    /**
     * AJAX: Migrate one workflow to managed assistants.
     */
    public function ajax_migrate_workflow()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'treetank-trans')]);
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error(['message' => __('No workflow ID provided', 'treetank-trans')]);
            return;
        }

        $stats = AssistantMigration::migrate_workflow_to_managed_assistants($workflow_id);

        if (!empty($stats['errors'])) {
            wp_send_json_error([
                'message' => __('Migration completed with errors.', 'treetank-trans'),
                'stats' => $stats,
            ]);
            return;
        }

        if ((int) ($stats['steps_migrated'] ?? 0) === 0) {
            wp_send_json_success([
                'message' => __('No legacy AI assistant steps were found in this workflow.', 'treetank-trans'),
                'stats' => $stats,
            ]);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d: number of steps migrated, %2$d: number of assistants created */
                __('Migration completed successfully. Migrated %1$d steps and created %2$d assistants.', 'treetank-trans'),
                $stats['steps_migrated'],
                $stats['assistants_created']
            ),
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX: Test workflow
     *
     * @deprecated The endpoint is registered by PostProcessing\WorkflowManager.
     * Kept as a compatibility shim for integrations that call the legacy method.
     */
    public function ajax_test_workflow()
    {
        // This is handled by the workflow manager
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $workflow_manager->ajax_test_workflow();
    }

    /**
     * AJAX: Search posts
     *
     * @deprecated The endpoint is registered by Core\PostAutocomplete. Kept as a
     * compatibility shim for integrations that call the legacy method directly.
     */
    public function ajax_search_posts()
    {
        // Delegate to the autocomplete class which now supports language filtering
        $post_autocomplete = \PolyTrans_Post_Autocomplete::get_instance();
        $post_autocomplete->ajax_search_posts();
    }

    /**
     * AJAX: Get post data
     */
    public function ajax_get_post_data()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : '';

        if (empty($post_id)) {
            wp_send_json_error('Post ID required');
            return;
        }

        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('You do not have permission to edit this post');
            return;
        }

        // Get post language
        $post_language = function_exists('pll_get_post_language') ?
            pll_get_post_language($post_id) : 'en';

        // If target_language is provided, validate it matches the post language
        if (!empty($target_language) && $post_language !== $target_language) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %1$s: post language code, %2$s: workflow target language code */
                    __('Selected post is in %1$s but workflow requires %2$s', 'treetank-trans'),
                    $post_language,
                    $target_language
                )
            ]);
            return;
        }

        // Return post data - using it as both original and translated
        // For manual workflows, we work directly with the selected post
        $post_data = [
            'ID' => $post->ID,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'language' => $post_language,
            'edit_url' => get_edit_post_link($post->ID, 'raw')
        ];

        wp_send_json_success([
            'original_post' => $post_data,
            'translated_post' => $post_data
        ]);
    }

    /**
     * AJAX: Load OpenAI assistants for workflow
     * @deprecated Use polytrans_load_assistants endpoint instead (returns all providers)
     */
    public function ajax_load_openai_assistants_for_workflow()
    {
        // Check nonce
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Use the universal endpoint logic - call it directly
        $settings = get_option('polytrans_settings', []);
        $api_key = $settings['openai_api_key'] ?? '';
        
        // Get grouped assistants using the same logic as polytrans_load_assistants endpoint
        $grouped_assistants = [
            'providers' => [],
            'managed' => [],
            'openai' => [],
            'claude' => [],
            'gemini' => []
        ];
        
        // Load Enabled Translation Providers
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        $all_providers = $registry->get_providers();

        foreach ($all_providers as $provider_id => $provider) {
            if ($provider_id === 'openai') {
                continue; // OpenAI doesn't have translation endpoints
            }
            
            if (in_array($provider_id, $enabled_providers)) {
                $grouped_assistants['providers'][] = [
                    'id' => 'provider_' . $provider_id,
                    'name' => $provider->get_name(),
                    'description' => $provider->get_description(),
                    'model' => 'N/A',
                    'provider' => $provider_id
                ];
            }
        }

        // Load Managed Assistants
        $managed_assistants = \PolyTrans\Assistants\AssistantManager::get_all_assistants();
        if (!empty($managed_assistants)) {
            foreach ($managed_assistants as $assistant) {
                $assistant_provider = $assistant['provider'] ?? 'openai';
                
                if (!in_array($assistant_provider, $enabled_providers)) {
                    continue;
                }
                
                if ($assistant_provider === 'openai' && empty($api_key)) {
                    continue;
                }
                
                $model_display = 'No model';
                if (!empty($assistant['api_parameters'])) {
                    $api_params = is_string($assistant['api_parameters']) 
                        ? json_decode($assistant['api_parameters'], true) 
                        : $assistant['api_parameters'];
                    if (is_array($api_params) && !empty($api_params['model'])) {
                        $model_display = $api_params['model'];
        }
                }
                if ($model_display === 'No model' || empty($model_display)) {
                    $model_display = 'Global Setting';
                }
                
                $grouped_assistants['managed'][] = [
                    'id' => 'managed_' . $assistant['id'],
                    'name' => $assistant['name'],
                    'description' => $assistant['description'] ?? '',
                    'model' => $model_display,
                    'provider' => $assistant_provider
                ];
            }
        }
        
        // Load OpenAI API Assistants
        $openai_enabled = in_array('openai', $enabled_providers);
        if ($openai_enabled && !empty($api_key)) {
            $client = new \PolyTrans\Providers\OpenAI\OpenAIClient($api_key);
            $openai_assistants = $client->get_all_assistants();
            
            if (!empty($openai_assistants)) {
                foreach ($openai_assistants as $assistant) {
                    $grouped_assistants['openai'][] = [
                'id' => $assistant['id'],
                'name' => $assistant['name'] ?? 'Unnamed Assistant',
                'description' => $assistant['description'] ?? '',
                        'model' => $assistant['model'] ?? 'gpt-4',
                        'provider' => 'openai'
            ];
                }
            }
        }
        
        // Flatten grouped structure for backward compatibility
        $flattened = [];
        foreach ($grouped_assistants as $group => $assistants) {
            if (is_array($assistants)) {
                foreach ($assistants as $assistant) {
                    $flattened[] = [
                        'id' => $assistant['id'],
                        'name' => $assistant['name'] ?? 'Unnamed Assistant',
                        'description' => $assistant['description'] ?? '',
                        'model' => $assistant['model'] ?? 'N/A',
                        'provider' => $assistant['provider'] ?? 'unknown',
                        'group' => $group
                    ];
                }
            }
        }

        wp_send_json_success($flattened);
    }

    /**
     * AJAX: Load managed assistants for workflow editor
     */
    public function ajax_load_managed_assistants()
    {
        // Check nonce
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Get all managed assistants
        $assistants = AssistantManager::get_all_assistants();

        if (empty($assistants)) {
            wp_send_json_error('No managed assistants found. Create one in TreeTank > AI Assistants.');
            return;
        }

        wp_send_json_success($assistants);
    }


    /**
     * Get chat providers for JavaScript
     */
    private function get_chat_providers_for_js()
    {
        $settings = get_option('polytrans_settings', []);
        $enabled_providers = $settings['enabled_translation_providers'] ?? [];
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $all_providers = $registry->get_providers();

        $chat_providers = [];

        foreach ($all_providers as $provider_id => $provider) {
            // Check if provider is enabled
            if (!in_array($provider_id, $enabled_providers)) {
                continue;
            }
            
            // Check if provider supports chat capability
            $settings_provider_class = $provider->get_settings_provider_class();
            if ($settings_provider_class && class_exists($settings_provider_class)) {
                $settings_provider = new $settings_provider_class();
                if (method_exists($settings_provider, 'get_provider_manifest')) {
                    $manifest = $settings_provider->get_provider_manifest($settings);
                    $capabilities = $manifest['capabilities'] ?? [];
                    
                    // Only include providers with 'chat' capability
                    if (in_array('chat', $capabilities)) {
                        // Check if API key is configured
                        $api_key_setting = $manifest['api_key_setting'] ?? '';
                        $api_key_configured = false;
                        
                        if (!empty($api_key_setting)) {
                            $api_key = $settings[$api_key_setting] ?? '';
                            $api_key_configured = !empty($api_key);
                        }
                        
                        // Only include if API key is configured
                        if ($api_key_configured) {
                            $chat_providers[$provider_id] = [
                                'id' => $provider_id,
                                'name' => $provider->get_name(),
                                'supports_system_prompt' => in_array('system_prompt', $capabilities),
                            ];
                        }
                    }
                }
            }
        }
        
        // Fallback to hardcoded list if no providers found
        if (empty($chat_providers)) {
            // Check OpenAI specifically
            $openai_key = $settings['openai_api_key'] ?? '';
            if (!empty($openai_key)) {
                $chat_providers['openai'] = [
                    'id' => 'openai',
                    'name' => 'OpenAI',
                    'supports_system_prompt' => true,
                ];
            }
        }
        
        return $chat_providers;
    }

    /**
     * Get OpenAI models from the settings provider
     */
    private function get_openai_models($selected_model = null)
    {
        // Check if OpenAI settings provider class exists
        if (!class_exists('\PolyTrans_OpenAI_Settings_Provider')) {
            return [];
        }

        try {
            $provider = new \PolyTrans_OpenAI_Settings_Provider();
            $reflection = new \ReflectionClass($provider);
            $method = $reflection->getMethod('get_grouped_models');
            $method->setAccessible(true);
            return $method->invoke($provider, $selected_model);
        } catch (\Exception $e) {
            // Fallback to basic models if we can't access the provider
            return [
                'GPT-4o Models' => [
                    'gpt-4o' => 'GPT-4o (Latest)',
                    'gpt-4o-mini' => 'GPT-4o Mini (Fast & Cost-effective)',
                ],
                'GPT-4 Models' => [
                    'gpt-4-turbo' => 'GPT-4 Turbo',
                    'gpt-4' => 'GPT-4',
                ],
                'GPT-3.5 Models' => [
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
                ]
            ];
        }
    }

    /**
     * AJAX: Run a full workflow for one post while overriding one managed assistant step prompt.
     */
    public function ajax_run_workflow_refinement_post()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $selected_post_id = isset($_POST['selected_post_id']) ? intval($_POST['selected_post_id']) : 0;
        $source_language = isset($_POST['source_language']) ? sanitize_text_field(wp_unslash($_POST['source_language'])) : '';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : '';
        $override_system_prompt = array_key_exists('override_system_prompt', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['override_system_prompt']))
            : null;
        $override_user_message_template = array_key_exists('override_user_message_template', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['override_user_message_template']))
            : null;
        $override_expected_output_schema = array_key_exists('override_expected_output_schema', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_input_deep(wp_unslash($_POST['override_expected_output_schema']))
            : null;

        $result = (new WorkflowRefinementService())->runPost(
            is_array($workflow) ? $workflow : [],
            $target_step_id,
            $selected_post_id,
            $source_language,
            $target_language,
            $override_system_prompt,
            $override_user_message_template,
            $override_expected_output_schema
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Evaluate a stored full workflow refinement run.
     */
    public function ajax_evaluate_workflow_refinement_run()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $workflow_purpose = isset($_POST['workflow_purpose']) ? sanitize_textarea_field(wp_unslash($_POST['workflow_purpose'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $evaluator_system_prompt = isset($_POST['evaluator_system_prompt']) ? sanitize_prompt_template(sanitize_prompt_template(wp_unslash($_POST['evaluator_system_prompt']))) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $evaluator_prompt_template = isset($_POST['evaluator_prompt_template']) ? sanitize_prompt_template(wp_unslash($_POST['evaluator_prompt_template'])) : '';

        $result = (new WorkflowRefinementService())->evaluateRun(
            $run_id,
            $target_step_id,
            $criteria,
            $workflow_purpose,
            $prompt_objective,
            is_string($evaluator_prompt_template) ? $evaluator_prompt_template : '',
            is_string($evaluator_system_prompt) ? $evaluator_system_prompt : ''
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Build prompt adjustment proposal from full workflow evaluations.
     */
    public function ajax_adjust_workflow_prompt()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $workflow_purpose = isset($_POST['workflow_purpose']) ? sanitize_textarea_field(wp_unslash($_POST['workflow_purpose'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $adjuster_system_prompt = isset($_POST['adjuster_system_prompt']) ? sanitize_prompt_template(wp_unslash($_POST['adjuster_system_prompt'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $adjuster_prompt_template = isset($_POST['adjuster_prompt_template']) ? sanitize_prompt_template(wp_unslash($_POST['adjuster_prompt_template'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- JSON stays raw so WorkflowRefinementService::decodeEvaluations() unslashes, json_decodes, and validates it before prompt rendering.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $evaluations_payload = sanitize_input_deep(wp_unslash($_POST['evaluations'] ?? '[]'));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- JSON stays raw so WorkflowRefinementService::decodeRefinementHistory() unslashes, json_decodes, and validates it before prompt rendering.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $refinement_history_payload = sanitize_input_deep(wp_unslash($_POST['refinement_history'] ?? '[]'));
        $current_system_prompt = array_key_exists('current_system_prompt', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['current_system_prompt']))
            : null;
        $current_user_message_template = array_key_exists('current_user_message_template', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['current_user_message_template']))
            : null;
        $current_expected_output_schema = array_key_exists('current_expected_output_schema', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_input_deep(wp_unslash($_POST['current_expected_output_schema']))
            : null;

        $result = (new WorkflowRefinementService())->adjustPrompt(
            $assistant_id,
            $criteria,
            $workflow_purpose,
            $prompt_objective,
            is_string($adjuster_prompt_template) ? $adjuster_prompt_template : '',
            $evaluations_payload,
            is_string($adjuster_system_prompt) ? $adjuster_system_prompt : '',
            $current_system_prompt,
            $current_user_message_template,
            $current_expected_output_schema,
            is_array($workflow) ? $workflow : [],
            $target_step_id,
            $refinement_history_payload
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Apply prompt pack generated from workflow refinement to the selected target.
     */
    public function ajax_apply_workflow_prompt_pack()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $target_step_type = isset($_POST['target_step_type']) ? sanitize_text_field(wp_unslash($_POST['target_step_type'])) : '';
        $base_prompt_pack = array_key_exists('base_prompt_pack', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_input_deep(wp_unslash($_POST['base_prompt_pack']))
            : null;
        $system_prompt = array_key_exists('system_prompt', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['system_prompt']))
            : '';
        $user_message_template = array_key_exists('user_message_template', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_prompt_template(wp_unslash($_POST['user_message_template']))
            : '';
        $expected_output_schema = array_key_exists('expected_output_schema', $_POST)
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
            ? sanitize_input_deep(wp_unslash($_POST['expected_output_schema']))
            : null;

        $result = (new WorkflowRefinementService())->applyPromptPack(
            $assistant_id,
            $system_prompt,
            $user_message_template,
            $expected_output_schema,
            is_array($workflow) ? $workflow : [],
            $target_step_id,
            $target_step_type,
            $base_prompt_pack
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Generate concise workflow or workflow-step description.
     */
    public function ajax_generate_workflow_description()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];
        if (!is_array($workflow)) {
            wp_send_json_error(['message' => __('Workflow payload is required.', 'treetank-trans')]);
            return;
        }

        $target_type = isset($_POST['target_type']) ? sanitize_text_field(wp_unslash($_POST['target_type'])) : 'workflow';
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $system_prompt_template = isset($_POST['description_system_prompt']) ? sanitize_prompt_template(wp_unslash($_POST['description_system_prompt'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $prompt_template = isset($_POST['description_prompt_template']) ? sanitize_prompt_template(wp_unslash($_POST['description_prompt_template'])) : '';

        $service = new DescriptionGeneratorService();
        if ($target_type === 'step') {
            $result = $service->generateWorkflowStepDescription(
                $workflow,
                $target_step_id,
                $system_prompt_template,
                $prompt_template
            );
        } else {
            $result = $service->generateWorkflowDescription(
                $workflow,
                $system_prompt_template,
                $prompt_template
            );
        }

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Rewrite workflow refinement criteria into a measurable replacement criterion.
     */
    public function ajax_generate_workflow_criteria()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_input_deep(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $workflow = isset($_POST['workflow']) ? sanitize_input_deep(wp_unslash($_POST['workflow'])) : [];
        if (!is_array($workflow)) {
            wp_send_json_error(['message' => __('Workflow payload is required.', 'treetank-trans')]);
            return;
        }

        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $current_criteria = isset($_POST['current_criteria']) ? sanitize_textarea_field(wp_unslash($_POST['current_criteria'])) : '';
        $workflow_purpose = isset($_POST['workflow_purpose']) ? sanitize_textarea_field(wp_unslash($_POST['workflow_purpose'])) : '';
        $prompt_objective = isset($_POST['prompt_objective']) ? sanitize_textarea_field(wp_unslash($_POST['prompt_objective'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $system_prompt_template = isset($_POST['criteria_system_prompt']) ? sanitize_prompt_template(wp_unslash($_POST['criteria_system_prompt'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $prompt_template = isset($_POST['criteria_prompt_template']) ? sanitize_prompt_template(wp_unslash($_POST['criteria_prompt_template'])) : '';

        $result = (new DescriptionGeneratorService())->generateWorkflowCriteria(
            $workflow,
            $target_step_id,
            $current_criteria,
            $workflow_purpose,
            $prompt_objective,
            $system_prompt_template,
            $prompt_template
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Persist only workflow or workflow-step description.
     */
    public function ajax_save_workflow_description(): void
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';
        $target_type = isset($_POST['target_type']) ? sanitize_text_field(wp_unslash($_POST['target_type'])) : 'workflow';
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';

        if ($workflow_id === '') {
            wp_send_json_error(['message' => __('Workflow must be saved before its description can be updated.', 'treetank-trans')]);
            return;
        }

        $workflow_manager = \PolyTrans\PostProcessing\WorkflowManager::get_instance();
        $storage_manager = $workflow_manager->get_storage_manager();
        $workflow = $storage_manager->get_workflow($workflow_id);

        if (!$workflow) {
            wp_send_json_error(['message' => __('Workflow not found.', 'treetank-trans')]);
            return;
        }

        if ($target_type === 'step') {
            $updated = false;
            foreach ((array) ($workflow['steps'] ?? []) as $index => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $step_id = (string) ($step['id'] ?? "step_{$index}");
                if ($step_id === $target_step_id) {
                    $workflow['steps'][$index]['description'] = $description;
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                wp_send_json_error(['message' => __('Workflow step not found.', 'treetank-trans')]);
                return;
            }
        } else {
            $workflow['description'] = $description;
        }

        $result = $storage_manager->save_workflow($workflow);
        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => __('Failed to save workflow description.', 'treetank-trans'),
                'errors' => $result['errors'] ?? [],
            ]);
            return;
        }

        wp_send_json_success([
            'message' => __('Workflow description saved.', 'treetank-trans'),
            'workflow_id' => $workflow_id,
            'target_type' => $target_type,
            'target_step_id' => $target_step_id,
            'description' => $description,
        ]);
    }

    /**
     * AJAX: Dispatch an async job (returns job_id immediately).
     */
    public function ajax_dispatch_async_job()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $job_type = isset($_POST['job_type']) ? sanitize_text_field(wp_unslash($_POST['job_type'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on this line by PolyTrans\Core\sanitize_prompt_template(); Plugin Check runs PHPCS with its own bundled ruleset, which cannot be given customSanitizingFunctions, and WPCS only accepts a sanitizer reached as a plain function call.
        $job_params = isset($_POST['job_params']) ? json_decode(sanitize_prompt_template(sanitize_prompt_template(wp_unslash($_POST['job_params']))), true) : [];

        $allowed_types = ['workflow_run', 'workflow_evaluate', 'workflow_adjust'];
        if (!in_array($job_type, $allowed_types, true)) {
            wp_send_json_error(['message' => __('Invalid job type.', 'treetank-trans')]);
            return;
        }

        $job_id = AsyncJobRunner::dispatch($job_type, is_array($job_params) ? $job_params : []);
        wp_send_json_success(['job_id' => $job_id]);
    }

    /**
     * AJAX: Poll an async job for completion.
     */
    public function ajax_poll_async_job()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'treetank-trans')]);
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'treetank-trans')]);
            return;
        }

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if ($job_id === '') {
            wp_send_json_error(['message' => __('Missing job ID.', 'treetank-trans')]);
            return;
        }

        $job = AsyncJobRunner::poll($job_id);
        if ($job === null) {
            wp_send_json_error(['message' => __('Job not found or expired.', 'treetank-trans')]);
            return;
        }

        wp_send_json_success([
            'status' => $job['status'],
            'result' => $job['result'],
        ]);
    }

    /**
     * Filter handler: execute async jobs by type.
     *
     * @param mixed  $result   Unused incoming filter value.
     * @param string $job_type Job type identifier.
     * @param array  $params   Serialized job params.
     * @return array The result to store.
     */
    public function handle_async_job($result, string $job_type, array $params)
    {
        switch ($job_type) {
            case 'workflow_run':
                return $this->execute_async_workflow_run($params);
            case 'workflow_evaluate':
                return $this->execute_async_workflow_evaluate($params);
            case 'workflow_adjust':
                return $this->execute_async_workflow_adjust($params);
            default:
                // Job types owned by other menus (e.g. assistant tests) reach this
                // filter too, so an unknown type is passed on rather than failed here.
                return $result;
        }
    }

    /**
     * Execute async workflow run job.
     */
    private function execute_async_workflow_run(array $params): array
    {
        $workflow = $params['workflow'] ?? [];
        $target_step_id = $params['target_step_id'] ?? '';
        $selected_post_id = intval($params['selected_post_id'] ?? 0);
        $source_language = $params['source_language'] ?? '';
        $target_language = $params['target_language'] ?? '';
        $override_system_prompt = $params['override_system_prompt'] ?? null;
        $override_user_message_template = $params['override_user_message_template'] ?? null;
        $override_expected_output_schema = $params['override_expected_output_schema'] ?? null;

        $result = (new WorkflowRefinementService())->runPost(
            is_array($workflow) ? $workflow : [],
            $target_step_id,
            $selected_post_id,
            $source_language,
            $target_language,
            $override_system_prompt,
            $override_user_message_template,
            $override_expected_output_schema
        );

        return $this->format_async_result($result);
    }

    /**
     * Execute async workflow evaluate job.
     */
    private function execute_async_workflow_evaluate(array $params): array
    {
        $result = (new WorkflowRefinementService())->evaluateRun(
            $params['run_id'] ?? '',
            $params['target_step_id'] ?? '',
            $params['criteria'] ?? '',
            $params['workflow_purpose'] ?? '',
            $params['prompt_objective'] ?? '',
            $params['evaluator_prompt_template'] ?? '',
            $params['evaluator_system_prompt'] ?? ''
        );

        return $this->format_async_result($result);
    }

    /**
     * Execute async workflow adjust job.
     */
    private function execute_async_workflow_adjust(array $params): array
    {
        $result = (new WorkflowRefinementService())->adjustPrompt(
            intval($params['assistant_id'] ?? 0),
            $params['criteria'] ?? '',
            $params['workflow_purpose'] ?? '',
            $params['prompt_objective'] ?? '',
            $params['adjuster_prompt_template'] ?? '',
            $params['evaluations'] ?? '[]',
            $params['adjuster_system_prompt'] ?? '',
            $params['current_system_prompt'] ?? null,
            $params['current_user_message_template'] ?? null,
            $params['current_expected_output_schema'] ?? null,
            is_array($params['workflow'] ?? null) ? $params['workflow'] : [],
            $params['target_step_id'] ?? '',
            $params['refinement_history'] ?? '[]'
        );

        return $this->format_async_result($result);
    }

    /**
     * Format a service result for async storage.
     *
     * @param mixed $result WP_Error or array from a refinement service method.
     * @return array Normalized {success, data} structure.
     */
    private function format_async_result($result): array
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

            return ['success' => false, 'data' => $payload];
        }
        return ['success' => true, 'data' => $result];
    }

    /**
     * Send workflow refinement service result as AJAX JSON.
     */
    private function send_workflow_refinement_result($result): void
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
            return;
        }

        wp_send_json_success($result);
    }

    /**
     * Sanitize workflow data from form submission
     */
    private function sanitize_workflow_data($workflow_data)
    {
        $attribution_user = intval($workflow_data['attribution_user'] ?? 0);
        if ($attribution_user === 0) {
            $attribution_user = null;
        }

        // Saving a workflow needs `edit_posts`. Naming somebody else as the author of what
        // the workflow writes is acting on another user's behalf, which is what
        // `edit_others_posts` means — without it the field is dropped rather than honoured.
        if ($attribution_user !== null && !current_user_can('edit_others_posts')) {
            \PolyTrans\Core\LogsManager::log(
                'Attribution user dropped from workflow: the current user cannot edit others\' posts.',
                'warning',
                ['source' => 'postprocessing_menu', 'requested_attribution_user' => $attribution_user]
            );
            $attribution_user = null;
        }

        return [
            'id' => sanitize_text_field($workflow_data['id'] ?? ''),
            'name' => sanitize_text_field($workflow_data['name'] ?? ''),
            'description' => sanitize_textarea_field($workflow_data['description'] ?? ''),
            'language' => sanitize_text_field($workflow_data['language'] ?? 'en'),
            'priority' => intval($workflow_data['priority'] ?? 100),
            'enabled' => $workflow_data['enabled'] === 'true',
            'attribution_user' => $attribution_user,
            'triggers' => [
                'on_translation_complete' => !empty($workflow_data['triggers']['on_translation_complete']),
                'manual_only' => $workflow_data['triggers']['manual_only'] === 'true',
                'conditions' => $this->sanitize_trigger_conditions($workflow_data['triggers']['conditions'] ?? [])
            ],
            'steps' => $this->sanitize_workflow_steps($workflow_data['steps'] ?? [])
        ];
    }

    /**
     * Sanitize trigger conditions
     */
    private function sanitize_trigger_conditions($conditions)
    {
        $sanitized = [];

        if (isset($conditions['post_type']) && is_array($conditions['post_type'])) {
            $sanitized['post_type'] = array_map('sanitize_text_field', $conditions['post_type']);
        }

        if (isset($conditions['category']) && is_array($conditions['category'])) {
            $sanitized['category'] = array_map('sanitize_text_field', $conditions['category']);
        }

        return $sanitized;
    }

    /**
     * Sanitize workflow steps
     */
    private function sanitize_workflow_steps($steps)
    {
        if (!is_array($steps)) {
            return [];
        }

        $sanitized_steps = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $sanitized_step = [
                'id' => sanitize_text_field($step['id'] ?? ''),
                'name' => sanitize_text_field($step['name'] ?? ''),
                'description' => sanitize_textarea_field($step['description'] ?? ''),
                'type' => sanitize_text_field($step['type'] ?? ''),
                'enabled' => !empty($step['enabled'])
            ];

            // Sanitize step-specific fields
            switch ($sanitized_step['type']) {
                case 'ai_assistant':
                    $sanitized_step['provider'] = sanitize_text_field($step['provider'] ?? '');
                    $sanitized_step['system_prompt'] = $this->sanitize_prompt_field($step['system_prompt'] ?? '');
                    $sanitized_step['user_message'] = $this->sanitize_prompt_field($step['user_message'] ?? '');
                    $sanitized_step['model'] = $this->sanitize_model_field($step['model'] ?? '');
                    $sanitized_step['expected_format'] = sanitize_text_field($step['expected_format'] ?? 'text');
                    $sanitized_step['temperature'] = !empty($step['temperature']) ? floatval($step['temperature']) : 0.7;

                    // Reasoning effort - canonical level, translated per provider at request time
                    $reasoning_effort = isset($step['reasoning_effort'])
                        ? sanitize_text_field($step['reasoning_effort'])
                        : '';
                    if (in_array($reasoning_effort, \PolyTrans\Core\ModelCapabilities::LEVELS, true)) {
                        $sanitized_step['reasoning_effort'] = $reasoning_effort;
                    }


                    // Handle max_tokens if provided
                    if (isset($step['max_tokens']) && !empty($step['max_tokens'])) {
                        $sanitized_step['max_tokens'] = absint($step['max_tokens']);
                    }

                    if (isset($step['output_variables']) && is_array($step['output_variables'])) {
                        $sanitized_step['output_variables'] = array_map('sanitize_text_field', $step['output_variables']);
                    } elseif (isset($step['output_variables'])) {
                        // Handle comma-separated string
                        $output_vars = explode(',', $step['output_variables']);
                        $sanitized_step['output_variables'] = array_map('trim', array_map('sanitize_text_field', $output_vars));
                    }

                    // Handle output actions
                    if (isset($step['output_actions']) && is_array($step['output_actions'])) {
                        $sanitized_step['output_actions'] = $this->sanitize_output_actions($step['output_actions']);
                    }
                    break;

                case 'predefined_assistant':
                    $sanitized_step['assistant_id'] = sanitize_text_field($step['assistant_id'] ?? '');
                    $sanitized_step['user_message'] = $this->sanitize_prompt_field($step['user_message'] ?? '');

                    if (isset($step['output_variables']) && is_array($step['output_variables'])) {
                        $sanitized_step['output_variables'] = array_map('sanitize_text_field', $step['output_variables']);
                    } elseif (isset($step['output_variables'])) {
                        // Handle comma-separated string
                        $output_vars = explode(',', $step['output_variables']);
                        $sanitized_step['output_variables'] = array_map('trim', array_map('sanitize_text_field', $output_vars));
                    }

                    // Handle output actions
                    if (isset($step['output_actions']) && is_array($step['output_actions'])) {
                        $sanitized_step['output_actions'] = $this->sanitize_output_actions($step['output_actions']);
                    }
                    break;

                case 'managed_assistant':
                    $sanitized_step['assistant_id'] = intval($step['assistant_id'] ?? 0);

                    // Handle output actions
                    if (isset($step['output_actions']) && is_array($step['output_actions'])) {
                        $sanitized_step['output_actions'] = $this->sanitize_output_actions($step['output_actions']);
                    }
                    break;
            }

            $sanitized_steps[] = $sanitized_step;
        }

        return $sanitized_steps;
    }

    /**
     * Sanitize output actions
     */
    private function sanitize_output_actions($output_actions)
    {
        if (!is_array($output_actions)) {
            return [];
        }

        $sanitized_actions = [];

        foreach ($output_actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $sanitized_action = [
                'type' => sanitize_text_field($action['type'] ?? ''),
                'source_variable' => sanitize_text_field($action['source_variable'] ?? ''),
                'target' => sanitize_text_field($action['target'] ?? '')
            ];

            // Only add if required fields are present
            // Allow empty source_variable as it can be auto-detected
            if (!empty($sanitized_action['type'])) {
                $sanitized_actions[] = $sanitized_action;
            }
        }

        return $sanitized_actions;
    }

    /**
     * Sanitize prompt field while preserving angle brackets and quotes
     */
    private function sanitize_prompt_field($prompt)
    {
        if (empty($prompt)) {
            return '';
        }

        // Remove null bytes and validate UTF-8
        $prompt = str_replace(chr(0), '', $prompt);
        $prompt = wp_check_invalid_utf8($prompt);

        // Remove dangerous script tags and on* attributes while preserving other content
        $prompt = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $prompt);
        $prompt = preg_replace('/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $prompt);

        // Normalize line breaks
        $prompt = str_replace(array("\r\n", "\r"), "\n", $prompt);

        return trim($prompt);
    }

    /**
     * Sanitize model field and validate against available models
     */
    private function sanitize_model_field($model)
    {
        if (empty($model)) {
            return '';
        }

        $model = sanitize_text_field($model);

        // Get available models from the OpenAI settings provider (pass model for backward compatibility)
        $available_models = $this->get_openai_models($model);

        // Flatten the grouped models to get all valid model values
        $valid_models = [];
        foreach ($available_models as $group => $models) {
            $valid_models = array_merge($valid_models, array_keys($models));
        }

        // Return the model if it's valid, otherwise return empty string
        return in_array($model, $valid_models) ? $model : '';
    }

    /**
     * Normalize workflow data to ensure all required fields are present with default values
     */
    private function normalize_workflow_data($workflow)
    {
        // Ensure triggers section exists with all required fields
        if (!isset($workflow['triggers'])) {
            $workflow['triggers'] = [];
        }

        // Set default values for missing trigger fields
        $workflow['triggers'] = array_merge([
            'on_translation_complete' => true,
            'manual_only' => false,
            'conditions' => []
        ], $workflow['triggers']);

        // Ensure other required fields exist
        $workflow = array_merge([
            'id' => '',
            'name' => '',
            'description' => '',
            'language' => 'en',
            'priority' => 100,
            'enabled' => true,
            'attribution_user' => null,
            'steps' => []
        ], $workflow);

        return $workflow;
    }
}

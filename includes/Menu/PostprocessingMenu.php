<?php

/**
 * Post-Processing Workflows Menu
 * 
 * Handles the admin menu page for managing post-processing workflows.
 */

namespace PolyTrans\Menu;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantMigration;
use PolyTrans\PostProcessing\Testing\WorkflowRefinementService;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\Templating\TemplateRenderer;

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
        add_action('wp_ajax_polytrans_test_workflow', [$this, 'ajax_test_workflow']);
        add_action('wp_ajax_polytrans_search_posts', [$this, 'ajax_search_posts']);
        add_action('wp_ajax_polytrans_get_post_data', [$this, 'ajax_get_post_data']);
        add_action('wp_ajax_polytrans_run_workflow_refinement_post', [$this, 'ajax_run_workflow_refinement_post']);
        add_action('wp_ajax_polytrans_evaluate_workflow_refinement_run', [$this, 'ajax_evaluate_workflow_refinement_run']);
        add_action('wp_ajax_polytrans_adjust_workflow_prompt', [$this, 'ajax_adjust_workflow_prompt']);
        add_action('wp_ajax_polytrans_apply_workflow_prompt_pack', [$this, 'ajax_apply_workflow_prompt_pack']);
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
            __('Post-Processing Workflows', 'polytrans'),
            __('Post-Processing', 'polytrans'),
            'edit_posts',
            'polytrans-workflows',
            [$this, 'render_workflow_page']
        );

        add_submenu_page(
            'polytrans',
            __('Execute Workflow', 'polytrans'),
            __('Execute Workflow', 'polytrans'),
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
            
            wp_localize_script('polytrans-workflows', 'polytransWorkflows', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_workflows_nonce'),
                'openai_nonce' => wp_create_nonce('polytrans_openai_nonce'),
                'models' => $this->get_openai_models($selected_model),
                'selected_model' => $selected_model,
                'chatProviders' => $chat_providers,
                'strings' => [
                    'confirmDelete' => __('Are you sure you want to delete this workflow?', 'polytrans'),
                    'confirmDuplicate' => __('Create a copy of this workflow?', 'polytrans'),
                    'saveSuccess' => __('Workflow saved successfully!', 'polytrans'),
                    'saveError' => __('Error saving workflow.', 'polytrans'),
                    'deleteSuccess' => __('Workflow deleted successfully!', 'polytrans'),
                    'deleteError' => __('Error deleting workflow.', 'polytrans'),
                    'testSuccess' => __('Test completed successfully!', 'polytrans'),
                    'testError' => __('Test failed.', 'polytrans'),
                    'loading' => __('Loading...', 'polytrans'),
                    'addStep' => __('Add Step', 'polytrans'),
                    'removeStep' => __('Remove Step', 'polytrans'),
                    'moveUp' => __('Move Up', 'polytrans'),
                    'moveDown' => __('Move Down', 'polytrans'),
                    'clearSelection' => __('Clear', 'polytrans'),
                    'noProviderSelected' => __('No provider selected. A random enabled provider will be used.', 'polytrans'),
                    'allLanguages' => __('All languages', 'polytrans'),
                    'allLanguagesOption' => __('— All languages —', 'polytrans'),
                    'allLanguagesDescription' => __('Select a specific language or "All languages" to run this workflow for any translation target', 'polytrans'),
                    'enableWorkflow' => __('Enable workflow', 'polytrans'),
                    'disableWorkflow' => __('Disable workflow', 'polytrans'),
                    'enabled' => __('Enabled', 'polytrans'),
                    'disabled' => __('Disabled', 'polytrans'),
                    'confirmMigrateWorkflow' => __('This will migrate legacy AI assistant steps in this workflow to managed assistants. Unsaved editor changes will not be included. Continue?', 'polytrans'),
                    'migrateWorkflow' => __('Migrate this workflow', 'polytrans'),
                    'migratingWorkflow' => __('Migrating...', 'polytrans'),
                    'migrationError' => __('Migration failed. Please check logs.', 'polytrans'),
                ]
            ]);

            // Localize user autocomplete script
            wp_localize_script('polytrans-user-autocomplete', 'PolyTransUserAutocomplete', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_nonce'),
                'i18n' => [
                    'no_results' => esc_html__('No users found.', 'polytrans'),
                    'searching' => esc_html__('Searching users...', 'polytrans'),
                    'clear_selection' => esc_html__('Clear selection', 'polytrans'),
                    'type_to_search' => esc_html__('Type to search users...', 'polytrans'),
                    'min_chars' => esc_html__('Type at least 2 characters to search.', 'polytrans'),
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
                    'loading' => __('Loading...', 'polytrans'),
                    'searching' => __('Searching...', 'polytrans'),
                    'selectWorkflow' => __('Select workflow...', 'polytrans'),
                    'selectPost' => __('Select a post...', 'polytrans'),
                    'noWorkflows' => __('No workflows available', 'polytrans'),
                    'noPosts' => __('No posts found', 'polytrans'),
                    'executing' => __('Executing...', 'polytrans'),
                    'verifying' => __('Verifying...', 'polytrans'),
                    'verify' => __('Verify', 'polytrans'),
                    'execute' => __('Execute Workflow', 'polytrans'),
                    'executeAnother' => __('Execute Another Workflow', 'polytrans'),
                    'viewPost' => __('View Post', 'polytrans'),
                    'editPost' => __('Edit Post', 'polytrans'),
                    'success' => __('Success!', 'polytrans'),
                    'failed' => __('Failed', 'polytrans'),
                    'error' => __('Error', 'polytrans'),
                    'alreadyRunning' => __('This workflow is already running on this post.', 'polytrans'),
                    'workflowNotFound' => __('Selected workflow does not exist.', 'polytrans'),
                    'postNotFound' => __('Selected post does not exist.', 'polytrans'),
                    'noTranslation' => __('This post does not have a translation in the selected language.', 'polytrans'),
                    'languageMismatch' => __('Post translation language does not match workflow language.', 'polytrans'),
                    'permissionDenied' => __('You do not have permission to execute workflows on this post.', 'polytrans'),
                    'timeout' => __('Execution timed out. Please check logs for details.', 'polytrans'),
                    'allLanguages' => __('All languages', 'polytrans'),
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
                wp_die(esc_html__('Workflow not found.', 'polytrans'));
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
            wp_die(esc_html__('Workflow not found.', 'polytrans'));
        }

        // Pass workflow test data to JavaScript via enqueued script
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowTestData = ' . wp_json_encode($workflow) . ';',
            'before'
        );
        wp_add_inline_script(
            'polytrans-workflows',
            'window.polytransWorkflowRefinementDefaults = ' . wp_json_encode([
                'evaluatorPromptTemplate' => PromptRefinementSettings::workflowEvaluator(),
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

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_workflow_data() below
        $workflow_data = isset($_POST['workflow']) ? wp_unslash($_POST['workflow']) : [];

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
                'message' => __('Workflow saved successfully!', 'polytrans'),
                'workflow_id' => $workflow['id']
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to save workflow.', 'polytrans'),
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
                    ? __('Workflow enabled', 'polytrans')
                    : __('Workflow disabled', 'polytrans')
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
                'message' => __('Workflow duplicated successfully!', 'polytrans'),
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
            wp_send_json_error(['message' => __('Security check failed', 'polytrans')]);
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'polytrans')]);
            return;
        }

        $workflow_id = isset($_POST['workflow_id']) ? sanitize_text_field(wp_unslash($_POST['workflow_id'])) : '';

        if (empty($workflow_id)) {
            wp_send_json_error(['message' => __('No workflow ID provided', 'polytrans')]);
            return;
        }

        $stats = AssistantMigration::migrate_workflow_to_managed_assistants($workflow_id);

        if (!empty($stats['errors'])) {
            wp_send_json_error([
                'message' => __('Migration completed with errors.', 'polytrans'),
                'stats' => $stats,
            ]);
            return;
        }

        if ((int) ($stats['steps_migrated'] ?? 0) === 0) {
            wp_send_json_success([
                'message' => __('No legacy AI assistant steps were found in this workflow.', 'polytrans'),
                'stats' => $stats,
            ]);
            return;
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d: number of steps migrated, %2$d: number of assistants created */
                __('Migration completed successfully. Migrated %1$d steps and created %2$d assistants.', 'polytrans'),
                $stats['steps_migrated'],
                $stats['assistants_created']
            ),
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX: Test workflow
     */
    public function ajax_test_workflow()
    {
        // This is handled by the workflow manager
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        $workflow_manager->ajax_test_workflow();
    }

    /**
     * AJAX: Search posts
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
                    __('Selected post is in %1$s but workflow requires %2$s', 'polytrans'),
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
            wp_send_json_error('No managed assistants found. Create one in PolyTrans > AI Assistants.');
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
            wp_send_json_error(['message' => __('Security check failed.', 'polytrans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Complex nested workflow config, executed in test mode only.
        $workflow = isset($_POST['workflow']) ? wp_unslash($_POST['workflow']) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $selected_post_id = isset($_POST['selected_post_id']) ? intval($_POST['selected_post_id']) : 0;
        $source_language = isset($_POST['source_language']) ? sanitize_text_field(wp_unslash($_POST['source_language'])) : '';
        $target_language = isset($_POST['target_language']) ? sanitize_text_field(wp_unslash($_POST['target_language'])) : '';
        $override_system_prompt = array_key_exists('override_system_prompt', $_POST)
            ? wp_unslash($_POST['override_system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_user_message_template = array_key_exists('override_user_message_template', $_POST)
            ? wp_unslash($_POST['override_user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $override_expected_output_schema = array_key_exists('override_expected_output_schema', $_POST)
            ? wp_unslash($_POST['override_expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
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
            wp_send_json_error(['message' => __('Security check failed.', 'polytrans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
            return;
        }

        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $evaluator_prompt_template = isset($_POST['evaluator_prompt_template']) ? wp_unslash($_POST['evaluator_prompt_template']) : '';

        $result = (new WorkflowRefinementService())->evaluateRun(
            $run_id,
            $target_step_id,
            $criteria,
            is_string($evaluator_prompt_template) ? $evaluator_prompt_template : ''
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Build prompt adjustment proposal from full workflow evaluations.
     */
    public function ajax_adjust_workflow_prompt()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'polytrans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
            return;
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Complex nested workflow config, executed in test mode only.
        $workflow = isset($_POST['workflow']) ? wp_unslash($_POST['workflow']) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $criteria = isset($_POST['criteria']) ? sanitize_textarea_field(wp_unslash($_POST['criteria'])) : '';
        $adjuster_prompt_template = isset($_POST['adjuster_prompt_template']) ? wp_unslash($_POST['adjuster_prompt_template']) : '';
        $evaluations_payload = $_POST['evaluations'] ?? '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- protected by check_ajax_referer above
        $current_system_prompt = array_key_exists('current_system_prompt', $_POST)
            ? wp_unslash($_POST['current_system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $current_user_message_template = array_key_exists('current_user_message_template', $_POST)
            ? wp_unslash($_POST['current_user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;
        $current_expected_output_schema = array_key_exists('current_expected_output_schema', $_POST)
            ? wp_unslash($_POST['current_expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin test payload
            : null;

        $result = (new WorkflowRefinementService())->adjustPrompt(
            $assistant_id,
            $criteria,
            is_string($adjuster_prompt_template) ? $adjuster_prompt_template : '',
            $evaluations_payload,
            $current_system_prompt,
            $current_user_message_template,
            $current_expected_output_schema,
            is_array($workflow) ? $workflow : [],
            $target_step_id
        );

        $this->send_workflow_refinement_result($result);
    }

    /**
     * AJAX: Apply prompt pack generated from workflow refinement to the selected target.
     */
    public function ajax_apply_workflow_prompt_pack()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'polytrans')]);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'polytrans')]);
            return;
        }

        $assistant_id = isset($_POST['assistant_id']) ? intval($_POST['assistant_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Complex nested workflow config from trusted admin UI.
        $workflow = isset($_POST['workflow']) ? wp_unslash($_POST['workflow']) : [];
        $target_step_id = isset($_POST['target_step_id']) ? sanitize_text_field(wp_unslash($_POST['target_step_id'])) : '';
        $target_step_type = isset($_POST['target_step_type']) ? sanitize_text_field(wp_unslash($_POST['target_step_type'])) : '';
        $base_prompt_pack = array_key_exists('base_prompt_pack', $_POST)
            ? wp_unslash($_POST['base_prompt_pack']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : null;
        $system_prompt = array_key_exists('system_prompt', $_POST)
            ? (string) wp_unslash($_POST['system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $user_message_template = array_key_exists('user_message_template', $_POST)
            ? (string) wp_unslash($_POST['user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $expected_output_schema = array_key_exists('expected_output_schema', $_POST)
            ? (string) wp_unslash($_POST['expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
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

        return [
            'id' => sanitize_text_field($workflow_data['id'] ?? ''),
            'name' => sanitize_text_field($workflow_data['name'] ?? ''),
            'description' => sanitize_textarea_field($workflow_data['description'] ?? ''),
            'language' => sanitize_text_field($workflow_data['language'] ?? 'en'),
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
            'enabled' => true,
            'attribution_user' => null,
            'steps' => []
        ], $workflow);

        return $workflow;
    }
}

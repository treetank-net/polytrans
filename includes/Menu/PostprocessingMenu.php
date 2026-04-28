<?php

/**
 * Post-Processing Workflows Menu
 * 
 * Handles the admin menu page for managing post-processing workflows.
 */

namespace PolyTrans\Menu;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Core\ChatClientFactory;
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
                'evaluatorPromptTemplate' => $this->get_default_workflow_evaluator_prompt_template(),
                'adjusterPromptTemplate' => $this->get_default_workflow_adjuster_prompt_template(),
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

        if (empty($workflow) || !is_array($workflow)) {
            wp_send_json_error(['message' => __('Workflow data is required.', 'polytrans')]);
            return;
        }
        if ($target_step_id === '') {
            wp_send_json_error(['message' => __('Select a workflow step to refine.', 'polytrans')]);
            return;
        }
        if ($selected_post_id <= 0) {
            wp_send_json_error(['message' => __('Select a valid post for refinement.', 'polytrans')]);
            return;
        }

        $target_step = $this->find_managed_assistant_step($workflow, $target_step_id);
        if (!$target_step) {
            wp_send_json_error(['message' => __('Selected workflow step is not a managed assistant step.', 'polytrans')]);
            return;
        }

        $assistant_id = intval($target_step['assistant_id'] ?? 0);
        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            wp_send_json_error(['message' => __('Target assistant was not found.', 'polytrans')]);
            return;
        }
        if (($assistant['status'] ?? 'active') !== 'active') {
            wp_send_json_error(['message' => __('Target assistant is inactive.', 'polytrans')]);
            return;
        }

        $context = $this->build_workflow_refinement_context_from_post($selected_post_id, $source_language, $target_language);
        if (!is_array($context)) {
            wp_send_json_error(['message' => __('Selected post was not found.', 'polytrans')]);
            return;
        }

        $prompt_overrides = [];
        if ($override_system_prompt !== null) {
            $prompt_overrides['system_prompt'] = (string) $override_system_prompt;
        }
        if ($override_user_message_template !== null) {
            $prompt_overrides['user_message_template'] = (string) $override_user_message_template;
        }
        if ($override_expected_output_schema !== null) {
            $prompt_overrides['expected_output_schema'] = (string) $override_expected_output_schema;
        }
        if (!empty($prompt_overrides)) {
            $context['__assistant_prompt_overrides'] = [
                $target_step_id => $prompt_overrides,
            ];
        }

        try {
            $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
            $workflow_result = $workflow_manager->execute_workflow($workflow, $context, true);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        $run_id = $this->generate_workflow_refinement_run_id();
        $run_payload = $this->build_workflow_refinement_run_payload($run_id, $workflow, $target_step, $assistant, $context, $workflow_result);

        if (!$this->persist_workflow_refinement_run_payload($run_id, $run_payload)) {
            wp_send_json_error([
                'message' => __('Failed to persist workflow run. Please retry.', 'polytrans'),
                'error_code' => 'workflow_refinement_run_persist_failed',
            ]);
            return;
        }

        wp_send_json_success($this->build_workflow_refinement_run_response($run_payload));
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

        if ($run_id === '') {
            wp_send_json_error(['message' => __('Run ID is required.', 'polytrans')]);
            return;
        }
        if ($criteria === '') {
            wp_send_json_error(['message' => __('Refinement criteria is required.', 'polytrans')]);
            return;
        }
        if (!is_string($evaluator_prompt_template) || trim($evaluator_prompt_template) === '') {
            $evaluator_prompt_template = $this->get_default_workflow_evaluator_prompt_template();
        }

        $run_payload = get_transient($this->get_workflow_refinement_run_transient_key($run_id));
        if (!is_array($run_payload)) {
            wp_send_json_error([
                'message' => __('Workflow run not found or expired. Run workflow again.', 'polytrans'),
                'error_code' => 'workflow_refinement_run_not_found',
            ]);
            return;
        }
        if ($target_step_id !== '' && (string) ($run_payload['target_step_id'] ?? '') !== $target_step_id) {
            wp_send_json_error([
                'message' => __('Run ID does not belong to the selected workflow step.', 'polytrans'),
                'error_code' => 'workflow_refinement_run_mismatch',
            ]);
            return;
        }

        $evaluation = $this->evaluate_workflow_refinement_run($run_payload, $criteria, $evaluator_prompt_template);
        if (is_wp_error($evaluation)) {
            wp_send_json_error([
                'message' => $evaluation->get_error_message(),
                'error_code' => $evaluation->get_error_code(),
            ]);
            return;
        }

        $run_payload['evaluation'] = $evaluation;
        $run_payload['evaluated_at'] = time();
        $this->persist_workflow_refinement_run_payload($run_id, $run_payload);

        wp_send_json_success([
            'run_id' => $run_id,
            'target_step_id' => (string) ($run_payload['target_step_id'] ?? ''),
            'assistant_id' => (int) ($run_payload['assistant_id'] ?? 0),
            'post_id' => (int) ($run_payload['post']['id'] ?? 0),
            'post_title' => (string) ($run_payload['post']['title'] ?? ''),
            'workflow_success' => !empty($run_payload['workflow_result']['success']),
            'evaluation' => $evaluation,
            'final_output' => $run_payload['final_output'] ?? [],
        ]);
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

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Invalid assistant ID.', 'polytrans')]);
            return;
        }
        if ($criteria === '') {
            wp_send_json_error(['message' => __('Refinement criteria is required.', 'polytrans')]);
            return;
        }
        if (!is_string($adjuster_prompt_template) || trim($adjuster_prompt_template) === '') {
            $adjuster_prompt_template = $this->get_default_workflow_adjuster_prompt_template();
        }

        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            wp_send_json_error(['message' => __('Assistant not found.', 'polytrans')]);
            return;
        }

        $evaluations = $this->decode_refinement_evaluations($evaluations_payload);
        if (empty($evaluations)) {
            wp_send_json_error(['message' => __('At least one evaluated workflow run is required.', 'polytrans')]);
            return;
        }

        $current_prompt_pack = $this->get_prompt_pack_from_assistant($assistant);
        if ($current_system_prompt !== null) {
            $current_prompt_pack['system_prompt'] = (string) $current_system_prompt;
        }
        if ($current_user_message_template !== null) {
            $current_prompt_pack['user_message_template'] = (string) $current_user_message_template;
        }
        if ($current_expected_output_schema !== null) {
            $current_prompt_pack['expected_output_schema'] = (string) $current_expected_output_schema;
        }

        $should_adjust_expected_output_schema = $this->should_adjust_expected_output_schema($assistant);
        $workflow_context = is_array($evaluations[0]['workflow_context'] ?? null) ? $evaluations[0]['workflow_context'] : [];
        $rendered_adjuster_prompt = $this->render_prompt_template($adjuster_prompt_template, [
            'criteria' => $criteria,
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'non_interpolated_system_prompt' => $current_prompt_pack['system_prompt'],
            'non_interpolated_user_message_template' => $current_prompt_pack['user_message_template'],
            'non_interpolated_expected_output_schema' => $current_prompt_pack['expected_output_schema'],
            'workflow_context_json' => $this->encode_refinement_prompt_json($workflow_context, 60000),
            'workflow_structure_json' => $this->encode_refinement_prompt_json($workflow_context['steps'] ?? [], 35000),
            'target_step_context_json' => $this->encode_refinement_prompt_json($workflow_context['target_step'] ?? [], 35000),
            'previous_steps_json' => $this->encode_refinement_prompt_json($workflow_context['previous_steps'] ?? [], 18000),
            'following_steps_json' => $this->encode_refinement_prompt_json($workflow_context['following_steps'] ?? [], 18000),
            'evaluations' => $evaluations,
            'evaluations_json' => $this->encode_refinement_prompt_json($evaluations, 65000),
        ]);

        $adjustment = $this->execute_chat_text_with_assistant(
            $assistant,
            $rendered_adjuster_prompt,
            $this->get_prompt_adjuster_system_prompt()
        );

        if (is_wp_error($adjustment)) {
            wp_send_json_error([
                'message' => $adjustment->get_error_message(),
                'error_code' => $adjustment->get_error_code(),
            ]);
            return;
        }

        $parsed = $this->parse_adjusted_prompt_pack(
            $adjustment['content'] ?? '',
            $should_adjust_expected_output_schema,
            $current_prompt_pack['expected_output_schema'] ?? '{}'
        );

        wp_send_json_success([
            'assistant_id' => $assistant_id,
            'assistant_name' => $assistant['name'] ?? '',
            'provider' => $adjustment['provider'] ?? ($assistant['provider'] ?? ''),
            'model' => $adjustment['model'] ?? ($assistant['api_parameters']['model'] ?? ''),
            'usage' => $adjustment['usage'] ?? [],
            'adjuster_prompt_rendered' => $rendered_adjuster_prompt,
            'adjuster_response' => $adjustment['content'] ?? '',
            'format' => 'json',
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'input_prompt_pack' => $current_prompt_pack,
            'parsed' => $parsed,
        ]);
    }

    /**
     * AJAX: Apply prompt pack generated from workflow refinement to the managed assistant.
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
        $system_prompt = array_key_exists('system_prompt', $_POST)
            ? (string) wp_unslash($_POST['system_prompt']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $user_message_template = array_key_exists('user_message_template', $_POST)
            ? (string) wp_unslash($_POST['user_message_template']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : '';
        $expected_output_schema = array_key_exists('expected_output_schema', $_POST)
            ? (string) wp_unslash($_POST['expected_output_schema']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- trusted admin payload
            : null;

        if ($assistant_id <= 0) {
            wp_send_json_error(['message' => __('Invalid assistant ID.', 'polytrans')]);
            return;
        }
        if (trim($system_prompt) === '') {
            wp_send_json_error(['message' => __('System prompt cannot be empty.', 'polytrans')]);
            return;
        }

        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            wp_send_json_error(['message' => __('Assistant not found.', 'polytrans')]);
            return;
        }

        $previous_prompt_pack = $this->get_prompt_pack_from_assistant($assistant);
        if (!$this->should_adjust_expected_output_schema($assistant)) {
            $expected_output_schema = $assistant['expected_output_schema'] ?? null;
        } elseif ($expected_output_schema === null) {
            $expected_output_schema = '{}';
        }

        $assistant_update = [
            'name' => $assistant['name'] ?? '',
            'description' => $assistant['description'] ?? '',
            'provider' => $assistant['provider'] ?? 'openai',
            'status' => $assistant['status'] ?? 'active',
            'system_prompt' => $system_prompt,
            'user_message_template' => $user_message_template,
            'api_parameters' => $assistant['api_parameters'] ?? [],
            'expected_format' => $assistant['expected_format'] ?? 'text',
            'expected_output_schema' => $expected_output_schema,
            'output_variables' => $assistant['output_variables'] ?? null,
        ];

        $updated = AssistantManager::update_assistant($assistant_id, $assistant_update);
        if (is_wp_error($updated)) {
            wp_send_json_error([
                'message' => $updated->get_error_message(),
                'error_code' => $updated->get_error_code(),
                'errors' => $updated->get_error_data(),
            ]);
            return;
        }

        wp_send_json_success([
            'assistant_id' => $assistant_id,
            'assistant_name' => $assistant['name'] ?? '',
            'previous_prompt_pack' => $previous_prompt_pack,
            'applied_prompt_pack' => [
                'system_prompt' => $system_prompt,
                'user_message_template' => $user_message_template,
                'expected_output_schema' => $expected_output_schema,
            ],
        ]);
    }

    /**
     * Find a managed assistant step inside a workflow.
     */
    private function find_managed_assistant_step($workflow, $target_step_id)
    {
        $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            if ((string) ($step['id'] ?? '') !== (string) $target_step_id) {
                continue;
            }
            if (($step['type'] ?? '') !== 'managed_assistant') {
                return null;
            }
            $step['__index'] = $index;
            return $step;
        }

        return null;
    }

    /**
     * Build workflow refinement context from a real post.
     */
    private function build_workflow_refinement_context_from_post($post_id, $source_language, $target_language)
    {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        $original_post_id = intval(get_post_meta($post_id, '_polytrans_original_post_id', true));
        if ($original_post_id <= 0) {
            $original_post_id = $post_id;
        }

        if ($target_language === '' && function_exists('pll_get_post_language')) {
            $target_language = (string) pll_get_post_language($post_id);
        }
        if ($source_language === '' && function_exists('pll_get_post_language')) {
            $source_language = (string) pll_get_post_language($original_post_id);
        }

        return [
            'original_post_id' => $original_post_id,
            'translated_post_id' => $post_id,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'trigger' => 'workflow_refinement_test',
            'test_mode' => true,
        ];
    }

    /**
     * Build transient payload for a workflow refinement run.
     */
    private function build_workflow_refinement_run_payload($run_id, $workflow, $target_step, $assistant, $context, $workflow_result)
    {
        $target_step_result = $this->find_step_result_by_id($workflow_result, (string) ($target_step['id'] ?? ''));
        $post_id = (int) ($context['translated_post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;

        return [
            'run_id' => (string) $run_id,
            'workflow_id' => (string) ($workflow['id'] ?? ''),
            'workflow_name' => (string) ($workflow['name'] ?? ''),
            'target_step_id' => (string) ($target_step['id'] ?? ''),
            'target_step_name' => (string) ($target_step['name'] ?? ''),
            'target_step_index' => (int) ($target_step['__index'] ?? 0),
            'assistant_id' => (int) ($assistant['id'] ?? 0),
            'assistant_name' => (string) ($assistant['name'] ?? ''),
            'assistant_config' => $this->build_refinement_assistant_config_snapshot($assistant),
            'context' => [
                'original_post_id' => (int) ($context['original_post_id'] ?? 0),
                'translated_post_id' => (int) ($context['translated_post_id'] ?? 0),
                'source_language' => (string) ($context['source_language'] ?? ''),
                'target_language' => (string) ($context['target_language'] ?? ''),
            ],
            'post' => [
                'id' => $post_id,
                'title' => $post ? (string) $post->post_title : '',
                'excerpt' => $post ? (string) wp_trim_words(wp_strip_all_tags($post->post_content), 24, '...') : '',
            ],
            'used_prompt_pack' => $this->get_prompt_pack_from_assistant($assistant),
            'workflow_context' => $this->build_workflow_refinement_context_map($workflow, $target_step, $workflow_result),
            'target_step_result' => $this->compact_workflow_step_result_for_refinement($target_step_result, true),
            'workflow_result' => [
                'success' => !empty($workflow_result['success']),
                'steps_executed' => (int) ($workflow_result['steps_executed'] ?? 0),
                'execution_time' => (float) ($workflow_result['execution_time'] ?? 0),
                'test_mode' => !empty($workflow_result['test_mode']),
            ],
            'workflow_result_summary' => $this->summarize_workflow_result($workflow_result),
            'final_output' => $this->build_workflow_final_output_snapshot($workflow_result),
            'created_at' => time(),
        ];
    }

    /**
     * Build response payload for workflow refinement run.
     */
    private function build_workflow_refinement_run_response($run_payload)
    {
        $target_step_result = is_array($run_payload['target_step_result'] ?? null) ? $run_payload['target_step_result'] : [];

        return [
            'run_id' => (string) ($run_payload['run_id'] ?? ''),
            'run_ttl_seconds' => $this->get_workflow_refinement_run_ttl(),
            'workflow_id' => (string) ($run_payload['workflow_id'] ?? ''),
            'workflow_name' => (string) ($run_payload['workflow_name'] ?? ''),
            'workflow_success' => !empty($run_payload['workflow_result']['success']),
            'target_step_id' => (string) ($run_payload['target_step_id'] ?? ''),
            'target_step_name' => (string) ($run_payload['target_step_name'] ?? ''),
            'assistant_id' => (int) ($run_payload['assistant_id'] ?? 0),
            'assistant_name' => (string) ($run_payload['assistant_name'] ?? ''),
            'post_id' => (int) ($run_payload['post']['id'] ?? 0),
            'post_title' => (string) ($run_payload['post']['title'] ?? ''),
            'post_excerpt' => (string) ($run_payload['post']['excerpt'] ?? ''),
            'assistant_output' => $target_step_result['data'] ?? null,
            'interpolated_system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
            'interpolated_user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
            'used_prompt_pack' => $run_payload['used_prompt_pack'] ?? [],
            'workflow_context' => $run_payload['workflow_context'] ?? [],
            'workflow_result_summary' => $run_payload['workflow_result_summary'] ?? [],
            'final_output' => $run_payload['final_output'] ?? [],
        ];
    }

    /**
     * Evaluate a stored workflow refinement run.
     *
     * @return array|\WP_Error
     */
    private function evaluate_workflow_refinement_run($run_payload, $criteria, $evaluator_prompt_template)
    {
        $assistant = is_array($run_payload['assistant_config'] ?? null) ? $run_payload['assistant_config'] : [];
        if (empty($assistant)) {
            return new \WP_Error('workflow_refinement_missing_assistant', __('Stored workflow run is missing assistant configuration.', 'polytrans'));
        }

        $target_step_result = is_array($run_payload['target_step_result'] ?? null) ? $run_payload['target_step_result'] : [];
        $assistant_output = $target_step_result['data'] ?? '';
        if (!is_string($assistant_output)) {
            $assistant_output = wp_json_encode($assistant_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $rendered_evaluator_prompt = $this->render_prompt_template($evaluator_prompt_template, [
            'criteria' => $criteria,
            'workflow_name' => (string) ($run_payload['workflow_name'] ?? ''),
            'workflow_id' => (string) ($run_payload['workflow_id'] ?? ''),
            'workflow_success' => !empty($run_payload['workflow_result']['success']) ? 'true' : 'false',
            'source_language' => (string) ($run_payload['context']['source_language'] ?? ''),
            'target_language' => (string) ($run_payload['context']['target_language'] ?? ''),
            'target_step_id' => (string) ($run_payload['target_step_id'] ?? ''),
            'target_step_name' => (string) ($run_payload['target_step_name'] ?? ''),
            'target_interpolated_system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
            'target_interpolated_user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
            'target_assistant_output' => (string) $assistant_output,
            'include_expected_output_schema' => $this->should_adjust_expected_output_schema($assistant),
            'expected_output_schema' => $this->normalize_expected_output_schema_for_prompt($assistant['expected_output_schema'] ?? null),
            'workflow_context_json' => $this->encode_refinement_prompt_json($run_payload['workflow_context'] ?? [], 60000),
            'workflow_structure_json' => $this->encode_refinement_prompt_json($run_payload['workflow_context']['steps'] ?? [], 35000),
            'target_step_context_json' => $this->encode_refinement_prompt_json($run_payload['workflow_context']['target_step'] ?? [], 35000),
            'previous_steps_json' => $this->encode_refinement_prompt_json($run_payload['workflow_context']['previous_steps'] ?? [], 18000),
            'following_steps_json' => $this->encode_refinement_prompt_json($run_payload['workflow_context']['following_steps'] ?? [], 18000),
            'final_output_json' => $this->encode_refinement_prompt_json($run_payload['final_output'] ?? [], 18000),
            'workflow_result_json' => $this->encode_refinement_prompt_json($run_payload['workflow_result_summary'] ?? [], 25000),
        ]);

        $evaluation_response = $this->execute_chat_text_with_assistant(
            $assistant,
            $rendered_evaluator_prompt,
            'You are a strict workflow quality evaluator. Be concise and always include one numeric score.'
        );

        if (is_wp_error($evaluation_response)) {
            return $evaluation_response;
        }

        $feedback = (string) ($evaluation_response['content'] ?? '');
        return [
            'score' => $this->extract_numeric_score($feedback),
            'feedback' => $feedback,
            'rendered_prompt' => $rendered_evaluator_prompt,
            'provider' => $evaluation_response['provider'] ?? ($assistant['provider'] ?? ''),
            'model' => $evaluation_response['model'] ?? ($assistant['api_parameters']['model'] ?? ''),
            'usage' => $evaluation_response['usage'] ?? [],
        ];
    }

    /**
     * Find step result from workflow execution response.
     */
    private function find_step_result_by_id($workflow_result, $step_id)
    {
        $step_results = is_array($workflow_result['step_results'] ?? null) ? $workflow_result['step_results'] : [];
        foreach ($step_results as $step_result) {
            if ((string) ($step_result['step_id'] ?? '') === (string) $step_id) {
                return $step_result;
            }
        }

        return [];
    }

    /**
     * Summarize workflow result for evaluator prompts and UI payloads.
     */
    private function summarize_workflow_result($workflow_result)
    {
        $steps = [];
        foreach ((array) ($workflow_result['step_results'] ?? []) as $step_result) {
            if (!is_array($step_result)) {
                continue;
            }
            $steps[] = [
                'step_id' => (string) ($step_result['step_id'] ?? ''),
                'step_name' => (string) ($step_result['step_name'] ?? ''),
                'step_type' => (string) ($step_result['step_type'] ?? ''),
                'success' => !empty($step_result['success']),
                'error' => $step_result['error'] ?? null,
                'data' => $this->compact_refinement_value($step_result['data'] ?? null, 1, 1000),
                'output_processing' => $this->compact_refinement_value($step_result['output_processing'] ?? null, 1, 1000),
            ];
        }

        return [
            'success' => !empty($workflow_result['success']),
            'steps_executed' => (int) ($workflow_result['steps_executed'] ?? 0),
            'execution_time' => (float) ($workflow_result['execution_time'] ?? 0),
            'steps' => $steps,
        ];
    }

    /**
     * Keep only assistant fields needed for evaluation and future adjustment.
     */
    private function build_refinement_assistant_config_snapshot($assistant)
    {
        return [
            'id' => (int) ($assistant['id'] ?? 0),
            'name' => (string) ($assistant['name'] ?? ''),
            'provider' => (string) ($assistant['provider'] ?? 'openai'),
            'status' => (string) ($assistant['status'] ?? 'active'),
            'system_prompt' => (string) ($assistant['system_prompt'] ?? ''),
            'user_message_template' => (string) ($assistant['user_message_template'] ?? ''),
            'expected_format' => (string) ($assistant['expected_format'] ?? 'text'),
            'expected_output_schema' => $this->normalize_expected_output_schema_for_prompt($assistant['expected_output_schema'] ?? null),
            'api_parameters' => is_array($assistant['api_parameters'] ?? null) ? $assistant['api_parameters'] : [],
        ];
    }

    /**
     * Compact a step result for transient storage while preserving prompt-relevant signal.
     */
    private function compact_workflow_step_result_for_refinement($step_result, $is_target = false)
    {
        if (!is_array($step_result)) {
            return [
                'success' => false,
                'error' => null,
                'data' => null,
                'output_processing' => null,
                'interpolated_system_prompt' => null,
                'interpolated_user_message' => null,
            ];
        }

        return [
            'success' => !empty($step_result['success']),
            'error' => $step_result['error'] ?? null,
            'data' => $this->compact_refinement_value($step_result['data'] ?? null, 2, $is_target ? 8000 : 1500),
            'output_processing' => $this->compact_refinement_value($step_result['output_processing'] ?? null, 2, $is_target ? 4000 : 1200),
            'interpolated_system_prompt' => $this->truncate_refinement_text($step_result['interpolated_system_prompt'] ?? null, $is_target ? 12000 : 0),
            'interpolated_user_message' => $this->truncate_refinement_text($step_result['interpolated_user_message'] ?? null, $is_target ? 16000 : 0),
        ];
    }

    /**
     * Recursively compact values before placing them into prompt/transient payloads.
     */
    private function compact_refinement_value($value, $depth = 3, $string_limit = 8000)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncate_refinement_text($value, $string_limit);
        }

        if ($depth <= 0) {
            if (is_array($value)) {
                return sprintf('[array with %d item(s)]', count($value));
            }
            if (is_object($value)) {
                return '[object ' . get_class($value) . ']';
            }
            return (string) $value;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $compacted = [];
            $index = 0;
            foreach ($value as $key => $item) {
                if ($index >= 30) {
                    $compacted['__truncated_items'] = count($value) - $index;
                    break;
                }
                $compacted[$key] = $this->compact_refinement_value($item, $depth - 1, $string_limit);
                $index++;
            }
            return $compacted;
        }

        return (string) $value;
    }

    /**
     * Truncate long text in a UTF-8 safe way and mark truncation explicitly.
     */
    private function truncate_refinement_text($value, $limit)
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        if ($limit === 0) {
            return null;
        }

        if ($limit < 0 || strlen($text) <= $limit) {
            return $text;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit) . "\n\n[truncated for workflow refinement payload]";
        }

        return substr($text, 0, $limit) . "\n\n[truncated for workflow refinement payload]";
    }

    /**
     * Build workflow structure and run context for refinement prompts.
     */
    private function build_workflow_refinement_context_map($workflow, $target_step, $workflow_result)
    {
        $target_step_id = (string) ($target_step['id'] ?? '');
        $steps = [];

        foreach ((array) ($workflow['steps'] ?? []) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }

            $step_id = (string) ($step['id'] ?? "step_{$index}");
            $step_result = $this->find_step_result_by_id($workflow_result, $step_id);
            $step_summary = $this->build_workflow_step_context_summary($step, $step_result, $index, $step_id === $target_step_id);
            $steps[] = $step_summary;
        }

        $target_index = null;
        foreach ($steps as $index => $step) {
            if (!empty($step['is_target'])) {
                $target_index = $index;
                break;
            }
        }

        return [
            'workflow' => [
                'id' => (string) ($workflow['id'] ?? ''),
                'name' => (string) ($workflow['name'] ?? ''),
                'description' => (string) ($workflow['description'] ?? ''),
                'language' => (string) ($workflow['language'] ?? ''),
                'enabled' => !empty($workflow['enabled']),
            ],
            'target_step' => $target_index !== null ? $steps[$target_index] : [],
            'previous_steps' => $target_index !== null ? array_slice($steps, 0, $target_index) : [],
            'following_steps' => $target_index !== null ? array_slice($steps, $target_index + 1) : [],
            'steps' => $steps,
        ];
    }

    /**
     * Summarize one workflow step with enough prompt and output-action context for evaluators.
     */
    private function build_workflow_step_context_summary($step, $step_result, $index, $is_target)
    {
        $summary = [
            'position' => (int) $index + 1,
            'id' => (string) ($step['id'] ?? "step_{$index}"),
            'name' => (string) ($step['name'] ?? ('Step ' . ((int) $index + 1))),
            'type' => (string) ($step['type'] ?? ''),
            'enabled' => !isset($step['enabled']) || !empty($step['enabled']),
            'is_target' => (bool) $is_target,
            'continue_on_error' => !empty($step['continue_on_error']),
            'output_actions' => is_array($step['output_actions'] ?? null) ? $step['output_actions'] : [],
            'run' => $this->compact_workflow_step_result_for_refinement($step_result, $is_target),
        ];

        if (($step['type'] ?? '') === 'managed_assistant') {
            $assistant_id = (int) ($step['assistant_id'] ?? 0);
            $assistant = $assistant_id > 0 ? AssistantManager::get_assistant($assistant_id) : null;

            $summary['assistant_id'] = $assistant_id;
            $summary['assistant_name'] = is_array($assistant) ? (string) ($assistant['name'] ?? '') : '';
            $summary['provider'] = is_array($assistant) ? (string) ($assistant['provider'] ?? '') : '';
            $summary['expected_format'] = is_array($assistant) ? (string) ($assistant['expected_format'] ?? 'text') : '';
            if ($is_target) {
                $summary['non_interpolated_prompt_pack'] = is_array($assistant)
                    ? $this->get_prompt_pack_from_assistant($assistant)
                    : [
                        'system_prompt' => '',
                        'user_message_template' => '',
                        'expected_output_schema' => '{}',
                    ];
            } else {
                $summary['non_interpolated_prompt_pack_summary'] = is_array($assistant)
                    ? [
                        'system_prompt_preview' => $this->truncate_refinement_text($assistant['system_prompt'] ?? '', 1200),
                        'user_message_template_preview' => $this->truncate_refinement_text($assistant['user_message_template'] ?? '', 1200),
                        'has_expected_output_schema' => !empty($assistant['expected_output_schema']),
                    ]
                    : [];
            }
        }

        return $summary;
    }

    /**
     * Build compact final workflow output snapshot.
     */
    private function build_workflow_final_output_snapshot($workflow_result)
    {
        $final_context = is_array($workflow_result['final_context'] ?? null) ? $workflow_result['final_context'] : [];
        $translated = is_array($final_context['translated_post'] ?? null) ? $final_context['translated_post'] : [];
        $meta = [];

        if (isset($translated['meta']) && is_array($translated['meta'])) {
            $meta = $translated['meta'];
        } elseif (isset($final_context['translated_meta']) && is_array($final_context['translated_meta'])) {
            $meta = $final_context['translated_meta'];
        }

        return [
            'title' => (string) ($final_context['title'] ?? ($translated['title'] ?? '')),
            'content' => $this->truncate_refinement_text((string) ($final_context['content'] ?? ($translated['content'] ?? '')), 12000),
            'excerpt' => (string) ($final_context['excerpt'] ?? ($translated['excerpt'] ?? '')),
            'meta' => $this->compact_refinement_value($meta, 2, 3000),
            'previous_steps' => $this->compact_refinement_value($final_context['previous_steps'] ?? [], 2, 2500),
        ];
    }

    /**
     * Decode and normalize evaluation payload from JS.
     */
    private function decode_refinement_evaluations($evaluations_payload)
    {
        $evaluations = [];
        if (is_string($evaluations_payload)) {
            $decoded = json_decode(wp_unslash($evaluations_payload), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $evaluations = $decoded;
            }
        } elseif (is_array($evaluations_payload)) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized before prompt rendering.
            $evaluations = wp_unslash($evaluations_payload);
        }

        $normalized = [];
        foreach ($evaluations as $item) {
            if (!is_array($item)) {
                continue;
            }
            $score = null;
            if (isset($item['evaluation']['score']) && is_numeric($item['evaluation']['score'])) {
                $score = (float) $item['evaluation']['score'];
            } elseif (isset($item['score']) && is_numeric($item['score'])) {
                $score = (float) $item['score'];
            }

            $normalized[] = [
                'run_id' => isset($item['run_id']) ? sanitize_text_field((string) $item['run_id']) : '',
                'post_id' => isset($item['post_id']) ? (int) $item['post_id'] : 0,
                'post_title' => isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '',
                'workflow_success' => !empty($item['workflow_success']),
                'score' => $score,
                'feedback' => isset($item['evaluation']['feedback'])
                    ? (string) $item['evaluation']['feedback']
                    : ((isset($item['feedback'])) ? (string) $item['feedback'] : ''),
                'final_output' => is_array($item['final_output'] ?? null) ? $item['final_output'] : [],
                'workflow_result_summary' => is_array($item['workflow_result_summary'] ?? null) ? $item['workflow_result_summary'] : [],
                'workflow_context' => is_array($item['workflow_context'] ?? null) ? $item['workflow_context'] : [],
            ];
        }

        return $normalized;
    }

    /**
     * Build transient key for workflow refinement run payload.
     */
    private function get_workflow_refinement_run_transient_key($run_id)
    {
        return 'polytrans_workflow_refine_run_' . md5((string) $run_id);
    }

    /**
     * Persist a workflow refinement run and verify it can be read back.
     */
    private function persist_workflow_refinement_run_payload($run_id, $run_payload)
    {
        $transient_key = $this->get_workflow_refinement_run_transient_key($run_id);
        $stored = set_transient($transient_key, $run_payload, $this->get_workflow_refinement_run_ttl());

        if ($stored) {
            return true;
        }

        $read_back = get_transient($transient_key);
        if (is_array($read_back) && (string) ($read_back['run_id'] ?? '') === (string) $run_id) {
            return true;
        }

        \PolyTrans\Core\LogsManager::log(
            'Failed to persist workflow refinement run payload',
            'error',
            [
                'run_id' => (string) $run_id,
                'payload_size_bytes' => strlen(maybe_serialize($run_payload)),
                'transient_key' => $transient_key,
            ]
        );

        return false;
    }

    /**
     * Generate unique workflow refinement run ID.
     */
    private function generate_workflow_refinement_run_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        return uniqid('workflow_run_', true);
    }

    /**
     * Get transient TTL for workflow refinement run payloads.
     */
    private function get_workflow_refinement_run_ttl()
    {
        return 2 * HOUR_IN_SECONDS;
    }

    /**
     * Execute a plain text prompt using the same provider/model as the assistant.
     *
     * @return array|\WP_Error
     */
    private function execute_chat_text_with_assistant($assistant, $user_prompt, $system_prompt)
    {
        $provider = (string) ($assistant['provider'] ?? 'openai');
        $settings = get_option('polytrans_settings', []);
        $client = ChatClientFactory::create($provider, $settings);

        if (!$client) {
            return new \WP_Error(
                'workflow_refinement_provider_unavailable',
                sprintf(
                    /* translators: %s: provider ID */
                    __('Provider "%s" is not available for refinement.', 'polytrans'),
                    $provider
                )
            );
        }

        $parameters = isset($assistant['api_parameters']) && is_array($assistant['api_parameters'])
            ? $assistant['api_parameters']
            : [];
        unset($parameters['migrated_from']);

        $model = isset($parameters['model']) ? trim((string) $parameters['model']) : '';
        if ($model === '') {
            $setting_key = $provider . '_model';
            $model = isset($settings[$setting_key]) ? trim((string) $settings[$setting_key]) : '';
        }
        if ($model !== '') {
            $parameters['model'] = $model;
        }

        $result = $client->chat_completion([
            [
                'role' => 'system',
                'content' => (string) $system_prompt,
            ],
            [
                'role' => 'user',
                'content' => (string) $user_prompt,
            ],
        ], $parameters);

        if (empty($result['success'])) {
            return new \WP_Error(
                'workflow_refinement_api_error',
                (string) ($result['error'] ?? __('Prompt refinement request failed.', 'polytrans'))
            );
        }

        $raw = $result['data'] ?? [];
        $content = $client->extract_content($raw);
        if ($content === null || trim((string) $content) === '') {
            return new \WP_Error('workflow_refinement_empty_response', __('Prompt refinement returned an empty response.', 'polytrans'));
        }

        return [
            'content' => (string) $content,
            'provider' => $provider,
            'model' => $model,
            'usage' => is_array($raw) && isset($raw['usage']) ? $raw['usage'] : [],
        ];
    }

    /**
     * Render Twig template for evaluator/adjuster prompts.
     */
    private function render_prompt_template($template, $context)
    {
        if (!is_string($template)) {
            return '';
        }

        try {
            if (class_exists('\PolyTrans\Templating\TwigEngine')) {
                return \PolyTrans\Templating\TwigEngine::render($template, $context);
            }
        } catch (\Throwable $e) {
            // Fall back to raw template if Twig rendering fails.
        }

        return $template;
    }

    /**
     * Encode prompt context JSON with a hard byte limit.
     */
    private function encode_refinement_prompt_json($value, $limit = 60000)
    {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }

        return $this->truncate_refinement_text($json, $limit);
    }

    /**
     * Normalize expected output schema into prompt-friendly text.
     */
    private function normalize_expected_output_schema_for_prompt($schema)
    {
        if ($schema === null || $schema === '') {
            return '{}';
        }
        if (is_array($schema) || is_object($schema)) {
            return (string) wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $schema;
    }

    /**
     * Extract non-interpolated prompt pack from assistant data.
     */
    private function get_prompt_pack_from_assistant($assistant)
    {
        return [
            'system_prompt' => (string) ($assistant['system_prompt'] ?? ''),
            'user_message_template' => (string) ($assistant['user_message_template'] ?? ''),
            'expected_output_schema' => $this->normalize_expected_output_schema_for_prompt($assistant['expected_output_schema'] ?? null),
        ];
    }

    /**
     * Decide whether expected output schema should be adjusted.
     */
    private function should_adjust_expected_output_schema($assistant)
    {
        $expected_format = isset($assistant['expected_format']) ? strtolower(trim((string) $assistant['expected_format'])) : 'text';
        return $expected_format === 'json';
    }

    /**
     * Parse adjuster response into a prompt pack. JSON is preferred; legacy separator format remains as fallback.
     */
    private function parse_adjusted_prompt_pack($content, $should_adjust_expected_output_schema = true, $fallback_expected_output_schema = '{}')
    {
        $text = trim((string) $content);
        $fallback_schema = $this->normalize_expected_output_schema_for_prompt($fallback_expected_output_schema);
        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $system_prompt = isset($decoded['system_prompt']) ? trim((string) $decoded['system_prompt']) : '';
            $user_message_template = isset($decoded['user_message_template']) ? trim((string) $decoded['user_message_template']) : '';
            $expected_output_schema = $should_adjust_expected_output_schema
                ? (isset($decoded['expected_output_schema']) ? $this->normalize_expected_output_schema_for_prompt($decoded['expected_output_schema']) : '')
                : $fallback_schema;

            if ($system_prompt !== '' && $user_message_template !== '' && (!$should_adjust_expected_output_schema || $expected_output_schema !== '')) {
                return [
                    'is_valid_pack' => true,
                    'system_prompt' => $system_prompt,
                    'user_message_template' => $user_message_template,
                    'expected_output_schema' => $expected_output_schema,
                ];
            }
        }

        $parts = preg_split('/\R---\R/', $text, 3);

        if ($should_adjust_expected_output_schema) {
            if (is_array($parts) && count($parts) === 3) {
                return [
                    'is_valid_pack' => true,
                    'system_prompt' => trim((string) $parts[0]),
                    'user_message_template' => trim((string) $parts[1]),
                    'expected_output_schema' => trim((string) $parts[2]),
                ];
            }

            return [
                'is_valid_pack' => false,
                'system_prompt' => '',
                'user_message_template' => '',
                'expected_output_schema' => '',
            ];
        }

        if (is_array($parts) && count($parts) >= 2) {
            return [
                'is_valid_pack' => true,
                'system_prompt' => trim((string) $parts[0]),
                'user_message_template' => trim((string) $parts[1]),
                'expected_output_schema' => $fallback_schema,
            ];
        }

        return [
            'is_valid_pack' => false,
            'system_prompt' => '',
            'user_message_template' => '',
            'expected_output_schema' => $fallback_schema,
        ];
    }

    /**
     * Extract first numeric score from evaluator response.
     */
    private function extract_numeric_score($text)
    {
        $score_patterns = [
            '/(?:score|rating|ocena)\s*[:=]\s*(100(?:\.\d+)?|[0-9]{1,2}(?:\.\d+)?)/i',
            '/\b(100(?:\.\d+)?|[0-9]{1,2}(?:\.\d+)?)\b/',
        ];

        foreach ($score_patterns as $pattern) {
            if (preg_match($pattern, (string) $text, $matches)) {
                $score = (float) $matches[1];
                if ($score >= 0 && $score <= 100) {
                    return $score;
                }
            }
        }

        return null;
    }

    /**
     * Default workflow evaluator prompt template.
     */
    private function get_default_workflow_evaluator_prompt_template()
    {
        return "You evaluate one selected managed assistant step inside a larger workflow.\n" .
            "The selected target step is the only prompt that may be adjusted later, but judge it by its contribution to the complete workflow outcome.\n" .
            "Use the workflow context to understand what happened before the target step, what the target step produced, how output actions changed workflow variables, and what later steps did with that output.\n" .
            "Criteria: {{ criteria }}\n\n" .
            "Be brief and provide:\n" .
            "1) Numeric score (0-100)\n" .
            "2) 2-4 findings that separate target-step issues from issues caused by previous or following workflow steps\n" .
            "3) One concrete prompt-change suggestion for the target assistant step only\n\n" .
            "Workflow: {{ workflow_name }} ({{ workflow_id }})\n" .
            "Workflow success: {{ workflow_success }}\n" .
            "Source language: {{ source_language }}\n" .
            "Target language: {{ target_language }}\n" .
            "Target step: {{ target_step_name }} ({{ target_step_id }})\n\n" .
            "Workflow structure JSON, including compact summaries of all steps:\n{{ workflow_structure_json }}\n\n" .
            "Target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Target step interpolated system prompt:\n{{ target_interpolated_system_prompt }}\n\n" .
            "Target step interpolated user message:\n{{ target_interpolated_user_message }}\n\n" .
            "{% if include_expected_output_schema %}Target expected output schema:\n{{ expected_output_schema }}\n\n{% endif %}" .
            "Target step assistant output:\n{{ target_assistant_output }}\n\n" .
            "Final workflow output JSON:\n{{ final_output_json }}\n\n" .
            "Workflow step summary JSON:\n{{ workflow_result_json }}";
    }

    /**
     * Default workflow prompt adjuster template.
     */
    private function get_default_workflow_adjuster_prompt_template()
    {
        return "You will receive a non-interpolated system prompt and user message template for one selected managed assistant step inside a larger workflow.\n" .
            "{% if adjust_expected_output_schema %}You will also receive expected output schema for JSON output mode.\n{% endif %}" .
            "The evaluations judge full workflow outcomes over several posts based on criteria: {{ criteria }}.\n\n" .
            "Use workflow context to understand which steps run before the selected target step, which steps run after it, what each step's prompts look like, whether each assistant returns JSON or text, and how output actions write data into workflow variables, post fields or meta.\n" .
            "Adjust only the selected target assistant prompt pack. Do not rewrite prompts for previous or following workflow steps.\n" .
            "If a problem belongs to another workflow step, mention it indirectly only by making the target prompt produce clearer or more useful output for that later step.\n\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags.\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.\n\n" .
            "Return only valid JSON with these keys:\n" .
            "- system_prompt: improved system prompt string\n" .
            "- user_message_template: improved user message template string\n" .
            "{% if adjust_expected_output_schema %}- expected_output_schema: improved expected output schema as a JSON object or JSON string\n{% endif %}" .
            "Do not use markdown fences. Do not split the answer with separators. Prompt text may contain --- and that must remain literal content.\n\n" .
            "Workflow structure JSON, including compact summaries of all steps:\n{{ workflow_structure_json }}\n\n" .
            "Selected target step context JSON:\n{{ target_step_context_json }}\n\n" .
            "Previous steps compact JSON:\n{{ previous_steps_json }}\n\n" .
            "Following steps compact JSON:\n{{ following_steps_json }}\n\n" .
            "Current system prompt:\n{{ non_interpolated_system_prompt }}\n\n" .
            "Current user message template:\n{{ non_interpolated_user_message_template }}\n\n" .
            "{% if adjust_expected_output_schema %}Current expected output schema:\n{{ non_interpolated_expected_output_schema }}\n\n{% else %}Expected output schema is not part of this adjustment and must stay unchanged.\n\n{% endif %}" .
            "Full-workflow evaluations JSON:\n{{ evaluations_json }}";
    }

    /**
     * System prompt for prompt adjuster requests.
     */
    private function get_prompt_adjuster_system_prompt()
    {
        return "You are a prompt optimization assistant. Return only the requested JSON object.\n" .
            "Do not wrap the JSON in markdown fences and do not use section separators.\n" .
            "Remember, interpolation contains Twig-specific variables that relate to specific parts of the user input. Maintain syntax exactly.\n" .
            "Do not rewrite Twig tags, delimiters or whitespace within tags (for example keep {{ content }} exactly as provided).\n" .
            "Variables like content may contain large text blocks. Keep variable references and do not inline or truncate their values.";
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

<?php

namespace PolyTrans\PostProcessing;

use PolyTrans\PostProcessing\Managers\WorkflowStorageManager;

/**
 * Workflow Meta Box for Post Editor
 * Displays available workflows and execution status in the post editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class WorkflowMetabox
{
    private static $instance = null;
    private $storage_manager;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->storage_manager = new WorkflowStorageManager();

        // Add meta box to post editor
        add_action('add_meta_boxes', [$this, 'add_meta_box']);

        // Enqueue scripts for meta box
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX handler for quick execute
        add_action('wp_ajax_polytrans_metabox_quick_execute', [$this, 'ajax_quick_execute']);
    }

    /**
     * Add meta box to post editor
     */
    public function add_meta_box()
    {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'polytrans-workflows-metabox',
                __('PolyTrans Workflows', 'polytrans'),
                [$this, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Enqueue scripts for meta box
     */
    public function enqueue_scripts($hook)
    {
        // Only on post editor
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        wp_enqueue_script(
            'polytrans-workflow-metabox',
            POLYTRANS_PLUGIN_URL . 'assets/js/postprocessing/workflow-metabox.js',
            ['jquery'],
            POLYTRANS_VERSION,
            true
        );

        wp_localize_script('polytrans-workflow-metabox', 'polytransMetabox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('polytrans_workflows_nonce'),
            'executePageUrl' => admin_url('admin.php?page=polytrans-execute-workflow'),
            'strings' => [
                'executing' => __('Executing...', 'polytrans'),
                'execute' => __('Execute', 'polytrans'),
                'error' => __('Error', 'polytrans'),
                'success' => __('Success', 'polytrans'),
                'confirmExecute' => __('Execute this workflow on the current post?', 'polytrans'),
            ]
        ]);

        // Register a style handle for metabox inline CSS
        wp_register_style('polytrans-workflow-metabox', false, [], POLYTRANS_VERSION);
        wp_enqueue_style('polytrans-workflow-metabox');
        wp_add_inline_style('polytrans-workflow-metabox', implode(' ', [
            '.polytrans-workflows-metabox .workflow-item { display: flex; align-items: center; justify-content: space-between; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px; margin-bottom: 8px; transition: background-color 0.2s ease; }',
            '.polytrans-workflows-metabox .workflow-item:hover { background: #f0f0f0; }',
            '.polytrans-workflows-metabox .workflow-name { font-weight: 500; font-size: 13px; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }',
            '.polytrans-workflows-metabox .workflow-steps-count { color: #666; font-size: 12px; font-weight: normal; margin-left: 6px; }',
            '.polytrans-workflows-metabox .workflow-actions { flex-shrink: 0; margin-left: 12px; }',
            '.polytrans-workflows-metabox .execution-running { padding: 12px; background: #e7f3ff; border-left: 3px solid #2271b1; border-radius: 3px; }',
            '.polytrans-workflows-metabox .execution-running .dashicons { color: #2271b1; vertical-align: middle; }',
            '.polytrans-workflows-metabox .execution-running strong { color: #2271b1; }',
            '.polytrans-workflows-metabox .execution-running .description { margin: 5px 0 0 24px; }',
            '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }',
            '.polytrans-workflows-metabox .spinning { animation: spin 1s linear infinite; }',
        ]));
    }

    /**
     * Render meta box content
     */
    public function render_meta_box($post)
    {
        // Get post language
        $post_language = function_exists('pll_get_post_language') ? 
            pll_get_post_language($post->ID) : '';

        // Get all workflows
        $all_workflows = $this->storage_manager->get_all_workflows();

        // Filter workflows by language and enabled status
        $available_workflows = [];
        foreach ($all_workflows as $workflow) {
            // Must match language (or be "all languages") AND be enabled
            $is_enabled = isset($workflow['enabled']) ? $workflow['enabled'] : false;
            $workflow_lang = $workflow['language'] ?? '';
            $matches_language = empty($workflow_lang) || $workflow_lang === $post_language;
            if ($matches_language && $is_enabled) {
                $available_workflows[] = $workflow;
            }
        }

        // Check for running executions
        $running_execution = $this->get_running_execution($post->ID);

        ?>
        <div class="polytrans-workflows-metabox">
            <?php if (empty($post_language)): ?>
                <p class="description">
                    <?php esc_html_e('This post has no language assigned.', 'polytrans'); ?>
                </p>
            <?php elseif (empty($available_workflows)): ?>
                <p class="description">
                    <?php 
                    $lang_name = $this->get_language_name($post_language);
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: language name wrapped in <strong> tags */
                            __('No workflows available for %s posts.', 'polytrans'),
                            '<strong>' . esc_html($lang_name) . '</strong>'
                        ),
                        ['strong' => []]
                    ); 
                    ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=polytrans-workflows&action=new')); ?>" class="button button-small">
                        <?php esc_html_e('Create Workflow', 'polytrans'); ?>
                    </a>
                </p>
            <?php else: ?>
                <?php if ($running_execution): ?>
                    <div class="polytrans-execution-status" data-execution-id="<?php echo esc_attr($running_execution['execution_id']); ?>">
                        <div class="execution-running">
                            <span class="dashicons dashicons-update-alt spinning"></span>
                            <strong><?php esc_html_e('Workflow Running...', 'polytrans'); ?></strong>
                            <p class="description"><?php echo esc_html($running_execution['workflow_name']); ?></p>
                        </div>
                    </div>
                    <hr style="margin: 15px 0;">
                <?php endif; ?>

                <div class="workflows-list">
                    <p class="description" style="margin-bottom: 10px;">
                        <?php 
                    printf(
                            /* translators: %d: number of available workflows */
                            esc_html__('%d workflow(s) available:', 'polytrans'),
                            count($available_workflows)
                        ); 
                        ?>
                    </p>

                    <?php foreach ($available_workflows as $workflow): ?>
                        <div class="workflow-item" data-workflow-id="<?php echo esc_attr($workflow['id']); ?>">
                            <div class="workflow-name">
                                <?php echo esc_html($workflow['name']); ?>
                                <?php if (!empty($workflow['steps'])): ?>
                                    <span class="workflow-steps-count">
                                        (<?php echo count($workflow['steps']); ?> <?php esc_html_e('steps', 'polytrans'); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="workflow-actions">
                                <button 
                                    type="button" 
                                    class="button button-small workflow-quick-execute"
                                    data-workflow-id="<?php echo esc_attr($workflow['id']); ?>"
                                    data-workflow-name="<?php echo esc_attr($workflow['name']); ?>"
                                    data-post-id="<?php echo esc_attr($post->ID); ?>">
                                    <?php esc_html_e('Execute', 'polytrans'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr style="margin: 15px 0;">

                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=polytrans-execute-workflow&post_id=' . $post->ID . '&language=' . urlencode($post_language))); ?>" class="button button-small" target="_blank">
                        <?php esc_html_e('Advanced Execution', 'polytrans'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get language name
     */
    private function get_language_name($lang_code)
    {
        $languages = [
            'en' => 'English',
            'es' => 'Spanish',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
        ];

        return $languages[$lang_code] ?? strtoupper($lang_code);
    }

    /**
     * Check for running execution on this post
     */
    private function get_running_execution($post_id)
    {
        // Check all workflows for running executions
        $all_workflows = $this->storage_manager->get_all_workflows();

        foreach ($all_workflows as $workflow) {
            $lock_key = 'polytrans_workflow_lock_' . $workflow['id'] . '_' . $post_id;
            $lock = get_transient($lock_key);

            if ($lock && !empty($lock['execution_id'])) {
                // Check if execution is still running
                $exec_key = 'polytrans_workflow_exec_' . $lock['execution_id'];
                $status = get_transient($exec_key);

                if ($status && $status['status'] === 'running') {
                    return [
                        'execution_id' => $lock['execution_id'],
                        'workflow_id' => $workflow['id'],
                        'workflow_name' => $workflow['name'],
                        'started_at' => $lock['started_at'] ?? time()
                    ];
                }
            }
        }

        return null;
    }

    /**
     * AJAX: Quick execute workflow
     */
    public function ajax_quick_execute()
    {
        if (!check_ajax_referer('polytrans_workflows_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $workflow_id = sanitize_text_field(wp_unslash($_POST['workflow_id'] ?? ''));
        $post_id = intval($_POST['post_id'] ?? 0);

        if (empty($workflow_id) || empty($post_id)) {
            wp_send_json_error(['message' => 'Missing required parameters']);
            return;
        }

        // Delegate to workflow manager
        $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
        
        // Prepare execution data
        $_POST['translated_post_id'] = $post_id;
        $_POST['original_post_id'] = $post_id; // Same for manual execution
        
        // Determine target language - use post's language (workflow language is for filtering, not execution)
        $post_language = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : '';
        $_POST['target_language'] = $post_language;

        // Call the manual execution handler
        $workflow_manager->ajax_execute_workflow_manual();
    }
}

<?php

namespace PolyTrans\Receiver;

use PolyTrans\Core\LogsManager;

/**
 * Extension that handles receiving translated posts from external translation services.
 * This class acts as a REST API endpoint and delegates the actual translation processing
 * to specialized manager classes for better organization and maintainability.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationReceiverExtension
{
    private $coordinator;
    private $security_manager;

    public function __construct()
    {
        $this->coordinator = new TranslationCoordinator();
        $this->security_manager = new Managers\SecurityManager();
    }

    /**
     * Main handler for receiving translated posts from external services.
     * 
     * @param \WP_REST_Request $request The REST API request containing translation data
     * @return \WP_REST_Response Response with created post ID or error
     */
    public function handle_receive_post($request)
    {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new \WP_REST_Response(['error' => 'Invalid JSON data'], 400);
        }

        // Extract key parameters for logging
        $original_post_id = $params['original_post_id'] ?? 0;
        $target_language = $params['target_language'] ?? '';
        $source_language = $params['source_language'] ?? '';
        $source_metrics = is_array($params['source_metrics'] ?? null)
            ? $params['source_metrics']
            : \PolyTrans\Core\TextMetrics::from_payload($params['translated'] ?? []);
        $run_id = \PolyTrans\Core\TranslationRunManager::normalize_id($params['run_id'] ?? null);
        $run_id = $run_id
            ? \PolyTrans\Core\TranslationRunManager::ensure(
                $run_id,
                [
                    'source_post_id' => $original_post_id,
                    'source_language' => $source_language,
                    'target_language' => $target_language,
                ],
                $source_metrics
            )
            : \PolyTrans\Core\TranslationRunManager::start(
                [
                    'source_post_id' => $original_post_id,
                    'source_language' => $source_language,
                    'target_language' => $target_language,
                ],
                $source_metrics
            );

        // Use global class with leading backslash (not namespaced)
        LogsManager::log("Received translation data for post $original_post_id from $source_language to $target_language", "info");

        // Process the translation using the coordinator
        $result = $this->coordinator->process_translation($params);

        if (!$result['success']) {
            \PolyTrans\Core\TranslationRunManager::fail($run_id);
            $status_code = (isset($result['code']) && $result['code'] === 'missing_data') ? 400 : 500;
            error_log("[polytrans] Translation processing failed: " . $result['error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return new \WP_REST_Response(['error' => $result['error']], $status_code);
        }

        error_log("[polytrans] Translation processing succeeded, created post ID: " . $result['created_post_id']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        \PolyTrans\Core\TranslationRunManager::attach_post($run_id, $result['created_post_id']);

        // Check if we should trigger workflows for received translations
        $settings = get_option('polytrans_settings', []);
        $workflow_mode = $settings['received_translation_workflow_mode'] ?? 'run_workflows';

        // Check if workflows were already executed by the sender (after_workflows dispatch mode)
        $workflows_already_executed = $params['workflows_executed'] ?? false;

        // Determine if workflows will run
        $will_run_workflows = ($workflow_mode === 'run_workflows' && !$workflows_already_executed);

        // Get status manager from coordinator
        $status_manager = $this->coordinator->get_status_manager();

        if ($will_run_workflows) {
            // Set intermediate status before workflows run
            $status_manager->update_status_intermediate($original_post_id, $target_language, $result['created_post_id'], 'post_processing');
            LogsManager::log("Set post_processing status, triggering workflows for post {$result['created_post_id']}", "info");

            // Fire action for post-processing workflows (synchronous)
            do_action('polytrans_translation_completed', $original_post_id, $result['created_post_id'], $target_language, $run_id);
            LogsManager::log("Workflows completed for received translation (post {$result['created_post_id']})", "info");
        } elseif ($workflows_already_executed) {
            LogsManager::log("Skipped workflows - already executed by sender (post {$result['created_post_id']})", "info");
        } else {
            LogsManager::log("Skipped workflows for received translation (mode: {$workflow_mode})", "info");
        }

        // Update final status to completed (after workflows if they ran, or immediately if skipped)
        $status_manager->update_status($original_post_id, $target_language, $result['created_post_id']);

        // Send notifications if timing is set to 'after_workflows'
        $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
        if ($notification_timing === 'after_workflows') {
            $notification_manager = new Managers\NotificationManager();
            $notification_manager->handle_notifications(
                $result['created_post_id'],
                $original_post_id,
                $target_language
            );
            LogsManager::log("Sent after-workflows notifications for post {$result['created_post_id']}", "info");
        }

        \PolyTrans\Core\TranslationRunManager::complete($run_id);

        // Include detailed information in the response for the sender to update status
        return new \WP_REST_Response([
            'created_post_id' => $result['created_post_id'],
            'status' => $result['status'],
            'original_post_id' => $original_post_id,
            'target_language' => $target_language,
            'run_id' => $run_id,
            /* translators: %d: the ID of the newly created translated post */
            'message' => sprintf(__('Translation successfully created with post ID %d', 'polytrans'), $result['created_post_id'])
        ], 201);
    }

    /**
     * Validates permission for translation receiver endpoints.
     * 
     * @param \WP_REST_Request $request The REST API request
     * @return bool True if authorized, false otherwise
     */
    public function permission_callback($request)
    {
        // Validate authentication secret
        if (!$this->security_manager->validate_permission($request)) {
            return false;
        }

        // Validate IP address if configured
        if (!$this->security_manager->validate_ip_address($request)) {
            return false;
        }

        return true;
    }

    /**
     * Registers REST API routes for the translation receiver.
     */
    public function register_routes()
    {
        register_rest_route('polytrans/v1', '/translation/receive-post', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_receive_post'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    /**
     * Initializes REST API extension by registering routes.
     */
    public function register_rest_api_extension()
    {
        $this->register_routes();
    }

    /**
     * Registers the extension with WordPress hooks.
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'register_rest_api_extension']);
    }

    /**
     * Gets the coordinator for external access.
     * 
     * @return PolyTrans_Translation_Coordinator
     */
    public function get_coordinator()
    {
        return $this->coordinator;
    }
}

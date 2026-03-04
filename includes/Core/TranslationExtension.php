<?php

namespace PolyTrans\Core;

/**
 * Translation Extension Class
 * Handles incoming translation requests from other servers and sends back translated content
 * This is the missing piece that allows one server to translate content for another
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationExtension
{
    private static $instance = null;
    private $providers = [];

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
        $this->initialize_providers();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Initialize translation providers using the new provider registry
     */
    private function initialize_providers()
    {
        // Get all registered providers from the registry
        // Note: Provider registry is now autoloaded via LegacyAutoloader
        $registry = \PolyTrans_Provider_Registry::get_instance();
        $this->providers = $registry->get_providers();
    }

    /**
     * Get provider by name
     */
    private function get_provider(string $provider_name)
    {
        $provider = $this->providers[$provider_name] ?? null;

        if (!$provider) {
            error_log("[polytrans] Provider '$provider_name' not found. Available providers: " . implode(', ', array_keys($this->providers))); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        return $provider;
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        register_rest_route('polytrans/v1', '/translation/translate', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_translate'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    /**
     * Handle translation request
     */
    public function handle_translate($request)
    {
        LogsManager::log("handleTranslate called", "info");

        $params = $request->get_json_params();
        $source_lang = $params['source_language'] ?? 'auto';
        $target_lang = $params['target_language'] ?? 'en';
        $original_post_id = $params['original_post_id'] ?? null;
        $to_translate = $params['toTranslate'] ?? [];
        $target_endpoint = $params['target_endpoint'] ?? null;

        if (!$target_endpoint) {
            LogsManager::log("handleTranslate error: target_endpoint required", "info");
            return new WP_REST_Response(['error' => 'target_endpoint required'], 400);
        }

        // Update original post's status and log for external translation tracking
        // Only if the post exists locally and has a pending translation status
        if ($original_post_id && $this->can_update_original_post($original_post_id, $target_lang)) {
            // Status and log keys for the target language
            $status_key = '_polytrans_translation_status_' . $target_lang;
            $log_key = '_polytrans_translation_log_' . $target_lang;

            // Set status to 'translating' to indicate it's being processed remotely
            update_post_meta($original_post_id, $status_key, 'translating');

            // Initialize log if needed
            $log = get_post_meta($original_post_id, $log_key, true);
            if (!is_array($log)) $log = [];

            // Add a status update log entry
            $log[] = [
                'timestamp' => time(),
                'msg' => __('External translation process started.', 'polytrans')
            ];
            update_post_meta($original_post_id, $log_key, $log);

            LogsManager::log("External translation process started for post $original_post_id from $source_lang to $target_lang", "info");
        } elseif ($original_post_id) {
            LogsManager::log("Skipping status update for post $original_post_id - not on shared database or invalid state", "info");
        }

        // Get settings and check for translation paths
        $settings = get_option('polytrans_settings', []);
        $translation_provider = $settings['translation_provider'] ?? 'google';
        // Use universal names with backward compatibility
        $path_rules = $settings['translation_path_rules'] ?? $settings['openai_path_rules'] ?? [];
        $assistants_mapping = $settings['assistants_mapping'] ?? $settings['openai_assistants'] ?? [];
        $has_paths = !empty($path_rules) || !empty($assistants_mapping);
        
        if ($has_paths) {
            // Use TranslationPathExecutor to respect path rules and provider/assistant mappings
            LogsManager::log("Using TranslationPathExecutor with configured paths", "info");
            
            $result = \PolyTrans\Core\TranslationPathExecutor::execute(
                $to_translate,
                $source_lang,
                $target_lang,
                $settings
            );
        } else {
            // Fallback to default provider if no paths configured
            LogsManager::log("No paths configured, using default translation provider: $translation_provider", "info");

        // Get the provider
        $provider = $this->get_provider($translation_provider);
        if (!$provider) {
            // Update error status for external translation
            if ($original_post_id) {
                $this->update_translation_failure($original_post_id, $target_lang, "Unknown translation provider: $translation_provider");
            }

            LogsManager::log("Unknown translation provider: $translation_provider", "info");
            return new WP_REST_Response(['error' => "Unknown translation provider: $translation_provider"], 400);
        }

        // Check if provider is configured
        if (!$provider->is_configured($settings)) {
            // Update error status for external translation
            if ($original_post_id) {
                $this->update_translation_failure($original_post_id, $target_lang, "Translation provider $translation_provider is not properly configured");
            }

            LogsManager::log("Translation provider $translation_provider is not properly configured", "info");
            return new WP_REST_Response(['error' => "Translation provider $translation_provider is not properly configured"], 400);
        }

        // Perform translation
        $result = $provider->translate($to_translate, $source_lang, $target_lang, $settings);
        }

        if (!$result['success']) {
            // Update error status for external translation
            if ($original_post_id) {
                $this->update_translation_failure($original_post_id, $target_lang, $result['error']);
            }

            error_log("[polytrans] Translation failed: " . $result['error']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return new WP_REST_Response(['error' => $result['error']], 500);
        }

        // Check server role and dispatch mode
        $server_role = $settings['translation_server_role'] ?? 'full';
        $dispatch_mode = $settings['outgoing_translation_dispatch_mode'] ?? 'immediate';

        // In "translation_only" mode, always use immediate dispatch (no local storage, no workflows)
        if ($server_role === 'translation_only') {
            $dispatch_mode = 'immediate';
            LogsManager::log("Translation-only mode: forcing immediate dispatch, skipping workflows", "info");
        }

        // Prepare payload for target endpoint
        $payload = [
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'original_post_id' => $original_post_id,
            'translated' => $result['translated_content']
        ];

        // Apply virtual workflows in translation_only mode (if enabled)
        if ($server_role === 'translation_only') {
            $enable_virtual_workflows = $settings['enable_virtual_workflows'] ?? false;
            if ($enable_virtual_workflows) {
                /**
                 * Filter to process virtual workflows on payload before dispatch.
                 *
                 * @param array $payload Translation payload
                 * @param string $source_lang Source language code
                 * @param string $target_lang Target language code
                 * @return array Modified payload
                 */
                $payload = apply_filters('polytrans_before_dispatch_payload', $payload, $source_lang, $target_lang);
                LogsManager::log("Virtual workflows processed on payload", "info");
            }
        }

        // Handle dispatch based on mode
        if ($dispatch_mode === 'after_workflows') {
            // Create post locally first, run workflows, then dispatch
            return $this->handle_after_workflows_dispatch($payload, $target_endpoint, $settings);
        }

        // Immediate mode: send to target endpoint right away
        $response = $this->post_to_target($target_endpoint, $payload);

        // Check if the response from the target endpoint indicates success
        $response_code = wp_remote_retrieve_response_code($response);
        $response_success = ($response_code >= 200 && $response_code < 300);

        // If we're handling an actual post (not just content) and the response failed, mark as failed
        if ($original_post_id && !$response_success && !is_wp_error($response)) {
            $error = wp_remote_retrieve_body($response);
            $this->update_translation_failure($original_post_id, $target_lang, "Failed to deliver translation: $error (HTTP $response_code)");
        }
        // If it's a WP_Error, also mark as failed
        else if ($original_post_id && is_wp_error($response)) {
            $this->update_translation_failure($original_post_id, $target_lang, "Failed to deliver translation: " . $response->get_error_message());
        }

        LogsManager::log("Translation finished for $source_lang->$target_lang (immediate dispatch)", "info");
        return new WP_REST_Response(['status' => 'sent', 'result' => $payload]);
    }

    /**
     * Check if we can safely update the original post's translation meta.
     *
     * This validates that:
     * 1. The post exists in the local database
     * 2. The post has a pending translation status (started/translating) for this language
     *
     * If both conditions are met, we're likely on the same database as the source
     * and can safely update. If not, the receiver will handle its own updates.
     *
     * @param int $post_id Original post ID
     * @param string $target_lang Target language code
     * @return bool True if safe to update, false otherwise
     */
    private function can_update_original_post($post_id, $target_lang)
    {
        if (!$post_id) {
            return false;
        }

        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            LogsManager::log("Cannot update original post $post_id: post does not exist locally", "info");
            return false;
        }

        // Check if post has a pending translation status for this language
        $status_key = '_polytrans_translation_status_' . $target_lang;
        $current_status = get_post_meta($post_id, $status_key, true);

        // Only update if the post is actively waiting for this translation
        $pending_statuses = ['started', 'translating', 'processing'];
        if (!in_array($current_status, $pending_statuses, true)) {
            LogsManager::log(
                "Cannot update original post $post_id for $target_lang: status is '$current_status' (expected: started/translating/processing)",
                "info"
            );
            return false;
        }

        return true;
    }

    /**
     * Helper method to update post meta for failed translations
     *
     * @param int $post_id Original post ID
     * @param string $target_lang Target language
     * @param string $error_message Error message
     */
    private function update_translation_failure($post_id, $target_lang, $error_message)
    {
        // Verify we can update this post
        if (!$this->can_update_original_post($post_id, $target_lang)) {
            LogsManager::log("Skipping failure update for post $post_id - receiver will handle", "info");
            return;
        }

        $status_key = '_polytrans_translation_status_' . $target_lang;
        $log_key = '_polytrans_translation_log_' . $target_lang;

        // Update status to failed
        update_post_meta($post_id, $status_key, 'failed');

        // Add failure log entry
        $log = get_post_meta($post_id, $log_key, true);
        if (!is_array($log)) $log = [];

        $log[] = [
            'timestamp' => time(),
            /* translators: %s: error message describing why the translation failed */
            'msg' => sprintf(__('Translation failed: %s', 'polytrans'), $error_message)
        ];

        update_post_meta($post_id, $log_key, $log);
        LogsManager::log("External translation failed for post $post_id: $error_message", "info");
    }

    /**
     * POST translated data to target endpoint
     * 
     * @param string $endpoint Target endpoint URL
     * @param array $payload Translation payload
     * @return mixed Response from wp_remote_post
     */
    private function post_to_target($endpoint, $payload)
    {
        error_log("[polytrans] postToTarget: endpoint=$endpoint, payload=" . wp_json_encode($payload)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

        // Extract data from payload for potential status updates
        $original_post_id = $payload['original_post_id'] ?? null;
        $target_language = $payload['target_language'] ?? null;

        // Use credentials from payload if provided (passed from source server)
        // Otherwise fall back to local settings
        $receiver_credentials = $payload['receiver_credentials'] ?? null;

        if ($receiver_credentials && !empty($receiver_credentials['secret'])) {
            // Use credentials from payload (source server told us how to authenticate to receiver)
            $secret = $receiver_credentials['secret'];
            $secret_method = $receiver_credentials['method'] ?? 'header_bearer';
            $custom_header_name = $receiver_credentials['custom_header'] ?? 'x-polytrans-secret';
            LogsManager::log("Using receiver credentials from payload", "debug");
        } else {
            // Fall back to local settings (translator's own receiver config)
            $settings = get_option('polytrans_settings', []);
            $secret = $settings['translation_receiver_secret'] ?? '';
            $secret_method = $settings['translation_receiver_secret_method'] ?? 'header_bearer';
            $custom_header_name = $settings['translation_receiver_secret_custom_header'] ?? 'x-polytrans-secret';
            LogsManager::log("Using local receiver credentials", "debug");
        }

        // Remove receiver_credentials from payload before sending (not needed by receiver)
        unset($payload['receiver_credentials']);

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
            'sslverify' => (getenv('WP_ENV') === 'prod') ? true : false,
        ];

        if ($secret && $secret_method !== 'none') {
            switch ($secret_method) {
                case 'get_param':
                    $endpoint = add_query_arg('secret', $secret, $endpoint);
                    break;
                case 'header_bearer':
                    $args['headers']['Authorization'] = 'Bearer ' . $secret;
                    break;
                case 'header_custom':
                    $args['headers'][$custom_header_name] = $secret;
                    break;
                case 'post_param':
                    // Add secret to payload
                    $body = json_decode($args['body'], true);
                    $body['secret'] = $secret;
                    $args['body'] = wp_json_encode($body);
                    break;
            }
        }

        $result = wp_remote_post($endpoint, $args);

        if (is_wp_error($result)) {
            error_log("[polytrans] postToTarget error: " . $result->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            // Update the original post's status if we have post ID and language info
            if ($original_post_id && $target_language) {
                $this->update_translation_failure($original_post_id, $target_language, "Network error: " . $result->get_error_message());
            }
        } else {
            $response_code = wp_remote_retrieve_response_code($result);
            $response_body = wp_remote_retrieve_body($result);
            $success = ($response_code >= 200 && $response_code < 300);

            // If successful response from receiver endpoint (201 Created)
            if ($success && $original_post_id && $target_language) {
                try {
                    // Try to parse the response body for more detailed info
                    $response_data = json_decode($response_body, true);
                    $created_post_id = $response_data['created_post_id'] ?? 0;

                    if ($created_post_id && $this->can_update_original_post($original_post_id, $target_language)) {
                        // Update status key to completed
                        $status_key = '_polytrans_translation_status_' . $target_language;
                        update_post_meta($original_post_id, $status_key, 'completed');

                        // Set a completion timestamp
                        update_post_meta($original_post_id, '_polytrans_translation_completed_' . $target_language, time());

                        // Store the translated post ID reference (both keys for compatibility)
                        update_post_meta($original_post_id, '_polytrans_translation_target_' . $target_language, $created_post_id);
                        update_post_meta($original_post_id, '_polytrans_translation_post_id_' . $target_language, $created_post_id);

                        // Add completion log entry
                        $log_key = '_polytrans_translation_log_' . $target_language;
                        $log = get_post_meta($original_post_id, $log_key, true);
                        if (!is_array($log)) $log = [];

                        $log[] = [
                            'timestamp' => time(),
                            'msg' => sprintf(
                                /* translators: %1$s: edit post URL, %2$d: created post ID */
                                __('Translation completed successfully. New post ID: <a href="%1$s">%2$d</a>', 'polytrans'),
                                esc_url(admin_url('post.php?post=' . $created_post_id . '&action=edit')),
                                $created_post_id
                            )
                        ];

                        update_post_meta($original_post_id, $log_key, $log);

                        // Note: We do NOT fire polytrans_translation_completed here because:
                        // 1. This is the SENDER - the created_post_id exists only on the TARGET server
                        // 2. The RECEIVER (TranslationReceiverExtension) fires this hook where the post actually exists
                        // 3. Workflows need the post to exist locally to function properly

                        LogsManager::log("External translation completed successfully for post $original_post_id -> $created_post_id (remote)", "info");
                    } elseif ($created_post_id) {
                        // Post doesn't exist locally or has wrong status - receiver will handle its own updates
                        LogsManager::log("Translation delivered to receiver (post $created_post_id) - skipping local status update", "info");
                    }
                } catch (\Exception $e) {
                    error_log("[polytrans] Error processing translation response: " . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
            // If failed response, update the failure status
            else if (!$success && $original_post_id && $target_language) {
                $this->update_translation_failure($original_post_id, $target_language, "Failed to deliver translation: HTTP $response_code - $response_body");
            }
        }

        return $result;
    }

    /**
     * Handle translation dispatch after workflows complete
     *
     * This method:
     * 1. Creates the post locally
     * 2. Triggers workflows (which run synchronously)
     * 3. After workflows complete, fetches updated content and dispatches to target
     *
     * @param array $payload Translation payload
     * @param string $target_endpoint Target endpoint URL
     * @param array $settings Plugin settings
     * @return \WP_REST_Response
     */
    private function handle_after_workflows_dispatch($payload, $target_endpoint, $settings)
    {
        $source_lang = $payload['source_language'];
        $target_lang = $payload['target_language'];
        $original_post_id = $payload['original_post_id'];
        $translated = $payload['translated'];

        LogsManager::log("After-workflows dispatch: creating local post for processing", "info", [
            'source' => 'translation_extension',
            'original_post_id' => $original_post_id,
            'target_language' => $target_lang
        ]);

        // Create the post locally using TranslationCoordinator
        // Mark as ephemeral to skip notifications and status updates (handled by receiver)
        $coordinator = new \PolyTrans\Receiver\TranslationCoordinator();
        $result = $coordinator->process_translation([
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'original_post_id' => $original_post_id,
            'translated' => $translated,
            'ephemeral' => true
        ]);

        if (!$result['success']) {
            LogsManager::log("After-workflows dispatch: failed to create local post: " . ($result['error'] ?? 'Unknown error'), "error");
            return new \WP_REST_Response(['error' => 'Failed to create local post: ' . ($result['error'] ?? 'Unknown error')], 500);
        }

        $created_post_id = $result['created_post_id'];

        LogsManager::log("After-workflows dispatch: local post created (ID: {$created_post_id}), triggering workflows", "info");

        // Set post_processing status before workflows (if we can update the original post)
        if ($this->can_update_original_post($original_post_id, $target_lang)) {
            $status_key = '_polytrans_translation_status_' . $target_lang;
            update_post_meta($original_post_id, $status_key, 'post_processing');

            // Store the translated post ID early (so UI can show preview link)
            update_post_meta($original_post_id, '_polytrans_translation_target_' . $target_lang, $created_post_id);
            update_post_meta($original_post_id, '_polytrans_translation_post_id_' . $target_lang, $created_post_id);

            LogsManager::log("Set post_processing status for after-workflows dispatch", "info");
        }

        // Fire the translation completed hook - workflows will run synchronously
        do_action('polytrans_translation_completed', $original_post_id, $created_post_id, $target_lang);

        LogsManager::log("After-workflows dispatch: workflows completed, fetching updated content", "info");

        // After workflows complete, fetch the updated post content
        $updated_post = get_post($created_post_id);
        if (!$updated_post) {
            LogsManager::log("After-workflows dispatch: post not found after workflows", "error");
            return new \WP_REST_Response(['error' => 'Post not found after workflow processing'], 500);
        }

        // Build updated payload with post-processed content
        $updated_payload = [
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'original_post_id' => $original_post_id,
            'workflows_executed' => true, // Signal to receiver that workflows already ran
            'translated' => [
                'title' => $updated_post->post_title,
                'content' => $updated_post->post_content,
                'excerpt' => $updated_post->post_excerpt,
                'status' => $updated_post->post_status,
                'meta' => $translated['meta'] ?? [] // Keep original meta, workflows may have modified post meta directly
            ]
        ];

        // Copy any additional fields from original translated content
        if (isset($translated['featured_image'])) {
            $updated_payload['translated']['featured_image'] = $translated['featured_image'];
        }

        // Check cleanup mode - delete ephemeral post before dispatch if configured
        $cleanup_mode = $settings['after_workflows_cleanup_mode'] ?? 'delete';
        if ($cleanup_mode === 'delete') {
            LogsManager::log("After-workflows dispatch: deleting ephemeral post {$created_post_id}", "info");
            wp_delete_post($created_post_id, true); // Force delete, skip trash
        }

        LogsManager::log("After-workflows dispatch: sending to target endpoint", "info");

        // Now dispatch to target endpoint
        $response = $this->post_to_target($target_endpoint, $updated_payload);

        // Check response
        $response_code = wp_remote_retrieve_response_code($response);
        $response_success = ($response_code >= 200 && $response_code < 300);

        if (!$response_success) {
            $error = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
            LogsManager::log("After-workflows dispatch: failed to send to target: {$error}", "error");

            if ($original_post_id) {
                $this->update_translation_failure($original_post_id, $target_lang, "Failed to deliver translation after workflows: $error");
            }
        } else {
            LogsManager::log("After-workflows dispatch: successfully sent to target", "info");
        }

        $response_data = [
            'status' => 'sent_after_workflows',
            'result' => $updated_payload
        ];

        if ($cleanup_mode === 'keep') {
            $response_data['local_post_id'] = $created_post_id;
        } else {
            $response_data['local_post_deleted'] = true;
        }

        return new \WP_REST_Response($response_data);
    }

    /**
     * Permission callback for translation requests
     */
    public function permission_callback($request)
    {
        $settings = get_option('polytrans_settings', []);
        $expected_secret = $settings['translation_receiver_secret'] ?? '';
        $secret_method = $settings['translation_receiver_secret_method'] ?? 'header_bearer';
        $custom_header_name = $settings['translation_receiver_secret_custom_header'] ?? 'x-polytrans-secret';

        if (empty($expected_secret) || $secret_method === 'none') {
            return true;
        }

        $received_secret = '';

        switch ($secret_method) {
            case 'get_param':
                $received_secret = $request->get_param('secret');
                break;
            case 'header_bearer':
                $auth = $request->get_header('authorization');
                if ($auth && stripos($auth, 'bearer ') === 0) {
                    $received_secret = trim(substr($auth, 7));
                }
                break;
            case 'header_custom':
                $received_secret = $request->get_header($custom_header_name);
                break;
            case 'post_param':
                $params = $request->get_json_params();
                error_log("[polytrans] post_param received params: " . wp_json_encode($params)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                $received_secret = $params['secret'] ?? '';
                break;
        }

        if (!$received_secret || $received_secret !== $expected_secret) {
            LogsManager::log("Invalid or missing translation receiver secret (permission callback)", "info");
            return false;
        }

        return true;
    }
}

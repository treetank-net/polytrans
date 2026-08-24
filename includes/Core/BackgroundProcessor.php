<?php

namespace PolyTrans\Core;

use Exception;
use PolyTrans\Scheduler\TranslationHandler;

/**
 * Background Process Handler
 * Handles running tasks in background processes
 */

if (!defined('ABSPATH')) {
    exit;
}

class BackgroundProcessor
{

    /**
     * Spawn a background process using available system methods
     * 
     * @param array $args The arguments to pass to the background process
     * @param string $action The action to perform (default: 'process-translation')
     * @return bool True if process was spawned successfully
     */
    public static function spawn($args, $action = 'process-translation')
    {
        // Validate args based on action type
        if ($action === 'process-translation') {
            if (empty($args['post_id']) || empty($args['source_lang']) || empty($args['target_lang'])) {
                self::log("Background process spawn failed: Invalid arguments", "error", $args);
                return false;
            }
        } elseif ($action === 'workflow-test') {
            if (empty($args['test_id']) || empty($args['workflow_data'])) {
                self::log("Background workflow test spawn failed: Invalid arguments", "error", $args);
                return false;
            }
        } elseif ($action === 'workflow-execute') {
            if (empty($args['execution_id']) || empty($args['workflow_id']) || empty($args['translated_post_id'])) {
                self::log("Background workflow execution spawn failed: Invalid arguments", "error", $args);
                return false;
            }
        }

        // A loopback HTTP request, always. The earlier alternative shelled out to the
        // PHP binary with a bootstrap script that included wp-load.php directly — a
        // pattern the WordPress.org guidelines forbid, which also never ran in a release
        // because that script is excluded from the distribution ZIP, so every host with
        // exec() enabled silently got a failed dispatch and no fallback.
        return self::spawn_http_request($args, $action);
    }

    /**
     * Sign a dispatch token for the loopback endpoint.
     *
     * Not a nonce, and deliberately so: wp_create_nonce() binds the value to the
     * current user ID and session token. A loopback request carries no cookies, so
     * the nonce is created for the logged-in admin and verified as an anonymous
     * visitor — it can never match, and the dispatch fails with "Invalid nonce" on
     * every host. The signature is derived from the one-time token with wp_hash()
     * (site salts, no session) and compared with hash_equals(). The token itself is
     * single-use: its transient is deleted as soon as the task has been handed over.
     *
     * @param string $token The dispatch token stored in the transient.
     * @return string
     */
    public static function dispatch_signature($token)
    {
        return wp_hash('polytrans_bg|' . $token);
    }

    /**
     * Build the loopback endpoint URL for a stored dispatch token.
     *
     * @param string $token  The dispatch token stored in the transient.
     * @param string $action The action the endpoint should run.
     * @return string
     */
    public static function dispatch_url($token, $action)
    {
        return add_query_arg([
            'polytrans_bg' => 1,
            'token' => $token,
            'action' => $action,
            'signature' => self::dispatch_signature($token),
        ], home_url('/'));
    }

    /**
     * Handle an incoming loopback dispatch request.
     *
     * Registered on init; authenticated by the HMAC signature above.
     *
     * @return void
     */
    public static function handle_request()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Loopback dispatch, authenticated by the HMAC signature verified below; see dispatch_signature().
        if (!isset($_GET['polytrans_bg'], $_GET['token'], $_GET['signature'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Loopback dispatch, authenticated by the HMAC signature verified below; see dispatch_signature().
        $token = sanitize_key(wp_unslash($_GET['token']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the credential being verified; a nonce cannot be used on a request with no user session.
        $signature = sanitize_text_field(wp_unslash($_GET['signature']));

        if ($token === '' || !hash_equals(self::dispatch_signature($token), $signature)) {
            LogsManager::log(
                'Background process request: invalid signature. Token: ' . ($token ?: 'missing'),
                'error',
                ['token' => $token]
            );
            return;
        }

        $data = get_transient('polytrans_bg_' . $token);

        // Set headers to prevent caching and handle long-running process
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, nofollow');
        header('Connection: close');

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best-effort for background processing; disabled on some hosts.
            @set_time_limit(0);
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Buffer may be absent depending on server config.
        @ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if (!$data) {
            LogsManager::log(
                'Background process request: no data found for token ' . $token,
                'error',
                ['token' => $token]
            );
            exit;
        }

        $args = $data['args'] ?? [];
        $action = $data['action'] ?? 'process-translation';

        // Consume the token before running: the task can take minutes, and a retry
        // must not be able to start a second copy of the same work.
        delete_transient('polytrans_bg_' . $token);

        try {
            self::process_task($args, $action);
        } catch (\Throwable $e) {
            LogsManager::log(
                'BackgroundProcessor::process_task() failed: ' . $e->getMessage(),
                'error',
                [
                    'token' => $token,
                    'action' => $action,
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            throw $e;
        }

        exit;
    }

    /**
     * Process a task directly (called from background process)
     * 
     * @param array $args Arguments for the process
     * @param string $action The action to perform
     * @return void
     */
    public static function process_task($args, $action)
    {
        try {
            // Make sure we run for as long as needed
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best-effort for background processing; disabled on some hosts.
                @set_time_limit(0);
            }

            // Log the start of processing
            self::log("Started background task processing: $action", "info", $args);

            // Process based on action
            switch ($action) {
                case 'process-translation':
                    $post_id = $args['post_id'] ?? 0;
                    $source_lang = $args['source_lang'] ?? '';
                    $target_lang = $args['target_lang'] ?? '';

                    if (!$post_id || !$source_lang || !$target_lang) {
                        self::log("Background translation task failed: Invalid arguments", "error", $args);
                        return;
                    }

                    self::process_translation($args);
                    break;

                case 'workflow-test':
                    self::process_workflow_test($args);
                    break;

                case 'workflow-execute':
                    self::process_workflow_execution($args);
                    break;

                default:
                    do_action("polytrans_bg_process_$action", $args);
                    break;
            }
        } catch (\Throwable $e) {
            // Catch any unhandled exceptions/errors
            self::log("Background task processing failed with unhandled exception: " . $e->getMessage(), "error", [
                'action' => $action,
                'args' => $args,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Try to update status if we have post_id
            if (isset($args['post_id']) && isset($args['target_lang'])) {
                $post_id = $args['post_id'];
                $target_lang = $args['target_lang'];
                $status_key = '_polytrans_translation_status_' . $target_lang;
                $log_key = '_polytrans_translation_log_' . $target_lang;
                
                update_post_meta($post_id, $status_key, 'failed');
                update_post_meta($post_id, '_polytrans_translation_error_' . $target_lang, $e->getMessage());
                
                $log = get_post_meta($post_id, $log_key, true);
                if (!is_array($log)) $log = [];
                $log[] = [
                    'timestamp' => time(),
                    /* translators: %s: error message */
                    'msg' => sprintf(__('Background process failed: %s', 'polytrans'), $e->getMessage())
                ];
                update_post_meta($post_id, $log_key, $log);
            }
        }
    }

    /**
     * Spawn a background process using HTTP request
     * 
     * @param array $args Arguments to pass to the process
     * @param string $action The action to perform
     * @return bool True if successful
     */
    private static function spawn_http_request($args, $action)
    {
        // Generate a unique token for this process
        $token = md5(uniqid(wp_rand(), true));

        // Store the args in a transient
        // Note: No log_file needed for HTTP request - endpoint runs in WordPress context
        // and all logging goes directly to LogsManager
        set_transient('polytrans_bg_' . $token, [
            'args' => $args,
            'action' => $action
        ], 3600); // expires in 1 hour

        // Build the URL to our processing endpoint
        $url = self::dispatch_url($token, $action);

        // Make a non-blocking request with multiple fallbacks
        $success = false;

        // Method 1: WordPress HTTP API
        $response = wp_remote_post($url, [
            'timeout' => 0.1, // Very short timeout for fire-and-forget
            'blocking' => false, // Non-blocking request
            'sslverify' => false, // Don't verify SSL for local requests
            'headers' => [
                'X-Polytrans-BG' => 'Processing',
                'User-Agent' => 'PolyTrans Background Process'
            ]
        ]);

        if (is_wp_error($response)) {
            self::log("HTTP request failed: " . $response->get_error_message(), "error", [
                'url' => $url,
                'token' => $token,
                'action' => $action,
                'error' => $response->get_error_message()
            ]);
        }
        
        $success = !is_wp_error($response);

        // Method 2: Try file_get_contents with stream context if Method 1 failed
        if (!$success) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "X-Polytrans-BG: Processing\r\nUser-Agent: PolyTrans Background Process\r\n",
                    'timeout' => 0.1
                ]
            ]);

            @file_get_contents($url, false, $context);
            $success = true; // Can't verify success with file_get_contents non-blocking
        }

        // Method 3: Try cURL if available
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- fallback when wp_remote_post fails
        if (!$success && function_exists('curl_init')) {
            $ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
            curl_setopt($ch, CURLOPT_URL, $url); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_USERAGENT, 'PolyTrans Background Process'); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Polytrans-BG: Processing']); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            curl_setopt($ch, CURLOPT_NOBODY, true); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
            @curl_exec($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
            curl_close($ch); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
            $success = true; // Can't verify success with curl non-blocking
        }

        if ($success) {
            self::log("Spawned background process with HTTP request", "info", [
                'url' => $url,
                'token' => $token,
                'action' => $action,
                'post_id' => $args['post_id'] ?? null
            ]);
            
            // Note: For HTTP requests, endpoint runs in WordPress context
            // All errors are logged directly to LogsManager, so no file log check needed
            // The endpoint will handle all error logging via LogsManager
        } else {
            self::log("Failed to spawn background process with HTTP request - all methods failed", "error", [
                'url' => $url,
                'token' => $token,
                'action' => $action,
                'post_id' => $args['post_id'] ?? null,
                'wp_remote_post_available' => function_exists('wp_remote_post'),
                'file_get_contents_available' => function_exists('file_get_contents'),
                'curl_available' => function_exists('curl_init')
            ]);
        }

        return $success;
    }

    /**
     * Process a translation request (called from background process)
     * 
     * @param array $args Arguments for the process
     * @return void
     */
    private static function process_translation($args)
    {
        $post_id = $args['post_id'] ?? 0;
        $source_lang = $args['source_lang'] ?? '';
        $target_lang = $args['target_lang'] ?? '';

        if (!$post_id || !$source_lang || !$target_lang) {
            self::log("Translation task failed: Invalid arguments", "error", $args);
            return;
        }

        // Get plugin settings
        $settings = get_option('polytrans_settings', []);
        $translation_provider = $settings['translation_provider'] ?? '';
        $transport_mode = $settings['translation_transport_mode'] ?? 'external';

        // Status and log keys
        $status_key = '_polytrans_translation_status_' . $target_lang;
        $log_key = '_polytrans_translation_log_' . $target_lang;

        // Get the current status - don't override if it's not set to 'translating'
        $current_status = get_post_meta($post_id, $status_key, true);

        // Only update if the status is 'started' or 'translating' to avoid overwriting completed or failed states
        if ($current_status === 'started' || $current_status === 'translating') {
            // Update status to processing
            update_post_meta($post_id, $status_key, 'processing');
        }

        // Initialize log entry if it doesn't exist
        $log = get_post_meta($post_id, $log_key, true);
        if (!is_array($log)) $log = [];

        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            self::log("Background translation failed: Post not found", "error", [
                'post_id' => $post_id
            ]);

            // Update error status and log
            update_post_meta($post_id, $status_key, 'failed');
            $log[] = [
                'timestamp' => time(),
                'msg' => __('Translation failed: Post not found.', 'polytrans')
            ];
            update_post_meta($post_id, $log_key, $log);
            return;
        }

        self::log("Starting translation process", "info", [
            'post_id' => $post_id,
            'provider' => $translation_provider,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'transport_mode' => $transport_mode,
            'post_title' => $post->post_title
        ]);

        // Log start in post meta
        $log[] = [
            'timestamp' => time(),
            /* translators: %s: translation provider name */
            'msg' => sprintf(__('Starting translation with %s.', 'polytrans'), ucfirst($translation_provider))
        ];
        update_post_meta($post_id, $log_key, $log);

        $run_id = null;

        try {
            // Get post content and metadata
            $all_meta = get_post_meta($post_id);

            // Check if translation paths are configured (need this early to decide on meta filtering)
            // Use universal names with backward compatibility
            $path_rules = $settings['translation_path_rules'] ?? $settings['openai_path_rules'] ?? [];
            $assistants_mapping = $settings['assistants_mapping'] ?? $settings['openai_assistants'] ?? [];
            $has_paths = !empty($path_rules) || !empty($assistants_mapping);

            // Find managed assistant ID if any
            $managed_assistant_id = null;
            foreach ($assistants_mapping as $assistant_id) {
                if (is_string($assistant_id) && strpos($assistant_id, 'managed_') === 0) {
                    $managed_assistant_id = $assistant_id;
                    break;
                }
            }

            // Filter meta based on managed assistant schema or use default filtering
            if ($managed_assistant_id) {
                $meta = self::filter_meta_by_schema($all_meta, $managed_assistant_id, $post);
            } else {
                $meta = TranslationHandler::filter_meta_for_translation($all_meta);
            }

            $content_to_translate = TranslationPayloadBuilder::build($post, $meta, $settings);
            $run_id = \PolyTrans\Core\TranslationRunManager::start(
                [
                    'source_post_id' => $post_id,
                    'source_language' => $source_lang,
                    'target_language' => $target_lang,
                ],
                \PolyTrans\Core\TextMetrics::from_payload($content_to_translate)
            );
            
            if ($has_paths) {
                // Use TranslationPathExecutor to respect path rules and provider/assistant mappings
                self::log("Using TranslationPathExecutor with configured paths", "info", [
                    'post_id' => $post_id,
                    'source_lang' => $source_lang,
                    'target_lang' => $target_lang,
                    'path_rules_count' => count($path_rules),
                    'assistants_mapping_count' => count($assistants_mapping)
                ]);
                
                $translation_result = \PolyTrans\Core\TranslationPathExecutor::execute(
                    $content_to_translate,
                    $source_lang,
                    $target_lang,
                    $settings,
                    [
                        'source_post_id' => $post_id,
                        'run_id' => $run_id,
                    ]
                );
            } else {
                // Fallback to default provider if no paths configured
                self::log("No paths configured, using default translation provider: $translation_provider", "info", ['post_id' => $post_id]);

            // Try namespaced class first, then legacy
            if (class_exists('\PolyTrans\Providers\ProviderRegistry')) {
                $registry = \PolyTrans\Providers\ProviderRegistry::get_instance();
            } elseif (class_exists('PolyTrans_Provider_Registry')) {
                $registry = PolyTrans_Provider_Registry::get_instance();
            } else {
                throw new Exception('ProviderRegistry class not found. Autoloader may not be initialized.');
            }
            
            $provider = $registry->get_provider($translation_provider);

            if (!$provider) {
                /* translators: %s: translation provider name */
                throw new Exception(sprintf(__('Translation provider %s not found.', 'polytrans'), $translation_provider));
            }

            // Use the provider to translate
            self::log("Sending content to translation provider", "info", [
                'post_id' => $post_id,
                'provider' => $translation_provider,
                'content_length' => strlen($post->post_content)
            ]);

            $translation_result = $provider->translate($content_to_translate, $source_lang, $target_lang, $settings);
            \PolyTrans\Core\TranslationPathExecutor::record_direct_usage(
                $translation_result,
                $source_lang,
                $target_lang,
                [
                    'source_post_id' => $post_id,
                    'run_id' => $run_id,
                ]
            );
            }

            if (!$translation_result['success']) {
                throw new Exception($translation_result['error'] ?? __('Unknown translation error.', 'polytrans'));
            }

            self::log("Translation received from provider, processing result", "info", [
                'post_id' => $post_id,
                'provider' => $translation_provider
            ]);

            // Process the translation using the coordinator
            // Try namespaced class first, then legacy
            if (class_exists('\PolyTrans\Receiver\TranslationCoordinator')) {
                $coordinator = new \PolyTrans\Receiver\TranslationCoordinator();
            } elseif (class_exists('PolyTrans_Translation_Coordinator')) {
                $coordinator = new \PolyTrans_Translation_Coordinator();
            } else {
                throw new Exception('TranslationCoordinator class not found. Autoloader may not be initialized.');
            }

            // Prepare the request data for processing
            $request_data = [
                'source_language' => $source_lang,
                'target_language' => $target_lang,
                'original_post_id' => $post_id,
                'translated' => $translation_result['translated_content'],
                'run_id' => $run_id,
            ];

            // Process the translation - this creates the translated post
            self::log("Creating translated post", "info", [
                'post_id' => $post_id,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang
            ]);

            $result = $coordinator->process_translation($request_data);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? __('Failed to process translation.', 'polytrans'));
            }

            // Update success status and log
            update_post_meta($post_id, $status_key, 'completed');

            // Set a completion timestamp
            update_post_meta($post_id, '_polytrans_translation_completed_' . $target_lang, time());

            $log[] = [
                'timestamp' => time(),
                'msg' => sprintf(
                    /* translators: %1$s: edit post URL, %2$d: created post ID */
                    __('Translation completed successfully. New post ID: <a href="%1$s">%2$d</a>', 'polytrans'),
                    esc_url(admin_url('post.php?post=' . $result['created_post_id'] . '&action=edit')),
                    $result['created_post_id']
                )
            ];

            // Store the created post ID
            update_post_meta($post_id, '_polytrans_translation_post_id_' . $target_lang, $result['created_post_id']);
            \PolyTrans\Core\TranslationRunManager::attach_post($run_id, $result['created_post_id']);

            // Fire action for post-processing workflows
            do_action('polytrans_translation_completed', $post_id, $result['created_post_id'], $target_lang, $run_id);

            // Send notifications after workflows complete (if timing is set to 'after_workflows')
            $settings = get_option('polytrans_settings', []);
            $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
            if ($notification_timing === 'after_workflows') {
                $notification_manager = new \PolyTrans\Receiver\Managers\NotificationManager();
                $notification_manager->handle_notifications(
                    $result['created_post_id'],
                    $post_id,
                    $target_lang
                );
                self::log("Sent after-workflows notifications for post {$result['created_post_id']}", "info");
            }

            \PolyTrans\Core\TranslationRunManager::complete($run_id);

            self::log("Translation completed successfully", "info", [
                'post_id' => $post_id,
                'created_post_id' => $result['created_post_id'],
                'source_lang' => $source_lang,
                'target_lang' => $target_lang
            ]);
        } catch (\Exception $e) {
            \PolyTrans\Core\TranslationRunManager::fail($run_id);

            // Update error status and log
            update_post_meta($post_id, $status_key, 'failed');

            // Store error details
            update_post_meta($post_id, '_polytrans_translation_error_' . $target_lang, $e->getMessage());

            $log[] = [
                'timestamp' => time(),
                /* translators: %s: error message */
                'msg' => sprintf(__('Translation failed: %s', 'polytrans'), $e->getMessage())
            ];

            self::log("Translation failed: " . $e->getMessage(), "error", [
                'post_id' => $post_id,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'error' => $e->getMessage()
            ]);
        }

        // Add a link to the logs page in the final log entry
        $logs_url = admin_url('admin.php?page=polytrans-logs&post_id=' . $post_id);
        $log[] = [
            'timestamp' => time(),
            'msg' => sprintf(
                /* translators: %s: URL to system logs page */
                __('Process complete. View detailed <a href="%s" target="_blank">system logs</a>.', 'polytrans'),
                esc_url($logs_url)
            )
        ];

        // Update the final log entries
        update_post_meta($post_id, $log_key, $log);
    }

    /**
     * Process workflow test in background
     * 
     * @param array $args Arguments for the process
     * @return void
     */
    private static function process_workflow_test($args)
    {
        $test_id = $args['test_id'] ?? '';
        $workflow_data = $args['workflow_data'] ?? [];
        $test_context = $args['test_context'] ?? [];

        if (!$test_id || empty($workflow_data)) {
            self::log("Workflow test failed: Invalid arguments", "error", $args);

            // Store error result
            set_transient('polytrans_workflow_test_' . $test_id, [
                'status' => 'completed',
                'completed_at' => time(),
                'data' => ['success' => false, 'error' => 'Invalid test arguments']
            ], 5 * MINUTE_IN_SECONDS);
            return;
        }

        self::log("Starting workflow test execution", "info", [
            'test_id' => $test_id,
            'workflow_name' => $workflow_data['name'] ?? 'Unknown'
        ]);

        try {
            // Get workflow manager instance
            // Try namespaced class first, then legacy
            if (class_exists('\PolyTrans\PostProcessing\WorkflowManager')) {
                $workflow_manager = \PolyTrans\PostProcessing\WorkflowManager::get_instance();
            } elseif (class_exists('PolyTrans_Workflow_Manager')) {
                $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
            } else {
                throw new Exception('WorkflowManager class not found. Autoloader may not be initialized.');
            }

            // Create test workflow
            $workflow = [
                'id' => $test_id,
                'name' => 'Test Workflow',
                'target_language' => $test_context['target_language'] ?? 'en',
                'enabled' => true,
                'steps' => $workflow_data['steps'] ?? []
            ];

            // Execute test in test mode
            $result = $workflow_manager->execute_workflow($workflow, $test_context, true);

            // Store result
            set_transient('polytrans_workflow_test_' . $test_id, [
                'status' => 'completed',
                'completed_at' => time(),
                'data' => $result
            ], 5 * MINUTE_IN_SECONDS);

            self::log("Workflow test completed successfully", "info", [
                'test_id' => $test_id,
                'success' => $result['success'] ?? false,
                'steps_executed' => $result['steps_executed'] ?? 0
            ]);
        } catch (\Throwable $e) {
            self::log("Workflow test failed: " . $e->getMessage(), "error", [
                'test_id' => $test_id,
                'error' => $e->getMessage()
            ]);

            // Store error result
            set_transient('polytrans_workflow_test_' . $test_id, [
                'status' => 'completed',
                'completed_at' => time(),
                'data' => ['success' => false, 'error' => $e->getMessage()]
            ], 5 * MINUTE_IN_SECONDS);
        }
    }

    /**
     * Process workflow execution in background
     * 
     * @param array $args Arguments for the process
     * @return void
     */
    private static function process_workflow_execution($args)
    {
        $execution_id = $args['execution_id'] ?? '';
        $workflow_id = $args['workflow_id'] ?? '';
        $original_post_id = $args['original_post_id'] ?? 0;
        $translated_post_id = $args['translated_post_id'] ?? 0;
        $target_language = $args['target_language'] ?? '';
        $started_at = $args['started_at'] ?? time();

        if (!$execution_id || !$workflow_id || !$translated_post_id) {
            self::log("Workflow execution failed: Invalid arguments", "error", $args);

            // Store error result
            if ($execution_id) {
                set_transient('polytrans_workflow_exec_' . $execution_id, [
                    'status' => 'completed',
                    'completed_at' => time(),
                    'result' => ['success' => false, 'error' => 'Invalid execution arguments']
                ], 10 * MINUTE_IN_SECONDS);
            }
            return;
        }

        self::log("Starting workflow execution", "info", [
            'execution_id' => $execution_id,
            'workflow_id' => $workflow_id,
            'post_id' => $translated_post_id
        ]);

        try {
            // Get workflow manager instance
            // Try namespaced class first, then legacy
            if (class_exists('\PolyTrans\PostProcessing\WorkflowManager')) {
                $workflow_manager = \PolyTrans\PostProcessing\WorkflowManager::get_instance();
            } elseif (class_exists('PolyTrans_Workflow_Manager')) {
                $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
            } else {
                throw new Exception('WorkflowManager class not found. Autoloader may not be initialized.');
            }

            // Get workflow
            $workflow = $workflow_manager->get_storage_manager()->get_workflow($workflow_id);

            if (!$workflow) {
                throw new Exception('Workflow not found: ' . $workflow_id);
            }

            // Build context
            $source_language = '';
            if ($original_post_id > 0 && function_exists('pll_get_post_language')) {
                $source_language = (string) pll_get_post_language($original_post_id);
            }
            $context = [
                'original_post_id' => $original_post_id,
                'translated_post_id' => $translated_post_id,
                'source_language' => $source_language,
                'target_language' => $target_language,
                'trigger' => 'manual'
            ];

            // Execute workflow (NOT in test mode)
            $result = $workflow_manager->execute_workflow($workflow, $context, false);

            // Store result
            set_transient('polytrans_workflow_exec_' . $execution_id, [
                'status' => 'completed',
                'started_at' => $started_at,
                'completed_at' => time(),
                'result' => $result
            ], 10 * MINUTE_IN_SECONDS);

            // Clear execution lock
            delete_transient('polytrans_workflow_lock_' . $workflow_id . '_' . $translated_post_id);

            self::log("Workflow execution completed successfully", "info", [
                'execution_id' => $execution_id,
                'workflow_id' => $workflow_id,
                'post_id' => $translated_post_id,
                'success' => $result['success'] ?? false,
                'steps_executed' => $result['steps_executed'] ?? 0,
                'execution_time' => isset($result['execution_time']) ? round((float) $result['execution_time'], 3) : 0,
                'step_summary' => self::summarize_workflow_steps_for_log($result['step_results'] ?? [])
            ]);
        } catch (\Throwable $e) {
            self::log("Workflow execution failed: " . $e->getMessage(), "error", [
                'execution_id' => $execution_id,
                'workflow_id' => $workflow_id,
                'post_id' => $translated_post_id,
                'error' => $e->getMessage()
            ]);

            // Store error result
            set_transient('polytrans_workflow_exec_' . $execution_id, [
                'status' => 'completed',
                'started_at' => $started_at,
                'completed_at' => time(),
                'result' => ['success' => false, 'error' => $e->getMessage()]
            ], 10 * MINUTE_IN_SECONDS);

            // Clear execution lock
            delete_transient('polytrans_workflow_lock_' . $workflow_id . '_' . $translated_post_id);
        }
    }

    /**
     * Compact workflow step results for background execution logs.
     */
    private static function summarize_workflow_steps_for_log($step_results): array
    {
        if (!is_array($step_results)) {
            return [];
        }

        $summary = [];
        foreach ($step_results as $index => $step_result) {
            if (!is_array($step_result)) {
                continue;
            }

            $data = $step_result['data'] ?? [];
            $output_processing = $step_result['output_processing'] ?? [];
            $changes = is_array($output_processing) && isset($output_processing['changes']) && is_array($output_processing['changes'])
                ? $output_processing['changes']
                : [];

            $summary[] = [
                'step_number' => $index + 1,
                'step_id' => $step_result['step_id'] ?? null,
                'step_name' => $step_result['step_name'] ?? null,
                'step_type' => $step_result['step_type'] ?? null,
                'success' => (bool) ($step_result['success'] ?? false),
                'execution_time' => isset($step_result['execution_time']) ? round((float) $step_result['execution_time'], 3) : 0,
                'output_variables' => is_array($data) ? array_keys($data) : [],
                'output_actions_processed' => is_array($output_processing) ? (int) ($output_processing['processed_actions'] ?? 0) : 0,
                'changes_count' => count($changes),
                'error' => $step_result['error'] ?? null,
                'tokens_used' => $step_result['tokens_used'] ?? null
            ];
        }

        return $summary;
    }

    /**
     * Log a message to both WordPress error log and optionally to our custom log table
     * 
     * @param string $message The log message
     * @param string $level The log level (info, warning, error)
     * @param array $context Additional context data
     * @return void
     */
    public static function log($message, $level = 'info', $context = [])
    {
        // Load the logs manager class
        // Note: PolyTrans_Logs_Manager is autoloaded

        // Extract post ID and languages from context if available
        $post_id = isset($context['post_id']) ? intval($context['post_id']) : 0;
        $source_lang = isset($context['source_lang']) ? $context['source_lang'] : '';
        $target_lang = isset($context['target_lang']) ? $context['target_lang'] : '';

        // Use the logs manager to log (it will handle both error_log and DB)
        LogsManager::log($message, $level, $context);

        // Also log to post meta for this specific translation if we have a post ID
        if ($post_id && $target_lang) {
            $log_key = '_polytrans_translation_log_' . $target_lang;
            $log = get_post_meta($post_id, $log_key, true);
            if (!is_array($log)) $log = [];

            $log[] = [
                'timestamp' => time(),
                'msg' => $message,
                'level' => $level
            ];

            update_post_meta($post_id, $log_key, $log);
        }
    }

    /**
     * Check and log table structure on plugin activation
     * This is a static method that can be called during plugin activation
     * to debug table structure issues
     */
    /**
     * Filter meta based on managed assistant's output schema
     *
     * Interpolates the schema with original meta to discover which keys are needed,
     * then returns only those meta fields.
     *
     * @param array $all_meta All post meta from get_post_meta()
     * @param string $managed_assistant_id Assistant ID (e.g., "managed_1")
     * @param WP_Post $post The post being translated
     * @return array Filtered meta
     */
    private static function filter_meta_by_schema($all_meta, $managed_assistant_id, $post)
    {
        // Flatten all meta first (get_post_meta returns arrays)
        $flat_meta = [];
        foreach ($all_meta as $key => $values) {
            $value = is_array($values) && count($values) === 1 ? $values[0] : $values;
            // Skip serialized PHP arrays
            if (is_string($value) && preg_match('/^[aOC]:\d+:/', $value)) {
                continue;
            }
            $flat_meta[$key] = $value;
        }

        // Get assistant
        $numeric_id = (int) str_replace('managed_', '', $managed_assistant_id);
        $assistant = \PolyTrans\Assistants\AssistantManager::get_assistant($numeric_id);

        if (!$assistant || empty($assistant['expected_output_schema'])) {
            self::log("No schema found for managed assistant, using all meta", "debug", [
                'assistant_id' => $managed_assistant_id
            ]);
            return $flat_meta;
        }

        // Build context for schema interpolation
        $context = [
            'original' => [
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'meta' => $flat_meta
            ],
            'translated' => [
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'meta' => $flat_meta
            ]
        ];

        // Interpolate schema
        $schema_str = $assistant['expected_output_schema'];
        if (is_array($schema_str)) {
            $schema_str = wp_json_encode($schema_str);
        }

        try {
            $variable_manager = new \PolyTrans\PostProcessing\VariableManager();
            $interpolated = $variable_manager->interpolate_template($schema_str, $context);
            $schema = json_decode($interpolated, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                self::log("Schema interpolation produced invalid JSON, using all meta", "warning", [
                    'json_error' => json_last_error_msg(),
                    'schema_preview' => substr($interpolated, 0, 500)
                ]);
                return $flat_meta;
            }
        } catch (\Exception $e) {
            self::log("Schema interpolation failed, using all meta", "warning", [
                'error' => $e->getMessage()
            ]);
            return $flat_meta;
        }

        // Extract meta keys from schema (look for "target": "meta.KEY")
        $needed_keys = self::extract_meta_keys_from_schema($schema);

        if (empty($needed_keys)) {
            self::log("No meta keys found in schema, using all meta", "debug");
            return $flat_meta;
        }

        // Filter meta to only needed keys
        $filtered_meta = [];
        foreach ($needed_keys as $key) {
            if (isset($flat_meta[$key])) {
                $filtered_meta[$key] = $flat_meta[$key];
            }
        }

        self::log("Filtered meta by schema", "debug", [
            'original_count' => count($flat_meta),
            'schema_keys_count' => count($needed_keys),
            'filtered_count' => count($filtered_meta)
        ]);

        return $filtered_meta;
    }

    /**
     * Recursively extract meta keys from schema
     * Looks for objects with "target": "meta.KEY" pattern
     *
     * @param array $schema Parsed schema array
     * @return array List of meta keys
     */
    private static function extract_meta_keys_from_schema($schema)
    {
        $keys = [];

        if (!is_array($schema)) {
            return $keys;
        }

        foreach ($schema as $field => $config) {
            if (is_array($config)) {
                // Check if this field has a target pointing to meta
                if (isset($config['target']) && is_string($config['target'])) {
                    if (strpos($config['target'], 'meta.') === 0) {
                        $meta_key = substr($config['target'], 5); // Remove "meta." prefix
                        $keys[] = $meta_key;
                    }
                }
                // Recurse into nested structures
                $keys = array_merge($keys, self::extract_meta_keys_from_schema($config));
            }
        }

        return array_unique($keys);
    }
}

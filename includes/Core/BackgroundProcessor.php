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

        // Method 1: Try PHP execution functions if available
        if (self::is_exec_available()) {
            return self::spawn_exec($args, $action);
        }

        // Method 2: Use direct loopback HTTP request (most compatible)
        return self::spawn_http_request($args, $action);
    }

    /**
     * Check if exec() is available
     * 
     * @return bool True if exec() is available
     */
    private static function is_exec_available()
    {
        // Common disabled functions in PHP
        $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions')));
        $exec_disabled = in_array('exec', $disabled_functions);
        $system_disabled = in_array('system', $disabled_functions);
        $shell_exec_disabled = in_array('shell_exec', $disabled_functions);

        // Check if any exec function is available
        if (!$exec_disabled && function_exists('exec')) {
            return true;
        }

        if (!$shell_exec_disabled && function_exists('shell_exec')) {
            return true;
        }

        if (!$system_disabled && function_exists('system')) {
            return true;
        }

        return false;
    }

    /**
     * Spawn a background process using exec() or equivalent
     * 
     * @param array $args Arguments to pass to the process
     * @param string $action The action to perform
     * @return bool True if successful
     */
    private static function spawn_exec($args, $action)
    {
        // Generate a unique token for this process
        $token = md5(uniqid(mt_rand(), true));

        // Store the args in a transient for retrieval by the background process
        set_transient('polytrans_bg_' . $token, [
            'args' => $args,
            'action' => $action
        ], 3600); // expires in 1 hour

        // Get path to the process task file
        $process_file = POLYTRANS_PLUGIN_DIR . 'includes/process-task.php';
        
        if (!file_exists($process_file)) {
            self::log("Background process spawn failed: process-task.php not found at $process_file", "error", [
                'action' => $action,
                'plugin_dir' => POLYTRANS_PLUGIN_DIR
            ]);
            return false;
        }

        // Get PHP binary
        $php_binary = PHP_BINARY ?: 'php';

        // Build command with token as argument
        // Note: We use transient for error logging instead of file (works with S3 uploads)
        $cmd = "$php_binary $process_file $token > /dev/null 2>&1 &";

        // Try multiple command execution methods
        $success = false;
        $output = '';

        if (function_exists('exec')) {
            @exec($cmd, $output, $return_var);
            $success = ($return_var === 0);
            if ($success) {
                $log_context = [
                    'cmd' => $cmd,
                    'token' => $token,
                    'action' => $action,
                    'process_file' => $process_file
                ];
                // Add post_id only if it exists (not for workflow tests)
                if (isset($args['post_id'])) {
                    $log_context['post_id'] = $args['post_id'];
                }
                if (isset($args['test_id'])) {
                    $log_context['test_id'] = $args['test_id'];
                }
                self::log("Spawned background process with exec", "info", $log_context);
            } else {
                self::log("Failed to spawn background process with exec", "error", [
                    'cmd' => $cmd,
                    'return_var' => $return_var,
                    'output' => $output,
                    'token' => $token,
                    'action' => $action
                ]);
            }
        }

        // Try shell_exec if exec failed
        if (!$success && function_exists('shell_exec')) {
            @shell_exec($cmd);
            $success = true; // Can't verify success with shell_exec
            $log_context = [
                'cmd' => $cmd,
                'token' => $token,
                'action' => $action,
                'log_file' => $log_file,
                'process_file' => $process_file
            ];
            if (isset($args['post_id'])) {
                $log_context['post_id'] = $args['post_id'];
            }
            if (isset($args['test_id'])) {
                $log_context['test_id'] = $args['test_id'];
            }
            self::log("Spawned background process with shell_exec", "info", $log_context);
        }

        // Try system if shell_exec failed
        if (!$success && function_exists('system')) {
            @system($cmd, $return_var);
            $success = ($return_var === 0);
            if ($success) {
                $log_context = [
                    'cmd' => $cmd,
                    'token' => $token,
                    'action' => $action,
                    'process_file' => $process_file
                ];
                if (isset($args['post_id'])) {
                    $log_context['post_id'] = $args['post_id'];
                }
                if (isset($args['test_id'])) {
                    $log_context['test_id'] = $args['test_id'];
                }
                self::log("Spawned background process with system", "info", $log_context);
            } else {
                self::log("Failed to spawn background process with system", "error", [
                    'cmd' => $cmd,
                    'return_var' => $return_var,
                    'token' => $token,
                    'action' => $action
                ]);
            }
        }

        // Check transient for errors after 1 second to catch early errors
        if ($success) {
            // If FastCGI is available, finish request first (non-blocking for user)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Wait 1 second for process to start and write initial errors to transient
            sleep(1);
            
            // Check transient for errors (instead of log file - works with S3 uploads)
            self::check_bg_errors_immediate($token, $action);
        }

        return $success;
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
            ignore_user_abort(true);
            set_time_limit(0);

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
        $token = md5(uniqid(mt_rand(), true));

        // Store the args in a transient
        // Note: No log_file needed for HTTP request - endpoint runs in WordPress context
        // and all logging goes directly to LogsManager
        set_transient('polytrans_bg_' . $token, [
            'args' => $args,
            'action' => $action
        ], 3600); // expires in 1 hour

        // Build the URL to our processing endpoint
        $url = add_query_arg([
            'polytrans_bg' => 1,
            'token' => $token,
            'action' => $action,
            'nonce' => wp_create_nonce('polytrans_bg_process')
        ], home_url('/'));

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
        if (!$success && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERAGENT, 'PolyTrans Background Process');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Polytrans-BG: Processing']);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            @curl_exec($ch);
            curl_close($ch);
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
        $translation_provider = $settings['translation_provider'] ?? 'google';
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
            'msg' => sprintf(__('Starting translation with %s.', 'polytrans'), ucfirst($translation_provider))
        ];
        update_post_meta($post_id, $log_key, $log);

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

            // Get featured image metadata for translation
            $featured_image_data = null;
            if (has_post_thumbnail($post_id)) {
                $thumbnail_id = get_post_thumbnail_id($post_id);
                $attachment = get_post($thumbnail_id);

                if ($attachment) {
                    $featured_image_data = [
                        'id' => $thumbnail_id,
                        'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
                        'title' => $attachment->post_title,
                        'caption' => $attachment->post_excerpt,
                        'description' => $attachment->post_content,
                        'filename' => basename(get_attached_file($thumbnail_id))
                    ];
                }
            }

            $content_to_translate = [
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'meta' => $meta,
                'featured_image' => $featured_image_data
            ];
            
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
                    $settings
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
                throw new Exception(sprintf(__('Translation provider %s not found.', 'polytrans'), $translation_provider));
            }

            // Use the provider to translate
            self::log("Sending content to translation provider", "info", [
                'post_id' => $post_id,
                'provider' => $translation_provider,
                'content_length' => strlen($post->post_content)
            ]);

            $translation_result = $provider->translate($content_to_translate, $source_lang, $target_lang, $settings);
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
                'translated' => $translation_result['translated_content']
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
                    __('Translation completed successfully. New post ID: <a href="%s">%d</a>', 'polytrans'),
                    esc_url(admin_url('post.php?post=' . $result['created_post_id'] . '&action=edit')),
                    $result['created_post_id']
                )
            ];

            // Store the created post ID
            update_post_meta($post_id, '_polytrans_translation_post_id_' . $target_lang, $result['created_post_id']);

            // Fire action for post-processing workflows
            do_action('polytrans_translation_completed', $post_id, $result['created_post_id'], $target_lang);

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

            self::log("Translation completed successfully", "info", [
                'post_id' => $post_id,
                'created_post_id' => $result['created_post_id'],
                'source_lang' => $source_lang,
                'target_lang' => $target_lang
            ]);
        } catch (\Exception $e) {
            // Update error status and log
            update_post_meta($post_id, $status_key, 'failed');

            // Store error details
            update_post_meta($post_id, '_polytrans_translation_error_' . $target_lang, $e->getMessage());

            $log[] = [
                'timestamp' => time(),
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
            $context = [
                'original_post_id' => $original_post_id,
                'translated_post_id' => $translated_post_id,
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
                'steps_executed' => $result['steps_executed'] ?? 0
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
     * Check logs table and functionality on plugin activation
     */
    public static function check_on_activation()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'polytrans_logs';

        // Load the logs manager
        // Note: PolyTrans_Logs_Manager is autoloaded

        // Check if the logs table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            error_log("[polytrans] Logs table exists: $table_name");

            // Check the table columns
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table_name`");
            $column_names = [];

            if ($columns) {
                foreach ($columns as $col) {
                    $column_names[] = $col->Field;
                }
                error_log("[polytrans] Logs table columns: " . implode(', ', $column_names));
            } else {
                error_log("[polytrans] Could not retrieve logs table columns");
            }

            // Add a test log entry
            self::log("Plugin activated - test log entry from Background Processor", "info", [
                'source' => 'activation_check'
            ]);
        } else {
            error_log("[polytrans] Logs table does not exist, using postmeta only");
        }

        // Test post meta logging
        $test_post_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_type = 'post' ORDER BY ID DESC LIMIT 1");
        if ($test_post_id) {
            $meta_key = '_polytrans_activation_test';
            update_post_meta($test_post_id, $meta_key, time());
            $meta_value = get_post_meta($test_post_id, $meta_key, true);
            if ($meta_value) {
                error_log("[polytrans] Post meta test successful on post ID: $test_post_id");
                // Clean up
                delete_post_meta($test_post_id, $meta_key);
            } else {
                error_log("[polytrans] Post meta test failed on post ID: $test_post_id");
            }
        } else {
            error_log("[polytrans] No posts found to test post meta");
        }
    }

    /**
     * Check background process errors from transient immediately for early errors
     * 
     * Background process writes errors to transient 'polytrans_bg_errors_{token}'
     * This method checks that transient after 1 second and logs to LogsManager
     * 
     * @param string $token Process token
     * @param string $action Process action
     * @return void
     */
    private static function check_bg_errors_immediate($token, $action)
    {
        $error_transient_key = 'polytrans_bg_errors_' . $token;
        $errors = get_transient($error_transient_key);
        
        if (empty($errors)) {
            return; // No errors yet
        }

        // Log errors to LogsManager
        if (is_array($errors)) {
            foreach ($errors as $error) {
                self::log(
                    "Background process error detected: " . ($error['message'] ?? 'Unknown error'),
                    "error",
                    [
                        'token' => $token,
                        'action' => $action,
                        'error' => $error
                    ]
                );
            }
        } else {
            // Fallback for string errors
            self::log(
                "Background process error detected: " . $errors,
                "error",
                [
                    'token' => $token,
                    'action' => $action
                ]
            );
        }
        
        // Clean up transient after reading
        delete_transient($error_transient_key);
    }

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
            $schema_str = json_encode($schema_str);
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

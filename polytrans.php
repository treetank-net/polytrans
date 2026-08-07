<?php

/**
 * Plugin Name: PolyTrans
 * Plugin URI: https://github.com/treetank-net/polytrans
 * Description: Advanced multilingual translation management system with AI-powered translation, scheduling, and review workflow
 * Version: 1.18.2
 * Author: treetank
 * Author URI: https://treetank.net
 * Text Domain: polytrans
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('POLYTRANS_VERSION', '1.18.2');
define('POLYTRANS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POLYTRANS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('POLYTRANS_PLUGIN_FILE', __FILE__);

// Initialize Bootstrap (handles autoloading and compatibility)
require_once POLYTRANS_PLUGIN_DIR . 'includes/Bootstrap.php';
\PolyTrans\Bootstrap::init();

// Include the main plugin class (still needed until we migrate it)
require_once POLYTRANS_PLUGIN_DIR . 'includes/class-polytrans.php';

// Background processor is now autoloaded via PSR-4
// (PolyTrans\Core\BackgroundProcessor)
add_action('polytrans_bg_process_async_job', [\PolyTrans\Core\AsyncJobRunner::class, 'executeBackgroundJob']);

/**
 * Handle background process requests
 */
function polytrans_handle_background_request()
{
    if (isset($_GET['polytrans_bg']) && isset($_GET['token']) && isset($_GET['nonce'])) {
        $token = sanitize_key(wp_unslash($_GET['token'] ?? ''));
        $data = get_transient('polytrans_bg_' . $token);

        $nonce_valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'polytrans_bg_process');
        if (!$nonce_valid) {
            if (class_exists('\PolyTrans\Core\LogsManager')) {
                \PolyTrans\Core\LogsManager::log("Background process request: Invalid nonce. Token: " . ($token ?: 'missing'), "error", ['token' => $token]);
            }
            return;
        }
        
        if ($nonce_valid) {
            // Set headers to prevent caching and handle long-running process
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            header('X-Robots-Tag: noindex, nofollow');
            header('Connection: close');

            // Disable browser buffering
            if (function_exists('fastcgi_finish_request')) {
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
                fastcgi_finish_request();
            } else {
                // Fallback for non-FastCGI servers
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
            }

            if ($data) {
                $args = $data['args'] ?? [];
                $action = $data['action'] ?? 'process-translation';

                // Use namespaced class first, then legacy fallback
                if (class_exists('\PolyTrans\Core\BackgroundProcessor')) {
                    try {
                        \PolyTrans\Core\BackgroundProcessor::process_task($args, $action);
                    } catch (\Throwable $e) {
                        if (class_exists('\PolyTrans\Core\LogsManager')) {
                            \PolyTrans\Core\LogsManager::log(
                                "BackgroundProcessor::process_task() failed: " . $e->getMessage(),
                                "error",
                                [
                                    'token' => $token,
                                    'action' => $action,
                                    'exception' => $e->getMessage(),
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'trace' => $e->getTraceAsString()
                                ]
                            );
                        }
                        throw $e;
                    }
                } else {
                    if (class_exists('\PolyTrans\Core\LogsManager')) {
                        \PolyTrans\Core\LogsManager::log("BackgroundProcessor class not found", "error", ['token' => $token]);
                    }
                }

                delete_transient('polytrans_bg_' . $token);
            } else {
                if (class_exists('\PolyTrans\Core\LogsManager')) {
                    \PolyTrans\Core\LogsManager::log("Background process request: No data found for token " . $token, "error", ['token' => $token]);
                }
            }

            exit;
        }
    }
}
add_action('init', 'polytrans_handle_background_request', 5);

/**
 * Register default providers (Google, OpenAI, Claude)
 * These are registered via hook for consistency with external plugins
 */
add_action('polytrans_register_providers', function($registry) {
    if (defined('POLYTRANS_ENABLE_GOOGLE') && POLYTRANS_ENABLE_GOOGLE) {
        $registry->register_provider(new \PolyTrans\Providers\Google\GoogleProvider());
    }
    $registry->register_provider(new \PolyTrans\Providers\OpenAI\OpenAIProvider());
    $registry->register_provider(new \PolyTrans\Providers\Claude\ClaudeProvider());
    $registry->register_provider(new \PolyTrans\Providers\Gemini\GeminiProvider());
}, 10, 1);

/**
 * Register default chat clients (OpenAI, Claude)
 * These are registered via filter for consistency with external plugins
 */
add_filter('polytrans_chat_client_factory_create', function($client, $provider_id, $settings) {
    // OpenAI chat client
    if ($provider_id === 'openai' && $client === null) {
        $api_key = $settings['openai_api_key'] ?? '';
        if (!empty($api_key)) {
            // Class is autoloaded via PSR-4, no require_once needed
            return new \PolyTrans\Providers\OpenAI\OpenAIChatClientAdapter($api_key);
        }
    }
    
    // Claude chat client
    if ($provider_id === 'claude' && $client === null) {
        $api_key = $settings['claude_api_key'] ?? '';
        if (!empty($api_key)) {
            return new \PolyTrans\Providers\Claude\ClaudeChatClientAdapter($api_key);
        }
    }
    
    // Gemini chat client
    if ($provider_id === 'gemini' && $client === null) {
        $api_key = $settings['gemini_api_key'] ?? '';
        if (!empty($api_key)) {
            return new \PolyTrans\Providers\Gemini\GeminiChatClientAdapter($api_key);
        }
    }
    
    // Future: Other providers can be registered here via filter
    // External plugins can use 'polytrans_chat_client_factory_create' filter
    
    return $client;
}, 10, 3);

/**
 * Initialize the plugin
 */
function polytrans_init()
{
    PolyTrans::get_instance();
}
add_action('plugins_loaded', 'polytrans_init');

/**
 * Initialize the new Workflow system bridge
 */
function polytrans_init_workflow_bridge()
{
    // Initialize WorkflowBridge for virtual workflow support
    if (class_exists(\PolyTrans\Workflows\WorkflowBridge::class)) {
        \PolyTrans\Workflows\WorkflowBridge::get_instance();
    }
}
add_action('plugins_loaded', 'polytrans_init_workflow_bridge', 20);

/**
 * Check database tables on admin_init (in case activation hook didn't run)
 */
function polytrans_check_workflows_table()
{
    \PolyTrans\PostProcessing\Managers\WorkflowStorageManager::initialize();
    \PolyTrans\Assistants\AssistantManager::create_table();
    \PolyTrans\PromptRefinement\RefinementRunStorage::initialize();
    \PolyTrans\Core\LogsManager::maybe_cleanup_old_logs();
    
    // Add expected_output_schema column if it doesn't exist
    global $wpdb;
    $table_name = $wpdb->prefix . 'polytrans_assistants';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'expected_output_schema'");
    if (empty($column_exists)) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin upgrade: adding missing column
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted source ($wpdb->prefix)
        $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN expected_output_schema text AFTER expected_format");
    }
}
add_action('admin_init', 'polytrans_check_workflows_table');

/**
 * Plugin activation hook
 */
function polytrans_activate()
{
    // Set default options
    $default_settings = [
        'translation_provider' => 'openai',
        'translation_transport_mode' => 'external',
        'translation_endpoint' => '',
        'translation_receiver_endpoint' => '',
        'translation_receiver_secret' => '',
        'translation_receiver_secret_method' => 'header_bearer',
        'allowed_sources' => [],
        'allowed_targets' => [],
        'reviewer_email' => '',
        'reviewer_email_title' => 'Translation ready for review: {title}',
        'author_email' => '',
        'author_email_title' => 'Your translation has been published: {title}',
        'enable_db_logging' => '1', // Enable DB logging by default
    ];

    add_option('polytrans_settings', $default_settings);

    // Ensure Bootstrap is loaded for autoloading
    if (!class_exists('\PolyTrans\Bootstrap')) {
        require_once POLYTRANS_PLUGIN_DIR . 'includes/Bootstrap.php';
    }
    \PolyTrans\Bootstrap::init();

    // Create database tables if needed and enabled in settings
    if (\PolyTrans\Core\LogsManager::is_db_logging_enabled()) {
        \PolyTrans\Core\LogsManager::create_logs_table();
        \PolyTrans\Core\LogsManager::log(
            "PolyTrans plugin activated",
            "info",
            ['version' => POLYTRANS_VERSION, 'source' => 'activation']
        );
    } else {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Plugin activated, database logging disabled in settings");
    }

    // Initialize workflows table
    \PolyTrans\PostProcessing\Managers\WorkflowStorageManager::initialize();

    // Initialize assistants table
    \PolyTrans\Assistants\AssistantManager::create_table();

    // Initialize prompt refinement runtime storage table
    \PolyTrans\PromptRefinement\RefinementRunStorage::initialize();

    // Initialize token usage / cost table
    \PolyTrans\Core\UsageRecorder::initialize();

    // Run migration from ai_assistant to managed_assistant (one-time)
    if (\PolyTrans\Assistants\AssistantMigration::is_migration_needed()) {
        \PolyTrans\Assistants\AssistantMigration::migrate_workflows_to_managed_assistants();
    }

    // Check the logs table structure for debugging
    if (class_exists(\PolyTrans\Core\BackgroundProcessor::class)) {
        \PolyTrans\Core\BackgroundProcessor::check_on_activation();
    }

    // Schedule cron job to check for stuck translations (daily)
    if (!wp_next_scheduled('polytrans_check_stuck_translations')) {
        wp_schedule_event(time(), 'daily', 'polytrans_check_stuck_translations');
    }
}
register_activation_hook(__FILE__, 'polytrans_activate');

/**
 * Plugin deactivation hook
 */
function polytrans_deactivate()
{
    // Clean up any scheduled tasks
    wp_clear_scheduled_hook('polytrans_cleanup');
    wp_clear_scheduled_hook('polytrans_check_stuck_translations');
    wp_clear_scheduled_hook('polytrans_cleanup_bg_log');
}
register_deactivation_hook(__FILE__, 'polytrans_deactivate');

/**
 * Create plugin database tables
 */
function polytrans_create_tables()
{
    // Load the settings to check if database logging is enabled
    $settings = get_option('polytrans_settings', []);
    $db_logging_enabled = isset($settings['enable_db_logging']) ? (bool)$settings['enable_db_logging'] : true;

    // Create logs table using the Logs Manager if database logging is enabled
    if ($db_logging_enabled) {
        // Ensure Bootstrap is loaded for autoloading
        if (!class_exists('\PolyTrans\Bootstrap')) {
            require_once POLYTRANS_PLUGIN_DIR . 'includes/Bootstrap.php';
        }
        \PolyTrans\Bootstrap::init();

        \PolyTrans\Core\LogsManager::create_logs_table();
    } else {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Database logging is disabled, skipping logs table creation");
    }

    // Any additional tables can be created here
}

/**
 * Schedule cleanup tasks
 */
function polytrans_schedule_cleanup()
{
    if (!wp_next_scheduled('polytrans_cleanup')) {
        wp_schedule_event(time(), 'daily', 'polytrans_cleanup');
    }
}
add_action('wp', 'polytrans_schedule_cleanup');

/**
 * Run cleanup tasks
 */
function polytrans_run_cleanup()
{
    $handler = \PolyTrans\Scheduler\TranslationHandler::get_instance();
    $fixed = $handler->fix_stuck_translations(24);

    if ($fixed > 0) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Fixed $fixed stuck translations");
    }
}
add_action('polytrans_cleanup', 'polytrans_run_cleanup');

/**
 * Check for stuck translations and mark them as failed
 */
function polytrans_check_stuck_translations()
{
    $status_manager = new \PolyTrans\Receiver\Managers\StatusManager();
    $results = $status_manager->check_stuck_translations(24);

    if ($results['fixed'] > 0) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Fixed {$results['fixed']} stuck translations out of {$results['checked']} checked");
    }
}
add_action('polytrans_check_stuck_translations', 'polytrans_check_stuck_translations');

/**
 * Clean up background process log files
 */
function polytrans_cleanup_bg_log($log_file)
{
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct file operation required
    if (file_exists($log_file) && is_writable($log_file)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cache file cleanup
        @unlink($log_file);
    }
}
add_action('polytrans_cleanup_bg_log', 'polytrans_cleanup_bg_log');

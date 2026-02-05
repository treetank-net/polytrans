<?php

namespace PolyTrans\Scheduler;

use PolyTrans\PostProcessing\Providers\ArticlesDataProvider;
use PolyTrans\Core\BackgroundProcessor;
use PolyTrans\Core\LogsManager;

/**
 * Translation Handler Class
 * Handles translation scheduling and processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationHandler
{

    // Define allowed SEO meta keys for translation as a constant
    public const POLYTRANS_ALLOWED_SEO_META_KEYS = [
        // RankMath SEO fields
        'rank_math_title',
        'rank_math_description',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        'rank_math_focus_keyword',
        // Yoast SEO fields
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
    ];

    // Additional meta key patterns (regex) to include in translation context
    // These are passed to assistants but not necessarily translated by default providers
    public const POLYTRANS_ADDITIONAL_META_PATTERNS = [
        // Flynt/ACF Flexible Content components - contentHtml (WYSIWYG)
        '/^postComponents_\d+_contentHtml$/',
        '/^pageComponents_\d+_contentHtml$/',
        // Flynt/ACF Flexible Content - all text fields (title, subtitle, description, text, label, etc.)
        '/^(post|page)Components_\d+_(title|subtitle|sectionTitle|sectionSubtitle|sectionTitleHighlight|titleHighlight|description|text|label|badge|offerText|leftTitle|rightTitle|leftSubtitle|rightSubtitle|perfectForTitle|headerTitle|formTitle)$/',
        // Flynt/ACF Flexible Content - nested repeater text fields
        '/^(post|page)Components_\d+_\w+_\d+_(title|description|text|label|number|linkText|textBefore)$/',
        // Flynt/ACF Flexible Content - nested group text fields (e.g., loginLink, ctaButton)
        '/^(post|page)Components_\d+_\w+_(text|linkText|textBefore)$/',
    ];

    /**
     * Filter meta keys for translation context
     * Includes both explicit SEO keys and pattern-matched keys
     *
     * @param array $all_meta All post meta (from get_post_meta)
     * @return array Filtered meta array
     */
    public static function filter_meta_for_translation($all_meta)
    {
        $filtered = [];
        $allowed_keys = self::POLYTRANS_ALLOWED_SEO_META_KEYS;
        $patterns = self::POLYTRANS_ADDITIONAL_META_PATTERNS;

        // Allow themes/plugins to extend patterns
        $patterns = apply_filters('polytrans_meta_patterns', $patterns);

        foreach ($all_meta as $key => $value) {
            // Check explicit keys
            if (in_array($key, $allowed_keys, true)) {
                $filtered[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
                continue;
            }

            // Check patterns
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $key)) {
                    $filtered[$key] = is_array($value) && count($value) === 1 ? $value[0] : $value;
                    break;
                }
            }
        }

        return $filtered;
    }
    
    private static $instance = null;

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
        add_action('wp_ajax_polytrans_get_translation_status', [$this, 'ajax_get_translation_status']);
        add_action('wp_ajax_polytrans_clear_translation_status', [$this, 'ajax_clear_translation_status']);
        add_action('wp_ajax_polytrans_retry_translation', [$this, 'ajax_retry_translation']);
    }

    /**
     * Handle schedule translation AJAX request
     */
    public function handle_schedule_translation()
    {
        check_ajax_referer('polytrans_schedule_translation');

        $post_id = intval($_POST['post_id'] ?? 0);
        $scope = sanitize_text_field($_POST['scope'] ?? 'local');
        $targets = array_map('sanitize_text_field', $_POST['targets'] ?? []);
        $source_lang = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : 'pl';
        $targets = array_diff($targets, [$source_lang]);
        $needs_review = !empty($_POST['needs_review']);

        $validation = $this->validate_schedule_translation($post_id, $scope, $targets);
        if ($validation !== true) {
            wp_send_json_error($validation);
        }

        // Get allowed targets from settings
        $settings = get_option('polytrans_settings', []);
        $allowed_targets = $settings['allowed_targets'] ?? [];
        if ($scope === 'global') {
            $targets = $allowed_targets;
        }

        // Scheduled languages meta key
        $langs_key = '_polytrans_translation_langs';
        $scheduled_langs = get_post_meta($post_id, $langs_key, true);
        if (!is_array($scheduled_langs)) $scheduled_langs = [];
        $now = time();
        $newly_scheduled = [];

        foreach ($targets as $lang) {
            if ($lang === $source_lang) continue;

            $status_key = '_polytrans_translation_status_' . $lang;
            $log_key = '_polytrans_translation_log_' . $lang;
            $needs_review_key = '_polytrans_translation_needs_review_' . $lang;
            // TODO: in theory we shouldn't translate to target language, but it seems stuck
            //$current_status = get_post_meta($post_id, $status_key, true);

            //if ($current_status === 'started') continue; // Already scheduled

            // Schedule translation
            update_post_meta($post_id, $status_key, 'started');
            update_post_meta($post_id, $needs_review_key, $needs_review ? '1' : '0');
            update_post_meta($post_id, $log_key, [[
                'timestamp' => $now,
                'msg' => __('Translation scheduled.', 'polytrans'),
            ]]);

            if (!in_array($lang, $scheduled_langs, true)) {
                $scheduled_langs[] = $lang;
                $newly_scheduled[] = $lang;
            }

            // Send translation request
            $this->send_translation_request($post_id, $source_lang, $lang, $settings);
        }

        update_post_meta($post_id, $langs_key, $scheduled_langs);
        LogsManager::log("Translation scheduling finished for post $post_id", "info", [
            'post_id' => $post_id,
            'scheduled_langs' => $scheduled_langs,
            'scope' => $scope,
            'targets' => $targets,
        ]);

        wp_send_json_success([
            'message' => 'Translation scheduled (' . esc_html($scope) . ').',
            'targets' => $targets,
            'scheduled_langs' => $scheduled_langs
        ]);
    }

    /**
     * Send translation request to background process or external endpoint
     * 
     * @param int $post_id Post ID to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param array $settings Plugin settings
     */
    private function send_translation_request($post_id, $source_lang, $target_lang, $settings)
    {
        // Get the transport mode from settings
        $transport_mode = isset($settings['translation_transport_mode']) ? $settings['translation_transport_mode'] : 'external';

        // Update translation status
        $status_key = '_polytrans_translation_status_' . $target_lang;
        $log_key = '_polytrans_translation_log_' . $target_lang;

        // Set status to 'started' to indicate the translation has been scheduled
        update_post_meta($post_id, $status_key, 'started');

        // Prepare log entry
        $log = get_post_meta($post_id, $log_key, true);
        if (!is_array($log)) $log = [];

        // Add a status update log entry
        $log[] = [
            'timestamp' => time(),
            'msg' => sprintf(__('Translation process scheduled (mode: %s).', 'polytrans'), $transport_mode)
        ];
        update_post_meta($post_id, $log_key, $log);
        LogsManager::log("Translation scheduled for post $post_id from $source_lang to $target_lang (mode: $transport_mode)", "info");

        if ($transport_mode === 'internal') {
            // For internal mode, use the background processor
            $this->process_with_background_processor($post_id, $source_lang, $target_lang, $log, $log_key, $status_key);
        } else {
            // For external mode, prepare to send to external endpoint
            $this->process_with_external_endpoint($post_id, $source_lang, $target_lang, $settings, $log, $log_key, $status_key);
        }
    }

    /**
     * Process translation using local background processor
     * 
     * @param int $post_id Post ID to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param array $log Log entries array
     * @param string $log_key Log meta key
     * @param string $status_key Status meta key
     */
    private function process_with_background_processor($post_id, $source_lang, $target_lang, &$log, $log_key, $status_key)
    {
        // Require the background processor class

        // Update status to 'translating' to indicate it's being processed via background
        update_post_meta($post_id, $status_key, 'translating');

        // Add a log entry about spawning the background process
        $log[] = [
            'timestamp' => time(),
            'msg' => __('Spawning background process for translation.', 'polytrans')
        ];
        update_post_meta($post_id, $log_key, $log);

        // Spawn a background process to handle this request
        $args = [
            'post_id' => $post_id,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang
        ];

        // Process using our background processor
        $result = BackgroundProcessor::spawn($args);

        if ($result) {
            // Get the logs page URL
            $logs_url = admin_url('admin.php?page=polytrans-logs');

            // Add a link to the logs
            $log[] = [
                'timestamp' => time(),
                'msg' => sprintf(
                    __('Translation request queued in background process. <a href="%s" target="_blank">View logs</a> for more details.', 'polytrans'),
                    esc_url($logs_url)
                )
            ];

            LogsManager::log("Translation request queued in background process for post $post_id from $source_lang to $target_lang", "info");
            update_post_meta($post_id, $log_key, $log);
            return;
        }

        // If background process spawning failed, log the error
        $log[] = [
            'timestamp' => time(),
            'msg' => __('Failed to start background process. Please check server configuration.', 'polytrans')
        ];
        LogsManager::log("Failed to spawn background process for post $post_id translation", "info");
        update_post_meta($post_id, $status_key, 'failed');
        update_post_meta($post_id, $log_key, $log);
    }

    /**
     * Process translation using external endpoint
     * 
     * @param int $post_id Post ID to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param array $settings Plugin settings
     * @param array $log Log entries array
     * @param string $log_key Log meta key
     * @param string $status_key Status meta key
     */
    private function process_with_external_endpoint($post_id, $source_lang, $target_lang, $settings, &$log, $log_key, $status_key)
    {
        // Get the external endpoint URL
        $translation_endpoint = isset($settings['translation_endpoint']) ? $settings['translation_endpoint'] : '';
        $translation_receiver_endpoint = isset($settings['translation_receiver_endpoint']) ? $settings['translation_receiver_endpoint'] : '';

        if (empty($translation_endpoint)) {
            // Log error if no endpoint is configured
            $log[] = [
                'timestamp' => time(),
                'msg' => __('Failed to send translation request: No translation endpoint configured.', 'polytrans')
            ];
            LogsManager::log("Failed to send external translation request for post $post_id: No endpoint configured", "info");
            update_post_meta($post_id, $status_key, 'failed');
            update_post_meta($post_id, $log_key, $log);
            return;
        }

        if (empty($translation_receiver_endpoint)) {
            // Use default receiver endpoint if none is configured
            $translation_receiver_endpoint = site_url('/wp-json/polytrans/v1/translation/receive-post');
            LogsManager::log("Using default receiver endpoint: $translation_receiver_endpoint", "info");
        }

        // Update status to 'translating' to indicate it's actively being sent to external service
        update_post_meta($post_id, $status_key, 'translating');

        // Add log entry about sending to external service
        $log[] = [
            'timestamp' => time(),
            'msg' => sprintf(__('Sending translation request to external endpoint: %s', 'polytrans'), $translation_endpoint)
        ];
        update_post_meta($post_id, $log_key, $log);

        // Prepare the post content for translation
        $post = get_post($post_id);
        if (!$post) {
            $log[] = [
                'timestamp' => time(),
                'msg' => __('Failed to send translation request: Post not found.', 'polytrans')
            ];
            LogsManager::log("Failed to send external translation request for post $post_id: Post not found", "info");
            update_post_meta($post_id, $status_key, 'failed');
            update_post_meta($post_id, $log_key, $log);
            return;
        }

        // Prepare metadata
        $all_meta = get_post_meta($post_id);
        $meta = self::filter_meta_for_translation($all_meta);

        // Get recent articles in target language for context
        $context_articles = [];
        $articles_provider = new ArticlesDataProvider();

        // Prepare context for the articles provider
        $context = [
            'articles_count' => 20,
            'post_id' => $post_id, // Exclude current post
            'article_post_types' => ['post'],
            'target_language' => $target_lang
        ];

        $variables = $articles_provider->get_variables($context);
        $recent_articles = $variables['recent_articles'] ?? [];

        if (!empty($recent_articles)) {
            foreach ($recent_articles as $article) {
                $context_articles[] = [
                    'id' => $article['id'],
                    'title' => $article['title'],
                    'content' => $article['content'],
                    'excerpt' => $article['excerpt'],
                    'date' => $article['date'],
                    'url' => $article['url'],
                    'categories' => $article['categories'],
                    'tags' => $article['tags']
                ];
            }

            LogsManager::log(
                "Added " . count($context_articles) . " recent articles in $target_lang as translation context for post $post_id",
                "info"
            );

            // Add payload structure info to log
            $log[] = [
                'timestamp' => time(),
                'msg' => sprintf(
                    __('Payload includes: source article + %d context articles in %s', 'polytrans'),
                    count($context_articles),
                    $target_lang
                )
            ];
            update_post_meta($post_id, $log_key, $log);
        } else {
            LogsManager::log(
                "No recent articles found in $target_lang for translation context for post $post_id",
                "info"
            );

            $log[] = [
                'timestamp' => time(),
                'msg' => sprintf(
                    __('No context articles found in %s - sending only source article', 'polytrans'),
                    $target_lang
                )
            ];
            update_post_meta($post_id, $log_key, $log);
        }

        // Receiver credentials (passed in payload to translator, for use when sending to receiver)
        $receiver_secret = $settings['translation_receiver_secret'] ?? '';
        $receiver_secret_method = $settings['translation_receiver_secret_method'] ?? 'header_bearer';
        $receiver_custom_header = $settings['translation_receiver_secret_custom_header'] ?? 'x-polytrans-secret';

        // Prepare payload for the external translation request
        $payload = [
            'source_language' => $source_lang,
            'target_language' => $target_lang,
            'original_post_id' => $post_id,
            'target_endpoint' => $translation_receiver_endpoint,
            'toTranslate' => [
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'meta' => json_decode(json_encode($meta), true)
            ],
            'context_articles' => $context_articles,
            // Receiver credentials - translator will use these when sending to receiver
            'receiver_credentials' => [
                'secret' => $receiver_secret,
                'method' => $receiver_secret_method,
                'custom_header' => $receiver_custom_header,
            ]
        ];

        // Endpoint credentials (for authenticating SOURCE → TRANSLATOR)
        // Falls back to receiver credentials if not specified
        $endpoint_secret = $settings['translation_endpoint_secret'] ?? '';
        $endpoint_secret_method = $settings['translation_endpoint_secret_method'] ?? '';
        $endpoint_custom_header = $settings['translation_endpoint_secret_custom_header'] ?? '';

        // Apply fallbacks
        if (empty($endpoint_secret)) {
            $endpoint_secret = $receiver_secret;
        }
        if (empty($endpoint_secret_method)) {
            $endpoint_secret_method = $receiver_secret_method;
        }
        if (empty($endpoint_custom_header)) {
            $endpoint_custom_header = $receiver_custom_header;
        }

        // These are what we use for the HTTP request to the translation endpoint
        $secret = $endpoint_secret;
        $secret_method = $endpoint_secret_method;
        $custom_header_name = $endpoint_custom_header;

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 0.1,
            'blocking' => false,
            'sslverify' => (getenv('WP_ENV') === 'prod') ? true : false,
        ];

        if ($secret && $secret_method !== 'none') {
            switch ($secret_method) {
                case 'get_param':
                    $translation_endpoint = add_query_arg('secret', $secret, $translation_endpoint);
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

        // Make the request
        wp_remote_post($translation_endpoint, $args);

        // Log success if request was accepted
        $log[] = [
            'timestamp' => time(),
            'msg' => sprintf(__('Translation request sent successfully to external endpoint. Awaiting response.', 'polytrans'))
        ];
        LogsManager::log("External translation request sent successfully for post $post_id", "info");


        // Update the log
        update_post_meta($post_id, $log_key, $log);
    }


    /**
     * Validate schedule translation request
     */
    private function validate_schedule_translation($post_id, $scope, $targets)
    {
        // Check if post exists
        if (!$post_id || !get_post($post_id)) {
            return ['message' => __('Cannot translate: the post does not exist.', 'polytrans')];
        }

        // Check if post is currently being edited (has an active edit lock)
        $lock = wp_check_post_lock($post_id);
        if ($lock) {
            $user = get_userdata($lock);
            $lock_user = $user ? $user->display_name : __('another user', 'polytrans');
            return ['message' => sprintf(__('Cannot translate: the post is currently being edited by %s.', 'polytrans'), $lock_user)];
        }

        // Check for unsaved changes - WordPress sets a revision when autosave happens
        // If there's a more recent revision than the post itself, there are unsaved changes
        $revisions = wp_get_post_revisions($post_id, array('posts_per_page' => 1));
        if (!empty($revisions)) {
            $latest_revision = array_shift($revisions);
            $post = get_post($post_id);
            if ($latest_revision->post_modified > $post->post_modified) {
                return ['message' => __('Cannot translate: the article has unsaved changes. Please save the post first.', 'polytrans')];
            }
        }

        // Legacy dirty check for backwards compatibility
        $is_dirty = get_post_meta($post_id, 'is_dirty', true);
        if ($is_dirty === '1' || $is_dirty === 1 || $is_dirty === true) {
            return ['message' => __('Cannot translate: the article has unsaved changes or is marked as dirty.', 'polytrans')];
        }

        // Get allowed targets from settings
        $settings = get_option('polytrans_settings', []);
        $allowed_targets = $settings['allowed_targets'] ?? [];
        if ($scope === 'global') {
            $targets = $allowed_targets;
        }
        if ($scope === 'regional') {
            if (empty($targets)) {
                return ['message' => __('Please select at least one target language for regional translation.', 'polytrans')];
            }
        }

        $invalid_targets = array_diff($targets, $allowed_targets);
        if (!empty($invalid_targets)) {
            return [
                'message' => __('Invalid target language(s) selected.', 'polytrans'),
                'invalid_targets' => $invalid_targets
            ];
        }

        return true;
    }

    /**
     * AJAX handler for getting translation status
     */
    public function ajax_get_translation_status()
    {
        check_ajax_referer('polytrans_schedule_translation');

        $post_id = intval($_POST['post_id'] ?? 0);
        $langs_key = '_polytrans_translation_langs';
        $scheduled_langs = get_post_meta($post_id, $langs_key, true);
        if (!is_array($scheduled_langs)) $scheduled_langs = [];

        $result = [];
        foreach ($scheduled_langs as $lang) {
            $status_key = '_polytrans_translation_status_' . $lang;
            $log_key = '_polytrans_translation_log_' . $lang;
            $post_id_key = '_polytrans_translation_post_id_' . $lang;
            $result[$lang] = [
                'status' => get_post_meta($post_id, $status_key, true),
                'log' => get_post_meta($post_id, $log_key, true),
                'post_id' => get_post_meta($post_id, $post_id_key, true),
            ];
        }

        wp_send_json_success(['status' => $result]);
    }

    /**
     * AJAX handler for clearing translation status
     */
    public function ajax_clear_translation_status()
    {
        check_ajax_referer('polytrans_schedule_translation');

        $post_id = intval($_POST['post_id'] ?? 0);
        // Use target_lang if available, fallback to lang for backward compatibility
        $lang = sanitize_text_field($_POST['target_lang'] ?? $_POST['lang'] ?? '');
        $langs_key = '_polytrans_translation_langs';
        $scheduled_langs = get_post_meta($post_id, $langs_key, true);
        if (!is_array($scheduled_langs)) $scheduled_langs = [];

        if ($lang && in_array($lang, $scheduled_langs, true)) {
            $scheduled_langs = array_values(array_diff($scheduled_langs, [$lang]));
            update_post_meta($post_id, $langs_key, $scheduled_langs);
            delete_post_meta($post_id, '_polytrans_translation_status_' . $lang);
            delete_post_meta($post_id, '_polytrans_translation_log_' . $lang);
            LogsManager::log("Cleared translation status for $lang on post $post_id", "info");
            wp_send_json_success(['scheduled_langs' => $scheduled_langs]);
        }

        wp_send_json_error(['message' => 'Nothing to clear']);
    }

    /**
     * AJAX handler for retrying translation
     * This forces a re-translation even if one already exists
     */
    public function ajax_retry_translation()
    {
        check_ajax_referer('polytrans_schedule_translation');

        $post_id = intval($_POST['post_id'] ?? 0);
        // Use target_lang if available, fallback to lang for backward compatibility
        $lang = sanitize_text_field($_POST['target_lang'] ?? $_POST['lang'] ?? '');

        if (!$post_id || !$lang) {
            wp_send_json_error(['message' => 'Invalid parameters']);
            return;
        }

        // Get source language
        $source_lang = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : 'pl';

        if ($source_lang === $lang) {
            wp_send_json_error(['message' => 'Cannot translate to the same language']);
            return;
        }

        // Get settings
        $settings = get_option('polytrans_settings', []);

        // Clear any existing translation status first
        $langs_key = '_polytrans_translation_langs';
        $scheduled_langs = get_post_meta($post_id, $langs_key, true);
        if (!is_array($scheduled_langs)) {
            $scheduled_langs = [];
        }

        // Remove existing status for this language
        delete_post_meta($post_id, '_polytrans_translation_status_' . $lang);
        delete_post_meta($post_id, '_polytrans_translation_log_' . $lang);
        delete_post_meta($post_id, '_polytrans_translation_error_' . $lang);
        delete_post_meta($post_id, '_polytrans_translation_completed_' . $lang);

        // Add to scheduled langs if not already there
        if (!in_array($lang, $scheduled_langs, true)) {
            $scheduled_langs[] = $lang;
        }

        // Initialize new translation with 'started' status
        $status_key = '_polytrans_translation_status_' . $lang;
        $log_key = '_polytrans_translation_log_' . $lang;

        update_post_meta($post_id, $status_key, 'started');
        update_post_meta($post_id, $log_key, [
            [
                'timestamp' => time(),
                'msg' => sprintf(__('Translation retry initiated by user.', 'polytrans'))
            ]
        ]);

        // Update scheduled languages
        update_post_meta($post_id, $langs_key, $scheduled_langs);

        LogsManager::log("Retry translation initiated for $lang on post $post_id", "info", [
            'post_id' => $post_id,
            'source_lang' => $source_lang,
            'target_lang' => $lang,
            'user_id' => get_current_user_id()
        ]);

        // Send the translation request (force re-translation)
        $this->send_translation_request($post_id, $source_lang, $lang, $settings);

        wp_send_json_success([
            'message' => sprintf(__('Translation retry started for %s', 'polytrans'), strtoupper($lang)),
            'scheduled_langs' => $scheduled_langs,
            'lang' => $lang
        ]);
    }

    /**
     * Fix inconsistent translation statuses
     * This method will check for posts that have a 'started' or 'in-progress' status
     * but haven't been updated in a while, and mark them as failed
     * 
     * @param int $timeout_hours Number of hours before considering a translation as stuck
     * @return int Number of posts fixed
     */
    public function fix_stuck_translations($timeout_hours = 24)
    {
        global $wpdb;
        $fixed = 0;

        // Get posts with translation metadata
        $posts = $wpdb->get_results("
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_polytrans_translation_status_%'
            AND (meta_value = 'started' OR meta_value = 'translating' OR meta_value = 'processing' OR meta_value = 'in-progress')
        ");

        if (empty($posts)) {
            return 0;
        }

        $current_time = time();
        $timeout = $timeout_hours * 3600; // Convert hours to seconds

        foreach ($posts as $post) {
            $post_id = $post->post_id;
            $meta_key = $post->meta_key;
            $lang = str_replace('_polytrans_translation_status_', '', $meta_key);
            $log_key = '_polytrans_translation_log_' . $lang;

            // Get the logs to check the last activity time
            $logs = get_post_meta($post_id, $log_key, true);

            if (!is_array($logs) || empty($logs)) {
                continue;
            }

            // Sort logs by timestamp (descending)
            usort($logs, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            // Get the latest log entry's timestamp
            $last_activity = $logs[0]['timestamp'] ?? 0;

            // Check if the translation has been stuck for too long
            if (($current_time - $last_activity) > $timeout) {
                // Mark as failed
                update_post_meta($post_id, $meta_key, 'failed');

                // Add a log entry
                $logs[] = [
                    'timestamp' => $current_time,
                    'msg' => sprintf(
                        __('Translation automatically marked as failed after %d hours of inactivity.', 'polytrans'),
                        $timeout_hours
                    )
                ];

                update_post_meta($post_id, $log_key, $logs);
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Get translation status summary
     * 
     * @return array Summary of translation statuses
     */
    public function get_translation_status_summary()
    {
        global $wpdb;

        $summary = [
            'total' => 0,
            'by_status' => [],
            'by_language' => [],
            'potentially_stuck' => []
        ];

        // Get all translation statuses
        $statuses = $wpdb->get_results("
            SELECT meta_key, meta_value, COUNT(*) as count
            FROM {$wpdb->postmeta}
            WHERE meta_key LIKE '_polytrans_translation_status_%'
            GROUP BY meta_key, meta_value
        ");

        if (empty($statuses)) {
            return $summary;
        }

        foreach ($statuses as $status) {
            $lang = str_replace('_polytrans_translation_status_', '', $status->meta_key);
            $status_value = $status->meta_value;
            $count = (int)$status->count;

            // Add to total
            $summary['total'] += $count;

            // Add to status counts
            if (!isset($summary['by_status'][$status_value])) {
                $summary['by_status'][$status_value] = 0;
            }
            $summary['by_status'][$status_value] += $count;

            // Add to language counts
            if (!isset($summary['by_language'][$lang])) {
                $summary['by_language'][$lang] = [
                    'total' => 0,
                    'by_status' => []
                ];
            }
            $summary['by_language'][$lang]['total'] += $count;

            if (!isset($summary['by_language'][$lang]['by_status'][$status_value])) {
                $summary['by_language'][$lang]['by_status'][$status_value] = 0;
            }
            $summary['by_language'][$lang]['by_status'][$status_value] += $count;

            // Check for potentially stuck translations
            if (in_array($status_value, ['started', 'translating', 'processing', 'in-progress'])) {
                // Get posts with this status
                $posts = $wpdb->get_col($wpdb->prepare("
                    SELECT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = %s AND meta_value = %s
                ", $status->meta_key, $status_value));

                foreach ($posts as $post_id) {
                    $log_key = '_polytrans_translation_log_' . $lang;
                    $logs = get_post_meta($post_id, $log_key, true);

                    if (!is_array($logs) || empty($logs)) {
                        continue;
                    }

                    // Sort logs by timestamp (descending)
                    usort($logs, function ($a, $b) {
                        return $b['timestamp'] - $a['timestamp'];
                    });

                    // Get the latest log entry's timestamp
                    $last_activity = $logs[0]['timestamp'] ?? 0;
                    $hours_since = (time() - $last_activity) / 3600;

                    if ($hours_since > 1) { // More than 1 hour of inactivity
                        $summary['potentially_stuck'][] = [
                            'post_id' => $post_id,
                            'language' => $lang,
                            'status' => $status_value,
                            'hours_since_activity' => round($hours_since, 1),
                            'post_title' => get_the_title($post_id)
                        ];
                    }
                }
            }
        }

        return $summary;
    }
}

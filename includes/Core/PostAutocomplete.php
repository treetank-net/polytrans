<?php

namespace PolyTrans\Core;

/**
 * Post Autocomplete Class
 * Handles post search functionality for workflow testing
 */

if (!defined('ABSPATH')) {
    exit;
}

class PostAutocomplete
{
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
        add_action('wp_ajax_polytrans_search_posts', [$this, 'ajax_search_posts']);
        add_action('wp_ajax_polytrans_get_recent_posts', [$this, 'ajax_get_recent_posts']);
        add_action('wp_ajax_polytrans_get_post_by_id', [$this, 'ajax_get_post_by_id']);
    }

    /**
     * AJAX handler for post search
     */
    public function ajax_search_posts()
    {
        check_ajax_referer('polytrans_workflows_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }

        $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
        $post_type = sanitize_text_field(wp_unslash($_POST['post_type'] ?? 'any'));
        $target_language = sanitize_text_field(wp_unslash($_POST['target_language'] ?? ''));

        if (strlen($search) < 2) {
            wp_send_json_success(['posts' => []]);
        }

        $args = [
            's' => $search,
            'post_type' => $post_type === 'any' ? ['post', 'page'] : [$post_type],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 20,
            'orderby' => 'relevance',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_polytrans_original_post_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_polytrans_original_post_id',
                    'value' => '',
                    'compare' => '='
                ]
            ],
        ];

        // Filter by language if Polylang is available and language is specified
        if (!empty($target_language) && function_exists('pll_get_post_language')) {
            $args['lang'] = $target_language;
            $args['tax_query'][] = [
                'taxonomy' => 'language',
                'field'    => 'slug',
                'terms'    => $target_language,
            ];
        }

        $posts = get_posts($args);

        $results = [];
        foreach ($posts as $post) {
            // Get post meta for additional context
            $post_meta = get_post_meta($post->ID);
            $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);

            // Check if this is a translated post
            $original_post_id = get_post_meta($post->ID, '_polytrans_original_post_id', true);
            $is_translation = !empty($original_post_id);

            // Get post language
            $post_language = function_exists('pll_get_post_language') ?
                pll_get_post_language($post->ID) : '';

            // if ($post_language !== $target_language && $target_language !== '') {
            //     continue;
            // }

            // Get some interesting meta fields for testing
            $seo_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
            $custom_fields = [];

            // Include some common meta fields that might be useful for testing
            $common_meta_keys = [
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                'custom_field_example',
                '_featured_text',
                '_subtitle'
            ];

            foreach ($common_meta_keys as $meta_key) {
                $meta_value = get_post_meta($post->ID, $meta_key, true);
                if (!empty($meta_value)) {
                    $custom_fields[$meta_key] = $meta_value;
                }
            }

            $results[] = [
                'id' => $post->ID,
                'ID' => $post->ID,
                'title' => $post->post_title,
                'post_title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_date' => $post->post_date,
                'language' => $post_language,
                'is_translation' => $is_translation,
                'original_post_id' => $original_post_id,
                'meta' => $custom_fields,
                'label' => $post->post_title . ' (' . ucfirst($post->post_type) . ' #' . $post->ID . ')' . ($is_translation ? ' [Translation]' : ''),
                'description' => wp_trim_words($excerpt, 15) . '...'
            ];
        }

        wp_send_json_success(['posts' => $results]);
    }

    /**
     * AJAX handler for getting recent posts by language
     */
    public function ajax_get_recent_posts()
    {
        check_ajax_referer('polytrans_workflows_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }

        $language = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
        $limit = intval($_POST['limit'] ?? 20);
        $include_translations = isset($_POST['include_translations'])
            ? wp_validate_boolean(sanitize_key(wp_unslash($_POST['include_translations'])))
            : false;

        // Limit the max number of posts to prevent performance issues
        if ($limit > 50) {
            $limit = 50;
        }

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        if (!$include_translations) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => '_polytrans_original_post_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_polytrans_original_post_id',
                    'value' => '',
                    'compare' => '='
                ]
            ];
        }

        // Filter by language if Polylang is available and language is specified
        if ($language && function_exists('pll_get_post_language')) {
            $args['lang'] = $language;
            $args['tax_query'][] = [
                'taxonomy' => 'language',
                'field'    => 'slug',
                'terms'    => $language,
            ];
        }

        $cache_key = 'polytrans_recent_posts_' . md5($language . '_' . $limit . '_' . ($include_translations ? 'with_translations' : 'originals_only'));
        $cached = \get_transient($cache_key);
        if (is_array($cached)) {
            wp_send_json_success(['posts' => $cached]);
        }

        $posts = get_posts($args);

        $results = [];
        foreach ($posts as $post) {
            $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);

            $original_post_id = get_post_meta($post->ID, '_polytrans_original_post_id', true);
            $is_translation = !empty($original_post_id);
            $post_language = function_exists('pll_get_post_language') ?
                pll_get_post_language($post->ID) : '';

            $custom_fields = [];
            $common_meta_keys = [
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                'custom_field_example',
                '_featured_text',
                '_subtitle'
            ];

            foreach ($common_meta_keys as $meta_key) {
                $meta_value = get_post_meta($post->ID, $meta_key, true);
                if (!empty($meta_value)) {
                    $custom_fields[$meta_key] = $meta_value;
                }
            }

            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_date' => $post->post_date,
                'language' => $post_language,
                'is_translation' => $is_translation,
                'original_post_id' => $original_post_id,
                'meta' => $custom_fields,
                'description' => wp_trim_words($excerpt, 15) . '...'
            ];
        }

        \set_transient($cache_key, $results, 5 * MINUTE_IN_SECONDS);

        wp_send_json_success(['posts' => $results]);
    }

    public function ajax_get_post_by_id()
    {
        check_ajax_referer('polytrans_workflows_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID']);
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
            return;
        }

        $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);
        $original_post_id = get_post_meta($post->ID, '_polytrans_original_post_id', true);
        $post_language = function_exists('pll_get_post_language') ? pll_get_post_language($post->ID) : '';

        $custom_fields = [];
        $common_meta_keys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            'custom_field_example',
            '_featured_text',
            '_subtitle'
        ];
        foreach ($common_meta_keys as $meta_key) {
            $meta_value = get_post_meta($post->ID, $meta_key, true);
            if (!empty($meta_value)) {
                $custom_fields[$meta_key] = $meta_value;
            }
        }

        wp_send_json_success([
            'post' => [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_date' => $post->post_date,
                'language' => $post_language,
                'is_translation' => !empty($original_post_id),
                'original_post_id' => $original_post_id,
                'meta' => $custom_fields,
                'description' => wp_trim_words($excerpt, 15) . '...'
            ]
        ]);
    }

    /**
     * Get post data for testing context
     */
    public function get_post_data($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        // Get all meta data
        $post_meta = get_post_meta($post_id);
        $meta_data = [];

        foreach ($post_meta as $key => $values) {
            // Skip WordPress internal meta and arrays
            if (strpos($key, '_wp_') === 0 || strpos($key, '_edit_') === 0) {
                continue;
            }

            $meta_data[$key] = is_array($values) && count($values) === 1 ? $values[0] : $values;
        }

        return [
            'ID' => $post->ID,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'post_author' => $post->post_author,
            'meta' => $meta_data
        ];
    }
}

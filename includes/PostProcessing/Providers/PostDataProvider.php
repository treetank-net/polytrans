<?php

namespace PolyTrans\PostProcessing\Providers;

use PolyTrans\PostProcessing\VariableProviderInterface;

/**
 * Post Data Provider
 * 
 * Provides original and translated post data to workflow execution context.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PostDataProvider implements VariableProviderInterface
{
    private static array $cache = [];

    /**
     * Clear cached formatted post data so following workflow executions see
     * changes persisted by previous workflow output actions.
     */
    public static function invalidate_post_cache(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        unset(self::$cache[$post_id]);

        $post = get_post($post_id);
        if ($post && function_exists('delete_transient')) {
            \delete_transient(self::build_cache_key($post));
        }

        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }
    }
    /**
     * Get the provider identifier
     * 
     * @return string Provider ID
     */
    public function get_provider_id()
    {
        return 'post_data';
    }

    /**
     * Get the provider name
     * 
     * @return string Localized provider name
     */
    public function get_provider_name()
    {
        return __('Post Data Provider', 'treetank-trans');
    }

    /**
     * Get variables provided by this provider
     * 
     * Provides post data including:
     * - original_post, translated_post (full post data)
     * - original, translated (aliases)
     * - title, content, excerpt (top-level aliases)
     * 
     * @param array $context Workflow execution context
     * @return array Variables to add to context
     */
    public function get_variables($context)
    {
        $variables = [];

        // Handle test context with direct post objects
        if (isset($context['original_post']) && is_array($context['original_post'])) {
            $variables['original_post'] = $context['original_post'];
        }
        // Handle real context with post IDs
        elseif (isset($context['original_post_id'])) {
            $original_post = get_post($context['original_post_id']);
            if ($original_post) {
                $variables['original_post'] = $this->format_post_data($original_post);
            }
        }

        // Handle test context with direct post objects
        if (isset($context['translated_post']) && is_array($context['translated_post'])) {
            $variables['translated_post'] = $context['translated_post'];
        }
        // Handle real context with post IDs
        elseif (isset($context['translated_post_id'])) {
            $translated_post = get_post($context['translated_post_id']);
            if ($translated_post) {
                $variables['translated_post'] = $this->format_post_data($translated_post);
            }
        }

        // Add convenience aliases for common fields (from translated post)
        if (isset($variables['translated_post'])) {
            $variables['title'] = $variables['translated_post']['title'] ?? '';
            $variables['content'] = $variables['translated_post']['content'] ?? '';
            $variables['excerpt'] = $variables['translated_post']['excerpt'] ?? '';
            $variables['author_name'] = $variables['translated_post']['author_name'] ?? '';
            $variables['date'] = $variables['translated_post']['date'] ?? '';
        }

        // Add short aliases for nested access (Phase 0.1 improvement)
        if (isset($variables['original_post'])) {
            $variables['original'] = $variables['original_post'];
        }
        if (isset($variables['translated_post'])) {
            $variables['translated'] = $variables['translated_post'];
        }

        return $variables;
    }

    /**
     * Get list of variable names this provider can supply
     * 
     * @return array List of available variable names
     */
    public function get_available_variables()
    {
        return [
            // Top-level convenience aliases (most common)
            'title',
            'content',
            'excerpt',
            'author_name',
            'date',
            // Short aliases (Phase 0.1 - recommended)
            'original',
            'original.title',
            'original.content',
            'original.excerpt',
            'original.slug',
            'original.status',
            'original.type',
            'original.author_name',
            'original.date',
            'original.categories',
            'original.tags',
            'original.meta',
            'translated',
            'translated.title',
            'translated.content',
            'translated.excerpt',
            'translated.slug',
            'translated.status',
            'translated.type',
            'translated.author_name',
            'translated.date',
            'translated.categories',
            'translated.tags',
            'translated.meta',
            // Full post objects (legacy, still supported)
            'original_post',
            'original_post.title',
            'original_post.content',
            'original_post.excerpt',
            'translated_post',
            'translated_post.title',
            'translated_post.content',
            'translated_post.excerpt'
        ];
    }

    /**
     * Check if provider can supply variables for given context
     */
    public function can_provide($context)
    {
        return isset($context['original_post_id']) ||
            isset($context['translated_post_id']) ||
            isset($context['original_post']) ||
            isset($context['translated_post']);
    }

    /**
     * Get variable documentation for UI display
     */
    public function get_variable_documentation()
    {
        return [
            // Top-level convenience aliases (most commonly used)
            'title' => [
                'description' => __('Translated post title', 'treetank-trans'),
                'example' => '{{ title }}'
            ],
            'content' => [
                'description' => __('Translated post content', 'treetank-trans'),
                'example' => '{{ content }}'
            ],
            'excerpt' => [
                'description' => __('Translated post excerpt', 'treetank-trans'),
                'example' => '{{ excerpt }}'
            ],
            // Short aliases (Phase 0.1 - RECOMMENDED)
            'original.title' => [
                'description' => __('Original post title', 'treetank-trans'),
                'example' => '{{ original.title }}'
            ],
            'original.content' => [
                'description' => __('Original post content', 'treetank-trans'),
                'example' => '{{ original.content }}'
            ],
            'original.excerpt' => [
                'description' => __('Original post excerpt', 'treetank-trans'),
                'example' => '{{ original.excerpt }}'
            ],
            'original.meta.KEY' => [
                'description' => __('Original post meta field', 'treetank-trans'),
                'example' => '{{ original.meta.seo_title }}'
            ],
            'translated.title' => [
                'description' => __('Translated post title', 'treetank-trans'),
                'example' => '{{ translated.title }}'
            ],
            'translated.content' => [
                'description' => __('Translated post content', 'treetank-trans'),
                'example' => '{{ translated.content }}'
            ],
            'translated.excerpt' => [
                'description' => __('Translated post excerpt', 'treetank-trans'),
                'example' => '{{ translated.excerpt }}'
            ],
            'translated.meta.KEY' => [
                'description' => __('Translated post meta field', 'treetank-trans'),
                'example' => '{{ translated.meta.seo_description }}'
            ],
            // Legacy (still supported for backward compatibility)
            'original_post.title' => [
                'description' => __('Original post title (legacy)', 'treetank-trans'),
                'example' => '{{ original_post.title }}'
            ],
            'translated_post.title' => [
                'description' => __('Translated post title (legacy)', 'treetank-trans'),
                'example' => '{{ translated_post.title }}'
            ]
        ];
    }

    /**
     * Format post data for variable context
     * 
     * @param WP_Post $post Post object
     * @return array Formatted post data
     */
    private function format_post_data($post)
    {
        $post_id = $post->ID;
        if (isset(self::$cache[$post_id])) {
            return self::$cache[$post_id];
        }

        $transient_key = self::build_cache_key($post);
        if (function_exists('get_transient')) {
            $cached = \get_transient($transient_key);
            if (is_array($cached)) {
                self::$cache[$post_id] = $cached;
                return $cached;
            }
        }

        // Get author information
        $author = get_userdata($post->post_author);

        // Get categories
        $categories = [];
        $post_categories = get_the_category($post->ID);
        foreach ($post_categories as $category) {
            $categories[] = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug
            ];
        }

        // Get tags
        $tags = [];
        $post_tags = get_the_tags($post->ID);
        if ($post_tags) {
            foreach ($post_tags as $tag) {
                $tags[] = [
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug
                ];
            }
        }

        // Get featured image
        $featured_image = null;
        if (has_post_thumbnail($post->ID)) {
            $featured_image = [
                'id' => get_post_thumbnail_id($post->ID),
                'url' => get_the_post_thumbnail_url($post->ID, 'full'),
                'alt' => get_post_meta(get_post_thumbnail_id($post->ID), '_wp_attachment_image_alt', true)
            ];
        }

        // Get ALL post meta as meta object
        $all_meta = get_post_meta($post->ID);
        $meta_data = [];

        foreach ($all_meta as $key => $values) {
            // For single values, store directly; for arrays, store as array
            if (count($values) === 1) {
                $meta_data[$key] = maybe_unserialize($values[0]);
            } else {
                $meta_data[$key] = array_map('maybe_unserialize', $values);
            }
        }

        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'author_id' => $post->post_author,
            'author_name' => $author ? $author->display_name : '',
            'author_email' => $author ? $author->user_email : '',
            'date' => $post->post_date,
            'date_gmt' => $post->post_date_gmt,
            'modified' => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
            'parent_id' => $post->post_parent,
            'menu_order' => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'categories' => $categories,
            'tags' => $tags,
            'meta' => $meta_data,
            'featured_image' => $featured_image,
            'permalink' => get_permalink($post->ID),
            'edit_link' => get_edit_post_link($post->ID),
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
            'character_count' => strlen($post->post_content)
        ];

        self::$cache[$post_id] = $data;
        if (function_exists('set_transient')) {
            $ttl = defined('MINUTE_IN_SECONDS') ? 10 * MINUTE_IN_SECONDS : 600;
            \set_transient($transient_key, $data, $ttl);
        }

        return $data;
    }

    /**
     * @param \WP_Post $post Post object
     */
    private static function build_cache_key($post): string
    {
        return 'polytrans_post_data_' . (int) $post->ID . '_' . strtotime((string) $post->post_modified_gmt);
    }
}

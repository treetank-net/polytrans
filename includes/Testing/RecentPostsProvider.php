<?php

declare(strict_types=1);

namespace PolyTrans\Testing;

if (!defined('ABSPATH')) {
    exit;
}

final class RecentPostsProvider
{
    /**
     * Load recent source posts for test/refinement selectors.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getRecentPosts(string $language = '', int $limit = 20): array
    {
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_polytrans_original_post_id',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_polytrans_original_post_id',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ];

        if ($language !== '' && function_exists('pll_get_post_language')) {
            $args['lang'] = $language;
            $args['tax_query'][] = [
                'taxonomy' => 'language',
                'field' => 'slug',
                'terms' => $language,
            ];
        }

        $posts = get_posts($args);
        $results = [];

        foreach ($posts as $post) {
            $excerpt = !empty($post->post_excerpt) ? $post->post_excerpt : self::trimWords($post->post_content, 20, '...');
            $original_post_id = get_post_meta($post->ID, '_polytrans_original_post_id', true);
            $custom_fields = self::getSelectorMeta($post->ID);

            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_date' => $post->post_date,
                'is_translation' => !empty($original_post_id),
                'original_post_id' => $original_post_id,
                'meta' => $custom_fields,
                'description' => self::trimWords($excerpt, 15, '...'),
            ];
        }

        return $results;
    }

    /**
     * Keep selector payloads small; full test context loads all prompt-friendly meta later.
     *
     * @return array<string,mixed>
     */
    private static function getSelectorMeta(int $postId): array
    {
        $custom_fields = [];
        $common_meta_keys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            'custom_field_example',
            '_featured_text',
            '_subtitle',
        ];

        foreach ($common_meta_keys as $meta_key) {
            $meta_value = get_post_meta($postId, $meta_key, true);
            if (!empty($meta_value)) {
                $custom_fields[$meta_key] = $meta_value;
            }
        }

        return $custom_fields;
    }

    private static function trimWords(string $content, int $words, string $more): string
    {
        if (function_exists('wp_trim_words')) {
            return wp_trim_words($content, $words, $more);
        }

        if (function_exists('wp_strip_all_tags')) {
            $plain = wp_strip_all_tags($content);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback for the unit suite, which exercises this class without WordPress loaded.
            $plain = strip_tags($content);
        }
        $parts = preg_split('/\s+/', trim($plain));
        if (!is_array($parts) || count($parts) <= $words) {
            return $plain;
        }

        return implode(' ', array_slice($parts, 0, $words)) . $more;
    }
}

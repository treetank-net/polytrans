<?php

namespace PolyTrans\Core;

/**
 * Builds the translation payload (toTranslate) from a WordPress post.
 *
 * Single source of truth for what data is sent to translation providers.
 * Used by both TranslationHandler (external mode) and BackgroundProcessor (internal mode).
 *
 * Filter: polytrans_translation_payload - modify the payload before it's sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TranslationPayloadBuilder
{
    /**
     * Build translation payload from a post.
     *
     * @param int|\WP_Post $post Post ID or WP_Post object
     * @param array $meta Filtered post meta array
     * @param array $settings Plugin settings (used for schema-based meta filtering)
     * @return array Translation payload with title, slug, content, excerpt, meta, featured_image
     */
    public static function build($post, array $meta = [], array $settings = [])
    {
        if (is_numeric($post)) {
            $post = get_post($post);
        }

        if (!$post) {
            return [];
        }

        $payload = [
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'meta' => $meta,
            'featured_image' => self::get_featured_image_data($post->ID),
        ];

        /**
         * Filter the translation payload before sending to providers.
         *
         * @param array $payload Translation payload
         * @param \WP_Post $post The source post
         * @param array $settings Plugin settings
         */
        return apply_filters('polytrans_translation_payload', $payload, $post, $settings);
    }

    /**
     * Get featured image data for translation.
     *
     * @param int $post_id Post ID
     * @return array|null Featured image data or null
     */
    private static function get_featured_image_data($post_id)
    {
        if (!has_post_thumbnail($post_id)) {
            return null;
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        $attachment = get_post($thumbnail_id);

        if (!$attachment) {
            return null;
        }

        return [
            'id' => $thumbnail_id,
            'alt' => get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true),
            'title' => $attachment->post_title,
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'filename' => basename(get_attached_file($thumbnail_id)),
        ];
    }
}

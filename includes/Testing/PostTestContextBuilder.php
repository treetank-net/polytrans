<?php

declare(strict_types=1);

namespace PolyTrans\Testing;

if (!defined('ABSPATH')) {
    exit;
}

final class PostTestContextBuilder
{
    private const TRANSLATION_SERVICE = 'managed_assistant_test';

    public static function fromText(string $title, string $content, string $sourceLanguage, string $targetLanguage): array
    {
        $plain_content = self::stripTags($content);
        $excerpt = self::trimWords($plain_content, 35, '...');
        $slug = self::sanitizeSlug($title);

        $post_data = self::buildPostData([
            'ID' => 0,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_name' => $slug,
            'post_status' => 'test',
            'post_type' => 'post',
        ], $excerpt, []);

        return self::buildContext($post_data, $sourceLanguage, $targetLanguage);
    }

    public static function fromPost(int $postId, string $sourceLanguage, string $targetLanguage): ?array
    {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        $plain_content = self::stripTags($post->post_content);
        $excerpt = !empty($post->post_excerpt)
            ? $post->post_excerpt
            : self::trimWords($plain_content, 35, '...');
        $slug = !empty($post->post_name) ? $post->post_name : self::sanitizeSlug($post->post_title);

        $post_data = self::buildPostData([
            'ID' => (int) $post->ID,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $excerpt,
            'post_name' => $slug,
            'post_status' => $post->post_status,
            'post_type' => $post->post_type,
        ], $excerpt, self::collectMeta($postId));

        return self::buildContext($post_data, $sourceLanguage, $targetLanguage);
    }

    /**
     * Collect post meta for assistant/workflow test contexts.
     *
     * @return array<string,mixed>
     */
    public static function collectMeta(int $postId): array
    {
        $all_meta = get_post_meta($postId);
        $meta_data = [];

        foreach ($all_meta as $key => $values) {
            if (strpos((string) $key, '_wp_') === 0 || strpos((string) $key, '_edit_') === 0) {
                continue;
            }

            $value = (is_array($values) && count($values) === 1) ? $values[0] : $values;
            $meta_data[$key] = (is_array($value) || is_object($value))
                ? wp_json_encode($value, JSON_UNESCAPED_UNICODE)
                : $value;
        }

        return $meta_data;
    }

    /**
     * Build a compact context for UI display without duplicated compatibility aliases.
     *
     * @param array<string,mixed> $context Full execution context.
     * @return array<string,mixed>
     */
    public static function compact(array $context): array
    {
        return [
            'source_language' => $context['source_language'] ?? '',
            'target_language' => $context['target_language'] ?? '',
            'translation_service' => $context['translation_service'] ?? '',
            'payload' => $context['payload'] ?? [],
            'test_mode' => !empty($context['test_mode']),
        ];
    }

    /**
     * @param array<string,mixed> $wpPostData WordPress-shaped post fields.
     * @param array<string,mixed> $meta Meta values.
     * @return array<string,mixed>
     */
    private static function buildPostData(array $wpPostData, string $excerpt, array $meta): array
    {
        $content = (string) ($wpPostData['post_content'] ?? '');
        $plain_content = self::stripTags($content);
        $title = (string) ($wpPostData['post_title'] ?? '');
        $slug = (string) ($wpPostData['post_name'] ?? self::sanitizeSlug($title));

        return [
            'ID' => (int) ($wpPostData['ID'] ?? 0),
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_name' => $slug,
            'post_status' => (string) ($wpPostData['post_status'] ?? 'test'),
            'post_type' => (string) ($wpPostData['post_type'] ?? 'post'),
            'id' => (int) ($wpPostData['ID'] ?? 0),
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'slug' => $slug,
            'status' => (string) ($wpPostData['post_status'] ?? 'test'),
            'type' => (string) ($wpPostData['post_type'] ?? 'post'),
            'meta' => $meta,
            'word_count' => str_word_count($plain_content),
            'character_count' => strlen($plain_content),
        ];
    }

    /**
     * @param array<string,mixed> $postData
     * @return array<string,mixed>
     */
    private static function buildContext(array $postData, string $sourceLanguage, string $targetLanguage): array
    {
        return [
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'translation_service' => self::TRANSLATION_SERVICE,
            'title' => $postData['title'],
            'content' => $postData['content'],
            'excerpt' => $postData['excerpt'],
            'original' => $postData,
            'translated' => $postData,
            'original_text' => $postData['content'],
            'translated_text' => $postData['content'],
            'original_post' => $postData,
            'translated_post' => $postData,
            'payload' => [
                'post' => $postData,
                'translation' => [
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                    'service' => self::TRANSLATION_SERVICE,
                ],
                'runtime' => [
                    'test_mode' => true,
                ],
            ],
            'test_mode' => true,
        ];
    }

    private static function stripTags(string $content): string
    {
        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($content) : strip_tags($content);
    }

    private static function trimWords(string $content, int $words, string $more): string
    {
        if (function_exists('wp_trim_words')) {
            return wp_trim_words($content, $words, $more);
        }

        $parts = preg_split('/\s+/', trim($content));
        if (!is_array($parts) || count($parts) <= $words) {
            return $content;
        }

        return implode(' ', array_slice($parts, 0, $words)) . $more;
    }

    private static function sanitizeSlug(string $title): string
    {
        if (function_exists('sanitize_title')) {
            return sanitize_title($title);
        }

        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));

        return $slug !== '' ? $slug : 'test-post';
    }
}

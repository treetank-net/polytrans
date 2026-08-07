<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metrics for the visible source text used by a translation request.
 *
 * The counters intentionally describe article text rather than the provider
 * prompt: HTML is removed, entities are decoded, and only title/content/excerpt
 * are counted. This makes the values useful for comparing engines by a stable
 * unit such as cost per 1,000 characters, without pretending that characters
 * are a provider billing unit.
 */
class TextMetrics
{
    /**
     * Measure the text fields sent as an article payload.
     *
     * @param mixed $payload Translation payload.
     * @return array{source_characters:int, source_words:int}
     */
    public static function from_payload($payload)
    {
        if (!is_array($payload)) {
            return self::from_content((string) $payload);
        }

        $parts = [];

        foreach (['title', 'content', 'excerpt'] as $field) {
            if (isset($payload[$field]) && is_scalar($payload[$field])) {
                $parts[] = (string) $payload[$field];
            }
        }

        return self::from_content(implode("\n", $parts));
    }

    /**
     * Measure visible text from a single content string.
     *
     * @param mixed $content Post content.
     * @return array{source_characters:int, source_words:int}
     */
    public static function from_content($content)
    {
        $content = is_scalar($content) ? (string) $content : '';

        // Preserve boundaries between block-level elements. A plain strip_tags()
        // turns `<h2>One</h2><p>Two</p>` into `OneTwo`, which undercounts words.
        $content = preg_replace(
            '/<\/(?:h[1-6]|p|div|li|blockquote|pre|br|tr|td|th)>/i',
            ' ',
            $content
        );

        if (function_exists('wp_strip_all_tags')) {
            $content = wp_strip_all_tags($content, true);
        } else {
            $content = strip_tags($content);
        }

        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[ \t]+/u', ' ', $content);
        $content = preg_replace('/ *\n */u', "\n", trim($content));

        $characters = function_exists('mb_strlen')
            ? mb_strlen($content, 'UTF-8')
            : self::unicode_length($content);

        preg_match_all('/[\p{L}\p{N}]+/u', $content, $matches);

        return [
            'source_characters' => (int) $characters,
            'source_words' => isset($matches[0]) ? count($matches[0]) : 0,
        ];
    }

    /**
     * Count Unicode code points when mbstring is unavailable.
     *
     * @param string $content Clean text.
     * @return int
     */
    private static function unicode_length($content)
    {
        preg_match_all('/./us', $content, $matches);

        return isset($matches[0]) ? count($matches[0]) : strlen($content);
    }
}

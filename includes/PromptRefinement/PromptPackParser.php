<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptPackParser
{
    /**
     * Parse adjuster response into a prompt pack. JSON is preferred; XML-style tags are the main natural-language fallback.
     *
     * JSON mode expects system_prompt, user_message_template and optionally expected_output_schema.
     * Tagged mode expects system_prompt, user_message_template and optionally expected_output_schema.
     * The prefixed polytrans_* tags are retained as compatibility aliases.
     * Legacy separator mode is retained for old runs.
     *
     * @return array<string,mixed>
     */
    public static function parse(
        string $content,
        bool $shouldAdjustExpectedOutputSchema = true,
        string $fallbackExpectedOutputSchema = '{}'
    ): array {
        $text = trim($content);
        $fallback_schema = PromptPackNormalizer::normalizeExpectedOutputSchema($fallbackExpectedOutputSchema);
        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $system_prompt = isset($decoded['system_prompt']) ? trim((string) $decoded['system_prompt']) : '';
            $user_message_template = isset($decoded['user_message_template']) ? trim((string) $decoded['user_message_template']) : '';
            $expected_output_schema = $shouldAdjustExpectedOutputSchema
                ? (isset($decoded['expected_output_schema']) ? PromptPackNormalizer::normalizeExpectedOutputSchema($decoded['expected_output_schema']) : '')
                : $fallback_schema;

            if ($system_prompt !== '' && $user_message_template !== '' && (!$shouldAdjustExpectedOutputSchema || $expected_output_schema !== '')) {
                return [
                    'is_valid_pack' => true,
                    'system_prompt' => $system_prompt,
                    'user_message_template' => $user_message_template,
                    'expected_output_schema' => $expected_output_schema,
                ];
            }
        }

        $tagged = self::parseTaggedPromptPack($text, $shouldAdjustExpectedOutputSchema, $fallback_schema);
        if ($tagged['is_valid_pack'] === true) {
            return $tagged;
        }

        $parts = preg_split('/\R---\R/', $text, 3);

        if ($shouldAdjustExpectedOutputSchema) {
            if (is_array($parts) && count($parts) === 3) {
                return [
                    'is_valid_pack' => true,
                    'system_prompt' => trim((string) $parts[0]),
                    'user_message_template' => trim((string) $parts[1]),
                    'expected_output_schema' => trim((string) $parts[2]),
                ];
            }

            return [
                'is_valid_pack' => false,
                'system_prompt' => '',
                'user_message_template' => '',
                'expected_output_schema' => '',
            ];
        }

        if (is_array($parts) && count($parts) >= 2) {
            return [
                'is_valid_pack' => true,
                'system_prompt' => trim((string) $parts[0]),
                'user_message_template' => trim((string) $parts[1]),
                'expected_output_schema' => $fallback_schema,
            ];
        }

        return [
            'is_valid_pack' => false,
            'system_prompt' => '',
            'user_message_template' => '',
            'expected_output_schema' => $fallback_schema,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function parseTaggedPromptPack(
        string $text,
        bool $shouldAdjustExpectedOutputSchema,
        string $fallbackSchema
    ): array {
        $system_prompt = self::extractFirstTag($text, ['polytrans_system_prompt', 'system_prompt']);
        $user_message_template = self::extractFirstTag($text, ['polytrans_user_message_template', 'user_message_template', 'user_message']);
        $expected_output_schema = self::extractFirstTag($text, ['polytrans_expected_output_schema', 'expected_output_schema']);

        if ($system_prompt === null || trim($system_prompt) === '' || $user_message_template === null || trim($user_message_template) === '') {
            return [
                'is_valid_pack' => false,
                'system_prompt' => '',
                'user_message_template' => '',
                'expected_output_schema' => $shouldAdjustExpectedOutputSchema ? '' : $fallbackSchema,
            ];
        }

        if ($shouldAdjustExpectedOutputSchema && ($expected_output_schema === null || trim($expected_output_schema) === '')) {
            return [
                'is_valid_pack' => false,
                'system_prompt' => '',
                'user_message_template' => '',
                'expected_output_schema' => '',
            ];
        }

        return [
            'is_valid_pack' => true,
            'system_prompt' => trim($system_prompt),
            'user_message_template' => trim($user_message_template),
            'expected_output_schema' => $shouldAdjustExpectedOutputSchema
                ? PromptPackNormalizer::normalizeExpectedOutputSchema(trim((string) $expected_output_schema))
                : $fallbackSchema,
        ];
    }

    /**
     * @param string[] $tagNames
     */
    private static function extractFirstTag(string $text, array $tagNames): ?string
    {
        foreach ($tagNames as $tagName) {
            $quoted_tag = preg_quote($tagName, '/');
            if (preg_match('/<' . $quoted_tag . '>\s*(.*?)\s*<\/' . $quoted_tag . '>/is', $text, $matches)) {
                return (string) $matches[1];
            }
        }

        return null;
    }
}

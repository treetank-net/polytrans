<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptPackNormalizer
{
    /**
     * Normalize expected output schema into prompt-friendly text.
     *
     * @param mixed $schema Assistant expected output schema.
     */
    public static function normalizeExpectedOutputSchema($schema): string
    {
        if ($schema === null || $schema === '') {
            return '{}';
        }

        if (is_array($schema) || is_object($schema)) {
            return (string) wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $schema;
    }

    /**
     * Extract non-interpolated prompt pack from assistant data.
     *
     * @param array<string,mixed> $assistant Assistant configuration.
     * @return array<string,string>
     */
    public static function fromAssistant(array $assistant): array
    {
        return [
            'system_prompt' => (string) ($assistant['system_prompt'] ?? ''),
            'user_message_template' => (string) ($assistant['user_message_template'] ?? ''),
            'expected_output_schema' => self::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
        ];
    }

    /**
     * Extract non-interpolated prompt pack from an inline workflow AI assistant step.
     *
     * @param array<string,mixed> $step Workflow step configuration.
     * @return array<string,string>
     */
    public static function fromWorkflowAiStep(array $step): array
    {
        return [
            'system_prompt' => (string) ($step['system_prompt'] ?? ''),
            'user_message_template' => (string) ($step['user_message'] ?? ''),
            'expected_output_schema' => self::normalizeExpectedOutputSchema(self::workflowAiStepOutputContract($step)),
        ];
    }

    /**
     * Build a compact output contract summary for inline workflow AI assistant steps.
     *
     * @param array<string,mixed> $step Workflow step configuration.
     * @return array<string,mixed>
     */
    public static function workflowAiStepOutputContract(array $step): array
    {
        $output_variables = $step['output_variables'] ?? [];
        if (is_string($output_variables)) {
            $output_variables = array_filter(array_map('trim', explode(',', $output_variables)));
        }
        if (!is_array($output_variables)) {
            $output_variables = [];
        }

        return [
            'expected_format' => (string) ($step['expected_format'] ?? 'text'),
            'output_variables' => array_values(array_map('strval', $output_variables)),
            'note' => 'Inline workflow step output contract. It is shown for context and is not automatically adjusted.',
        ];
    }

    /**
     * Decide whether expected output schema should be adjusted.
     *
     * @param array<string,mixed> $assistant Assistant configuration.
     */
    public static function shouldAdjustExpectedOutputSchema(array $assistant): bool
    {
        $expected_format = isset($assistant['expected_format']) ? strtolower(trim((string) $assistant['expected_format'])) : 'text';

        return $expected_format === 'json';
    }
}

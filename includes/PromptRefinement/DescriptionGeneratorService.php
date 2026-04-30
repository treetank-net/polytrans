<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

use PolyTrans\Assistants\AssistantManager;

if (!defined('ABSPATH')) {
    exit;
}

final class DescriptionGeneratorService
{
    /**
     * @param array<string,mixed> $assistant
     * @return array<string,mixed>|\WP_Error
     */
    public function generateAssistantDescription(
        array $assistant,
        string $systemPromptTemplate = '',
        string $promptTemplate = ''
    ) {
        $systemPromptTemplate = trim($systemPromptTemplate) !== ''
            ? $systemPromptTemplate
            : PromptRefinementSettings::descriptionGeneratorSystem();
        $promptTemplate = trim($promptTemplate) !== ''
            ? $promptTemplate
            : PromptRefinementSettings::assistantDescriptionGenerator();

        $context = $this->buildAssistantContext($assistant);

        return $this->generate($assistant, $context, $systemPromptTemplate, $promptTemplate, 'assistant_description');
    }

    /**
     * @param array<string,mixed> $workflow
     * @return array<string,mixed>|\WP_Error
     */
    public function generateWorkflowDescription(
        array $workflow,
        string $systemPromptTemplate = '',
        string $promptTemplate = ''
    ) {
        $systemPromptTemplate = trim($systemPromptTemplate) !== ''
            ? $systemPromptTemplate
            : PromptRefinementSettings::descriptionGeneratorSystem();
        $promptTemplate = trim($promptTemplate) !== ''
            ? $promptTemplate
            : PromptRefinementSettings::workflowDescriptionGenerator();

        $context = $this->buildWorkflowContext($workflow, null);
        $runner = $this->resolveWorkflowRunnerConfig($workflow, null);

        return $this->generate($runner, $context, $systemPromptTemplate, $promptTemplate, 'workflow_description');
    }

    /**
     * @param array<string,mixed> $workflow
     * @return array<string,mixed>|\WP_Error
     */
    public function generateWorkflowStepDescription(
        array $workflow,
        string $targetStepId,
        string $systemPromptTemplate = '',
        string $promptTemplate = ''
    ) {
        $target_step = $this->findStep($workflow, $targetStepId);
        if (!$target_step) {
            return new \WP_Error('description_target_step_not_found', __('Selected workflow step was not found.', 'polytrans'));
        }

        $systemPromptTemplate = trim($systemPromptTemplate) !== ''
            ? $systemPromptTemplate
            : PromptRefinementSettings::descriptionGeneratorSystem();
        $promptTemplate = trim($promptTemplate) !== ''
            ? $promptTemplate
            : PromptRefinementSettings::workflowStepDescriptionGenerator();

        $context = $this->buildWorkflowContext($workflow, $target_step);
        $runner = $this->resolveWorkflowRunnerConfig($workflow, $target_step);

        return $this->generate($runner, $context, $systemPromptTemplate, $promptTemplate, 'workflow_step_description');
    }

    /**
     * @param array<string,mixed> $runner
     * @param array<string,mixed> $context
     * @return array<string,mixed>|\WP_Error
     */
    private function generate(
        array $runner,
        array $context,
        string $systemPromptTemplate,
        string $promptTemplate,
        string $errorPrefix
    ) {
        $rendered_system_prompt = PromptTemplateRenderer::render($systemPromptTemplate, $context);
        $rendered_prompt = PromptTemplateRenderer::render($promptTemplate, $context);

        $response = PromptChatRunner::execute($runner, $rendered_prompt, $rendered_system_prompt, $errorPrefix);
        if (is_wp_error($response)) {
            return $response;
        }

        $parsed = $this->parseDescription((string) ($response['content'] ?? ''));
        if ($parsed === '') {
            return new \WP_Error($errorPrefix . '_empty_description', __('Description generator did not return a usable description.', 'polytrans'));
        }

        return [
            'description' => $parsed,
            'raw_response' => (string) ($response['content'] ?? ''),
            'rendered_system_prompt' => $rendered_system_prompt,
            'rendered_prompt' => $rendered_prompt,
            'provider' => $response['provider'] ?? ($runner['provider'] ?? ''),
            'model' => $response['model'] ?? ($runner['api_parameters']['model'] ?? ''),
            'usage' => $response['usage'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $assistant
     * @return array<string,mixed>
     */
    private function buildAssistantContext(array $assistant): array
    {
        $api_parameters = is_array($assistant['api_parameters'] ?? null) ? $assistant['api_parameters'] : [];

        return [
            'assistant_name' => (string) ($assistant['name'] ?? ''),
            'assistant_description' => $this->plainText((string) ($assistant['description'] ?? '')),
            'assistant_provider' => (string) ($assistant['provider'] ?? ''),
            'assistant_model' => (string) ($api_parameters['model'] ?? ''),
            'response_format' => (string) ($assistant['expected_format'] ?? $assistant['response_format'] ?? 'text'),
            'system_prompt' => (string) ($assistant['system_prompt'] ?? ''),
            'user_message_template' => (string) ($assistant['user_message_template'] ?? ''),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $workflow
     * @param array<string,mixed>|null $targetStep
     * @return array<string,mixed>
     */
    private function buildWorkflowContext(array $workflow, ?array $targetStep): array
    {
        $steps = [];
        $target_index = null;

        foreach ((array) ($workflow['steps'] ?? []) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $summary = $this->summarizeStep($step, $index);
            if ($targetStep && (string) ($summary['id'] ?? '') === (string) ($targetStep['id'] ?? '')) {
                $target_index = count($steps);
            }
            $steps[] = $summary;
        }

        $target_summary = $target_index !== null ? $steps[$target_index] : [];

        return [
            'workflow_name' => (string) ($workflow['name'] ?? ''),
            'workflow_description' => $this->plainText((string) ($workflow['description'] ?? '')),
            'workflow_language' => (string) ($workflow['language'] ?? ''),
            'workflow_steps_json' => $this->encodeJson($steps, 30000),
            'target_step_id' => (string) ($target_summary['id'] ?? ''),
            'target_step_name' => (string) ($target_summary['name'] ?? ''),
            'target_step_type' => (string) ($target_summary['type'] ?? ''),
            'target_step_description' => $this->plainText((string) ($target_summary['description'] ?? '')),
            'target_step_json' => $this->encodeJson($target_summary, 16000),
            'previous_steps_json' => $this->encodeJson($target_index !== null ? array_slice($steps, 0, $target_index) : [], 12000),
            'following_steps_json' => $this->encodeJson($target_index !== null ? array_slice($steps, $target_index + 1) : [], 12000),
        ];
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function summarizeStep(array $step, int $index): array
    {
        $summary = [
            'position' => $index + 1,
            'id' => (string) ($step['id'] ?? "step_{$index}"),
            'name' => (string) ($step['name'] ?? ('Step ' . ($index + 1))),
            'description' => $this->plainText((string) ($step['description'] ?? '')),
            'type' => (string) ($step['type'] ?? ''),
            'enabled' => !isset($step['enabled']) || !empty($step['enabled']),
            'output_actions' => is_array($step['output_actions'] ?? null) ? $step['output_actions'] : [],
        ];

        if (($step['type'] ?? '') === 'managed_assistant') {
            $assistant = AssistantManager::get_assistant((int) ($step['assistant_id'] ?? 0));
            $summary['assistant_id'] = (int) ($step['assistant_id'] ?? 0);
            $summary['assistant_name'] = is_array($assistant) ? (string) ($assistant['name'] ?? '') : '';
            $summary['assistant_description'] = is_array($assistant) ? $this->plainText((string) ($assistant['description'] ?? '')) : '';
            $summary['system_prompt'] = is_array($assistant) ? $this->truncate((string) ($assistant['system_prompt'] ?? ''), 5000) : '';
            $summary['user_message_template'] = is_array($assistant) ? $this->truncate((string) ($assistant['user_message_template'] ?? ''), 5000) : '';
            $summary['response_format'] = is_array($assistant) ? (string) ($assistant['expected_format'] ?? 'text') : '';
        }

        if (($step['type'] ?? '') === 'ai_assistant') {
            $summary['provider'] = (string) ($step['provider'] ?? '');
            $summary['model'] = (string) ($step['model'] ?? '');
            $summary['system_prompt'] = $this->truncate((string) ($step['system_prompt'] ?? ''), 5000);
            $summary['user_message_template'] = $this->truncate((string) ($step['user_message'] ?? ''), 5000);
            $summary['response_format'] = (string) ($step['expected_format'] ?? 'text');
            $summary['output_variables'] = is_array($step['output_variables'] ?? null) ? $step['output_variables'] : [];
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $workflow
     * @param array<string,mixed>|null $targetStep
     * @return array<string,mixed>
     */
    private function resolveWorkflowRunnerConfig(array $workflow, ?array $targetStep): array
    {
        if ($targetStep) {
            $runner = $this->runnerFromStep($targetStep);
            if (!empty($runner)) {
                return $runner;
            }
        }

        foreach ((array) ($workflow['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $runner = $this->runnerFromStep($step);
            if (!empty($runner)) {
                return $runner;
            }
        }

        $settings = get_option('polytrans_settings', []);
        $provider = trim((string) ($settings['translation_provider'] ?? ''));
        if ($provider === '') {
            $provider = 'openai';
        }

        return [
            'id' => 0,
            'name' => 'Description generator',
            'provider' => $provider,
            'api_parameters' => [
                'model' => (string) ($settings[$provider . '_model'] ?? ''),
                'temperature' => 0.2,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function runnerFromStep(array $step): array
    {
        if (($step['type'] ?? '') === 'managed_assistant') {
            $assistant = AssistantManager::get_assistant((int) ($step['assistant_id'] ?? 0));
            return is_array($assistant) ? $assistant : [];
        }

        if (($step['type'] ?? '') === 'ai_assistant') {
            $settings = get_option('polytrans_settings', []);
            $provider = trim((string) ($step['provider'] ?? ''));
            if ($provider === '') {
                $provider = trim((string) ($settings['translation_provider'] ?? 'openai'));
            }

            return [
                'id' => 0,
                'name' => (string) ($step['name'] ?? 'Workflow AI step'),
                'provider' => $provider,
                'api_parameters' => [
                    'model' => (string) ($step['model'] ?? $settings[$provider . '_model'] ?? ''),
                    'temperature' => 0.2,
                ],
            ];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $workflow
     * @return array<string,mixed>|null
     */
    private function findStep(array $workflow, string $targetStepId): ?array
    {
        foreach ((array) ($workflow['steps'] ?? []) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $step_id = (string) ($step['id'] ?? "step_{$index}");
            if ($step_id === $targetStepId) {
                $step['id'] = $step_id;
                return $step;
            }
        }

        return null;
    }

    private function parseDescription(string $content): string
    {
        $text = trim($content);
        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $description = trim((string) ($decoded['description'] ?? ''));
            if ($description !== '') {
                return $this->plainText($description);
            }
        }

        return $this->plainText($text);
    }

    private function plainText(string $value): string
    {
        return trim(wp_strip_all_tags($value));
    }

    private function encodeJson($value, int $limit): string
    {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '[]';
        }

        return $this->truncate($json, $limit);
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . "\n\n[truncated for description generation]";
    }
}

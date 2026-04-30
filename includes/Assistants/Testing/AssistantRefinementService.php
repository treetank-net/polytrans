<?php

declare(strict_types=1);

namespace PolyTrans\Assistants\Testing;

use PolyTrans\Assistants\AssistantExecutor;
use PolyTrans\Assistants\AssistantManager;
use PolyTrans\PromptRefinement\EvaluationScoreExtractor;
use PolyTrans\PromptRefinement\PromptChatRunner;
use PolyTrans\PromptRefinement\PromptPackNormalizer;
use PolyTrans\PromptRefinement\PromptPackParser;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\PromptRefinement\PromptTemplateRenderer;
use PolyTrans\Testing\PostTestContextBuilder;

if (!defined('ABSPATH')) {
    exit;
}

final class AssistantRefinementService
{
    /**
     * Execute assistant step for one refinement post and persist the run.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function runPost(
        int $assistantId,
        int $selectedPostId,
        string $sourceLanguage,
        string $targetLanguage,
        $overrideSystemPrompt = null,
        $overrideUserMessageTemplate = null,
        $overrideExpectedOutputSchema = null
    ) {
        $execution = $this->executeAssistantStep(
            $assistantId,
            $selectedPostId,
            $sourceLanguage,
            $targetLanguage,
            $overrideSystemPrompt,
            $overrideUserMessageTemplate,
            $overrideExpectedOutputSchema
        );

        if (is_wp_error($execution)) {
            return $execution;
        }

        $run_id = $this->generateRunId();
        $run_payload = $this->buildRunPayload($run_id, $execution);

        if (!set_transient($this->getRunTransientKey($run_id), $run_payload, $this->getRunTtl())) {
            return new \WP_Error('assistant_run_persist_failed', __('Failed to persist assistant run. Please retry.', 'polytrans'));
        }

        return $this->buildRunResponse($run_payload);
    }

    /**
     * Evaluate a previously executed assistant run by run_id.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function evaluateRun(
        int $assistantId,
        string $runId,
        string $criteria,
        string $promptObjective = '',
        string $evaluatorPromptTemplate = '',
        string $evaluatorSystemPromptTemplate = ''
    )
    {
        if ($assistantId <= 0) {
            return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
        }
        if (trim($runId) === '') {
            return new \WP_Error('missing_run_id', __('Run ID is required.', 'polytrans'));
        }
        if (trim($criteria) === '') {
            return new \WP_Error('missing_refinement_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($evaluatorPromptTemplate) === '') {
            $evaluatorPromptTemplate = PromptRefinementSettings::assistantEvaluator();
        }
        if (trim($evaluatorSystemPromptTemplate) === '') {
            $evaluatorSystemPromptTemplate = PromptRefinementSettings::assistantEvaluatorSystem();
        }

        $run_payload = get_transient($this->getRunTransientKey($runId));
        if (!is_array($run_payload)) {
            return new \WP_Error('assistant_run_not_found', __('Assistant run not found or expired. Run assistant step again.', 'polytrans'));
        }

        if ((int) ($run_payload['assistant_id'] ?? 0) !== $assistantId) {
            return new \WP_Error('assistant_run_mismatch', __('Run ID does not belong to the selected assistant.', 'polytrans'));
        }

        $assistant_config = is_array($run_payload['assistant_config'] ?? null) ? $run_payload['assistant_config'] : [];
        $assistant_result = is_array($run_payload['assistant_result'] ?? null) ? $run_payload['assistant_result'] : [];
        $context = is_array($run_payload['context'] ?? null) ? $run_payload['context'] : [];

        if (empty($assistant_config) || empty($assistant_result)) {
            return new \WP_Error('assistant_run_incomplete', __('Stored assistant run is incomplete. Please execute assistant step again.', 'polytrans'));
        }

        $evaluation = $this->evaluateAssistantRun(
            $assistant_config,
            $context,
            $assistant_result,
            $criteria,
            $promptObjective,
            $evaluatorPromptTemplate,
            $evaluatorSystemPromptTemplate
        );

        if (is_wp_error($evaluation)) {
            return $evaluation;
        }

        $run_payload['evaluation'] = $evaluation;
        $run_payload['evaluated_at'] = time();
        set_transient($this->getRunTransientKey($runId), $run_payload, $this->getRunTtl());

        return [
            'run_id' => $runId,
            'assistant_id' => $assistantId,
            'post_id' => (int) ($run_payload['post']['id'] ?? 0),
            'post_title' => (string) ($run_payload['post']['title'] ?? ''),
            'post_excerpt' => (string) ($run_payload['post']['excerpt'] ?? ''),
            'evaluation' => $evaluation,
            'final_post_candidate' => $run_payload['final_post_candidate'] ?? null,
        ];
    }

    /**
     * Backward-compatible flow combining assistant execution and evaluation.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function refinePost(
        int $assistantId,
        int $selectedPostId,
        string $sourceLanguage,
        string $targetLanguage,
        string $criteria,
        string $promptObjective = '',
        string $evaluatorPromptTemplate = '',
        string $evaluatorSystemPromptTemplate = '',
        $overrideSystemPrompt = null,
        $overrideUserMessageTemplate = null,
        $overrideExpectedOutputSchema = null
    ) {
        if (trim($criteria) === '') {
            return new \WP_Error('missing_refinement_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($evaluatorPromptTemplate) === '') {
            $evaluatorPromptTemplate = PromptRefinementSettings::assistantEvaluator();
        }
        if (trim($evaluatorSystemPromptTemplate) === '') {
            $evaluatorSystemPromptTemplate = PromptRefinementSettings::assistantEvaluatorSystem();
        }

        $execution = $this->executeAssistantStep(
            $assistantId,
            $selectedPostId,
            $sourceLanguage,
            $targetLanguage,
            $overrideSystemPrompt,
            $overrideUserMessageTemplate,
            $overrideExpectedOutputSchema
        );

        if (is_wp_error($execution)) {
            return $execution;
        }

        $run_id = $this->generateRunId();
        $run_payload = $this->buildRunPayload($run_id, $execution);

        $evaluation = $this->evaluateAssistantRun(
            $execution['assistant_config'],
            $execution['context'],
            $execution['assistant_result'],
            $criteria,
            $promptObjective,
            $evaluatorPromptTemplate,
            $evaluatorSystemPromptTemplate
        );

        if (is_wp_error($evaluation)) {
            return $evaluation;
        }

        $run_payload['evaluation'] = $evaluation;
        $run_payload['evaluated_at'] = time();
        set_transient($this->getRunTransientKey($run_id), $run_payload, $this->getRunTtl());

        $response = $this->buildRunResponse($run_payload);
        $response['evaluation'] = $evaluation;

        return $response;
    }

    /**
     * Build prompt adjustment proposal from evaluated assistant runs.
     *
     * @param mixed $evaluationsPayload JSON string or array from request.
     * @return array<string,mixed>|\WP_Error
     */
    public function adjustPrompt(
        int $assistantId,
        string $criteria,
        string $promptObjective,
        string $adjusterPromptTemplate,
        string $adjusterSystemPromptTemplate,
        $evaluationsPayload,
        $currentSystemPrompt = null,
        $currentUserMessageTemplate = null,
        $currentExpectedOutputSchema = null
    ) {
        if ($assistantId <= 0) {
            return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
        }
        if (trim($criteria) === '') {
            return new \WP_Error('missing_refinement_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($adjusterPromptTemplate) === '') {
            $adjusterPromptTemplate = PromptRefinementSettings::assistantAdjuster();
        }
        if (trim($adjusterSystemPromptTemplate) === '') {
            $adjusterSystemPromptTemplate = PromptRefinementSettings::assistantAdjusterSystem();
        }

        $assistant = AssistantManager::get_assistant($assistantId);
        if (!$assistant) {
            return new \WP_Error('assistant_not_found', __('Assistant not found.', 'polytrans'));
        }
        $promptObjective = $this->resolvePromptObjective($promptObjective, $assistant);

        $evaluations = $this->decodeEvaluations($evaluationsPayload);
        if (empty($evaluations)) {
            return new \WP_Error('missing_evaluations', __('At least one evaluated post is required.', 'polytrans'));
        }

        $normalized_evaluations = array_map(static function ($item): array {
            $score = null;
            if (isset($item['evaluation']['score']) && is_numeric($item['evaluation']['score'])) {
                $score = (float) $item['evaluation']['score'];
            } elseif (isset($item['score']) && is_numeric($item['score'])) {
                $score = (float) $item['score'];
            }

            return [
                'post_id' => isset($item['post_id']) ? (int) $item['post_id'] : 0,
                'post_title' => isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '',
                'score' => $score,
                'feedback' => isset($item['evaluation']['feedback'])
                    ? (string) $item['evaluation']['feedback']
                    : ((isset($item['feedback'])) ? (string) $item['feedback'] : ''),
            ];
        }, $evaluations);

        $current_prompt_pack = PromptPackNormalizer::fromAssistant($assistant);
        if ($currentSystemPrompt !== null) {
            $current_prompt_pack['system_prompt'] = (string) $currentSystemPrompt;
        }
        if ($currentUserMessageTemplate !== null) {
            $current_prompt_pack['user_message_template'] = (string) $currentUserMessageTemplate;
        }
        if ($currentExpectedOutputSchema !== null) {
            $current_prompt_pack['expected_output_schema'] = (string) $currentExpectedOutputSchema;
        }

        $should_adjust_expected_output_schema = PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant);
        $adjuster_context = [
            'criteria' => $criteria,
            'prompt_objective' => $promptObjective,
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'non_interpolated_system_prompt' => $current_prompt_pack['system_prompt'],
            'non_interpolated_user_message_template' => $current_prompt_pack['user_message_template'],
            'non_interpolated_expected_output_schema' => $current_prompt_pack['expected_output_schema'],
            'evaluations' => $normalized_evaluations,
            'evaluations_json' => wp_json_encode(
                $normalized_evaluations,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ];
        $rendered_adjuster_system_prompt = PromptTemplateRenderer::render(
            $adjusterSystemPromptTemplate,
            $adjuster_context
        );
        $rendered_adjuster_prompt = PromptTemplateRenderer::render($adjusterPromptTemplate, $adjuster_context);

        $adjustment = PromptChatRunner::execute(
            $assistant,
            $rendered_adjuster_prompt,
            $rendered_adjuster_system_prompt,
            'assistant_refinement'
        );

        if (is_wp_error($adjustment)) {
            return $adjustment;
        }

        $parsed = PromptPackParser::parse(
            $adjustment['content'] ?? '',
            $should_adjust_expected_output_schema,
            $current_prompt_pack['expected_output_schema'] ?? '{}'
        );

        return [
            'assistant_id' => $assistantId,
            'assistant_name' => $assistant['name'] ?? '',
            'provider' => $adjustment['provider'] ?? ($assistant['provider'] ?? ''),
            'model' => $adjustment['model'] ?? ($assistant['api_parameters']['model'] ?? ''),
            'usage' => $adjustment['usage'] ?? [],
            'adjuster_system_prompt_rendered' => $rendered_adjuster_system_prompt,
            'adjuster_prompt_rendered' => $rendered_adjuster_prompt,
            'adjuster_response' => $adjustment['content'] ?? '',
            'format' => 'json',
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'input_prompt_pack' => $current_prompt_pack,
            'parsed' => $parsed,
        ];
    }

    /**
     * Apply prompt pack to assistant configuration.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function applyPromptPack(int $assistantId, string $systemPrompt, string $userMessageTemplate, $expectedOutputSchema)
    {
        if ($assistantId <= 0) {
            return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
        }
        if (trim($systemPrompt) === '') {
            return new \WP_Error('empty_system_prompt', __('System prompt cannot be empty.', 'polytrans'));
        }

        $assistant = AssistantManager::get_assistant($assistantId);
        if (!$assistant) {
            return new \WP_Error('assistant_not_found', __('Assistant not found.', 'polytrans'));
        }

        $previous_prompt_pack = PromptPackNormalizer::fromAssistant($assistant);
        if (!PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant)) {
            $expectedOutputSchema = $assistant['expected_output_schema'] ?? null;
        } elseif ($expectedOutputSchema === null) {
            $expectedOutputSchema = '{}';
        }

        $assistant_update = [
            'name' => $assistant['name'] ?? '',
            'description' => $assistant['description'] ?? '',
            'provider' => $assistant['provider'] ?? 'openai',
            'status' => $assistant['status'] ?? 'active',
            'system_prompt' => $systemPrompt,
            'user_message_template' => $userMessageTemplate,
            'api_parameters' => $assistant['api_parameters'] ?? [],
            'expected_format' => $assistant['expected_format'] ?? 'text',
            'expected_output_schema' => $expectedOutputSchema,
            'output_variables' => $assistant['output_variables'] ?? null,
        ];

        $updated = AssistantManager::update_assistant($assistantId, $assistant_update);
        if (is_wp_error($updated)) {
            return $updated;
        }

        return [
            'assistant_id' => $assistantId,
            'assistant_name' => $assistant['name'] ?? '',
            'previous_prompt_pack' => $previous_prompt_pack,
            'applied_prompt_pack' => [
                'system_prompt' => $systemPrompt,
                'user_message_template' => $userMessageTemplate,
                'expected_output_schema' => $expectedOutputSchema,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    private function executeAssistantStep(
        int $assistantId,
        int $selectedPostId,
        string $sourceLanguage,
        string $targetLanguage,
        $overrideSystemPrompt,
        $overrideUserMessageTemplate,
        $overrideExpectedOutputSchema
    ) {
        if ($assistantId <= 0) {
            return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
        }
        if ($selectedPostId <= 0) {
            return new \WP_Error('invalid_post_id', __('Select a valid post for refinement.', 'polytrans'));
        }

        $assistant = AssistantManager::get_assistant($assistantId);
        if (!$assistant) {
            return new \WP_Error('assistant_not_found', __('Assistant not found.', 'polytrans'));
        }
        if (($assistant['status'] ?? 'active') !== 'active') {
            return new \WP_Error('assistant_inactive', __('Assistant is inactive.', 'polytrans'));
        }

        $context = PostTestContextBuilder::fromPost($selectedPostId, $sourceLanguage, $targetLanguage);
        if (!is_array($context)) {
            return new \WP_Error('post_not_found', __('Selected post not found.', 'polytrans'));
        }

        $assistant_config = $assistant;
        $has_prompt_overrides = $overrideSystemPrompt !== null
            || $overrideUserMessageTemplate !== null
            || $overrideExpectedOutputSchema !== null;

        if ($has_prompt_overrides) {
            if ($overrideSystemPrompt !== null) {
                $assistant_config['system_prompt'] = (string) $overrideSystemPrompt;
            }
            if ($overrideUserMessageTemplate !== null) {
                $assistant_config['user_message_template'] = (string) $overrideUserMessageTemplate;
            }
            if ($overrideExpectedOutputSchema !== null) {
                $assistant_config['expected_output_schema'] = (string) $overrideExpectedOutputSchema;
            }
            $assistant_result = AssistantExecutor::execute_with_config($assistant_config, $context);
        } else {
            $assistant_result = AssistantExecutor::execute($assistantId, $context);
        }

        if (is_wp_error($assistant_result)) {
            return $assistant_result;
        }
        if (empty($assistant_result['success'])) {
            return new \WP_Error(
                'assistant_execution_failed',
                (string) ($assistant_result['error'] ?? __('Assistant execution failed.', 'polytrans'))
            );
        }

        return [
            'assistant' => $assistant,
            'assistant_config' => $assistant_config,
            'assistant_result' => $assistant_result,
            'context' => $context,
            'post_data' => is_array($context['payload']['post'] ?? null) ? $context['payload']['post'] : [],
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'selected_post_id' => $selectedPostId,
        ];
    }

    /**
     * @param array<string,mixed> $execution
     * @return array<string,mixed>
     */
    private function buildRunPayload(string $runId, array $execution): array
    {
        $post_data = is_array($execution['post_data'] ?? null) ? $execution['post_data'] : [];
        $assistant_result = is_array($execution['assistant_result'] ?? null) ? $execution['assistant_result'] : [];
        $assistant_config = is_array($execution['assistant_config'] ?? null) ? $execution['assistant_config'] : [];
        $context = is_array($execution['context'] ?? null) ? $execution['context'] : [];

        return [
            'run_id' => $runId,
            'assistant_id' => (int) ($execution['assistant']['id'] ?? 0),
            'assistant_name' => (string) ($execution['assistant']['name'] ?? ''),
            'assistant_config' => $assistant_config,
            'assistant_result' => $assistant_result,
            'context' => [
                'source_language' => (string) ($context['source_language'] ?? ''),
                'target_language' => (string) ($context['target_language'] ?? ''),
                'payload' => [
                    'post' => [
                        'id' => (int) ($post_data['id'] ?? 0),
                        'title' => (string) ($post_data['title'] ?? ''),
                        'excerpt' => (string) ($post_data['excerpt'] ?? ''),
                    ],
                ],
            ],
            'post' => [
                'id' => (int) ($post_data['id'] ?? 0),
                'title' => (string) ($post_data['title'] ?? ''),
                'excerpt' => (string) ($post_data['excerpt'] ?? ''),
            ],
            'used_prompt_pack' => PromptPackNormalizer::fromAssistant($assistant_config),
            'final_post_candidate' => $this->buildFinalPostCandidate($assistant_result['output'] ?? null, $context),
            'created_at' => time(),
        ];
    }

    /**
     * @param array<string,mixed> $runPayload
     * @return array<string,mixed>
     */
    private function buildRunResponse(array $runPayload): array
    {
        $assistant_result = is_array($runPayload['assistant_result'] ?? null) ? $runPayload['assistant_result'] : [];
        $assistant_config = is_array($runPayload['assistant_config'] ?? null) ? $runPayload['assistant_config'] : [];
        $post_data = is_array($runPayload['post'] ?? null) ? $runPayload['post'] : [];

        return [
            'run_id' => (string) ($runPayload['run_id'] ?? ''),
            'run_ttl_seconds' => $this->getRunTtl(),
            'post_id' => (int) ($post_data['id'] ?? 0),
            'post_title' => (string) ($post_data['title'] ?? ''),
            'post_excerpt' => (string) ($post_data['excerpt'] ?? ''),
            'assistant_id' => (int) ($runPayload['assistant_id'] ?? 0),
            'assistant_name' => (string) ($runPayload['assistant_name'] ?? ''),
            'provider' => $assistant_result['provider'] ?? ($assistant_config['provider'] ?? ''),
            'model' => $assistant_result['model'] ?? ($assistant_config['api_parameters']['model'] ?? ''),
            'assistant_output' => $assistant_result['output'] ?? '',
            'assistant_usage' => $assistant_result['usage'] ?? [],
            'interpolated_system_prompt' => $assistant_result['interpolated_system_prompt'] ?? '',
            'interpolated_user_message' => $assistant_result['interpolated_user_message'] ?? '',
            'used_prompt_pack' => $runPayload['used_prompt_pack'] ?? PromptPackNormalizer::fromAssistant($assistant_config),
            'final_post_candidate' => $runPayload['final_post_candidate'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $assistant
     * @param array<string,mixed> $context
     * @param array<string,mixed> $assistantResult
     * @return array<string,mixed>|\WP_Error
     */
    private function evaluateAssistantRun(
        array $assistant,
        array $context,
        array $assistantResult,
        string $criteria,
        string $promptObjective,
        string $evaluatorPromptTemplate,
        string $evaluatorSystemPromptTemplate
    )
    {
        $assistant_output = $assistantResult['output'] ?? '';
        if (!is_string($assistant_output)) {
            $assistant_output = wp_json_encode($assistant_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $evaluator_context = [
            'criteria' => $criteria,
            'prompt_objective' => $this->resolvePromptObjective($promptObjective, $assistant),
            'source_language' => $context['source_language'] ?? '',
            'target_language' => $context['target_language'] ?? '',
            'include_expected_output_schema' => PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant),
            'interpolated_system_prompt' => (string) ($assistantResult['interpolated_system_prompt'] ?? ''),
            'interpolated_user_message' => (string) ($assistantResult['interpolated_user_message'] ?? ''),
            'assistant_output' => (string) $assistant_output,
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
        ];
        $rendered_evaluator_system_prompt = PromptTemplateRenderer::render(
            $evaluatorSystemPromptTemplate,
            $evaluator_context
        );
        $rendered_evaluator_prompt = PromptTemplateRenderer::render($evaluatorPromptTemplate, $evaluator_context);

        $evaluation_response = PromptChatRunner::execute(
            $assistant,
            $rendered_evaluator_prompt,
            $rendered_evaluator_system_prompt,
            'assistant_refinement'
        );

        if (is_wp_error($evaluation_response)) {
            return $evaluation_response;
        }

        $feedback = (string) ($evaluation_response['content'] ?? '');

        return [
            'score' => EvaluationScoreExtractor::extract($feedback),
            'feedback' => $feedback,
            'rendered_system_prompt' => $rendered_evaluator_system_prompt,
            'rendered_prompt' => $rendered_evaluator_prompt,
            'provider' => $evaluation_response['provider'] ?? ($assistant['provider'] ?? ''),
            'model' => $evaluation_response['model'] ?? ($assistant['api_parameters']['model'] ?? ''),
            'usage' => $evaluation_response['usage'] ?? [],
        ];
    }

    /**
     * @param mixed $assistantOutput
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function buildFinalPostCandidate($assistantOutput, array $context): ?array
    {
        $post_data = is_array($context['payload']['post'] ?? null) ? $context['payload']['post'] : [];
        $base_meta = is_array($post_data['meta'] ?? null) ? $post_data['meta'] : [];

        if (is_array($assistantOutput)) {
            $meta = $assistantOutput['meta'] ?? $base_meta;
            if (!is_array($meta)) {
                $meta = $base_meta;
            }

            return [
                'title' => isset($assistantOutput['title']) ? (string) $assistantOutput['title'] : (string) ($post_data['title'] ?? ''),
                'content' => isset($assistantOutput['content']) ? (string) $assistantOutput['content'] : (string) ($post_data['content'] ?? ''),
                'excerpt' => isset($assistantOutput['excerpt']) ? (string) $assistantOutput['excerpt'] : (string) ($post_data['excerpt'] ?? ''),
                'slug' => isset($assistantOutput['slug']) ? (string) $assistantOutput['slug'] : (string) ($post_data['slug'] ?? ''),
                'meta' => $meta,
            ];
        }

        if (is_string($assistantOutput) && trim($assistantOutput) !== '') {
            return [
                'title' => (string) ($post_data['title'] ?? ''),
                'content' => $assistantOutput,
                'excerpt' => (string) ($post_data['excerpt'] ?? ''),
                'slug' => (string) ($post_data['slug'] ?? ''),
                'meta' => $base_meta,
            ];
        }

        return null;
    }

    /**
     * @param mixed $evaluationsPayload
     * @return array<int,array<string,mixed>>
     */
    private function decodeEvaluations($evaluationsPayload): array
    {
        if (is_string($evaluationsPayload)) {
            $decoded = json_decode(wp_unslash($evaluationsPayload), true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }

        if (is_array($evaluationsPayload)) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized by caller.
            return wp_unslash($evaluationsPayload);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $assistant
     */
    private function resolvePromptObjective(string $promptObjective, array $assistant): string
    {
        $objective = trim($promptObjective);
        if ($objective !== '') {
            return $objective;
        }

        $description = trim(wp_strip_all_tags((string) ($assistant['description'] ?? '')));
        if ($description !== '') {
            return $description;
        }

        return __('Preserve the assistant original purpose and existing behavioral contract while applying the refinement criteria.', 'polytrans');
    }

    private function getRunTransientKey(string $runId): string
    {
        return 'polytrans_assistant_refine_run_' . md5($runId);
    }

    private function generateRunId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        return uniqid('assistant_run_', true);
    }

    private function getRunTtl(): int
    {
        return 2 * HOUR_IN_SECONDS;
    }
}

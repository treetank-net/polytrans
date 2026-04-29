<?php

declare(strict_types=1);

namespace PolyTrans\PostProcessing\Testing;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\Testing\AssistantRefinementService;
use PolyTrans\Core\LogsManager;
use PolyTrans\PromptRefinement\EvaluationScoreExtractor;
use PolyTrans\PromptRefinement\PromptChatRunner;
use PolyTrans\PromptRefinement\PromptPackNormalizer;
use PolyTrans\PromptRefinement\PromptPackParser;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\PromptRefinement\PromptTemplateRenderer;

if (!defined('ABSPATH')) {
    exit;
}

final class WorkflowRefinementService
{
    /**
     * Run a full workflow for one post while overriding one managed assistant step prompt.
     *
     * @param array<string,mixed> $workflow Workflow configuration from UI.
     * @return array<string,mixed>|\WP_Error
     */
    public function runPost(
        array $workflow,
        string $targetStepId,
        int $selectedPostId,
        string $sourceLanguage,
        string $targetLanguage,
        $overrideSystemPrompt = null,
        $overrideUserMessageTemplate = null,
        $overrideExpectedOutputSchema = null
    ) {
        if (empty($workflow)) {
            return new \WP_Error('workflow_refinement_missing_workflow', __('Workflow data is required.', 'polytrans'));
        }
        if ($targetStepId === '') {
            return new \WP_Error('workflow_refinement_missing_target_step', __('Select a workflow step to refine.', 'polytrans'));
        }
        if ($selectedPostId <= 0) {
            return new \WP_Error('workflow_refinement_invalid_post', __('Select a valid post for refinement.', 'polytrans'));
        }

        $target_step = $this->findManagedAssistantStep($workflow, $targetStepId);
        if (!$target_step) {
            return new \WP_Error('workflow_refinement_invalid_target_step', __('Selected workflow step is not a managed assistant step.', 'polytrans'));
        }

        $assistant_id = (int) ($target_step['assistant_id'] ?? 0);
        $assistant = AssistantManager::get_assistant($assistant_id);
        if (!$assistant) {
            return new \WP_Error('workflow_refinement_assistant_not_found', __('Target assistant was not found.', 'polytrans'));
        }
        if (($assistant['status'] ?? 'active') !== 'active') {
            return new \WP_Error('workflow_refinement_assistant_inactive', __('Target assistant is inactive.', 'polytrans'));
        }

        $context = $this->buildContextFromPost($selectedPostId, $sourceLanguage, $targetLanguage);
        if (!is_array($context)) {
            return new \WP_Error('workflow_refinement_post_not_found', __('Selected post was not found.', 'polytrans'));
        }

        $prompt_overrides = [];
        if ($overrideSystemPrompt !== null) {
            $prompt_overrides['system_prompt'] = (string) $overrideSystemPrompt;
        }
        if ($overrideUserMessageTemplate !== null) {
            $prompt_overrides['user_message_template'] = (string) $overrideUserMessageTemplate;
        }
        if ($overrideExpectedOutputSchema !== null) {
            $prompt_overrides['expected_output_schema'] = (string) $overrideExpectedOutputSchema;
        }
        if (!empty($prompt_overrides)) {
            $context['__assistant_prompt_overrides'] = [
                $targetStepId => $prompt_overrides,
            ];
        }

        try {
            $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
            $workflow_result = $workflow_manager->execute_workflow($workflow, $context, true);
        } catch (\Throwable $e) {
            return new \WP_Error('workflow_refinement_execution_error', $e->getMessage());
        }

        $run_id = $this->generateRunId();
        $run_payload = $this->buildRunPayload($run_id, $workflow, $target_step, $assistant, $context, $workflow_result);

        if (!$this->persistRunPayload($run_id, $run_payload)) {
            return new \WP_Error('workflow_refinement_run_persist_failed', __('Failed to persist workflow run. Please retry.', 'polytrans'));
        }

        return $this->buildRunResponse($run_payload);
    }

    /**
     * Evaluate a stored full workflow refinement run.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function evaluateRun(string $runId, string $targetStepId, string $criteria, string $evaluatorPromptTemplate = '')
    {
        if ($runId === '') {
            return new \WP_Error('workflow_refinement_missing_run_id', __('Run ID is required.', 'polytrans'));
        }
        if ($criteria === '') {
            return new \WP_Error('workflow_refinement_missing_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($evaluatorPromptTemplate) === '') {
            $evaluatorPromptTemplate = PromptRefinementSettings::workflowEvaluator();
        }

        $run_payload = get_transient($this->getRunTransientKey($runId));
        if (!is_array($run_payload)) {
            return new \WP_Error('workflow_refinement_run_not_found', __('Workflow run not found or expired. Run workflow again.', 'polytrans'));
        }
        if ($targetStepId !== '' && (string) ($run_payload['target_step_id'] ?? '') !== $targetStepId) {
            return new \WP_Error('workflow_refinement_run_mismatch', __('Run ID does not belong to the selected workflow step.', 'polytrans'));
        }

        $evaluation = $this->evaluateWorkflowRun($run_payload, $criteria, $evaluatorPromptTemplate);
        if (is_wp_error($evaluation)) {
            return $evaluation;
        }

        $run_payload['evaluation'] = $evaluation;
        $run_payload['evaluated_at'] = time();
        $this->persistRunPayload($runId, $run_payload);

        return [
            'run_id' => $runId,
            'target_step_id' => (string) ($run_payload['target_step_id'] ?? ''),
            'assistant_id' => (int) ($run_payload['assistant_id'] ?? 0),
            'post_id' => (int) ($run_payload['post']['id'] ?? 0),
            'post_title' => (string) ($run_payload['post']['title'] ?? ''),
            'workflow_success' => !empty($run_payload['workflow_result']['success']),
            'evaluation' => $evaluation,
            'final_output' => $run_payload['final_output'] ?? [],
        ];
    }

    /**
     * Build prompt adjustment proposal from full workflow evaluations.
     *
     * @param mixed $evaluationsPayload JSON string or array from request.
     * @return array<string,mixed>|\WP_Error
     */
    public function adjustPrompt(
        int $assistantId,
        string $criteria,
        string $adjusterPromptTemplate,
        $evaluationsPayload,
        $currentSystemPrompt = null,
        $currentUserMessageTemplate = null,
        $currentExpectedOutputSchema = null
    ) {
        if ($assistantId <= 0) {
            return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
        }
        if ($criteria === '') {
            return new \WP_Error('missing_refinement_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($adjusterPromptTemplate) === '') {
            $adjusterPromptTemplate = PromptRefinementSettings::workflowAdjuster();
        }

        $assistant = AssistantManager::get_assistant($assistantId);
        if (!$assistant) {
            return new \WP_Error('assistant_not_found', __('Assistant not found.', 'polytrans'));
        }

        $evaluations = $this->decodeEvaluations($evaluationsPayload);
        if (empty($evaluations)) {
            return new \WP_Error('missing_evaluations', __('At least one evaluated workflow run is required.', 'polytrans'));
        }

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
        $workflow_context = is_array($evaluations[0]['workflow_context'] ?? null) ? $evaluations[0]['workflow_context'] : [];
        $adjuster_context = [
            'criteria' => $criteria,
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'non_interpolated_system_prompt' => $current_prompt_pack['system_prompt'],
            'non_interpolated_user_message_template' => $current_prompt_pack['user_message_template'],
            'non_interpolated_expected_output_schema' => $current_prompt_pack['expected_output_schema'],
            'workflow_context_json' => $this->encodeJson($workflow_context, 60000),
            'workflow_structure_json' => $this->encodeJson($workflow_context['steps'] ?? [], 35000),
            'target_step_context_json' => $this->encodeJson($workflow_context['target_step'] ?? [], 35000),
            'previous_steps_json' => $this->encodeJson($workflow_context['previous_steps'] ?? [], 18000),
            'following_steps_json' => $this->encodeJson($workflow_context['following_steps'] ?? [], 18000),
            'evaluations' => $evaluations,
            'evaluations_json' => $this->encodeJson($evaluations, 65000),
        ];
        $rendered_adjuster_system_prompt = PromptTemplateRenderer::render(
            PromptRefinementSettings::workflowAdjusterSystem(),
            $adjuster_context
        );
        $rendered_adjuster_prompt = PromptTemplateRenderer::render($adjusterPromptTemplate, $adjuster_context);

        $adjustment = PromptChatRunner::execute(
            $assistant,
            $rendered_adjuster_prompt,
            $rendered_adjuster_system_prompt,
            'workflow_refinement'
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
     * @return array<string,mixed>|\WP_Error
     */
    public function applyPromptPack(int $assistantId, string $systemPrompt, string $userMessageTemplate, $expectedOutputSchema)
    {
        return (new AssistantRefinementService())->applyPromptPack(
            $assistantId,
            $systemPrompt,
            $userMessageTemplate,
            $expectedOutputSchema
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findManagedAssistantStep(array $workflow, string $targetStepId): ?array
    {
        $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            if ((string) ($step['id'] ?? '') !== $targetStepId) {
                continue;
            }
            if (($step['type'] ?? '') !== 'managed_assistant') {
                return null;
            }
            $step['__index'] = $index;
            return $step;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildContextFromPost(int $postId, string $sourceLanguage, string $targetLanguage): ?array
    {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        $original_post_id = (int) get_post_meta($postId, '_polytrans_original_post_id', true);
        if ($original_post_id <= 0) {
            $original_post_id = $postId;
        }

        if ($targetLanguage === '' && function_exists('pll_get_post_language')) {
            $targetLanguage = (string) pll_get_post_language($postId);
        }
        if ($sourceLanguage === '' && function_exists('pll_get_post_language')) {
            $sourceLanguage = (string) pll_get_post_language($original_post_id);
        }

        return [
            'original_post_id' => $original_post_id,
            'translated_post_id' => $postId,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'trigger' => 'workflow_refinement_test',
            'test_mode' => true,
        ];
    }

    /**
     * @param array<string,mixed> $workflow
     * @param array<string,mixed> $targetStep
     * @param array<string,mixed> $assistant
     * @param array<string,mixed> $context
     * @param array<string,mixed> $workflowResult
     * @return array<string,mixed>
     */
    private function buildRunPayload(string $runId, array $workflow, array $targetStep, array $assistant, array $context, array $workflowResult): array
    {
        $target_step_result = $this->findStepResultById($workflowResult, (string) ($targetStep['id'] ?? ''));
        $post_id = (int) ($context['translated_post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;

        return [
            'run_id' => $runId,
            'workflow_id' => (string) ($workflow['id'] ?? ''),
            'workflow_name' => (string) ($workflow['name'] ?? ''),
            'target_step_id' => (string) ($targetStep['id'] ?? ''),
            'target_step_name' => (string) ($targetStep['name'] ?? ''),
            'target_step_index' => (int) ($targetStep['__index'] ?? 0),
            'assistant_id' => (int) ($assistant['id'] ?? 0),
            'assistant_name' => (string) ($assistant['name'] ?? ''),
            'assistant_config' => $this->buildAssistantConfigSnapshot($assistant),
            'context' => [
                'original_post_id' => (int) ($context['original_post_id'] ?? 0),
                'translated_post_id' => (int) ($context['translated_post_id'] ?? 0),
                'source_language' => (string) ($context['source_language'] ?? ''),
                'target_language' => (string) ($context['target_language'] ?? ''),
            ],
            'post' => [
                'id' => $post_id,
                'title' => $post ? (string) $post->post_title : '',
                'excerpt' => $post ? (string) wp_trim_words(wp_strip_all_tags($post->post_content), 24, '...') : '',
            ],
            'used_prompt_pack' => PromptPackNormalizer::fromAssistant($assistant),
            'workflow_context' => $this->buildContextMap($workflow, $targetStep, $workflowResult),
            'target_step_result' => $this->compactStepResult($target_step_result, true),
            'workflow_result' => [
                'success' => !empty($workflowResult['success']),
                'steps_executed' => (int) ($workflowResult['steps_executed'] ?? 0),
                'execution_time' => (float) ($workflowResult['execution_time'] ?? 0),
                'test_mode' => !empty($workflowResult['test_mode']),
            ],
            'workflow_result_summary' => $this->summarizeWorkflowResult($workflowResult),
            'final_output' => $this->buildFinalOutputSnapshot($workflowResult),
            'created_at' => time(),
        ];
    }

    /**
     * @param array<string,mixed> $runPayload
     * @return array<string,mixed>
     */
    private function buildRunResponse(array $runPayload): array
    {
        $target_step_result = is_array($runPayload['target_step_result'] ?? null) ? $runPayload['target_step_result'] : [];

        return [
            'run_id' => (string) ($runPayload['run_id'] ?? ''),
            'run_ttl_seconds' => $this->getRunTtl(),
            'workflow_id' => (string) ($runPayload['workflow_id'] ?? ''),
            'workflow_name' => (string) ($runPayload['workflow_name'] ?? ''),
            'workflow_success' => !empty($runPayload['workflow_result']['success']),
            'target_step_id' => (string) ($runPayload['target_step_id'] ?? ''),
            'target_step_name' => (string) ($runPayload['target_step_name'] ?? ''),
            'assistant_id' => (int) ($runPayload['assistant_id'] ?? 0),
            'assistant_name' => (string) ($runPayload['assistant_name'] ?? ''),
            'post_id' => (int) ($runPayload['post']['id'] ?? 0),
            'post_title' => (string) ($runPayload['post']['title'] ?? ''),
            'post_excerpt' => (string) ($runPayload['post']['excerpt'] ?? ''),
            'assistant_output' => $target_step_result['data'] ?? null,
            'interpolated_system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
            'interpolated_user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
            'used_prompt_pack' => $runPayload['used_prompt_pack'] ?? [],
            'workflow_context' => $runPayload['workflow_context'] ?? [],
            'workflow_result_summary' => $runPayload['workflow_result_summary'] ?? [],
            'final_output' => $runPayload['final_output'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $runPayload
     * @return array<string,mixed>|\WP_Error
     */
    private function evaluateWorkflowRun(array $runPayload, string $criteria, string $evaluatorPromptTemplate)
    {
        $assistant = is_array($runPayload['assistant_config'] ?? null) ? $runPayload['assistant_config'] : [];
        if (empty($assistant)) {
            return new \WP_Error('workflow_refinement_missing_assistant', __('Stored workflow run is missing assistant configuration.', 'polytrans'));
        }

        $target_step_result = is_array($runPayload['target_step_result'] ?? null) ? $runPayload['target_step_result'] : [];
        $assistant_output = $target_step_result['data'] ?? '';
        if (!is_string($assistant_output)) {
            $assistant_output = wp_json_encode($assistant_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $evaluator_context = [
            'criteria' => $criteria,
            'workflow_name' => (string) ($runPayload['workflow_name'] ?? ''),
            'workflow_id' => (string) ($runPayload['workflow_id'] ?? ''),
            'workflow_success' => !empty($runPayload['workflow_result']['success']) ? 'true' : 'false',
            'source_language' => (string) ($runPayload['context']['source_language'] ?? ''),
            'target_language' => (string) ($runPayload['context']['target_language'] ?? ''),
            'target_step_id' => (string) ($runPayload['target_step_id'] ?? ''),
            'target_step_name' => (string) ($runPayload['target_step_name'] ?? ''),
            'target_interpolated_system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
            'target_interpolated_user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
            'target_assistant_output' => (string) $assistant_output,
            'include_expected_output_schema' => PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
            'workflow_context_json' => $this->encodeJson($runPayload['workflow_context'] ?? [], 60000),
            'workflow_structure_json' => $this->encodeJson($runPayload['workflow_context']['steps'] ?? [], 35000),
            'target_step_context_json' => $this->encodeJson($runPayload['workflow_context']['target_step'] ?? [], 35000),
            'previous_steps_json' => $this->encodeJson($runPayload['workflow_context']['previous_steps'] ?? [], 18000),
            'following_steps_json' => $this->encodeJson($runPayload['workflow_context']['following_steps'] ?? [], 18000),
            'final_output_json' => $this->encodeJson($runPayload['final_output'] ?? [], 18000),
            'workflow_result_json' => $this->encodeJson($runPayload['workflow_result_summary'] ?? [], 25000),
        ];
        $rendered_evaluator_system_prompt = PromptTemplateRenderer::render(
            PromptRefinementSettings::workflowEvaluatorSystem(),
            $evaluator_context
        );
        $rendered_evaluator_prompt = PromptTemplateRenderer::render($evaluatorPromptTemplate, $evaluator_context);

        $evaluation_response = PromptChatRunner::execute(
            $assistant,
            $rendered_evaluator_prompt,
            $rendered_evaluator_system_prompt,
            'workflow_refinement'
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
     * @param array<string,mixed> $workflowResult
     * @return array<string,mixed>|null
     */
    private function findStepResultById(array $workflowResult, string $stepId): ?array
    {
        $step_results = is_array($workflowResult['step_results'] ?? null) ? $workflowResult['step_results'] : [];
        foreach ($step_results as $step_result) {
            if (!is_array($step_result)) {
                continue;
            }
            if ((string) ($step_result['step_id'] ?? '') === $stepId) {
                return $step_result;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $workflowResult
     * @return array<string,mixed>
     */
    private function summarizeWorkflowResult(array $workflowResult): array
    {
        $steps = [];
        foreach ((array) ($workflowResult['step_results'] ?? []) as $step_result) {
            if (!is_array($step_result)) {
                continue;
            }
            $steps[] = [
                'step_id' => (string) ($step_result['step_id'] ?? ''),
                'step_name' => (string) ($step_result['step_name'] ?? ''),
                'step_type' => (string) ($step_result['step_type'] ?? ''),
                'success' => !empty($step_result['success']),
                'error' => $step_result['error'] ?? null,
                'data' => $this->compactValue($step_result['data'] ?? null, 1, 1000),
                'output_processing' => $this->compactValue($step_result['output_processing'] ?? null, 1, 1000),
            ];
        }

        return [
            'success' => !empty($workflowResult['success']),
            'steps_executed' => (int) ($workflowResult['steps_executed'] ?? 0),
            'execution_time' => (float) ($workflowResult['execution_time'] ?? 0),
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string,mixed> $assistant
     * @return array<string,mixed>
     */
    private function buildAssistantConfigSnapshot(array $assistant): array
    {
        return [
            'id' => (int) ($assistant['id'] ?? 0),
            'name' => (string) ($assistant['name'] ?? ''),
            'provider' => (string) ($assistant['provider'] ?? 'openai'),
            'status' => (string) ($assistant['status'] ?? 'active'),
            'system_prompt' => (string) ($assistant['system_prompt'] ?? ''),
            'user_message_template' => (string) ($assistant['user_message_template'] ?? ''),
            'api_parameters' => is_array($assistant['api_parameters'] ?? null) ? $assistant['api_parameters'] : [],
            'expected_format' => (string) ($assistant['expected_format'] ?? 'text'),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed>|null $stepResult
     * @return array<string,mixed>
     */
    private function compactStepResult(?array $stepResult, bool $isTarget = false): array
    {
        if (!$stepResult) {
            return [
                'success' => false,
                'error' => null,
                'data' => null,
                'output_processing' => null,
                'interpolated_system_prompt' => null,
                'interpolated_user_message' => null,
            ];
        }

        return [
            'step_id' => (string) ($stepResult['step_id'] ?? ''),
            'step_name' => (string) ($stepResult['step_name'] ?? ''),
            'step_type' => (string) ($stepResult['step_type'] ?? ''),
            'success' => !empty($stepResult['success']),
            'error' => $stepResult['error'] ?? null,
            'data' => $this->compactValue($stepResult['data'] ?? null, 2, $isTarget ? 8000 : 1500),
            'output_processing' => $this->compactValue($stepResult['output_processing'] ?? null, 2, $isTarget ? 4000 : 1200),
            'interpolated_system_prompt' => $this->truncateText($stepResult['interpolated_system_prompt'] ?? null, $isTarget ? 12000 : 0),
            'interpolated_user_message' => $this->truncateText($stepResult['interpolated_user_message'] ?? null, $isTarget ? 16000 : 0),
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function compactValue($value, int $depth = 3, int $stringLimit = 8000)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $this->truncateText($value, $stringLimit);
        }
        if (is_array($value)) {
            if ($depth <= 0) {
                return sprintf('[array with %d item(s)]', count($value));
            }

            $compacted = [];
            $index = 0;
            foreach ($value as $key => $item) {
                if ($index >= 30) {
                    $compacted['__truncated_items'] = count($value) - $index;
                    break;
                }
                $compacted[$key] = $this->compactValue($item, $depth - 1, $stringLimit);
                $index++;
            }

            return $compacted;
        }
        if (is_object($value)) {
            if ($depth <= 0) {
                return '[object ' . get_class($value) . ']';
            }

            return $this->compactValue(get_object_vars($value), $depth, $stringLimit);
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     */
    private function truncateText($value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        if ($limit === 0) {
            return null;
        }

        if ($limit < 0 || strlen($text) <= $limit) {
            return $text;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit) . "\n\n[truncated for workflow refinement payload]";
        }

        return substr($text, 0, $limit) . "\n\n[truncated for workflow refinement payload]";
    }

    /**
     * @param array<string,mixed> $workflow
     * @param array<string,mixed> $targetStep
     * @param array<string,mixed> $workflowResult
     * @return array<string,mixed>
     */
    private function buildContextMap(array $workflow, array $targetStep, array $workflowResult): array
    {
        $target_step_id = (string) ($targetStep['id'] ?? '');
        $steps = [];

        foreach ((array) ($workflow['steps'] ?? []) as $index => $step) {
            if (!is_array($step)) {
                continue;
            }

            $step_id = (string) ($step['id'] ?? "step_{$index}");
            $step_result = $this->findStepResultById($workflowResult, $step_id);
            $steps[] = $this->buildStepContextSummary($step, $step_result, $index, $step_id === $target_step_id);
        }

        $target_index = null;
        foreach ($steps as $index => $step) {
            if (!empty($step['is_target'])) {
                $target_index = $index;
                break;
            }
        }

        return [
            'workflow' => [
                'id' => (string) ($workflow['id'] ?? ''),
                'name' => (string) ($workflow['name'] ?? ''),
                'description' => (string) ($workflow['description'] ?? ''),
                'language' => (string) ($workflow['language'] ?? ''),
                'enabled' => !empty($workflow['enabled']),
            ],
            'target_step' => $target_index !== null ? $steps[$target_index] : [],
            'previous_steps' => $target_index !== null ? array_slice($steps, 0, $target_index) : [],
            'following_steps' => $target_index !== null ? array_slice($steps, $target_index + 1) : [],
            'steps' => $steps,
        ];
    }

    /**
     * @param array<string,mixed> $step
     * @param array<string,mixed>|null $stepResult
     * @return array<string,mixed>
     */
    private function buildStepContextSummary(array $step, ?array $stepResult, int $index, bool $isTarget): array
    {
        $summary = [
            'position' => $index + 1,
            'id' => (string) ($step['id'] ?? "step_{$index}"),
            'name' => (string) ($step['name'] ?? ('Step ' . ($index + 1))),
            'type' => (string) ($step['type'] ?? ''),
            'enabled' => !isset($step['enabled']) || !empty($step['enabled']),
            'is_target' => $isTarget,
            'continue_on_error' => !empty($step['continue_on_error']),
            'output_actions' => is_array($step['output_actions'] ?? null) ? $step['output_actions'] : [],
            'run' => $this->compactStepResult($stepResult, $isTarget),
        ];

        if (($step['type'] ?? '') === 'managed_assistant') {
            $assistant_id = (int) ($step['assistant_id'] ?? 0);
            $assistant = $assistant_id > 0 ? AssistantManager::get_assistant($assistant_id) : null;

            $summary['assistant_id'] = $assistant_id;
            $summary['assistant_name'] = is_array($assistant) ? (string) ($assistant['name'] ?? '') : '';
            $summary['provider'] = is_array($assistant) ? (string) ($assistant['provider'] ?? '') : '';
            $summary['expected_format'] = is_array($assistant) ? (string) ($assistant['expected_format'] ?? 'text') : '';
            if ($isTarget) {
                $summary['non_interpolated_prompt_pack'] = is_array($assistant)
                    ? PromptPackNormalizer::fromAssistant($assistant)
                    : [
                        'system_prompt' => '',
                        'user_message_template' => '',
                        'expected_output_schema' => '{}',
                    ];
            } else {
                $summary['non_interpolated_prompt_pack_summary'] = is_array($assistant)
                    ? [
                        'system_prompt_preview' => $this->truncateText($assistant['system_prompt'] ?? '', 1200),
                        'user_message_template_preview' => $this->truncateText($assistant['user_message_template'] ?? '', 1200),
                        'has_expected_output_schema' => !empty($assistant['expected_output_schema']),
                    ]
                    : [];
            }
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $workflowResult
     * @return array<string,mixed>
     */
    private function buildFinalOutputSnapshot(array $workflowResult): array
    {
        $final_context = is_array($workflowResult['final_context'] ?? null) ? $workflowResult['final_context'] : [];
        $translated = is_array($final_context['translated_post'] ?? null) ? $final_context['translated_post'] : [];
        $meta = [];

        if (isset($translated['meta']) && is_array($translated['meta'])) {
            $meta = $translated['meta'];
        } elseif (isset($final_context['translated_meta']) && is_array($final_context['translated_meta'])) {
            $meta = $final_context['translated_meta'];
        }

        return [
            'title' => (string) ($final_context['title'] ?? ($translated['title'] ?? '')),
            'content' => $this->truncateText((string) ($final_context['content'] ?? ($translated['content'] ?? '')), 12000),
            'excerpt' => (string) ($final_context['excerpt'] ?? ($translated['excerpt'] ?? '')),
            'meta' => $this->compactValue($meta, 2, 3000),
            'previous_steps' => $this->compactValue($final_context['previous_steps'] ?? [], 2, 2500),
        ];
    }

    /**
     * @param mixed $evaluationsPayload
     * @return array<int,array<string,mixed>>
     */
    private function decodeEvaluations($evaluationsPayload): array
    {
        $evaluations = [];
        if (is_string($evaluationsPayload)) {
            $decoded = json_decode(wp_unslash($evaluationsPayload), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $evaluations = $decoded;
            }
        } elseif (is_array($evaluationsPayload)) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized before prompt rendering.
            $evaluations = wp_unslash($evaluationsPayload);
        }

        $normalized = [];
        foreach ($evaluations as $item) {
            if (!is_array($item)) {
                continue;
            }
            $score = null;
            if (isset($item['evaluation']['score']) && is_numeric($item['evaluation']['score'])) {
                $score = (float) $item['evaluation']['score'];
            } elseif (isset($item['score']) && is_numeric($item['score'])) {
                $score = (float) $item['score'];
            }

            $normalized[] = [
                'run_id' => isset($item['run_id']) ? sanitize_text_field((string) $item['run_id']) : '',
                'post_id' => isset($item['post_id']) ? (int) $item['post_id'] : 0,
                'post_title' => isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '',
                'workflow_success' => !empty($item['workflow_success']),
                'score' => $score,
                'feedback' => isset($item['evaluation']['feedback'])
                    ? (string) $item['evaluation']['feedback']
                    : ((isset($item['feedback'])) ? (string) $item['feedback'] : ''),
                'final_output' => is_array($item['final_output'] ?? null) ? $item['final_output'] : [],
                'workflow_result_summary' => is_array($item['workflow_result_summary'] ?? null) ? $item['workflow_result_summary'] : [],
                'workflow_context' => is_array($item['workflow_context'] ?? null) ? $item['workflow_context'] : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value, int $limit = 60000): string
    {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }

        return (string) $this->truncateText($json, $limit);
    }

    /**
     * @param array<string,mixed> $runPayload
     */
    private function persistRunPayload(string $runId, array $runPayload): bool
    {
        $transient_key = $this->getRunTransientKey($runId);
        $stored = set_transient($transient_key, $runPayload, $this->getRunTtl());

        if ($stored) {
            return true;
        }

        $read_back = get_transient($transient_key);
        if (is_array($read_back) && (string) ($read_back['run_id'] ?? '') === $runId) {
            return true;
        }

        LogsManager::log(
            'Failed to persist workflow refinement run payload',
            'error',
            [
                'run_id' => $runId,
                'payload_size_bytes' => strlen(maybe_serialize($runPayload)),
                'transient_key' => $transient_key,
            ]
        );

        return false;
    }

    private function getRunTransientKey(string $runId): string
    {
        return 'polytrans_workflow_refine_run_' . md5($runId);
    }

    private function generateRunId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        return uniqid('workflow_run_', true);
    }

    private function getRunTtl(): int
    {
        return 2 * HOUR_IN_SECONDS;
    }
}

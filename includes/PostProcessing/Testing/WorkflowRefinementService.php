<?php

declare(strict_types=1);

namespace PolyTrans\PostProcessing\Testing;

use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\Testing\AssistantRefinementService;
use PolyTrans\Core\LogsManager;
use PolyTrans\PostProcessing\Managers\WorkflowStorageManager;
use PolyTrans\PromptRefinement\EvaluationScoreExtractor;
use PolyTrans\PromptRefinement\PromptChatRunner;
use PolyTrans\PromptRefinement\PromptPackNormalizer;
use PolyTrans\PromptRefinement\PromptPackParser;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\PromptRefinement\PromptTemplateRenderer;
use PolyTrans\PromptRefinement\RefinementRunStorage;

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

        $target_step = $this->findRefinableStep($workflow, $targetStepId);
        if (!$target_step) {
            return new \WP_Error('workflow_refinement_invalid_target_step', __('Selected workflow step is not a refinable assistant step.', 'polytrans'));
        }

        $target_type = (string) ($target_step['type'] ?? '');
        $assistant = $this->buildPromptRunnerConfigForTargetStep($target_step);
        if (is_wp_error($assistant)) {
            return $assistant;
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
            if ($target_type === 'managed_assistant') {
                $context['__assistant_prompt_overrides'] = [
                    $targetStepId => $prompt_overrides,
                ];
            } elseif ($target_type === 'ai_assistant') {
                unset($prompt_overrides['expected_output_schema']);
                $context['__workflow_step_prompt_overrides'] = [
                    $targetStepId => $prompt_overrides,
                ];
            }
        }

        try {
            $workflow_manager = \PolyTrans_Workflow_Manager::get_instance();
            $workflow_result = $workflow_manager->execute_workflow($workflow, $context, true);
        } catch (\Throwable $e) {
            return new \WP_Error('workflow_refinement_execution_error', $e->getMessage());
        }

        $run_id = $this->generateRunId();
        $used_prompt_pack = $this->buildPromptPackForTargetStep($target_step, is_array($assistant) ? $assistant : []);
        if ($overrideSystemPrompt !== null) {
            $used_prompt_pack['system_prompt'] = (string) $overrideSystemPrompt;
        }
        if ($overrideUserMessageTemplate !== null) {
            $used_prompt_pack['user_message_template'] = (string) $overrideUserMessageTemplate;
        }
        if ($target_type === 'managed_assistant' && $overrideExpectedOutputSchema !== null) {
            $used_prompt_pack['expected_output_schema'] = (string) $overrideExpectedOutputSchema;
        }

        $run_payload = $this->buildRunPayload($run_id, $workflow, $target_step, $assistant, $context, $workflow_result, $used_prompt_pack);

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
    public function evaluateRun(
        string $runId,
        string $targetStepId,
        string $criteria,
        string $workflowPurpose = '',
        string $promptObjective = '',
        string $evaluatorPromptTemplate = '',
        string $evaluatorSystemPromptTemplate = ''
    )
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
        if (trim($evaluatorSystemPromptTemplate) === '') {
            $evaluatorSystemPromptTemplate = PromptRefinementSettings::workflowEvaluatorSystem();
        }

        $run_payload = $this->loadRunPayload($runId);
        if (!is_array($run_payload)) {
            return new \WP_Error('workflow_refinement_run_not_found', __('Workflow run not found or expired. Run workflow again.', 'polytrans'));
        }
        if ($targetStepId !== '' && (string) ($run_payload['target_step_id'] ?? '') !== $targetStepId) {
            return new \WP_Error('workflow_refinement_run_mismatch', __('Run ID does not belong to the selected workflow step.', 'polytrans'));
        }

        $evaluation = $this->evaluateWorkflowRun($run_payload, $criteria, $workflowPurpose, $promptObjective, $evaluatorPromptTemplate, $evaluatorSystemPromptTemplate);
        if (is_wp_error($evaluation)) {
            return $evaluation;
        }

        $run_payload['evaluation'] = $evaluation;
        $run_payload['evaluated_at'] = time();
        $this->persistRunPayload($runId, $run_payload);

        // TODO: check if this is outdated? No longer used
        // $workflow_context = $this->compactWorkflowContextForStorage(
        //     $this->buildContextMap($workflow, $targetStep, $workflowResult)
        // );

        return [
            'run_id' => $runId,
            'target_step_id' => (string) ($run_payload['target_step_id'] ?? ''),
            'target_step_type' => (string) ($run_payload['target_step_type'] ?? ''),
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
        string $workflowPurpose,
        string $promptObjective,
        string $adjusterPromptTemplate,
        $evaluationsPayload,
        string $adjusterSystemPromptTemplate = '',
        $currentSystemPrompt = null,
        $currentUserMessageTemplate = null,
        $currentExpectedOutputSchema = null,
        array $workflow = [],
        string $targetStepId = '',
        $refinementHistoryPayload = '[]'
    ) {
        if ($criteria === '') {
            return new \WP_Error('missing_refinement_criteria', __('Refinement criteria is required.', 'polytrans'));
        }
        if (trim($adjusterPromptTemplate) === '') {
            $adjusterPromptTemplate = PromptRefinementSettings::workflowAdjuster();
        }
        if (trim($adjusterSystemPromptTemplate) === '') {
            $adjusterSystemPromptTemplate = PromptRefinementSettings::workflowAdjusterSystem();
        }

        $target_step = null;
        if ($targetStepId !== '' && !empty($workflow)) {
            $target_step = $this->findRefinableStep($workflow, $targetStepId);
            if (!$target_step) {
                return new \WP_Error('workflow_refinement_invalid_target_step', __('Selected workflow step is not a refinable assistant step.', 'polytrans'));
            }
        }

        if ($target_step) {
            $assistant = $this->buildPromptRunnerConfigForTargetStep($target_step);
            if (is_wp_error($assistant)) {
                return $assistant;
            }
            $current_prompt_pack = $this->buildPromptPackForTargetStep($target_step, $assistant);
            $should_adjust_expected_output_schema = $this->shouldAdjustTargetExpectedOutputSchema($target_step, $assistant);
        } else {
            if ($assistantId <= 0) {
                return new \WP_Error('invalid_assistant_id', __('Invalid assistant ID.', 'polytrans'));
            }

            $assistant = AssistantManager::get_assistant($assistantId);
            if (!$assistant) {
                return new \WP_Error('assistant_not_found', __('Assistant not found.', 'polytrans'));
            }
            $current_prompt_pack = PromptPackNormalizer::fromAssistant($assistant);
            $should_adjust_expected_output_schema = PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant);
        }
        $promptObjective = $this->resolveWorkflowPromptObjective($promptObjective, is_array($target_step) ? $target_step : [], $assistant);
        $workflowPurpose = $this->resolveWorkflowPurpose($workflowPurpose, $workflow);

        $evaluations = $this->decodeEvaluations($evaluationsPayload);
        if (empty($evaluations)) {
            return new \WP_Error('missing_evaluations', __('At least one evaluated workflow run is required.', 'polytrans'));
        }

        if ($currentSystemPrompt !== null) {
            $current_prompt_pack['system_prompt'] = (string) $currentSystemPrompt;
        }
        if ($currentUserMessageTemplate !== null) {
            $current_prompt_pack['user_message_template'] = (string) $currentUserMessageTemplate;
        }
        if ($currentExpectedOutputSchema !== null) {
            $current_prompt_pack['expected_output_schema'] = (string) $currentExpectedOutputSchema;
        }

        $workflow_context = is_array($evaluations[0]['workflow_context'] ?? null) ? $evaluations[0]['workflow_context'] : [];
        $refinement_history = $this->decodeRefinementHistory($refinementHistoryPayload);
        $workflow_evidence_json = $this->encodeJsonForEvaluation($this->buildAdjusterWorkflowEvidence($workflow_context), 60000);
        $workflow_context_json = $this->encodeJsonForEvaluation($workflow_context, 60000);
        $workflow_structure_json = $this->encodeJsonForEvaluation($workflow_context['steps'] ?? [], 35000);
        $target_step_context_json = $this->encodeJsonForEvaluation($workflow_context['target_step'] ?? [], 35000);
        $previous_steps_json = $this->encodeJsonForEvaluation($workflow_context['previous_steps'] ?? [], 18000);
        $following_steps_json = $this->encodeJsonForEvaluation($workflow_context['following_steps'] ?? [], 18000);
        $evaluations_for_prompt = $this->buildAdjusterEvaluationSummaries($evaluations);
        $evaluations_json = $this->encodeJsonForEvaluation($evaluations_for_prompt, 45000);
        $refinement_history_json = $this->encodeJsonForEvaluation($refinement_history, 25000);
        $adjuster_context = [
            'criteria' => $criteria,
            'workflow_purpose' => $workflowPurpose,
            'prompt_objective' => $promptObjective,
            'adjust_expected_output_schema' => $should_adjust_expected_output_schema,
            'non_interpolated_system_prompt' => $current_prompt_pack['system_prompt'],
            'non_interpolated_user_message_template' => $current_prompt_pack['user_message_template'],
            'non_interpolated_expected_output_schema' => $current_prompt_pack['expected_output_schema'],
            'workflow_evidence_json' => $workflow_evidence_json,
            'workflow_context_json' => $workflow_context_json,
            'workflow_structure_json' => $workflow_structure_json,
            'target_step_context_json' => $target_step_context_json,
            'previous_steps_json' => $previous_steps_json,
            'following_steps_json' => $following_steps_json,
            'evaluations' => $evaluations_for_prompt,
            'evaluations_json' => $evaluations_json,
            'refinement_history' => $refinement_history,
            'refinement_history_json' => $refinement_history_json,
        ];
        $rendered_adjuster_system_prompt = PromptTemplateRenderer::render(
            $adjusterSystemPromptTemplate,
            $adjuster_context
        );
        $rendered_adjuster_prompt = PromptTemplateRenderer::render($adjusterPromptTemplate, $adjuster_context);
        $prompt_diagnostics = $this->buildAdjusterPromptDiagnostics([
            'criteria' => $criteria,
            'workflow_purpose' => $workflowPurpose,
            'prompt_objective' => $promptObjective,
            'current_system_prompt' => $current_prompt_pack['system_prompt'],
            'current_user_message_template' => $current_prompt_pack['user_message_template'],
            'current_expected_output_schema' => $current_prompt_pack['expected_output_schema'],
            'workflow_evidence_json' => $workflow_evidence_json,
            'workflow_context_json' => $workflow_context_json,
            'workflow_structure_json' => $workflow_structure_json,
            'target_step_context_json' => $target_step_context_json,
            'previous_steps_json' => $previous_steps_json,
            'following_steps_json' => $following_steps_json,
            'evaluations_json' => $evaluations_json,
            'refinement_history_json' => $refinement_history_json,
            'adjuster_system_prompt_template' => $adjusterSystemPromptTemplate,
            'adjuster_prompt_template' => $adjusterPromptTemplate,
        ], $rendered_adjuster_system_prompt, $rendered_adjuster_prompt);
        LogsManager::log('Workflow refinement adjuster prompt diagnostics', 'info', $prompt_diagnostics);

        $adjustment = PromptChatRunner::execute(
            $assistant,
            $rendered_adjuster_prompt,
            $rendered_adjuster_system_prompt,
            'workflow_refinement'
        );

        if (is_wp_error($adjustment)) {
            $adjustment->add_data([
                'prompt_diagnostics' => $prompt_diagnostics,
                'rendered_system_prompt_preview' => $this->buildPromptPreview($rendered_adjuster_system_prompt),
                'rendered_user_prompt_preview' => $this->buildPromptPreview($rendered_adjuster_prompt),
            ]);
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
            'target_step_id' => (string) ($target_step['id'] ?? ''),
            'target_step_type' => (string) ($target_step['type'] ?? 'managed_assistant'),
            'target_step_name' => (string) ($target_step['name'] ?? ''),
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
    public function applyPromptPack(
        int $assistantId,
        string $systemPrompt,
        string $userMessageTemplate,
        $expectedOutputSchema,
        array $workflow = [],
        string $targetStepId = '',
        string $targetStepType = '',
        $basePromptPack = null
    ) {
        if ($targetStepType === 'ai_assistant' || ($assistantId <= 0 && $targetStepId !== '')) {
            return $this->applyPromptPackToWorkflowAiStep(
                $workflow,
                $targetStepId,
                $systemPrompt,
                $userMessageTemplate,
                $basePromptPack
            );
        }

        if ($targetStepType !== '' && $targetStepType !== 'managed_assistant') {
            return new \WP_Error('workflow_refinement_unsupported_target', __('Selected workflow step type cannot be updated by prompt refinement.', 'polytrans'));
        }

        return (new AssistantRefinementService())->applyPromptPack(
            $assistantId,
            $systemPrompt,
            $userMessageTemplate,
            $expectedOutputSchema
        );
    }

    /**
     * @param array<string,mixed> $workflow
     * @param mixed $basePromptPack
     * @return array<string,mixed>|\WP_Error
     */
    private function applyPromptPackToWorkflowAiStep(
        array $workflow,
        string $targetStepId,
        string $systemPrompt,
        string $userMessageTemplate,
        $basePromptPack
    ) {
        if ($targetStepId === '') {
            return new \WP_Error('workflow_refinement_missing_target_step', __('Select a workflow step to refine.', 'polytrans'));
        }
        if (trim($systemPrompt) === '') {
            return new \WP_Error('empty_system_prompt', __('System prompt cannot be empty.', 'polytrans'));
        }
        if (trim($userMessageTemplate) === '') {
            return new \WP_Error('empty_user_message_template', __('User message template cannot be empty.', 'polytrans'));
        }

        $workflow_id = (string) ($workflow['id'] ?? '');
        if ($workflow_id === '') {
            return new \WP_Error('workflow_refinement_missing_workflow', __('Workflow data is required.', 'polytrans'));
        }

        $storage = new WorkflowStorageManager();
        $stored_workflow = $storage->get_workflow($workflow_id);
        if (!is_array($stored_workflow)) {
            return new \WP_Error('workflow_refinement_workflow_not_found', __('Workflow was not found. Save the workflow before applying prompt changes.', 'polytrans'));
        }

        $step_index = $this->findStepIndexById($stored_workflow, $targetStepId);
        if ($step_index === null || (($stored_workflow['steps'][$step_index]['type'] ?? '') !== 'ai_assistant')) {
            return new \WP_Error('workflow_refinement_invalid_target_step', __('Selected workflow step is not a custom AI assistant step.', 'polytrans'));
        }

        $current_prompt_pack = PromptPackNormalizer::fromWorkflowAiStep($stored_workflow['steps'][$step_index]);
        $base_prompt_pack = $this->normalizeBasePromptPack($basePromptPack);
        if ($base_prompt_pack && !$this->promptPacksMatch($current_prompt_pack, $base_prompt_pack)) {
            return new \WP_Error(
                'workflow_refinement_prompt_conflict',
                __('The target workflow step changed since refinement started. Refresh the workflow and run refinement again before applying.', 'polytrans'),
                [
                    'current_prompt_pack' => $current_prompt_pack,
                    'base_prompt_pack' => $base_prompt_pack,
                ]
            );
        }

        $previous_prompt_pack = $current_prompt_pack;
        $stored_workflow['steps'][$step_index]['system_prompt'] = $systemPrompt;
        $stored_workflow['steps'][$step_index]['user_message'] = $userMessageTemplate;

        $save_result = $storage->save_workflow($stored_workflow);
        if (empty($save_result['success'])) {
            return new \WP_Error(
                'workflow_refinement_workflow_save_failed',
                __('Failed to save workflow prompt changes.', 'polytrans'),
                $save_result['errors'] ?? []
            );
        }

        return [
            'workflow_id' => $workflow_id,
            'target_step_id' => $targetStepId,
            'target_step_type' => 'ai_assistant',
            'target_step_name' => (string) ($stored_workflow['steps'][$step_index]['name'] ?? ''),
            'previous_prompt_pack' => $previous_prompt_pack,
            'applied_prompt_pack' => [
                'system_prompt' => $systemPrompt,
                'user_message_template' => $userMessageTemplate,
                'expected_output_schema' => $previous_prompt_pack['expected_output_schema'] ?? '{}',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRefinableStep(array $workflow, string $targetStepId): ?array
    {
        $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            if ((string) ($step['id'] ?? '') !== $targetStepId) {
                continue;
            }
            if (!in_array(($step['type'] ?? ''), ['managed_assistant', 'ai_assistant'], true)) {
                return null;
            }
            if (isset($step['enabled']) && empty($step['enabled'])) {
                return null;
            }
            $step['__index'] = $index;
            return $step;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $workflow
     */
    private function findStepIndexById(array $workflow, string $targetStepId): ?int
    {
        $steps = is_array($workflow['steps'] ?? null) ? $workflow['steps'] : [];
        foreach ($steps as $index => $step) {
            if (is_array($step) && (string) ($step['id'] ?? '') === $targetStepId) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $targetStep
     * @return array<string,mixed>|\WP_Error
     */
    private function buildPromptRunnerConfigForTargetStep(array $targetStep)
    {
        $target_type = (string) ($targetStep['type'] ?? '');
        if ($target_type === 'managed_assistant') {
            $assistant_id = (int) ($targetStep['assistant_id'] ?? 0);
            $assistant = AssistantManager::get_assistant($assistant_id);
            if (!$assistant) {
                return new \WP_Error('workflow_refinement_assistant_not_found', __('Target assistant was not found.', 'polytrans'));
            }
            if (($assistant['status'] ?? 'active') !== 'active') {
                return new \WP_Error('workflow_refinement_assistant_inactive', __('Target assistant is inactive.', 'polytrans'));
            }

            return $assistant;
        }

        if ($target_type === 'ai_assistant') {
            if (trim((string) ($targetStep['system_prompt'] ?? '')) === '' || trim((string) ($targetStep['user_message'] ?? '')) === '') {
                return new \WP_Error('workflow_refinement_invalid_custom_step', __('Custom AI assistant steps need both system prompt and user message to be refined.', 'polytrans'));
            }

            $api_parameters = [];
            if (!empty($targetStep['model'])) {
                $api_parameters['model'] = (string) $targetStep['model'];
            }
            if (isset($targetStep['temperature'])) {
                $api_parameters['temperature'] = (float) $targetStep['temperature'];
            }

            return [
                'id' => 0,
                'name' => (string) ($targetStep['name'] ?? __('Custom workflow AI step', 'polytrans')),
                'description' => (string) ($targetStep['description'] ?? ''),
                'provider' => $this->resolvePromptRunnerProvider($targetStep),
                'status' => 'active',
                'system_prompt' => (string) ($targetStep['system_prompt'] ?? ''),
                'user_message_template' => (string) ($targetStep['user_message'] ?? ''),
                'api_parameters' => $api_parameters,
                'expected_format' => (string) ($targetStep['expected_format'] ?? 'text'),
                'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema(
                    PromptPackNormalizer::workflowAiStepOutputContract($targetStep)
                ),
            ];
        }

        return new \WP_Error('workflow_refinement_unsupported_target', __('Selected workflow step type cannot be refined.', 'polytrans'));
    }

    /**
     * @param array<string,mixed> $targetStep
     * @param array<string,mixed> $assistant
     * @return array<string,string>
     */
    private function buildPromptPackForTargetStep(array $targetStep, array $assistant): array
    {
        if (($targetStep['type'] ?? '') === 'ai_assistant') {
            return PromptPackNormalizer::fromWorkflowAiStep($targetStep);
        }

        return PromptPackNormalizer::fromAssistant($assistant);
    }

    /**
     * @param array<string,mixed> $targetStep
     * @param array<string,mixed> $assistant
     */
    private function shouldAdjustTargetExpectedOutputSchema(array $targetStep, array $assistant): bool
    {
        if (($targetStep['type'] ?? '') === 'ai_assistant') {
            return false;
        }

        return PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant);
    }

    /**
     * @param array<string,mixed> $targetStep
     */
    private function resolvePromptRunnerProvider(array $targetStep): string
    {
        $provider = trim((string) ($targetStep['provider'] ?? ''));
        if ($provider !== '') {
            return $provider;
        }

        $settings = get_option('polytrans_settings', []);
        $enabled = is_array($settings['enabled_translation_providers'] ?? null)
            ? $settings['enabled_translation_providers']
            : [];

        foreach (['openai', 'claude', 'gemini'] as $candidate) {
            if (!empty($enabled) && !in_array($candidate, $enabled, true)) {
                continue;
            }

            $api_key = (string) ($settings[$candidate . '_api_key'] ?? '');
            if ($api_key !== '') {
                return $candidate;
            }
        }

        return 'openai';
    }

    /**
     * @param array<string,mixed> $targetStep
     * @param array<string,mixed> $assistant
     */
    private function resolveWorkflowPromptObjective(string $promptObjective, array $targetStep, array $assistant): string
    {
        $objective = trim($promptObjective);
        if ($objective !== '') {
            return $objective;
        }

        $step_description = trim(wp_strip_all_tags((string) ($targetStep['description'] ?? '')));
        if ($step_description !== '') {
            return $step_description;
        }

        $assistant_description = trim(wp_strip_all_tags((string) ($assistant['description'] ?? '')));
        if ($assistant_description !== '') {
            return $assistant_description;
        }

        return __('Preserve the selected workflow step original purpose and existing behavioral contract while applying the refinement criteria.', 'polytrans');
    }

    /**
     * @param array<string,mixed> $workflow
     */
    private function resolveWorkflowPurpose(string $workflowPurpose, array $workflow): string
    {
        $purpose = trim($workflowPurpose);
        if ($purpose !== '') {
            return $purpose;
        }

        $workflow_description = trim(wp_strip_all_tags((string) ($workflow['description'] ?? '')));
        if ($workflow_description !== '') {
            return $workflow_description;
        }

        return __('Preserve the overall workflow purpose and final output quality while applying the selected step refinement criteria.', 'polytrans');
    }

    /**
     * @param mixed $basePromptPack
     * @return array<string,string>|null
     */
    private function normalizeBasePromptPack($basePromptPack): ?array
    {
        if (is_string($basePromptPack) && trim($basePromptPack) !== '') {
            $decoded = json_decode($basePromptPack, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $basePromptPack = $decoded;
            }
        }

        if (!is_array($basePromptPack)) {
            return null;
        }

        return [
            'system_prompt' => (string) ($basePromptPack['system_prompt'] ?? ''),
            'user_message_template' => (string) ($basePromptPack['user_message_template'] ?? ''),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($basePromptPack['expected_output_schema'] ?? '{}'),
        ];
    }

    /**
     * @param array<string,string> $left
     * @param array<string,string> $right
     */
    private function promptPacksMatch(array $left, array $right): bool
    {
        return hash_equals($this->promptPackFingerprint($left), $this->promptPackFingerprint($right));
    }

    /**
     * @param array<string,string> $pack
     */
    private function promptPackFingerprint(array $pack): string
    {
        $normalized = [
            'system_prompt' => (string) ($pack['system_prompt'] ?? ''),
            'user_message_template' => (string) ($pack['user_message_template'] ?? ''),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($pack['expected_output_schema'] ?? '{}'),
        ];

        return hash('sha256', (string) wp_json_encode($normalized));
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
    private function buildRunPayload(
        string $runId,
        array $workflow,
        array $targetStep,
        array $assistant,
        array $context,
        array $workflowResult,
        array $usedPromptPack
    ): array
    {
        $target_step_result = $this->findStepResultById($workflowResult, (string) ($targetStep['id'] ?? ''));
        $workflow_context = $this->compactWorkflowContextForStorage(
            $this->buildContextMap($workflow, $targetStep, $workflowResult)
        );
        $post_id = (int) ($context['translated_post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;

        return [
            'run_id' => $runId,
            'workflow_id' => (string) ($workflow['id'] ?? ''),
            'workflow_name' => (string) ($workflow['name'] ?? ''),
            'workflow' => [
                'id' => (string) ($workflow['id'] ?? ''),
                'name' => (string) ($workflow['name'] ?? ''),
                'description' => (string) ($workflow['description'] ?? ''),
            ],
            'target_step_id' => (string) ($targetStep['id'] ?? ''),
            'target_step_name' => (string) ($targetStep['name'] ?? ''),
            'target_step_type' => (string) ($targetStep['type'] ?? ''),
            'target_step_index' => (int) ($targetStep['__index'] ?? 0),
            'target_step' => [
                'id' => (string) ($targetStep['id'] ?? ''),
                'name' => (string) ($targetStep['name'] ?? ''),
                'description' => (string) ($targetStep['description'] ?? ''),
                'type' => (string) ($targetStep['type'] ?? ''),
            ],
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
            'used_prompt_pack' => $usedPromptPack,
            'workflow_context' => $workflow_context,
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
            'target_step_type' => (string) ($runPayload['target_step_type'] ?? ''),
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
    private function evaluateWorkflowRun(
        array $runPayload,
        string $criteria,
        string $workflowPurpose,
        string $promptObjective,
        string $evaluatorPromptTemplate,
        string $evaluatorSystemPromptTemplate
    )
    {
        $assistant = is_array($runPayload['assistant_config'] ?? null) ? $runPayload['assistant_config'] : [];
        if (empty($assistant)) {
            return new \WP_Error('workflow_refinement_missing_assistant', __('Stored workflow run is missing assistant configuration.', 'polytrans'));
        }

        $target_step_result = is_array($runPayload['target_step_result'] ?? null) ? $runPayload['target_step_result'] : [];
        $assistant_output = $this->formatStepOutputForEvaluation($target_step_result['data'] ?? '');
        $workflow_evidence = $this->buildEvaluatorWorkflowEvidence($runPayload, (string) $assistant_output, $assistant);

        $evaluator_context = [
            'criteria' => $criteria,
            'workflow_purpose' => $this->resolveWorkflowPurpose($workflowPurpose, is_array($runPayload['workflow'] ?? null) ? $runPayload['workflow'] : []),
            'prompt_objective' => $this->resolveWorkflowPromptObjective(
                $promptObjective,
                is_array($runPayload['target_step'] ?? null) ? $runPayload['target_step'] : [],
                $assistant
            ),
            'workflow_name' => (string) ($runPayload['workflow_name'] ?? ''),
            'workflow_id' => (string) ($runPayload['workflow_id'] ?? ''),
            'workflow_success' => !empty($runPayload['workflow_result']['success']) ? 'true' : 'false',
            'source_language' => (string) ($runPayload['context']['source_language'] ?? ''),
            'target_language' => (string) ($runPayload['context']['target_language'] ?? ''),
            'target_step_id' => (string) ($runPayload['target_step_id'] ?? ''),
            'target_step_name' => (string) ($runPayload['target_step_name'] ?? ''),
            'target_step_type' => (string) ($runPayload['target_step_type'] ?? ''),
            'target_interpolated_system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
            'target_interpolated_user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
            'target_assistant_output' => (string) $assistant_output,
            'include_expected_output_schema' => PromptPackNormalizer::shouldAdjustExpectedOutputSchema($assistant),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
            'workflow_evidence_json' => $this->encodeJsonForEvaluation($workflow_evidence, 80000),
            'workflow_context_json' => $this->encodeJsonForEvaluation($runPayload['workflow_context'] ?? [], 60000),
            'workflow_structure_json' => $this->encodeJsonForEvaluation($runPayload['workflow_context']['steps'] ?? [], 35000),
            'target_step_context_json' => $this->encodeJsonForEvaluation($runPayload['workflow_context']['target_step'] ?? [], 35000),
            'previous_steps_json' => $this->encodeJsonForEvaluation($runPayload['workflow_context']['previous_steps'] ?? [], 18000),
            'following_steps_json' => $this->encodeJsonForEvaluation($runPayload['workflow_context']['following_steps'] ?? [], 18000),
            'final_output_json' => $this->encodeJsonForEvaluation(
                $this->buildFinalOutputForEvaluation(
                    is_array($runPayload['final_output'] ?? null) ? $runPayload['final_output'] : [],
                    $assistant_output
                ),
                0
            ),
            'workflow_result_json' => $this->encodeJsonForEvaluation($runPayload['workflow_result_summary'] ?? [], 25000),
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
                'data_summary' => $this->summarizeValueShape($step_result['data'] ?? null),
                'output_processing_summary' => $this->summarizeOutputProcessing($step_result['output_processing'] ?? null),
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
     * Build one non-duplicated evidence object for the workflow evaluator prompt.
     *
     * @param array<string,mixed> $runPayload
     * @param array<string,mixed> $assistant
     * @return array<string,mixed>
     */
    private function buildEvaluatorWorkflowEvidence(array $runPayload, string $assistantOutput, array $assistant): array
    {
        $workflow_context = is_array($runPayload['workflow_context'] ?? null) ? $runPayload['workflow_context'] : [];
        $target_step_result = is_array($runPayload['target_step_result'] ?? null) ? $runPayload['target_step_result'] : [];

        return [
            'workflow' => [
                'id' => (string) ($runPayload['workflow_id'] ?? ''),
                'name' => (string) ($runPayload['workflow_name'] ?? ''),
                'success' => !empty($runPayload['workflow_result']['success']),
                'steps_executed' => (int) ($runPayload['workflow_result']['steps_executed'] ?? 0),
                'execution_time' => (float) ($runPayload['workflow_result']['execution_time'] ?? 0),
            ],
            'context' => [
                'source_language' => (string) ($runPayload['context']['source_language'] ?? ''),
                'target_language' => (string) ($runPayload['context']['target_language'] ?? ''),
                'post_id' => (int) ($runPayload['post']['id'] ?? 0),
                'post_title' => (string) ($runPayload['post']['title'] ?? ''),
            ],
            'steps_before_target' => $this->compactWorkflowStepsForPrompt($workflow_context['previous_steps'] ?? [], true),
            'target_step' => $this->compactWorkflowStepForPrompt(
                is_array($workflow_context['target_step'] ?? null) ? $workflow_context['target_step'] : [],
                false
            ),
            'steps_after_target' => $this->compactWorkflowStepsForPrompt($workflow_context['following_steps'] ?? [], true),
            'target_prompt' => [
                'system_prompt' => (string) ($target_step_result['interpolated_system_prompt'] ?? ''),
                'user_message' => (string) ($target_step_result['interpolated_user_message'] ?? ''),
                'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
            ],
            'target_output' => $assistantOutput,
            'final_output' => $this->buildFinalOutputForEvaluation(
                is_array($runPayload['final_output'] ?? null) ? $runPayload['final_output'] : [],
                $assistantOutput
            ),
            'step_results' => $this->buildPromptStepResultSummaries($runPayload['workflow_result_summary']['steps'] ?? []),
        ];
    }

    /**
     * Build one compact workflow context object for the adjuster prompt.
     *
     * @param array<string,mixed> $workflowContext
     * @return array<string,mixed>
     */
    private function buildAdjusterWorkflowEvidence(array $workflowContext): array
    {
        return [
            'workflow' => is_array($workflowContext['workflow'] ?? null) ? $workflowContext['workflow'] : [],
            'steps_before_target' => $this->compactWorkflowStepsForPrompt($workflowContext['previous_steps'] ?? [], true),
            'target_step' => $this->compactWorkflowStepForPrompt(
                is_array($workflowContext['target_step'] ?? null) ? $workflowContext['target_step'] : [],
                false
            ),
            'steps_after_target' => $this->compactWorkflowStepsForPrompt($workflowContext['following_steps'] ?? [], true),
        ];
    }

    /**
     * @param mixed $steps
     * @return array<int,array<string,mixed>>
     */
    private function compactWorkflowStepsForPrompt($steps, bool $includeRun): array
    {
        if (!is_array($steps)) {
            return [];
        }

        $result = [];
        foreach ($steps as $step) {
            if (is_array($step)) {
                $result[] = $this->compactWorkflowStepForPrompt($step, $includeRun);
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function compactWorkflowStepForPrompt(array $step, bool $includeRun): array
    {
        $summary = [
            'position' => (int) ($step['position'] ?? 0),
            'id' => (string) ($step['id'] ?? ''),
            'name' => (string) ($step['name'] ?? ''),
            'description' => (string) ($step['description'] ?? ''),
            'type' => (string) ($step['type'] ?? ''),
            'enabled' => !isset($step['enabled']) || !empty($step['enabled']),
            'is_target' => !empty($step['is_target']),
            'output_actions' => is_array($step['output_actions'] ?? null) ? $step['output_actions'] : [],
            'provider' => (string) ($step['provider'] ?? ''),
            'model' => (string) ($step['model'] ?? ''),
            'expected_format' => (string) ($step['expected_format'] ?? ''),
            'output_variables' => is_array($step['output_variables'] ?? null) ? $step['output_variables'] : [],
        ];

        if (isset($step['assistant_id'])) {
            $summary['assistant_id'] = (int) $step['assistant_id'];
            $summary['assistant_name'] = (string) ($step['assistant_name'] ?? '');
        }
        if (is_array($step['non_interpolated_prompt_pack_summary'] ?? null)) {
            $summary['prompt_pack_summary'] = $step['non_interpolated_prompt_pack_summary'];
        }
        if ($includeRun && is_array($step['run'] ?? null)) {
            $run = $step['run'];
            $summary['run'] = [
                'success' => !empty($run['success']),
                'error' => $run['error'] ?? null,
                'data' => $run['data'] ?? null,
                'output_processing' => $run['output_processing'] ?? null,
            ];
        }

        return array_filter($summary, static function ($value): bool {
            return $value !== '' && $value !== [] && $value !== null;
        });
    }

    /**
     * @param mixed $steps
     * @return array<int,array<string,mixed>>
     */
    private function buildPromptStepResultSummaries($steps): array
    {
        if (!is_array($steps)) {
            return [];
        }

        $summaries = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $summaries[] = [
                'step_id' => (string) ($step['step_id'] ?? ''),
                'step_name' => (string) ($step['step_name'] ?? ''),
                'step_type' => (string) ($step['step_type'] ?? ''),
                'success' => !empty($step['success']),
                'error' => $step['error'] ?? null,
                'data_summary' => $step['data_summary'] ?? null,
                'output_processing_summary' => $step['output_processing_summary'] ?? null,
            ];
        }

        return $summaries;
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
            'api_parameters' => is_array($assistant['api_parameters'] ?? null) ? $assistant['api_parameters'] : [],
            'expected_format' => (string) ($assistant['expected_format'] ?? 'text'),
            'expected_output_schema' => PromptPackNormalizer::normalizeExpectedOutputSchema($assistant['expected_output_schema'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function compactWorkflowContextForStorage(array $context): array
    {
        if (is_array($context['target_step'] ?? null)) {
            $context['target_step'] = $this->compactWorkflowStepContextForStorage($context['target_step']);
        }

        foreach (['previous_steps', 'following_steps', 'steps'] as $group_key) {
            if (!is_array($context[$group_key] ?? null)) {
                continue;
            }
            $context[$group_key] = array_map(function ($step): array {
                return $this->compactWorkflowStepContextForStorage(is_array($step) ? $step : []);
            }, $context[$group_key]);
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>
     */
    private function compactWorkflowStepContextForStorage(array $step): array
    {
        if (is_array($step['non_interpolated_prompt_pack'] ?? null)) {
            $pack = $step['non_interpolated_prompt_pack'];
            $step['non_interpolated_prompt_pack_summary'] = [
                'system_prompt_chars' => strlen((string) ($pack['system_prompt'] ?? '')),
                'user_message_template_chars' => strlen((string) ($pack['user_message_template'] ?? '')),
                'expected_output_schema_chars' => strlen((string) ($pack['expected_output_schema'] ?? '')),
            ];
            unset($step['non_interpolated_prompt_pack']);
        }

        return $step;
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
            'data' => $isTarget
                ? $this->compactTargetStepData($stepResult['data'] ?? null)
                : $this->summarizeValueShape($stepResult['data'] ?? null),
            'output_processing' => $this->summarizeOutputProcessing($stepResult['output_processing'] ?? null),
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
     * Preserve the selected step output as primary evidence. For text-mode steps
     * this usually means one full ai_response string; for structured JSON outputs
     * preserve values because the evaluator needs to verify output contracts.
     *
     * @param mixed $value
     * @return mixed
     */
    private function compactTargetStepData($value)
    {
        return $this->removeInternalCompactionMarkers($value);
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function summarizeOutputProcessing($value): array
    {
        if (!is_array($value)) {
            return $this->summarizeValueShape($value);
        }

        $changes = is_array($value['changes'] ?? null) ? $value['changes'] : [];

        return [
            'success' => !empty($value['success']),
            'processed_actions' => isset($value['processed_actions'])
                ? (int) $value['processed_actions']
                : (isset($value['actions_processed']) ? (int) $value['actions_processed'] : 0),
            'errors' => is_array($value['errors'] ?? null)
                ? array_values(array_map('strval', $value['errors']))
                : [],
            'message' => isset($value['message']) ? (string) $value['message'] : '',
            'changes' => $this->summarizeOutputChanges($changes),
            'updated_context' => [
                'present' => array_key_exists('updated_context', $value),
                'value_detail' => 'not_included_in_refinement_evidence',
            ],
        ];
    }

    /**
     * @param array<int|string,mixed> $changes
     * @return array<int,array<string,mixed>>
     */
    private function summarizeOutputChanges(array $changes): array
    {
        $summaries = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $summary = [];
            foreach (['type', 'target', 'source_variable', 'field', 'meta_key'] as $key) {
                if (isset($change[$key])) {
                    $summary[$key] = (string) $change[$key];
                }
            }

            if (isset($change['value'])) {
                $summary['value'] = $this->summarizeValueShape($change['value']);
            }

            $summaries[] = $summary;
        }

        return $summaries;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function summarizeValueShape($value): array
    {
        if ($value === null) {
            return [
                'type' => 'null',
                'present' => false,
            ];
        }

        if (is_string($value)) {
            return [
                'type' => 'string',
                'present' => trim($value) !== '',
                'chars' => strlen($value),
            ];
        }

        if (is_array($value)) {
            $keys = array_keys($value);

            return [
                'type' => 'array',
                'present' => !empty($value),
                'item_count' => count($value),
                'keys' => array_slice(array_map('strval', $keys), 0, 20),
                'value_detail' => 'shape_only',
            ];
        }

        if (is_object($value)) {
            return [
                'type' => 'object',
                'present' => true,
                'class' => get_class($value),
                'keys' => array_slice(array_keys(get_object_vars($value)), 0, 20),
                'value_detail' => 'shape_only',
            ];
        }

        return [
            'type' => gettype($value),
            'present' => true,
            'value' => $value,
        ];
    }

    /**
     * @param mixed $value
     */
    private function formatStepOutputForEvaluation($value): string
    {
        if (is_array($value) && array_key_exists('ai_response', $value)) {
            $non_empty_keys = array_filter(array_keys($value), static function ($key) use ($value): bool {
                return $key !== 'ai_response' && $value[$key] !== null && $value[$key] !== '';
            });

            if (empty($non_empty_keys)) {
                return (string) $value['ai_response'];
            }
        }

        if (is_string($value)) {
            return $value;
        }

        return (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $finalOutput
     * @return array<string,mixed>
     */
    private function buildFinalOutputForEvaluation(array $finalOutput, string $targetStepOutput): array
    {
        $result = $finalOutput;
        if (!isset($result['content']) || !is_string($result['content']) || trim($result['content']) === '') {
            return $result;
        }

        $content = $result['content'];
        if (strlen($content) <= 4000) {
            return $result;
        }

        if ($this->textsSubstantiallyOverlap($content, $targetStepOutput)) {
            $result['content'] = [
                'omitted' => true,
                'chars' => strlen($content),
                'reason' => 'Omitted because it substantially duplicates the selected target-step output already provided in full.',
            ];
        }

        return $result;
    }

    private function textsSubstantiallyOverlap(string $left, string $right): bool
    {
        $left_normalized = $this->normalizeTextForComparison($left);
        $right_normalized = $this->normalizeTextForComparison($right);

        if ($left_normalized === '' || $right_normalized === '') {
            return false;
        }

        if ($left_normalized === $right_normalized) {
            return true;
        }

        $shorter = strlen($left_normalized) <= strlen($right_normalized) ? $left_normalized : $right_normalized;
        $longer = $shorter === $left_normalized ? $right_normalized : $left_normalized;

        if (strlen($shorter) < 1000) {
            return false;
        }

        return strpos($longer, substr($shorter, 0, min(4000, strlen($shorter)))) !== false;
    }

    private function normalizeTextForComparison(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        if (!is_string($text)) {
            return '';
        }

        return trim($text);
    }

    /**
     * @param mixed $value
     */
    private function encodeJsonForEvaluation($value, int $limit = 0): string
    {
        return $this->encodeJson($this->removeInternalCompactionMarkers($value), $limit);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function removeInternalCompactionMarkers($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if ($key === '__truncated_items') {
                    continue;
                }
                $clean[$key] = $this->removeInternalCompactionMarkers($item);
            }

            return $clean;
        }

        if (is_object($value)) {
            return $this->removeInternalCompactionMarkers(get_object_vars($value));
        }

        return $value;
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

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit);
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
            'description' => (string) ($step['description'] ?? ''),
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
        } elseif (($step['type'] ?? '') === 'ai_assistant') {
            $summary['provider'] = (string) ($step['provider'] ?? '');
            $summary['model'] = (string) ($step['model'] ?? '');
            $summary['expected_format'] = (string) ($step['expected_format'] ?? 'text');
            $summary['output_variables'] = is_array($step['output_variables'] ?? null) ? $step['output_variables'] : [];
            if ($isTarget) {
                $summary['non_interpolated_prompt_pack'] = PromptPackNormalizer::fromWorkflowAiStep($step);
                $summary['output_contract_is_adjustable'] = false;
            } else {
                $summary['non_interpolated_prompt_pack_summary'] = [
                    'system_prompt_preview' => $this->truncateText($step['system_prompt'] ?? '', 1200),
                    'user_message_template_preview' => $this->truncateText($step['user_message'] ?? '', 1200),
                    'expected_format' => (string) ($step['expected_format'] ?? 'text'),
                    'output_variables' => is_array($step['output_variables'] ?? null) ? $step['output_variables'] : [],
                ];
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
            'content' => (string) ($final_context['content'] ?? ($translated['content'] ?? '')),
            'excerpt' => (string) ($final_context['excerpt'] ?? ($translated['excerpt'] ?? '')),
            'meta' => $this->compactValue($meta, 2, 3000),
            'previous_steps' => $this->summarizeValueShape($final_context['previous_steps'] ?? []),
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
     * Keep only the evaluation evidence the adjuster needs. Full workflow context is
     * provided separately, so including it in every evaluated run duplicates large
     * prompt and step summaries.
     *
     * @param array<int,array<string,mixed>> $evaluations
     * @return array<int,array<string,mixed>>
     */
    private function buildAdjusterEvaluationSummaries(array $evaluations): array
    {
        $summaries = [];
        foreach ($evaluations as $item) {
            $summaries[] = [
                'run_id' => (string) ($item['run_id'] ?? ''),
                'post_id' => isset($item['post_id']) ? (int) $item['post_id'] : 0,
                'post_title' => isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '',
                'workflow_success' => !empty($item['workflow_success']),
                'score' => isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                'feedback' => $this->truncateText((string) ($item['feedback'] ?? ''), 12000),
            ];
        }

        return $summaries;
    }

    /**
     * @param array<string,string> $components
     * @return array<string,mixed>
     */
    private function buildAdjusterPromptDiagnostics(array $components, string $renderedSystemPrompt, string $renderedUserPrompt): array
    {
        $component_lengths = [];
        foreach ($components as $name => $value) {
            $length = strlen((string) $value);
            $component_lengths[$name] = [
                'chars' => $length,
                'estimated_tokens' => (int) ceil($length / 4),
            ];
        }

        uasort($component_lengths, static function (array $left, array $right): int {
            return ($right['chars'] ?? 0) <=> ($left['chars'] ?? 0);
        });

        $system_chars = strlen($renderedSystemPrompt);
        $user_chars = strlen($renderedUserPrompt);
        $total_chars = $system_chars + $user_chars;

        return [
            'rendered_system_prompt_chars' => $system_chars,
            'rendered_user_prompt_chars' => $user_chars,
            'rendered_total_chars' => $total_chars,
            'estimated_total_tokens' => (int) ceil($total_chars / 4),
            'component_lengths' => $component_lengths,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPromptPreview(string $prompt): array
    {
        $chars = strlen($prompt);
        $head = (string) $this->truncateText($prompt, 6000);
        $tail = $chars > 6000 ? substr($prompt, -6000) : '';

        return [
            'chars' => $chars,
            'estimated_tokens' => (int) ceil($chars / 4),
            'head' => $head,
            'tail' => $tail,
        ];
    }

    /**
     * @param mixed $historyPayload
     * @return array<int,array<string,mixed>>
     */
    private function decodeRefinementHistory($historyPayload): array
    {
        $history = [];
        if (is_string($historyPayload)) {
            $decoded = json_decode(wp_unslash($historyPayload), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $history = $decoded;
            }
        } elseif (is_array($historyPayload)) {
            $history = wp_unslash($historyPayload);
        }

        $normalized = [];
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'iteration' => isset($item['iteration']) ? (int) $item['iteration'] : 0,
                'evaluated_prompt_version' => isset($item['evaluated_prompt_version']) ? sanitize_text_field((string) $item['evaluated_prompt_version']) : '',
                'evaluated_prompt_pack' => $this->normalizeHistoryPromptPack($item['evaluated_prompt_pack'] ?? []),
                'average_score' => isset($item['average_score']) && is_numeric($item['average_score']) ? (float) $item['average_score'] : null,
                'post_scores' => $this->normalizeHistoryPostScores($item['post_scores'] ?? []),
                'produced_prompt_version' => isset($item['produced_prompt_version']) ? sanitize_text_field((string) $item['produced_prompt_version']) : null,
                'produced_prompt_pack' => is_array($item['produced_prompt_pack'] ?? null) ? $this->normalizeHistoryPromptPack($item['produced_prompt_pack']) : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $pack
     * @return array<string,string>
     */
    private function normalizeHistoryPromptPack($pack): array
    {
        $pack = is_array($pack) ? $pack : [];

        return [
            'system_prompt' => (string) ($pack['system_prompt'] ?? ''),
            'user_message_template' => (string) ($pack['user_message_template'] ?? ''),
            'expected_output_schema' => (string) ($pack['expected_output_schema'] ?? '{}'),
        ];
    }

    /**
     * @param mixed $postScores
     * @return array<int,array<string,mixed>>
     */
    private function normalizeHistoryPostScores($postScores): array
    {
        if (!is_array($postScores)) {
            return [];
        }

        $normalized = [];
        foreach ($postScores as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'post_id' => isset($item['post_id']) ? (int) $item['post_id'] : 0,
                'post_title' => isset($item['post_title']) ? sanitize_text_field((string) $item['post_title']) : '',
                'score' => isset($item['score']) && is_numeric($item['score']) ? (float) $item['score'] : null,
                'workflow_success' => !empty($item['workflow_success']),
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
        if (RefinementRunStorage::store($runId, 'workflow', $runPayload, $this->getRunTtl())) {
            return true;
        }

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

    /**
     * @return array<string,mixed>|null
     */
    private function loadRunPayload(string $runId): ?array
    {
        $payload = RefinementRunStorage::get($runId);
        if (is_array($payload)) {
            return $payload;
        }

        $payload = get_transient($this->getRunTransientKey($runId));
        return is_array($payload) ? $payload : null;
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

<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptRefinementSettings
{
    public const ASSISTANT_EVALUATOR_KEY = 'prompt_refinement_assistant_evaluator_template';
    public const ASSISTANT_EVALUATOR_SYSTEM_KEY = 'prompt_refinement_assistant_evaluator_system_prompt';
    public const ASSISTANT_ADJUSTER_KEY = 'prompt_refinement_assistant_adjuster_template';
    public const ASSISTANT_ADJUSTER_SYSTEM_KEY = 'prompt_refinement_assistant_adjuster_system_prompt';
    public const WORKFLOW_EVALUATOR_KEY = 'prompt_refinement_workflow_evaluator_template';
    public const WORKFLOW_EVALUATOR_SYSTEM_KEY = 'prompt_refinement_workflow_evaluator_system_prompt';
    public const WORKFLOW_ADJUSTER_KEY = 'prompt_refinement_workflow_adjuster_template';
    public const WORKFLOW_ADJUSTER_SYSTEM_KEY = 'prompt_refinement_workflow_adjuster_system_prompt';
    public const DESCRIPTION_GENERATOR_SYSTEM_KEY = 'prompt_refinement_description_generator_system_prompt';
    public const CRITERIA_GENERATOR_SYSTEM_KEY = 'prompt_refinement_criteria_generator_system_prompt';
    public const ASSISTANT_DESCRIPTION_GENERATOR_KEY = 'prompt_refinement_assistant_description_generator_template';
    public const WORKFLOW_DESCRIPTION_GENERATOR_KEY = 'prompt_refinement_workflow_description_generator_template';
    public const WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY = 'prompt_refinement_workflow_step_description_generator_template';
    public const WORKFLOW_CRITERIA_GENERATOR_KEY = 'prompt_refinement_workflow_criteria_generator_template';

    public static function assistantEvaluatorSystem(?array $settings = null): string
    {
        return self::getTemplate(self::ASSISTANT_EVALUATOR_SYSTEM_KEY, DefaultPromptTemplates::assistantEvaluatorSystem(), $settings);
    }

    public static function assistantEvaluator(?array $settings = null): string
    {
        return self::getTemplate(self::ASSISTANT_EVALUATOR_KEY, DefaultPromptTemplates::assistantEvaluator(), $settings);
    }

    public static function assistantAdjusterSystem(?array $settings = null): string
    {
        return self::getTemplate(self::ASSISTANT_ADJUSTER_SYSTEM_KEY, DefaultPromptTemplates::assistantAdjusterSystem(), $settings);
    }

    public static function assistantAdjuster(?array $settings = null): string
    {
        return self::getTemplate(self::ASSISTANT_ADJUSTER_KEY, DefaultPromptTemplates::assistantAdjuster(), $settings);
    }

    public static function workflowEvaluatorSystem(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_EVALUATOR_SYSTEM_KEY, DefaultPromptTemplates::workflowEvaluatorSystem(), $settings);
    }

    public static function workflowEvaluator(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_EVALUATOR_KEY, DefaultPromptTemplates::workflowEvaluator(), $settings);
    }

    public static function workflowAdjusterSystem(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_ADJUSTER_SYSTEM_KEY, DefaultPromptTemplates::workflowAdjusterSystem(), $settings);
    }

    public static function workflowAdjuster(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_ADJUSTER_KEY, DefaultPromptTemplates::workflowAdjuster(), $settings);
    }

    public static function descriptionGeneratorSystem(?array $settings = null): string
    {
        return self::getTemplate(self::DESCRIPTION_GENERATOR_SYSTEM_KEY, DefaultPromptTemplates::descriptionGeneratorSystem(), $settings);
    }

    public static function criteriaGeneratorSystem(?array $settings = null): string
    {
        return self::getTemplate(self::CRITERIA_GENERATOR_SYSTEM_KEY, DefaultPromptTemplates::criteriaGeneratorSystem(), $settings);
    }

    public static function assistantDescriptionGenerator(?array $settings = null): string
    {
        return self::getTemplate(self::ASSISTANT_DESCRIPTION_GENERATOR_KEY, DefaultPromptTemplates::assistantDescriptionGenerator(), $settings);
    }

    public static function workflowDescriptionGenerator(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_DESCRIPTION_GENERATOR_KEY, DefaultPromptTemplates::workflowDescriptionGenerator(), $settings);
    }

    public static function workflowStepDescriptionGenerator(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY, DefaultPromptTemplates::workflowStepDescriptionGenerator(), $settings);
    }

    public static function workflowCriteriaGenerator(?array $settings = null): string
    {
        return self::getTemplate(self::WORKFLOW_CRITERIA_GENERATOR_KEY, DefaultPromptTemplates::workflowCriteriaGenerator(), $settings);
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            self::ASSISTANT_EVALUATOR_SYSTEM_KEY => DefaultPromptTemplates::assistantEvaluatorSystem(),
            self::ASSISTANT_EVALUATOR_KEY => DefaultPromptTemplates::assistantEvaluator(),
            self::ASSISTANT_ADJUSTER_SYSTEM_KEY => DefaultPromptTemplates::assistantAdjusterSystem(),
            self::ASSISTANT_ADJUSTER_KEY => DefaultPromptTemplates::assistantAdjuster(),
            self::WORKFLOW_EVALUATOR_SYSTEM_KEY => DefaultPromptTemplates::workflowEvaluatorSystem(),
            self::WORKFLOW_EVALUATOR_KEY => DefaultPromptTemplates::workflowEvaluator(),
            self::WORKFLOW_ADJUSTER_SYSTEM_KEY => DefaultPromptTemplates::workflowAdjusterSystem(),
            self::WORKFLOW_ADJUSTER_KEY => DefaultPromptTemplates::workflowAdjuster(),
            self::DESCRIPTION_GENERATOR_SYSTEM_KEY => DefaultPromptTemplates::descriptionGeneratorSystem(),
            self::CRITERIA_GENERATOR_SYSTEM_KEY => DefaultPromptTemplates::criteriaGeneratorSystem(),
            self::ASSISTANT_DESCRIPTION_GENERATOR_KEY => DefaultPromptTemplates::assistantDescriptionGenerator(),
            self::WORKFLOW_DESCRIPTION_GENERATOR_KEY => DefaultPromptTemplates::workflowDescriptionGenerator(),
            self::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY => DefaultPromptTemplates::workflowStepDescriptionGenerator(),
            self::WORKFLOW_CRITERIA_GENERATOR_KEY => DefaultPromptTemplates::workflowCriteriaGenerator(),
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function current(?array $settings = null): array
    {
        return [
            self::ASSISTANT_EVALUATOR_SYSTEM_KEY => self::assistantEvaluatorSystem($settings),
            self::ASSISTANT_EVALUATOR_KEY => self::assistantEvaluator($settings),
            self::ASSISTANT_ADJUSTER_SYSTEM_KEY => self::assistantAdjusterSystem($settings),
            self::ASSISTANT_ADJUSTER_KEY => self::assistantAdjuster($settings),
            self::WORKFLOW_EVALUATOR_SYSTEM_KEY => self::workflowEvaluatorSystem($settings),
            self::WORKFLOW_EVALUATOR_KEY => self::workflowEvaluator($settings),
            self::WORKFLOW_ADJUSTER_SYSTEM_KEY => self::workflowAdjusterSystem($settings),
            self::WORKFLOW_ADJUSTER_KEY => self::workflowAdjuster($settings),
            self::DESCRIPTION_GENERATOR_SYSTEM_KEY => self::descriptionGeneratorSystem($settings),
            self::CRITERIA_GENERATOR_SYSTEM_KEY => self::criteriaGeneratorSystem($settings),
            self::ASSISTANT_DESCRIPTION_GENERATOR_KEY => self::assistantDescriptionGenerator($settings),
            self::WORKFLOW_DESCRIPTION_GENERATOR_KEY => self::workflowDescriptionGenerator($settings),
            self::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY => self::workflowStepDescriptionGenerator($settings),
            self::WORKFLOW_CRITERIA_GENERATOR_KEY => self::workflowCriteriaGenerator($settings),
        ];
    }

    private static function getTemplate(string $key, string $default, ?array $settings = null): string
    {
        if ($settings === null) {
            $settings = get_option('polytrans_settings', []);
        }

        $template = $settings[$key] ?? '';
        if (!is_string($template) || trim($template) === '') {
            return $default;
        }

        return $template;
    }
}

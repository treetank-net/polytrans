<?php

declare(strict_types=1);

use PolyTrans\PromptRefinement\EvaluationScoreExtractor;
use PolyTrans\PromptRefinement\DefaultPromptTemplates;
use PolyTrans\PromptRefinement\PromptPackNormalizer;
use PolyTrans\PromptRefinement\PromptPackParser;
use PolyTrans\PromptRefinement\PromptRefinementSettings;
use PolyTrans\PromptRefinement\PromptTemplateRenderer;
use PolyTrans\Templating\TwigEngine;

it('parses JSON prompt packs with literal separators inside prompts', function () {
    $pack = [
        'system_prompt' => "You are helpful.\nText after --- is user input.",
        'user_message_template' => "Instructions:\n{{ original.meta.REVIEW }}\n---\n{{ translated.content }}",
        'expected_output_schema' => ['type' => 'object'],
    ];

    $parsed = PromptPackParser::parse(
        wp_json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        true,
        '{}'
    );

    expect($parsed['is_valid_pack'])->toBeTrue();
    expect($parsed['system_prompt'])->toContain('Text after --- is user input.');
    expect($parsed['user_message_template'])->toContain('{{ translated.content }}');
    expect($parsed['expected_output_schema'])->toContain('"type"');
});

it('parses tagged prompt packs with optional adjuster commentary', function () {
    $content = "<change_summary>\nMade the instruction stricter.\n</change_summary>\n\n" .
        "<system_prompt>\nImproved system\n</system_prompt>\n\n" .
        "<user_message_template>\nImproved {{ content }}\n---\nKeep literal separators.\n</user_message_template>\n\n" .
        "<expected_output_schema>\n{\"type\":\"object\",\"required\":[\"summary\"]}\n</expected_output_schema>";

    $parsed = PromptPackParser::parse($content, true, '{}');

    expect($parsed['is_valid_pack'])->toBeTrue();
    expect($parsed['system_prompt'])->toBe('Improved system');
    expect($parsed['user_message_template'])->toContain('{{ content }}');
    expect($parsed['user_message_template'])->toContain('---');
    expect($parsed['expected_output_schema'])->toContain('"summary"');
});

it('parses prefixed tagged prompt packs for compatibility', function () {
    $content = "<polytrans_system_prompt>\nImproved system\n</polytrans_system_prompt>\n\n" .
        "<polytrans_user_message_template>\nImproved {{ content }}\n</polytrans_user_message_template>\n\n" .
        "<polytrans_expected_output_schema>\n{\"type\":\"object\"}\n</polytrans_expected_output_schema>";

    $parsed = PromptPackParser::parse($content, true, '{}');

    expect($parsed['is_valid_pack'])->toBeTrue();
    expect($parsed['system_prompt'])->toBe('Improved system');
    expect($parsed['user_message_template'])->toBe('Improved {{ content }}');
    expect($parsed['expected_output_schema'])->toContain('"type"');
});

it('parses tagged prompt packs while preserving schema for text output assistants', function () {
    $content = "<system_prompt>\nImproved system\n</system_prompt>\n\n" .
        "<user_message_template>\nImproved {{ title }}\n</user_message_template>\n\n" .
        "<expected_output_schema>\n{\"hallucinated\":true}\n</expected_output_schema>";

    $parsed = PromptPackParser::parse($content, false, '{"original":true}');

    expect($parsed['is_valid_pack'])->toBeTrue();
    expect($parsed['system_prompt'])->toBe('Improved system');
    expect($parsed['user_message_template'])->toBe('Improved {{ title }}');
    expect($parsed['expected_output_schema'])->toBe('{"original":true}');
});

it('keeps expected output schema unchanged for text output assistants', function () {
    $content = wp_json_encode([
        'system_prompt' => 'Improved system',
        'user_message_template' => 'Improved {{ content }}',
        'expected_output_schema' => ['hallucinated' => true],
    ]);

    $parsed = PromptPackParser::parse($content, false, '{"original":true}');

    expect($parsed['is_valid_pack'])->toBeTrue();
    expect($parsed['system_prompt'])->toBe('Improved system');
    expect($parsed['user_message_template'])->toBe('Improved {{ content }}');
    expect($parsed['expected_output_schema'])->toBe('{"original":true}');
});

it('detects whether schema should be adjusted from assistant output format', function () {
    expect(PromptPackNormalizer::shouldAdjustExpectedOutputSchema(['expected_format' => 'json']))->toBeTrue();
    expect(PromptPackNormalizer::shouldAdjustExpectedOutputSchema(['expected_format' => 'text']))->toBeFalse();
    expect(PromptPackNormalizer::shouldAdjustExpectedOutputSchema([]))->toBeFalse();
});

it('builds prompt packs from inline workflow ai assistant steps', function () {
    $pack = PromptPackNormalizer::fromWorkflowAiStep([
        'system_prompt' => 'System {{ title }}',
        'user_message' => 'User {{ content }}',
        'expected_format' => 'json',
        'output_variables' => ['summary', 'score'],
    ]);

    expect($pack['system_prompt'])->toBe('System {{ title }}');
    expect($pack['user_message_template'])->toBe('User {{ content }}');
    expect($pack['expected_output_schema'])->toContain('summary');
    expect($pack['expected_output_schema'])->toContain('not automatically adjusted');
});

it('extracts numeric evaluator score', function () {
    expect(EvaluationScoreExtractor::extract('Score: 82.5. Better rewrite needed.'))->toBe(82.5);
    expect(EvaluationScoreExtractor::extract('Ocena = 91'))->toBe(91.0);
    expect(EvaluationScoreExtractor::extract('**Score: 62/100**'))->toBe(62.0);
    expect(EvaluationScoreExtractor::extract('1) **Score:** 78/100'))->toBe(78.0);
    expect(EvaluationScoreExtractor::extract('1) **Score: 82/100**'))->toBe(82.0);
    expect(EvaluationScoreExtractor::extract('{"score": "79.5", "feedback": "ok"}'))->toBe(79.5);
    expect(EvaluationScoreExtractor::extract("```json\n{\"evaluation\":{\"score\":88}}\n```"))->toBe(88.0);
    expect(EvaluationScoreExtractor::extract('1) Missing details 2) Needs work'))->toBeNull();
    expect(EvaluationScoreExtractor::extract('No numeric score here'))->toBeNull();
});

it('renders Twig refinement templates through shared renderer', function () {
    TwigEngine::init(['debug' => true]);

    $result = PromptTemplateRenderer::render(
        'Criteria: {{ criteria }} / {{ original.title }}',
        [
            'criteria' => 'Rewrite more naturally',
            'original' => ['title' => 'Source title'],
        ]
    );

    expect($result)->toBe('Criteria: Rewrite more naturally / Source title');
});

it('keeps literal Twig examples in default rendered system prompts', function () {
    TwigEngine::init(['debug' => true]);

    $result = PromptTemplateRenderer::render(
        DefaultPromptTemplates::promptAdjusterSystem(),
        ['content' => 'This must not be injected']
    );

    expect($result)->toContain('{{ content }}');
    expect($result)->not->toContain('This must not be injected');
});

it('includes primary prompt purpose in default refinement templates', function () {
    $assistant_templates = [
        DefaultPromptTemplates::assistantEvaluator(),
        DefaultPromptTemplates::assistantAdjuster(),
    ];
    $workflow_templates = [
        DefaultPromptTemplates::workflowEvaluator(),
        DefaultPromptTemplates::workflowAdjuster(),
    ];

    foreach ($assistant_templates as $template) {
        expect($template)->toContain('{{ prompt_objective }}');
        expect($template)->toContain('{{ criteria }}');
    }
    expect(DefaultPromptTemplates::assistantAdjuster())->toContain('{{ refinement_history_json }}');

    foreach ($workflow_templates as $template) {
        expect($template)->toContain('{{ workflow_purpose }}');
        expect($template)->toContain('{{ prompt_objective }}');
        expect($template)->toContain('{{ criteria }}');
    }
    expect(DefaultPromptTemplates::workflowAdjuster())->toContain('{{ refinement_history_json }}');
});

it('guides refinement prompts away from prompt bloat and blind rule stacking', function () {
    expect(DefaultPromptTemplates::assistantEvaluatorSystem())->toContain('instruction overload');
    expect(DefaultPromptTemplates::workflowEvaluatorSystem())->toContain('Do not assume adding another rule is the best fix');

    expect(DefaultPromptTemplates::assistantEvaluator())->toContain('If the criteria would encourage prompt bloat');
    expect(DefaultPromptTemplates::assistantEvaluator())->toContain('smallest effective prompt change');
    expect(DefaultPromptTemplates::workflowEvaluator())->toContain('workflow contract change');
    expect(DefaultPromptTemplates::workflowEvaluator())->toContain('Constraint diagnosis');

    expect(DefaultPromptTemplates::assistantAdjuster())->toContain('Treat the criteria as user intent');
    expect(DefaultPromptTemplates::assistantAdjuster())->toContain('Do not append new checklist sections');
    expect(DefaultPromptTemplates::workflowAdjuster())->toContain('shorter, clearer, more reliable target-step prompt');
    expect(DefaultPromptTemplates::workflowAdjuster())->toContain('validation/workflow mechanics are needed outside the prompt');

    expect(DefaultPromptTemplates::promptAdjusterSystem())->toContain('Prefer the smallest effective prompt change');
    expect(DefaultPromptTemplates::promptAdjusterSystem())->toContain('do not merely restate it more strongly');
});

it('loads prompt refinement templates from settings with built-in fallback', function () {
    expect(PromptRefinementSettings::assistantEvaluatorSystem([]))->toBe(DefaultPromptTemplates::assistantEvaluatorSystem());
    expect(PromptRefinementSettings::assistantEvaluator([]))->toBe(DefaultPromptTemplates::assistantEvaluator());
    expect(PromptRefinementSettings::workflowEvaluatorSystem([
        PromptRefinementSettings::WORKFLOW_EVALUATOR_SYSTEM_KEY => 'Custom workflow evaluator system',
    ]))->toBe('Custom workflow evaluator system');
    expect(PromptRefinementSettings::workflowAdjuster([
        PromptRefinementSettings::WORKFLOW_ADJUSTER_KEY => "Custom workflow adjuster {{ criteria }}",
    ]))->toBe("Custom workflow adjuster {{ criteria }}");
    expect(PromptRefinementSettings::assistantAdjuster([
        PromptRefinementSettings::ASSISTANT_ADJUSTER_KEY => '',
    ]))->toBe(DefaultPromptTemplates::assistantAdjuster());
});

it('includes description generator templates in prompt refinement settings', function () {
    $defaults = PromptRefinementSettings::defaults();
    $current = PromptRefinementSettings::current([]);

    expect($defaults[PromptRefinementSettings::DESCRIPTION_GENERATOR_SYSTEM_KEY])->toContain('admin-facing descriptions');
    expect($defaults[PromptRefinementSettings::ASSISTANT_DESCRIPTION_GENERATOR_KEY])->toContain('{{ assistant_name }}');
    expect($defaults[PromptRefinementSettings::WORKFLOW_DESCRIPTION_GENERATOR_KEY])->toContain('{{ workflow_steps_json }}');
    expect($defaults[PromptRefinementSettings::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY])->toContain('{{ target_step_json }}');
    expect($defaults[PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY])->toContain('{{ current_criteria }}');
    expect($defaults[PromptRefinementSettings::CRITERIA_GENERATOR_SYSTEM_KEY])->toContain('observable quality');
    expect($defaults[PromptRefinementSettings::CRITERIA_GENERATOR_SYSTEM_KEY])->toContain('do not infer a narrow step contract');
    expect($defaults[PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY])->toContain('more detected issues');
    expect($defaults[PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY])->toContain('Do not turn a broad goal into a narrow implementation');
    expect($defaults[PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY])->toContain('Treat those as context for understanding the task, not as criteria to import');
    expect($current[PromptRefinementSettings::DESCRIPTION_GENERATOR_SYSTEM_KEY])->toBe(DefaultPromptTemplates::descriptionGeneratorSystem());
    expect($current[PromptRefinementSettings::CRITERIA_GENERATOR_SYSTEM_KEY])->toBe(DefaultPromptTemplates::criteriaGeneratorSystem());
    expect($current[PromptRefinementSettings::WORKFLOW_CRITERIA_GENERATOR_KEY])->toBe(DefaultPromptTemplates::workflowCriteriaGenerator());
    expect(PromptRefinementSettings::workflowStepDescriptionGenerator([
        PromptRefinementSettings::WORKFLOW_STEP_DESCRIPTION_GENERATOR_KEY => '',
    ]))->toBe(DefaultPromptTemplates::workflowStepDescriptionGenerator());
});

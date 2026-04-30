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
    $templates = [
        DefaultPromptTemplates::assistantEvaluator(),
        DefaultPromptTemplates::assistantAdjuster(),
        DefaultPromptTemplates::workflowEvaluator(),
        DefaultPromptTemplates::workflowAdjuster(),
    ];

    foreach ($templates as $template) {
        expect($template)->toContain('{{ prompt_objective }}');
        expect($template)->toContain('{{ criteria }}');
    }
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

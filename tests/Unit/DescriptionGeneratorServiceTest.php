<?php

declare(strict_types=1);

use PolyTrans\PromptRefinement\DescriptionGeneratorService;

function invoke_description_generator_method(DescriptionGeneratorService $service, string $method, array $args = [])
{
    $reflection = new ReflectionClass($service);
    $method_reflection = $reflection->getMethod($method);
    $method_reflection->setAccessible(true);

    return $method_reflection->invokeArgs($service, $args);
}

it('parses JSON description generator responses', function () {
    $service = new DescriptionGeneratorService();

    $description = invoke_description_generator_method($service, 'parseDescription', [
        '{"description":"<p>Translates WordPress posts into natural Polish.</p>"}',
    ]);

    expect($description)->toBe('Translates WordPress posts into natural Polish.');
});

it('builds assistant description context from prompt pack fields', function () {
    $service = new DescriptionGeneratorService();

    $context = invoke_description_generator_method($service, 'buildAssistantContext', [[
        'name' => 'SEO Translator',
        'description' => '<p>Existing description</p>',
        'provider' => 'openai',
        'expected_format' => 'json',
        'api_parameters' => ['model' => 'gpt-test'],
        'system_prompt' => 'System {{ title }}',
        'user_message_template' => 'User {{ content }}',
        'expected_output_schema' => ['type' => 'object'],
    ]]);

    expect($context['assistant_name'])->toBe('SEO Translator');
    expect($context['assistant_description'])->toBe('Existing description');
    expect($context['assistant_model'])->toBe('gpt-test');
    expect($context['system_prompt'])->toBe('System {{ title }}');
    expect($context['user_message_template'])->toBe('User {{ content }}');
    expect($context['expected_output_schema'])->toContain('"type"');
});

it('builds workflow description context with target step and neighboring steps', function () {
    $service = new DescriptionGeneratorService();

    $context = invoke_description_generator_method($service, 'buildWorkflowContext', [[
        'name' => 'Review and rewrite',
        'description' => '<p>Reviews translated posts and rewrites weak parts.</p>',
        'language' => 'pl',
        'steps' => [
            [
                'id' => 'review',
                'name' => 'Review',
                'description' => 'Find literal translations.',
                'type' => 'ai_assistant',
                'system_prompt' => 'Review system',
                'user_message' => 'Review user',
                'expected_format' => 'json',
            ],
            [
                'id' => 'rewrite',
                'name' => 'Rewrite',
                'description' => 'Rewrite selected paragraphs.',
                'type' => 'ai_assistant',
                'system_prompt' => 'Rewrite system',
                'user_message' => 'Rewrite user',
                'expected_format' => 'text',
            ],
        ],
    ], [
        'id' => 'rewrite',
        'name' => 'Rewrite',
        'description' => 'Rewrite selected paragraphs.',
        'type' => 'ai_assistant',
    ]]);

    expect($context['workflow_name'])->toBe('Review and rewrite');
    expect($context['workflow_description'])->toBe('Reviews translated posts and rewrites weak parts.');
    expect($context['target_step_id'])->toBe('rewrite');
    expect($context['target_step_name'])->toBe('Rewrite');
    expect($context['previous_steps_json'])->toContain('Find literal translations.');
    expect($context['following_steps_json'])->toBe('[]');
});

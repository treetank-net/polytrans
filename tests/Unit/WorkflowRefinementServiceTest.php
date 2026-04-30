<?php

declare(strict_types=1);

use PolyTrans\PostProcessing\Testing\WorkflowRefinementService;

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags((string) $text);
    }
}

function invoke_workflow_refinement_method(string $methodName, ...$args)
{
    $service = new WorkflowRefinementService();
    $method = new ReflectionMethod($service, $methodName);
    $method->setAccessible(true);

    return $method->invoke($service, ...$args);
}

it('compacts workflow values before they are stored in refinement payloads', function () {
    $large = [];
    for ($i = 0; $i < 35; $i++) {
        $large['key_' . $i] = str_repeat('x', 40);
    }

    $compacted = invoke_workflow_refinement_method('compactValue', $large, 2, 10);

    expect($compacted)->toHaveKey('key_0');
    expect($compacted['key_0'])->toBe("xxxxxxxxxx\n\n[truncated for workflow refinement payload]");
    expect($compacted['__truncated_items'])->toBe(5);
});

it('summarizes workflow context around the selected target step', function () {
    $workflow = [
        'id' => 'workflow_test',
        'name' => 'Review and rewrite',
        'description' => 'Review post, then improve it.',
        'language' => 'pl',
        'enabled' => true,
        'steps' => [
            [
                'id' => 'step_review',
                'name' => 'Review',
                'type' => 'review',
                'output_actions' => [['target' => 'translated.meta.TRANSLATION_REVIEW']],
            ],
            [
                'id' => 'step_rewrite',
                'name' => 'Rewrite',
                'description' => 'Rewrite the reviewed post into natural target-language copy.',
                'type' => 'rewrite',
                'output_actions' => [['target' => 'translated.content']],
            ],
        ],
    ];
    $workflow_result = [
        'step_results' => [
            [
                'step_id' => 'step_review',
                'step_name' => 'Review',
                'step_type' => 'review',
                'success' => true,
                'data' => ['review' => 'Too literal.'],
            ],
            [
                'step_id' => 'step_rewrite',
                'step_name' => 'Rewrite',
                'step_type' => 'rewrite',
                'success' => true,
                'data' => ['content' => 'More natural text.'],
                'interpolated_system_prompt' => 'System prompt',
                'interpolated_user_message' => 'User message',
            ],
        ],
    ];

    $context = invoke_workflow_refinement_method(
        'buildContextMap',
        $workflow,
        $workflow['steps'][1],
        $workflow_result
    );

    expect($context['workflow']['id'])->toBe('workflow_test');
    expect($context['target_step']['id'])->toBe('step_rewrite');
    expect($context['target_step']['description'])->toBe('Rewrite the reviewed post into natural target-language copy.');
    expect($context['target_step']['run']['interpolated_system_prompt'])->toBe('System prompt');
    expect($context['previous_steps'])->toHaveCount(1);
    expect($context['previous_steps'][0]['id'])->toBe('step_review');
    expect($context['following_steps'])->toBe([]);
});

it('uses workflow target step description as default refinement primary purpose', function () {
    expect(invoke_workflow_refinement_method(
        'resolveWorkflowPromptObjective',
        '',
        ['description' => '<p>Review the translation and write actionable feedback.</p>'],
        ['description' => 'Fallback assistant description.']
    ))->toBe('Review the translation and write actionable feedback.');

    expect(invoke_workflow_refinement_method(
        'resolveWorkflowPromptObjective',
        '',
        [],
        ['description' => '<p>Fallback assistant description.</p>']
    ))->toBe('Fallback assistant description.');

    expect(invoke_workflow_refinement_method(
        'resolveWorkflowPromptObjective',
        'Keep the rewrite step aligned with the full workflow.',
        ['description' => 'Step description.'],
        ['description' => 'Assistant description.']
    ))->toBe('Keep the rewrite step aligned with the full workflow.');
});

it('accepts custom ai assistant steps as workflow refinement targets', function () {
    $workflow = [
        'steps' => [
            [
                'id' => 'custom_review',
                'name' => 'Custom Review',
                'type' => 'ai_assistant',
                'enabled' => true,
                'system_prompt' => 'Review the post.',
                'user_message' => 'Content: {{ content }}',
                'expected_format' => 'json',
                'output_variables' => ['review'],
            ],
            [
                'id' => 'legacy',
                'name' => 'Legacy',
                'type' => 'predefined_assistant',
                'enabled' => true,
            ],
        ],
    ];

    $custom = invoke_workflow_refinement_method('findRefinableStep', $workflow, 'custom_review');
    $legacy = invoke_workflow_refinement_method('findRefinableStep', $workflow, 'legacy');

    expect($custom)->toBeArray();
    expect($custom['type'])->toBe('ai_assistant');
    expect($legacy)->toBeNull();
});

it('does not allow custom workflow step output contracts to be adjusted automatically', function () {
    $step = [
        'id' => 'custom_json',
        'name' => 'Custom JSON',
        'type' => 'ai_assistant',
        'enabled' => true,
        'system_prompt' => 'Return JSON.',
        'user_message' => 'Content: {{ content }}',
        'expected_format' => 'json',
        'output_variables' => ['review'],
    ];

    $assistant = invoke_workflow_refinement_method('buildPromptRunnerConfigForTargetStep', $step);
    $shouldAdjust = invoke_workflow_refinement_method('shouldAdjustTargetExpectedOutputSchema', $step, $assistant);

    expect($assistant)->toBeArray();
    expect($assistant['expected_format'])->toBe('json');
    expect($shouldAdjust)->toBeFalse();
});

it('summarizes custom ai assistant target prompt packs without making output contract adjustable', function () {
    $workflow = [
        'id' => 'workflow_custom',
        'name' => 'Custom workflow',
        'steps' => [
            [
                'id' => 'custom_rewrite',
                'name' => 'Custom Rewrite',
                'type' => 'ai_assistant',
                'enabled' => true,
                'provider' => 'openai',
                'model' => 'gpt-5.2',
                'system_prompt' => 'Rewrite naturally.',
                'user_message' => 'Content: {{ content }}',
                'expected_format' => 'json',
                'output_variables' => ['rewritten_content'],
            ],
        ],
    ];

    $context = invoke_workflow_refinement_method(
        'buildContextMap',
        $workflow,
        $workflow['steps'][0],
        [
            'step_results' => [
                [
                    'step_id' => 'custom_rewrite',
                    'success' => true,
                    'data' => ['rewritten_content' => 'Better text.'],
                    'interpolated_system_prompt' => 'Rewrite naturally.',
                    'interpolated_user_message' => 'Content: Source text',
                ],
            ],
        ]
    );

    expect($context['target_step']['type'])->toBe('ai_assistant');
    expect($context['target_step']['non_interpolated_prompt_pack']['system_prompt'])->toBe('Rewrite naturally.');
    expect($context['target_step']['non_interpolated_prompt_pack']['user_message_template'])->toBe('Content: {{ content }}');
    expect($context['target_step']['output_contract_is_adjustable'])->toBeFalse();
    expect($context['target_step']['non_interpolated_prompt_pack']['expected_output_schema'])->toContain('rewritten_content');
});

it('builds compact final output snapshot from workflow final context', function () {
    $snapshot = invoke_workflow_refinement_method('buildFinalOutputSnapshot', [
        'final_context' => [
            'translated_post' => [
                'title' => 'Final title',
                'content' => str_repeat('a', 12020),
                'excerpt' => 'Final excerpt',
                'meta' => [
                    'TRANSLATION_REVIEW' => 'Looks better.',
                ],
            ],
            'previous_steps' => [
                'step_review' => ['feedback' => 'Too literal.'],
            ],
        ],
    ]);

    expect($snapshot['title'])->toBe('Final title');
    expect($snapshot['content'])->toContain('[truncated for workflow refinement payload]');
    expect($snapshot['excerpt'])->toBe('Final excerpt');
    expect($snapshot['meta']['TRANSLATION_REVIEW'])->toBe('Looks better.');
    expect($snapshot['previous_steps']['step_review']['feedback'])->toBe('Too literal.');
});

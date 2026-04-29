<?php

declare(strict_types=1);

use PolyTrans\PostProcessing\Testing\WorkflowRefinementService;

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
    expect($context['target_step']['run']['interpolated_system_prompt'])->toBe('System prompt');
    expect($context['previous_steps'])->toHaveCount(1);
    expect($context['previous_steps'][0]['id'])->toBe('step_review');
    expect($context['following_steps'])->toBe([]);
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

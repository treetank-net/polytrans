<?php

declare(strict_types=1);

use PolyTrans\Assistants\Testing\AssistantRefinementService;

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags((string) $text);
    }
}

function invoke_assistant_refinement_method(string $methodName, ...$args)
{
    $service = new AssistantRefinementService();
    $method = new ReflectionMethod($service, $methodName);
    $method->setAccessible(true);

    return $method->invoke($service, ...$args);
}

it('builds final post candidate from structured assistant output', function () {
    $context = [
        'payload' => [
            'post' => [
                'title' => 'Original title',
                'content' => '<p>Original content</p>',
                'excerpt' => 'Original excerpt',
                'slug' => 'original-title',
                'meta' => [
                    'TRANSLATION_REVIEW' => 'Keep this unless output overrides it.',
                ],
            ],
        ],
    ];

    $candidate = invoke_assistant_refinement_method('buildFinalPostCandidate', [
        'title' => 'Translated title',
        'content' => '<p>Translated content</p>',
        'excerpt' => 'Translated excerpt',
    ], $context);

    expect($candidate)->toMatchArray([
        'title' => 'Translated title',
        'content' => '<p>Translated content</p>',
        'excerpt' => 'Translated excerpt',
        'slug' => 'original-title',
        'meta' => [
            'TRANSLATION_REVIEW' => 'Keep this unless output overrides it.',
        ],
    ]);
});

it('treats text assistant output as final content candidate', function () {
    $context = [
        'payload' => [
            'post' => [
                'title' => 'Existing title',
                'content' => '<p>Existing content</p>',
                'excerpt' => 'Existing excerpt',
                'slug' => 'existing-title',
                'meta' => ['review' => 'ok'],
            ],
        ],
    ];

    $candidate = invoke_assistant_refinement_method('buildFinalPostCandidate', 'Improved plain text.', $context);

    expect($candidate)->toMatchArray([
        'title' => 'Existing title',
        'content' => 'Improved plain text.',
        'excerpt' => 'Existing excerpt',
        'slug' => 'existing-title',
        'meta' => ['review' => 'ok'],
    ]);
});

it('uses assistant description as default refinement primary purpose', function () {
    $objective = invoke_assistant_refinement_method(
        'resolvePromptObjective',
        '',
        ['description' => '<p>Translate WordPress posts while preserving formatting.</p>']
    );

    expect($objective)->toBe('Translate WordPress posts while preserving formatting.');
    expect(invoke_assistant_refinement_method(
        'resolvePromptObjective',
        'Keep SEO rewrite behavior.',
        ['description' => 'Translate posts.']
    ))->toBe('Keep SEO rewrite behavior.');
});

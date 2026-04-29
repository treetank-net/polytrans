<?php

declare(strict_types=1);

use PolyTrans\Testing\PostTestContextBuilder;

it('builds translation-shaped context from raw text', function () {
    $context = PostTestContextBuilder::fromText(
        'The deadline for replacing tachographs is approaching.',
        '<p>Experts estimate that many tachographs remain to be replaced.</p>',
        'en',
        'pl'
    );

    expect($context['source_language'])->toBe('en');
    expect($context['target_language'])->toBe('pl');
    expect($context['translation_service'])->toBe('managed_assistant_test');
    expect($context['original']['title'])->toBe('The deadline for replacing tachographs is approaching.');
    expect($context['translated']['content'])->toContain('tachographs');
    expect($context['payload']['post']['meta'])->toBe([]);
    expect($context['payload']['translation']['service'])->toBe('managed_assistant_test');
});

it('builds compact UI context without duplicated aliases', function () {
    $context = PostTestContextBuilder::fromText('Title', '<p>Content</p>', 'pl', 'en');
    $compact = PostTestContextBuilder::compact($context);

    expect($compact)->toHaveKeys([
        'source_language',
        'target_language',
        'translation_service',
        'payload',
        'test_mode',
    ]);
    expect($compact)->not->toHaveKey('original');
    expect($compact)->not->toHaveKey('translated');
});

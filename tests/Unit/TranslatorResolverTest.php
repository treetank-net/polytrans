<?php

declare(strict_types=1);

use PolyTrans\Core\TranslatorResolver;

/*
 * A path rule can name a translator, so "All → All, translator X" is a default and
 * the per-pair mapping can stay empty. A step's own mapping still wins.
 */

it('uses the translator of an all-to-all rule for any pair', function () {
    $rules = [['source' => 'all', 'target' => 'all', 'intermediate' => '', 'assistant' => 'managed_1']];

    expect(TranslatorResolver::resolve_path('pl', 'de', $rules))->toBe(['pl', 'de']);
    expect(TranslatorResolver::rule_translator('pl', 'de', $rules))->toBe('managed_1');
    expect(TranslatorResolver::step_translator('pl', 'de', [], 'managed_1'))->toBe('managed_1');
});

it('lets an explicit pair mapping win over the rule translator', function () {
    expect(TranslatorResolver::step_translator('pl', 'de', ['pl_to_de' => 'provider_google'], 'managed_1'))
        ->toBe('provider_google');
    expect(TranslatorResolver::step_translator('pl', 'de', ['pl_to_de' => ''], 'managed_1'))
        ->toBe('managed_1');
});

it('prefers the more specific rule, and the later one on a tie', function () {
    $rules = [
        ['source' => 'all', 'target' => 'all', 'intermediate' => '', 'assistant' => 'managed_1'],
        ['source' => 'pl', 'target' => 'all', 'intermediate' => '', 'assistant' => 'managed_2'],
        ['source' => 'all', 'target' => 'all', 'intermediate' => '', 'assistant' => 'managed_3'],
    ];

    expect(TranslatorResolver::rule_translator('pl', 'de', $rules))->toBe('managed_2');
    expect(TranslatorResolver::rule_translator('en', 'de', $rules))->toBe('managed_3');
});

it('does not let a routing rule without a translator hide a broader default', function () {
    $rules = [
        ['source' => 'all', 'target' => 'all', 'intermediate' => '', 'assistant' => 'managed_1'],
        ['source' => 'pl', 'target' => 'de', 'intermediate' => 'en'],
    ];

    expect(TranslatorResolver::resolve_path('pl', 'de', $rules))->toBe(['pl', 'en', 'de']);
    expect(TranslatorResolver::rule_translator('pl', 'de', $rules))->toBe('managed_1');
});

it('covers both hops of a relayed translation with the rule translator', function () {
    $rules = [['source' => 'all', 'target' => 'all', 'intermediate' => 'en', 'assistant' => 'managed_1']];
    $mapping = ['en_to_de' => 'managed_7'];
    $fallback = TranslatorResolver::rule_translator('pl', 'de', $rules);

    expect(TranslatorResolver::resolve_path('pl', 'de', $rules))->toBe(['pl', 'en', 'de']);
    expect(TranslatorResolver::step_translator('pl', 'en', $mapping, $fallback))->toBe('managed_1');
    expect(TranslatorResolver::step_translator('en', 'de', $mapping, $fallback))->toBe('managed_7');
});

it('keeps the old behaviour when no rule names a translator', function () {
    $rules = [['source' => 'all', 'target' => 'all', 'intermediate' => 'en']];

    expect(TranslatorResolver::rule_translator('pl', 'de', $rules))->toBe('');
    expect(TranslatorResolver::step_translator('pl', 'en', [], ''))->toBe('');
    expect(TranslatorResolver::resolve_path('en', 'de', $rules))->toBe(['en', 'de']);
    expect(TranslatorResolver::resolve_path('pl', 'de', []))->toBe(['pl', 'de']);
});

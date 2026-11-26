<?php

declare(strict_types=1);

use PolyTrans\Core\TranslationPathExecutor;

/*
 * polytrans_translation_settings: an add-on routes one translation (by post, by
 * language pair) without touching the saved option. Observed through the path the
 * executor resolves, which is reported in the validation error before any call.
 */

if (!class_exists('PolyTrans_Logs_Manager')) {
    class PolyTrans_Logs_Manager
    {
        public static function log($message, $level = 'info', $context = [])
        {
        }
    }
}

afterEach(function () {
    unset($GLOBALS['polytrans_test_filters']['polytrans_translation_settings']);
});

$relay = ['translation_path_rules' => [['source' => 'all', 'target' => 'all', 'intermediate' => 'en']], 'assistants_mapping' => []];

it('relays through the configured intermediate language without a filter', function () use ($relay) {
    $result = TranslationPathExecutor::execute(['title' => 'x'], 'pl', 'de', $relay, ['source_post_id' => 5]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('pl_to_en');
});

it('passes languages and post context to the filter and uses what it returns', function () use ($relay) {
    $seen = null;
    $GLOBALS['polytrans_test_filters']['polytrans_translation_settings'] = function ($settings, $source, $target, $context) use (&$seen) {
        $seen = [$source, $target, $context['source_post_id'] ?? null];
        $settings['translation_path_rules'] = [];
        return $settings;
    };

    $result = TranslationPathExecutor::execute(['title' => 'x'], 'pl', 'de', $relay, ['source_post_id' => 5]);

    expect($seen)->toBe(['pl', 'de', 5]);
    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('pl_to_de');
    expect($result['error'])->not->toContain('pl_to_en');
});

it('ignores a filter that returns something other than an array', function () use ($relay) {
    $GLOBALS['polytrans_test_filters']['polytrans_translation_settings'] = fn () => null;

    $result = TranslationPathExecutor::execute(['title' => 'x'], 'pl', 'de', $relay);

    expect($result['error'])->toContain('pl_to_en');
});

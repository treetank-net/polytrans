<?php

declare(strict_types=1);

/**
 * Unit Tests: Reasoning Effort settings field
 *
 * The site-wide effort selector shown on provider settings tabs. Levels come from
 * the capability layer, so the field must offer exactly what the selected model
 * accepts - and offer nothing at all for classic models.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Providers\ReasoningEffortField;

beforeEach(function () {
    ModelCapabilities::flush_cache();
});

describe('rendering', function () {
    it('offers the levels the selected model actually accepts', function () {
        $html = ReasoningEffortField::get_html('openai', 'gpt-5.6-luna', 'high');

        expect($html)->toContain('name="openai_reasoning_effort"');
        foreach (['none', 'low', 'medium', 'high', 'xhigh'] as $level) {
            expect($html)->toContain('value="' . $level . '"');
        }

        // "max" exists only on /responses, but the model does support it, so the
        // field must offer it - selecting it routes the request to that endpoint.
        expect($html)->toContain('value="max"');
    });

    it('marks the stored level as selected', function () {
        $html = ReasoningEffortField::get_html('openai', 'gpt-5.6-luna', 'xhigh');

        expect($html)->toMatch('/value="xhigh"\s+selected="selected"/');
    });

    it('always offers deferring to the provider default', function () {
        $html = ReasoningEffortField::get_html('openai', 'gpt-5.6-luna', '');

        expect($html)->toContain('value=""');
        expect($html)->toContain('Provider default');
    });

    it('hides itself for classic models rather than disappearing', function () {
        // Kept in the DOM so the JS can reveal it after a model switch.
        $html = ReasoningEffortField::get_html('openai', 'gpt-4o', '');

        expect($html)->toContain('display:none;');
        expect($html)->toContain('data-field="reasoning-effort"');
    });

    it('uses provider-native level naming', function () {
        $html = ReasoningEffortField::get_html('claude', 'claude-opus-4-6', 'high');

        expect($html)->toContain('name="claude_reasoning_effort"');
        expect($html)->toContain('value="max"');
    });

    it('carries a clean base description for the JS to append notes to', function () {
        $html = ReasoningEffortField::get_html('openai', 'gpt-5.6-luna', '');

        expect($html)->toContain('data-base-description="');
        // The attribute must not already include the model-specific note, or the
        // JS would compound notes on every model change.
        expect($html)->not->toMatch('/data-base-description="[^"]*reasoning_effort/');
    });
});

describe('sanitization', function () {
    it('stores a supported level canonically', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', 'xhigh'))->toBe('xhigh');
    });

    it('keeps a level that only one of the model\'s surfaces provides', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', 'max'))->toBe('max');
    });

    it('snaps a level no surface of the model provides', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.1', 'max'))->toBe('high');
    });

    it('accepts an empty value as "provider default"', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', ''))->toBe('');
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', '   '))->toBe('');
    });

    it('drops a level for models with no reasoning control', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-4o', 'high'))->toBe('');
    });

    it('keeps the value when no model is chosen yet', function () {
        // Nothing to validate against; losing the setting would be worse.
        expect(ReasoningEffortField::sanitize('openai', '', 'high'))->toBe('high');
    });

    it('rejects non-string input', function () {
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', ['high']))->toBe('');
        expect(ReasoningEffortField::sanitize('openai', 'gpt-5.6-luna', null))->toBe('');
    });
});

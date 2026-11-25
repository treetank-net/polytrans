<?php

declare(strict_types=1);

/**
 * Unit Tests: AssistantAvailability
 *
 * The rule that decides whether a managed assistant can be picked for a
 * translation. The picker lists unavailable assistants disabled with this
 * reason instead of hiding them.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Assistants\AssistantAvailability;

const PROVIDER_NAMES = ['openai' => 'OpenAI', 'claude' => 'Claude'];

describe('AssistantAvailability::unavailable_reason', function () {
    it('accepts an OpenAI assistant when OpenAI is enabled and a key is set', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'openai'], ['openai'], 'sk-test');

        expect($reason)->toBeNull();
    });

    it('names the provider when it is not enabled', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'openai'], ['claude'], 'sk-test', PROVIDER_NAMES);

        expect($reason)->toBe('provider OpenAI is not enabled');
    });

    it('falls back to the capitalised id for an unregistered provider', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'mistral'], ['openai'], 'sk-test', PROVIDER_NAMES);

        expect($reason)->toBe('provider Mistral is not enabled');
    });

    it('reports a missing OpenAI key once the provider is enabled', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'openai'], ['openai'], '');

        expect($reason)->toBe('no OpenAI API key');
    });

    it('reports the disabled provider first when both are wrong', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'openai'], [], '', PROVIDER_NAMES);

        expect($reason)->toBe('provider OpenAI is not enabled');
    });

    it('does not require an OpenAI key for other providers', function () {
        $reason = AssistantAvailability::unavailable_reason(['provider' => 'claude'], ['claude'], '');

        expect($reason)->toBeNull();
    });

    it('treats an assistant without a provider as OpenAI, as the picker always did', function () {
        $reason = AssistantAvailability::unavailable_reason([], ['openai'], '');

        expect($reason)->toBe('no OpenAI API key');
    });
});

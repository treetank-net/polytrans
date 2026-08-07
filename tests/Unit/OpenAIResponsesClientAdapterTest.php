<?php

declare(strict_types=1);

/**
 * Unit Tests: OpenAI Responses client adapter
 *
 * /responses differs from Chat Completions in request shape and response shape.
 * These tests pin the translation between the two, since callers hand this adapter
 * a Chat Completions style parameter bag and expect ChatClientInterface semantics.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Providers\OpenAI\OpenAIResponsesClientAdapter;

beforeEach(function () {
    ModelCapabilities::flush_cache();
    unset($GLOBALS['polytrans_test_options']['polytrans_settings']);
});

/**
 * Build a request body the way chat_completion() does, without the HTTP call.
 */
function responses_body(string $model, array $parameters, $messages = null): array
{
    $messages = $messages ?? [
        ['role' => 'system', 'content' => 'You translate.'],
        ['role' => 'user', 'content' => 'Translate as json.'],
    ];

    $adapter = new OpenAIResponsesClientAdapter('test-key');
    $prepared = ModelCapabilities::prepare_chat_parameters(
        'openai',
        $model,
        array_merge(['model' => $model], $parameters),
        ModelCapabilities::SURFACE_RESPONSES
    );

    $method = new ReflectionMethod($adapter, 'build_body');
    $method->setAccessible(true);

    return $method->invoke($adapter, $messages, $model, $prepared);
}

describe('request shape', function () {
    it('sends messages as input rather than messages', function () {
        $body = responses_body('gpt-5.6-luna', []);

        expect($body)->not->toHaveKey('messages');
        expect($body['input'])->toHaveCount(2);
        expect($body['input'][0]['role'])->toBe('system');
        expect($body['input'][1]['content'])->toBe('Translate as json.');
    });

    it('accepts a plain string prompt', function () {
        $body = responses_body('gpt-5.6-luna', [], 'just translate');

        expect($body['input'])->toBe('just translate');
    });

    it('drops malformed messages instead of sending them', function () {
        $body = responses_body('gpt-5.6-luna', [], [
            ['role' => 'user', 'content' => 'keep me'],
            ['content' => 'no role'],
            'not an array',
        ]);

        expect($body['input'])->toHaveCount(1);
        expect($body['input'][0]['content'])->toBe('keep me');
    });

    it('maps every token-limit spelling onto max_output_tokens without reasoning', function () {
        foreach (['max_tokens', 'max_completion_tokens', 'max_output_tokens'] as $key) {
            $body = responses_body('gpt-5.6-luna', [$key => 1234, 'reasoning_effort' => 'none']);

            expect($body['max_output_tokens'])->toBe(1234);
            expect($body)->not->toHaveKey('max_tokens');
            expect($body)->not->toHaveKey('max_completion_tokens');
        }
    });

    it('omits the token limit when reasoning is active', function () {
        // This budget covers reasoning tokens here, unlike max_tokens on Chat
        // Completions. Measured against the live API, a 4000 budget at `max` effort
        // was consumed entirely by reasoning and the reply came back empty, so the
        // configured limit must not be carried over. The parameter is optional.
        foreach (['max_tokens', 'max_completion_tokens', 'max_output_tokens'] as $key) {
            $body = responses_body('gpt-5.6-luna', [$key => 4000, 'reasoning_effort' => 'max']);

            expect($body)->not->toHaveKey('max_output_tokens');
            expect($body)->not->toHaveKey('max_tokens');
            expect($body)->not->toHaveKey('max_completion_tokens');
        }
    });

    it('moves response_format under text.format', function () {
        $body = responses_body('gpt-5.6-luna', ['response_format' => ['type' => 'json_object']]);

        expect($body)->not->toHaveKey('response_format');
        expect($body['text']['format'])->toBe(['type' => 'json_object']);
    });

    it('nests the reasoning effort', function () {
        // A flat "reasoning.effort" key is rejected by the API. Checked with
        // array_key_exists because Pest reads a dot in toHaveKey() as a nested path.
        $body = responses_body('gpt-5.6-luna', ['reasoning_effort' => 'max']);

        expect(array_key_exists('reasoning.effort', $body))->toBeFalse();
        expect($body['reasoning'])->toBe(['effort' => 'max']);
    });

    it('carries the max level that only this endpoint offers', function () {
        $body = responses_body('gpt-5.6-luna', ['reasoning_effort' => 'max']);

        expect($body['reasoning']['effort'])->toBe('max');
    });

    it('strips parameters this endpoint does not accept', function () {
        $body = responses_body('gpt-5.6-luna', ['n' => 2, 'stop' => ['x'], 'stream' => true]);

        foreach (['n', 'stop', 'stream'] as $key) {
            expect($body)->not->toHaveKey($key);
        }
    });

    it('drops temperature for a reasoning model', function () {
        $body = responses_body('gpt-5.6-luna', ['temperature' => 0.4, 'reasoning_effort' => 'high']);

        expect($body)->not->toHaveKey('temperature');
    });
});

describe('response parsing', function () {
    it('reads the assistant message past the reasoning item', function () {
        $adapter = new OpenAIResponsesClientAdapter('test-key');

        $content = $adapter->extract_content([
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [
                    ['type' => 'output_text', 'text' => '{"title":"Dzień dobry"}'],
                ]],
            ],
        ]);

        expect($content)->toBe('{"title":"Dzień dobry"}');
    });

    it('concatenates multiple text chunks', function () {
        $adapter = new OpenAIResponsesClientAdapter('test-key');

        $content = $adapter->extract_content([
            'output' => [
                ['type' => 'message', 'content' => [
                    ['type' => 'output_text', 'text' => 'part one '],
                    ['type' => 'output_text', 'text' => 'part two'],
                ]],
            ],
        ]);

        expect($content)->toBe('part one part two');
    });

    it('returns null when there is only reasoning and no message', function () {
        $adapter = new OpenAIResponsesClientAdapter('test-key');

        expect($adapter->extract_content(['output' => [['type' => 'reasoning']]]))->toBeNull();
        expect($adapter->extract_content(['choices' => []]))->toBeNull();
        expect($adapter->extract_content('not an array'))->toBeNull();
    });

    it('prefers the output_text convenience field when present', function () {
        $adapter = new OpenAIResponsesClientAdapter('test-key');

        expect($adapter->extract_content(['output_text' => 'short circuit', 'output' => []]))
            ->toBe('short circuit');
    });
});

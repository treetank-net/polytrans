<?php

declare(strict_types=1);

/**
 * Unit Tests: Which model a managed assistant reports having used
 *
 * This name is what the price lookup keys on, so getting it wrong does not fail
 * loudly - the call is simply recorded with no cost and shows up on the dashboard as
 * "unpriced". Reading it from the assistant's configuration produced exactly that:
 * an assistant with no model of its own inherits the provider default, leaving the
 * configured field empty.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Assistants\AssistantExecutor;

/**
 * Calls the private resolver directly: it has no observable effect other than the
 * name it returns, and reaching it through execute_with_config() would mean standing
 * up a provider client.
 *
 * @param array $api_response Raw provider response.
 * @param array $config       Assistant configuration.
 */
function polytrans_resolve_model(array $api_response, array $config): string
{
    $method = new ReflectionMethod(AssistantExecutor::class, 'resolve_reported_model');
    $method->setAccessible(true);

    return $method->invoke(null, $api_response, $config);
}

beforeEach(function () {
    $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
        'openai_model' => 'gpt-5.6-luna',
        'gemini_model' => 'gemini-3.5-flash',
    ];
});

describe('resolving the model that served the request', function () {
    it('prefers what the response reports over what was configured', function () {
        // Providers resolve an alias to a concrete dated build, and that is the name
        // the price list should be asked about.
        $model = polytrans_resolve_model(
            ['model' => 'gpt-4o-mini-2024-07-18'],
            ['api_parameters' => ['model' => 'gpt-4o-mini']]
        );

        expect($model)->toBe('gpt-4o-mini-2024-07-18');
    });

    it('falls back to the configured model when the response names none', function () {
        $model = polytrans_resolve_model(
            ['usage' => ['prompt_tokens' => 10]],
            ['api_parameters' => ['model' => 'claude-sonnet-4-5']]
        );

        expect($model)->toBe('claude-sonnet-4-5');
    });

    it('falls back to the provider default when the assistant configures no model', function () {
        // This is the ordinary case for a managed assistant, and the one that used to
        // record an empty model: an empty string is present, so ?? never fired.
        $model = polytrans_resolve_model(
            [],
            ['provider' => 'openai', 'api_parameters' => ['model' => '']]
        );

        expect($model)->toBe('gpt-5.6-luna');
    });

    it('resolves the default of the assistant\'s own provider, not OpenAI\'s', function () {
        $model = polytrans_resolve_model(
            [],
            ['provider' => 'gemini', 'api_parameters' => []]
        );

        expect($model)->toBe('gemini-3.5-flash');
    });

    it('returns an empty name rather than inventing one', function () {
        // 'unknown' used to be recorded here, which reads on the dashboard as a model
        // by that name and cannot be told apart from a real one.
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [];

        $model = polytrans_resolve_model([], ['provider' => 'openai']);

        expect($model)->toBe('');
    });

    it('ignores a non-string model in the response', function () {
        $model = polytrans_resolve_model(
            ['model' => ['gpt-4o']],
            ['api_parameters' => ['model' => 'gpt-4o-mini']]
        );

        expect($model)->toBe('gpt-4o-mini');
    });
});

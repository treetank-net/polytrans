<?php

declare(strict_types=1);

use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Providers\Claude\ClaudeSettingsProvider;
use PolyTrans\Providers\OpenAI\OpenAISettingsProvider;

/*
 * A model without a dropdown group is silently dropped from the model list, even when
 * ModelCapabilities knows how to call it. New generations must still get a group.
 */

function polytrans_model_group(string $class, string $model): string
{
    $method = new ReflectionMethod($class, 'get_model_group');
    $method->setAccessible(true);
    return (string) $method->invoke((new ReflectionClass($class))->newInstanceWithoutConstructor(), $model);
}

it('groups GPT-6 and later instead of dropping them', function () {
    expect(polytrans_model_group(OpenAISettingsProvider::class, 'gpt-6'))->toBe('GPT-6 Models');
    expect(polytrans_model_group(OpenAISettingsProvider::class, 'gpt-6.1-mini'))->toBe('GPT-6 Models');
    expect(polytrans_model_group(OpenAISettingsProvider::class, 'gpt-5.6-luna'))->toBe('GPT-5 Models');
    expect(polytrans_model_group(OpenAISettingsProvider::class, 'gpt-4o-mini'))->toBe('GPT-4o Models');
});

it('treats GPT-6 as a usable reasoning model', function () {
    expect(ModelCapabilities::is_model_usable('openai', 'gpt-6'))->toBeTrue();
});

it('groups the Claude 5 family and Fable instead of dropping them', function () {
    expect(polytrans_model_group(ClaudeSettingsProvider::class, 'claude-opus-5-5'))->toBe('Claude Opus 5 Models');
    expect(polytrans_model_group(ClaudeSettingsProvider::class, 'claude-sonnet-5-5'))->toBe('Claude Sonnet 5 Models');
    expect(polytrans_model_group(ClaudeSettingsProvider::class, 'claude-haiku-5-5'))->toBe('Claude Haiku 5 Models');
    expect(polytrans_model_group(ClaudeSettingsProvider::class, 'claude-fable-5-2'))->toBe('Claude Fable 5 Models');
    expect(polytrans_model_group(ClaudeSettingsProvider::class, 'claude-sonnet-4-5'))->toBe('Claude Sonnet 4 Models');
});

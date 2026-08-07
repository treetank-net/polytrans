<?php

declare(strict_types=1);

/**
 * Unit Tests: Model pricing
 *
 * Costs are estimated from OpenRouter's catalogue, since no provider publishes
 * prices through its own API. Two things must not go wrong: reasoning tokens
 * must not be billed twice, and an unpriced model must report nothing rather
 * than zero - a zero would silently understate every total it lands in.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\ModelPricing;

// Own backing store, matching how the other test files stub transients; going
// through the functions keeps this working whichever file defined them first.
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        $GLOBALS['polytrans_pricing_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['polytrans_pricing_transients'][$key] ?? false;
    }
}

/**
 * Prices as published for these models, trimmed to the fields we read.
 */
function seed_pricing_catalog(): void
{
    set_transient(ModelPricing::TRANSIENT_KEY, [
        'openai/gpt-5.6-luna' => [
            'input' => '0.0000001',
            'output' => '0.0000006',
            'cached_read' => '0.00000001',
            'cached_write' => '0.000000125',
            'reasoning' => null,
        ],
        'openai/gpt-5.2' => [
            'input' => '0.00000125',
            'output' => '0.00001',
            'cached_read' => '0.000000125',
            'cached_write' => null,
            'reasoning' => null,
        ],
        'anthropic/claude-opus-4.6' => [
            'input' => '0.000005',
            'output' => '0.000025',
            'cached_read' => '0.0000005',
            'cached_write' => '0.00000625',
            'reasoning' => null,
        ],
        'google/gemini-3.5-flash' => [
            'input' => '0.0000015',
            'output' => '0.000009',
            'cached_read' => '0.00000015',
            'cached_write' => '0.00000008',
            'reasoning' => '0.000009',
        ],
    ], 0);
}

beforeEach(function () {
    set_transient(ModelPricing::TRANSIENT_KEY, false, 0);
    $GLOBALS['polytrans_test_filters'] = [];
    unset($GLOBALS['polytrans_test_http_get']);
    ModelPricing::flush_cache();
    seed_pricing_catalog();
});

describe('model name mapping', function () {
    it('maps a provider model onto the catalogue slug', function () {
        expect(ModelPricing::resolve_catalog_key('openai', 'gpt-5.6-luna'))->toBe('openai/gpt-5.6-luna');
    });

    it('prices a dated snapshot as its base model', function () {
        expect(ModelPricing::resolve_catalog_key('openai', 'gpt-5.2-2026-01-30'))->toBe('openai/gpt-5.2');
    });

    it('rewrites Anthropic version dashes as dots', function () {
        // The API says claude-opus-4-6; the catalogue says claude-opus-4.6.
        expect(ModelPricing::resolve_catalog_key('claude', 'claude-opus-4-6'))
            ->toBe('anthropic/claude-opus-4.6');
        expect(ModelPricing::resolve_catalog_key('claude', 'claude-opus-4-6-20260514'))
            ->toBe('anthropic/claude-opus-4.6');
    });

    it('strips the Gemini models/ prefix', function () {
        expect(ModelPricing::resolve_catalog_key('gemini', 'models/gemini-3.5-flash'))
            ->toBe('google/gemini-3.5-flash');
    });

    it('returns null for a model the catalogue does not list', function () {
        expect(ModelPricing::resolve_catalog_key('openai', 'gpt-nonexistent'))->toBeNull();
    });
});

describe('usage normalization', function () {
    it('reads the Chat Completions shape', function () {
        $tokens = ModelPricing::normalize_usage('openai', [
            'prompt_tokens' => 1751,
            'completion_tokens' => 300,
            'prompt_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1748],
        ]);

        expect($tokens['input'])->toBe(1751);
        expect($tokens['output'])->toBe(300);
        expect($tokens['cached_write'])->toBe(1748);
    });

    it('reads the /responses shape, which names the same numbers differently', function () {
        $tokens = ModelPricing::normalize_usage('openai', [
            'input_tokens' => 1751,
            'input_tokens_details' => ['cached_tokens' => 1748],
            'output_tokens' => 13382,
            'output_tokens_details' => ['reasoning_tokens' => 9995],
        ]);

        expect($tokens['input'])->toBe(1751);
        expect($tokens['output'])->toBe(13382);
        expect($tokens['reasoning'])->toBe(9995);
        // Only the uncached remainder costs full price.
        expect($tokens['input_uncached'])->toBe(3);
    });

    it('reads the Claude shape with its separate cache counters', function () {
        $tokens = ModelPricing::normalize_usage('claude', [
            'input_tokens' => 100,
            'output_tokens' => 200,
            'cache_read_input_tokens' => 60,
            'cache_creation_input_tokens' => 40,
        ]);

        expect($tokens['cached_read'])->toBe(60);
        expect($tokens['cached_write'])->toBe(40);
        expect($tokens['input_uncached'])->toBe(40);
    });

    it('records that OpenAI and Claude count reasoning inside the output', function () {
        expect(ModelPricing::normalize_usage('openai', [])['reasoning_in_output'])->toBeTrue();
        expect(ModelPricing::normalize_usage('claude', [])['reasoning_in_output'])->toBeTrue();
    });

    it('records that Gemini counts thoughts outside the output', function () {
        $tokens = ModelPricing::normalize_usage('gemini', [
            'promptTokenCount' => 1751,
            'candidatesTokenCount' => 3387,
            'thoughtsTokenCount' => 9995,
        ]);

        expect($tokens['reasoning_in_output'])->toBeFalse();
        expect($tokens['reasoning'])->toBe(9995);
        expect($tokens['output'])->toBe(3387);
    });
});

describe('cost estimation', function () {
    it('prices a real reasoning run without double-billing the thinking', function () {
        // Measured live: gpt-5.6-luna at max effort on an 8.5k-character article.
        $result = ModelPricing::estimate_cost('openai', 'gpt-5.6-luna', [
            'input_tokens' => 1751,
            'input_tokens_details' => ['cached_tokens' => 1748],
            'output_tokens' => 13382,
            'output_tokens_details' => ['reasoning_tokens' => 9995],
        ]);

        // 3 uncached input + 1748 cached + 13382 output; the 9995 reasoning tokens
        // are already inside that output count and must not be added again.
        $expected = (3 * 0.0000001) + (1748 * 0.00000001) + (13382 * 0.0000006);
        expect((float) $result['cost_usd'])->toBe(round($expected, 10));
        expect($result['source'])->toBe(ModelPricing::SOURCE_CATALOG);
    });

    it('bills Gemini thoughts on top, since they sit outside the output count', function () {
        $result = ModelPricing::estimate_cost('gemini', 'gemini-3.5-flash', [
            'promptTokenCount' => 1751,
            'candidatesTokenCount' => 3387,
            'thoughtsTokenCount' => 9995,
        ]);

        $expected = (1751 * 0.0000015) + (3387 * 0.000009) + (9995 * 0.000009);
        expect((float) $result['cost_usd'])->toBe(round($expected, 10));
    });

    it('charges cached input at its own cheaper rate', function () {
        $cached = ModelPricing::estimate_cost('openai', 'gpt-5.6-luna', [
            'input_tokens' => 1000,
            'input_tokens_details' => ['cached_tokens' => 1000],
            'output_tokens' => 0,
        ]);
        $uncached = ModelPricing::estimate_cost('openai', 'gpt-5.6-luna', [
            'input_tokens' => 1000,
            'output_tokens' => 0,
        ]);

        expect((float) $cached['cost_usd'])->toBeLessThan((float) $uncached['cost_usd']);
    });

    it('reports no cost rather than zero for an unpriced model', function () {
        // A zero would quietly understate every total it is summed into.
        $result = ModelPricing::estimate_cost('openai', 'gpt-nonexistent', ['input_tokens' => 5000]);

        expect($result['cost_usd'])->toBeNull();
        expect($result['source'])->toBe(ModelPricing::SOURCE_UNKNOWN);
    });

    it('keeps full precision instead of rounding to cents', function () {
        $result = ModelPricing::estimate_cost('openai', 'gpt-5.6-luna', ['output_tokens' => 1]);

        expect($result['cost_usd'])->toBe('0.0000006000');
    });

    it('lets a filter override the catalogue', function () {
        $GLOBALS['polytrans_test_filters']['polytrans_model_pricing'] = function () {
            return ['input' => '0.000002', 'output' => '0.000004'];
        };

        $result = ModelPricing::estimate_cost('openai', 'gpt-5.6-luna', ['output_tokens' => 1000]);

        expect((float) $result['cost_usd'])->toBe(0.004);
        expect($result['source'])->toBe(ModelPricing::SOURCE_OVERRIDE);
    });
});

describe('catalogue retrieval', function () {
    it('serves a stale copy when the refresh fails', function () {
        // Losing pricing entirely is worse than pricing from yesterday's table.
        $GLOBALS['polytrans_test_http_get'] = fn () => new WP_Error('down', 'unreachable');
        ModelPricing::flush_cache();

        expect(ModelPricing::get_catalog(true))->toHaveKey('openai/gpt-5.6-luna');
    });

    it('parses the published catalogue shape and skips batch SKUs', function () {
        set_transient(ModelPricing::TRANSIENT_KEY, false, 0);
        ModelPricing::flush_cache();
        $GLOBALS['polytrans_test_http_get'] = fn () => [
            'response' => ['code' => 200],
            'body' => json_encode(['data' => [
                [
                    'id' => 'openai/gpt-5.6-luna',
                    'context_length' => 1050000,
                    'pricing' => [
                        'prompt' => '0.0000001',
                        'completion' => '0.0000006',
                        'input_cache_read' => '0.00000001',
                    ],
                ],
                [
                    'id' => 'openai/gpt-5.6-luna:batch',
                    'pricing' => ['prompt' => '0.00000005', 'completion' => '0.0000003'],
                ],
            ]]),
        ];

        $catalog = ModelPricing::get_catalog(true);

        expect($catalog)->toHaveKey('openai/gpt-5.6-luna');
        expect($catalog)->not->toHaveKey('openai/gpt-5.6-luna:batch');
        expect($catalog['openai/gpt-5.6-luna']['cached_read'])->toBe('0.00000001');
    });
});

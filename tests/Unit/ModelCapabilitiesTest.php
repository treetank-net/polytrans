<?php

declare(strict_types=1);

/**
 * Unit Tests: Model Capabilities
 *
 * Verifies the provider-independent knowledge about temperature vs reasoning
 * effort, including the provider-native naming of effort levels.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\ModelCapabilities;

beforeEach(function () {
    ModelCapabilities::flush_cache();
});

describe('temperature support', function () {
    it('keeps temperature for classic OpenAI models', function () {
        expect(ModelCapabilities::supports_temperature('openai', 'gpt-4o'))->toBeTrue();
        expect(ModelCapabilities::supports_reasoning_effort('openai', 'gpt-4o'))->toBeFalse();

        $capabilities = ModelCapabilities::get_model_capabilities('openai', 'gpt-4o');
        expect($capabilities['temperature']['max'])->toBe(2.0);
    });

    it('rejects temperature for OpenAI reasoning models', function () {
        foreach (['gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'o1', 'o3', 'o4-mini'] as $model) {
            expect(ModelCapabilities::supports_temperature('openai', $model))->toBeFalse();
            expect(ModelCapabilities::supports_reasoning_effort('openai', $model))->toBeTrue();
        }
    });

    it('allows a temperature on GPT-5.1+ only while reasoning is off', function () {
        foreach (['gpt-5.1', 'gpt-5.2', 'gpt-5.4', 'gpt-5.5', 'gpt-5.6-sol'] as $model) {
            $capabilities = ModelCapabilities::get_model_capabilities('openai', $model);

            expect($capabilities['temperature']['supported'])->toBeTrue();
            expect($capabilities['temperature']['requires_effort_none'])->toBeTrue();
            expect($capabilities['reasoning']['disables_temperature'])->toBeTrue();
            expect(array_keys($capabilities['reasoning']['levels']))->toContain('none');
        }
    });

    it('turns reasoning off explicitly when a temperature is requested', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.4', [
            'temperature' => 0.5,
        ]);

        // Sending the temperature without an explicit "none" would be a 400.
        expect($prepared['parameters']['temperature'])->toBe(0.5);
        expect($prepared['reasoning']['param'])->toBe('reasoning_effort');
        expect($prepared['reasoning']['value'])->toBe('none');
        expect(ModelCapabilities::is_reasoning_active($prepared['reasoning']))->toBeFalse();
    });

    it('drops the temperature when reasoning is explicitly enabled', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.4', [
            'temperature' => 0.5,
            'reasoning_effort' => 'high',
        ]);

        expect($prepared['parameters'])->not->toHaveKey('temperature');
        expect($prepared['reasoning']['value'])->toBe('high');
    });

    it('treats unknown models as classic temperature models', function () {
        expect(ModelCapabilities::supports_temperature('somecustomprovider', 'weird-model-1'))->toBeTrue();
        expect(ModelCapabilities::supports_reasoning_effort('somecustomprovider', 'weird-model-1'))->toBeFalse();
    });

    it('clamps temperature into the model specific range', function () {
        expect(ModelCapabilities::resolve_temperature('openai', 'gpt-4o', 5))->toBe(2.0);
        expect(ModelCapabilities::resolve_temperature('claude', 'claude-3-5-sonnet-20241022', 1.8))->toBe(1.0);
        expect(ModelCapabilities::resolve_temperature('openai', 'gpt-4o', -1))->toBe(0.0);
    });

    it('drops temperature for models that do not accept it', function () {
        expect(ModelCapabilities::resolve_temperature('openai', 'gpt-5', 0.2))->toBeNull();
    });
});

describe('provider specific effort naming', function () {
    it('exposes OpenAI reasoning_effort values', function () {
        $capabilities = ModelCapabilities::get_model_capabilities('openai', 'gpt-5');

        expect($capabilities['reasoning']['param'])->toBe('reasoning_effort');
        expect($capabilities['reasoning']['mode'])->toBe(ModelCapabilities::MODE_EFFORT);
        expect(array_keys($capabilities['reasoning']['levels']))->toBe(['minimal', 'low', 'medium', 'high']);
    });

    it('adds the none level for GPT-5.1 and newer', function () {
        $values = array_column(ModelCapabilities::get_effort_levels('openai', 'gpt-5.1'), 'value');

        expect($values)->toBe(['none', 'low', 'medium', 'high']);
    });

    it('adds the xhigh level for GPT-5.2 and newer', function () {
        foreach (['gpt-5.2', 'gpt-5.4-mini', 'gpt-5.5', 'gpt-5.6-terra'] as $model) {
            $values = array_column(ModelCapabilities::get_effort_levels('openai', $model), 'value');

            expect($values)->toBe(['none', 'low', 'medium', 'high', 'xhigh']);
        }
    });

    it('never offers max on OpenAI - chat completions has no such level', function () {
        foreach (['gpt-5', 'gpt-5.1', 'gpt-5.5', 'gpt-5.6-luna', 'o3'] as $model) {
            $values = array_column(ModelCapabilities::get_effort_levels('openai', $model), 'value');

            expect($values)->not->toContain('max');
        }
    });

    it('offers xhigh on the o-series', function () {
        foreach (['o1', 'o3', 'o3-mini', 'o4-mini'] as $model) {
            $values = array_column(ModelCapabilities::get_effort_levels('openai', $model), 'value');

            expect($values)->toBe(['low', 'medium', 'high', 'xhigh']);
        }
    });

    it('maps effort to a Gemini thinking level', function () {
        $capabilities = ModelCapabilities::get_model_capabilities('gemini', 'gemini-3-pro-preview');

        expect($capabilities['reasoning']['param'])->toBe('thinkingLevel');
        expect($capabilities['reasoning']['mode'])->toBe(ModelCapabilities::MODE_THINKING_LEVEL);
        expect(array_keys($capabilities['reasoning']['levels']))->toBe(['low', 'medium', 'high']);
        // Gemini still accepts temperature.
        expect($capabilities['temperature']['supported'])->toBeTrue();
    });

    it('adds the minimal level for Gemini flash models', function () {
        $values = array_column(ModelCapabilities::get_effort_levels('gemini', 'gemini-3.5-flash'), 'value');

        expect($values)->toBe(['minimal', 'low', 'medium', 'high']);
    });

    it('keeps the thinking budget on Gemini 2.5, which rejects thinkingLevel', function () {
        $reasoning = ModelCapabilities::resolve_reasoning('gemini', 'gemini-2.5-flash-lite', 'medium');

        expect($reasoning['mode'])->toBe(ModelCapabilities::MODE_THINKING_BUDGET);
        expect($reasoning['param'])->toBe('thinkingBudget');
        expect($reasoning['value'])->toBe(8192);
    });

    it('never disables thinking on Gemini 2.5 Pro or on Gemini 3', function () {
        foreach (['gemini-2.5-pro', 'gemini-3-pro-preview', 'gemini-3.6-flash'] as $model) {
            $values = array_column(ModelCapabilities::get_effort_levels('gemini', $model), 'value');

            expect($values)->not->toContain('none');
        }

        // Gemini 2.5 Flash is the exception - a zero budget turns thinking off.
        $flash = array_column(ModelCapabilities::get_effort_levels('gemini', 'gemini-2.5-flash-lite'), 'value');
        expect($flash)->toContain('none');
    });

    it('maps effort to Claude output_config.effort and drops temperature', function () {
        $capabilities = ModelCapabilities::get_model_capabilities('claude', 'claude-opus-5');

        expect($capabilities['reasoning']['mode'])->toBe(ModelCapabilities::MODE_EFFORT);
        expect($capabilities['reasoning']['param'])->toBe('output_config.effort');
        expect(array_keys($capabilities['reasoning']['levels']))
            ->toBe(['low', 'medium', 'high', 'xhigh', 'max']);
        // Temperature is deprecated on adaptive-thinking-only models.
        expect($capabilities['temperature']['supported'])->toBeFalse();
        expect($capabilities['reasoning']['default'])->toBe('high');
    });

    it('keeps temperature next to effort on Claude 4.6', function () {
        $capabilities = ModelCapabilities::get_model_capabilities('claude', 'claude-opus-4-6');

        expect($capabilities['reasoning']['param'])->toBe('output_config.effort');
        expect(array_keys($capabilities['reasoning']['levels']))->toBe(['low', 'medium', 'high', 'max']);
        expect($capabilities['temperature']['supported'])->toBeTrue();
        expect(ModelCapabilities::resolve_temperature('claude', 'claude-opus-4-6', 0.4, true))->toBe(0.4);
    });

    it('maps effort to a Claude thinking budget on models without effort support', function () {
        $reasoning = ModelCapabilities::resolve_reasoning('claude', 'claude-sonnet-4-5', 'high');

        expect($reasoning['param'])->toBe('budget_tokens');
        expect($reasoning['value'])->toBe(16384);
        expect($reasoning['disables_temperature'])->toBeTrue();

        // Temperature stays available only while thinking is off.
        expect(ModelCapabilities::resolve_temperature('claude', 'claude-sonnet-4-5', 0.4, false))->toBe(0.4);
        expect(ModelCapabilities::resolve_temperature('claude', 'claude-sonnet-4-5', 0.4, true))->toBeNull();
    });

    it('shows the native value in level labels', function () {
        $openai = ModelCapabilities::get_effort_levels('openai', 'gpt-5');
        expect($openai[0]['label'])->toContain('minimal');

        $claude = ModelCapabilities::get_effort_levels('claude', 'claude-opus-4-1');
        $high = array_values(array_filter($claude, fn ($level) => $level['value'] === 'high'))[0];
        expect($high['label'])->toContain('16');
    });
});

describe('effort normalization', function () {
    it('accepts canonical values', function () {
        expect(ModelCapabilities::normalize_effort('openai', 'gpt-5', 'high'))->toBe('high');
    });

    it('snaps unsupported canonical values to the nearest level', function () {
        // Gemini 3 Pro has no minimal - low is the closest.
        expect(ModelCapabilities::normalize_effort('gemini', 'gemini-3-pro-preview', 'minimal'))->toBe('low');
        // ...and no xhigh either.
        expect(ModelCapabilities::normalize_effort('gemini', 'gemini-3-pro-preview', 'max'))->toBe('high');
        // GPT-5 has no none - minimal is the closest.
        expect(ModelCapabilities::normalize_effort('openai', 'gpt-5', 'none'))->toBe('minimal');
        // Claude 4.6 has max but no xhigh - the cheaper neighbour wins on a tie.
        expect(ModelCapabilities::normalize_effort('claude', 'claude-opus-4-6', 'xhigh'))->toBe('high');
    });

    it('accepts stored token budgets', function () {
        expect(ModelCapabilities::normalize_effort('claude', 'claude-sonnet-4-5', 2048))->toBe('low');
        expect(ModelCapabilities::normalize_effort('claude', 'claude-sonnet-4-5', '9000'))->toBe('medium');
    });

    it('returns null for models without reasoning control', function () {
        expect(ModelCapabilities::normalize_effort('openai', 'gpt-4o', 'high'))->toBeNull();
    });

    it('returns null for empty values', function () {
        expect(ModelCapabilities::normalize_effort('openai', 'gpt-5', ''))->toBeNull();
    });
});

describe('request parameter preparation', function () {
    it('replaces temperature with reasoning effort for reasoning models', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5', [
            'temperature' => 0.2,
            'reasoning_effort' => 'high',
            'max_tokens' => 1000,
        ]);

        expect($prepared['parameters'])->not->toHaveKey('temperature');
        expect($prepared['parameters'])->not->toHaveKey('reasoning_effort');
        expect($prepared['parameters']['max_tokens'])->toBe(1000);
        expect($prepared['reasoning']['param'])->toBe('reasoning_effort');
        expect($prepared['reasoning']['value'])->toBe('high');
    });

    it('keeps temperature for classic models and ignores effort', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-4o-mini', [
            'temperature' => 0.3,
            'reasoning_effort' => 'high',
        ]);

        expect($prepared['parameters']['temperature'])->toBe(0.3);
        expect($prepared['parameters'])->not->toHaveKey('reasoning_effort');
        expect($prepared['reasoning'])->toBeNull();
    });

    it('drops an unsupported temperature even when no effort is configured', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'o3-mini', [
            'temperature' => 0.2,
        ]);

        expect($prepared['parameters'])->not->toHaveKey('temperature');
        expect($prepared['reasoning'])->toBeNull();
    });

    it('leaves the provider default in place when effort is empty', function () {
        $prepared = ModelCapabilities::prepare_chat_parameters('gemini', 'gemini-2.5-flash', [
            'temperature' => 0.5,
            'reasoning_effort' => '',
        ]);

        expect($prepared['parameters']['temperature'])->toBe(0.5);
        expect($prepared['reasoning'])->toBeNull();
    });
});

describe('UI payload', function () {
    it('groups models by profile', function () {
        $payload = ModelCapabilities::get_capabilities_payload('openai', [
            'GPT-5 Models' => ['gpt-5' => 'GPT-5', 'gpt-5-mini' => 'GPT-5 Mini'],
            'GPT-4o Models' => ['gpt-4o' => 'GPT-4o'],
        ]);

        expect($payload['models']['gpt-5'])->toBe('openai-gpt-5');
        expect($payload['models']['gpt-5-mini'])->toBe('openai-gpt-5');
        expect($payload['models']['gpt-4o'])->toBe('openai-classic');
        expect($payload['profiles']['openai-gpt-5']['reasoning']['levels'])->toHaveCount(4);
        expect($payload['profiles']['openai-classic']['reasoning'])->toBeNull();
        expect($payload['profiles'])->toHaveKey($payload['fallback']);
    });

    it('normalizes gemini model names with the models/ prefix', function () {
        $payload = ModelCapabilities::get_capabilities_payload('gemini', ['models/gemini-3-pro-preview' => 'Gemini 3 Pro']);

        expect($payload['models'])->toHaveKey('gemini-3-pro-preview');
    });
});

describe('provider API metadata', function () {
    it('refines the temperature window from /models metadata', function () {
        ModelCapabilities::store_api_metadata('gemini', [
            'gemini-2.0-flash' => ['temperature' => 1.0, 'maxTemperature' => 1.5],
        ]);

        $capabilities = ModelCapabilities::get_model_capabilities('gemini', 'gemini-2.0-flash');

        expect($capabilities['temperature']['max'])->toBe(1.5);
        expect($capabilities['temperature']['default'])->toBe(1.0);

        ModelCapabilities::flush_cache();
    });

    it('trusts the provider when it reports no thinking support', function () {
        ModelCapabilities::store_api_metadata('gemini', [
            'gemini-2.5-flash' => ['thinking' => false],
        ]);

        expect(ModelCapabilities::supports_reasoning_effort('gemini', 'gemini-2.5-flash'))->toBeFalse();

        ModelCapabilities::flush_cache();
    });

    it('takes the effort levels reported by Anthropic /v1/models', function () {
        // Shape produced by ClaudeSettingsProvider from the API `capabilities` object.
        ModelCapabilities::store_api_metadata('claude', [
            'claude-brand-new-9' => [
                'effort' => [
                    'supported' => true,
                    'levels' => ['low', 'high', 'max'],
                    'param' => 'output_config.effort',
                ],
                'thinking' => ['adaptive' => true, 'enabled' => false],
            ],
        ]);

        $capabilities = ModelCapabilities::get_model_capabilities('claude', 'claude-brand-new-9');

        expect($capabilities['reasoning']['mode'])->toBe(ModelCapabilities::MODE_EFFORT);
        expect($capabilities['reasoning']['param'])->toBe('output_config.effort');
        expect(array_keys($capabilities['reasoning']['levels']))->toBe(['low', 'high', 'max']);
        // Adaptive-thinking-only models reject temperature as deprecated.
        expect($capabilities['temperature']['supported'])->toBeFalse();

        ModelCapabilities::flush_cache();
    });

    it('drops effort when Anthropic reports it as unsupported', function () {
        ModelCapabilities::store_api_metadata('claude', [
            'claude-opus-4-6' => [
                'effort' => ['supported' => false, 'levels' => []],
                'thinking' => ['adaptive' => true, 'enabled' => true],
            ],
        ]);

        $capabilities = ModelCapabilities::get_model_capabilities('claude', 'claude-opus-4-6');

        expect($capabilities['reasoning'])->toBeNull();
        // Models that still accept an explicit thinking budget still accept a temperature.
        expect($capabilities['temperature']['supported'])->toBeTrue();

        ModelCapabilities::flush_cache();
    });

    it('drops a static thinking budget when the model has no enabled thinking mode', function () {
        ModelCapabilities::store_api_metadata('claude', [
            'claude-sonnet-4-5' => [
                'effort' => ['supported' => false, 'levels' => []],
                'thinking' => ['adaptive' => true, 'enabled' => false],
            ],
        ]);

        $capabilities = ModelCapabilities::get_model_capabilities('claude', 'claude-sonnet-4-5');

        expect($capabilities['reasoning'])->toBeNull();

        ModelCapabilities::flush_cache();
    });
});

describe('API surfaces', function () {
    it('serves the -pro and -codex models only through /responses', function () {
        foreach (['gpt-5-pro', 'gpt-5.5-pro', 'gpt-5.4-pro-2026-03-05', 'gpt-5.3-codex', 'o1-pro', 'o3-pro'] as $model) {
            expect(ModelCapabilities::get_api_surfaces('openai', $model))
                ->toBe([ModelCapabilities::SURFACE_RESPONSES]);
        }
    });

    it('serves the search models only through /chat/completions', function () {
        foreach (['gpt-4o-search-preview', 'gpt-5-search-api', 'gpt-3.5-turbo-16k'] as $model) {
            expect(ModelCapabilities::get_api_surfaces('openai', $model))
                ->toBe([ModelCapabilities::SURFACE_CHAT]);
        }
    });

    it('reports no surface for models that are not chat models', function () {
        $models = ['gpt-4o-mini-tts', 'gpt-4o-transcribe', 'gpt-realtime', 'gpt-image-1',
                   'gpt-audio', 'gpt-3.5-turbo-instruct', 'o3-deep-research'];

        foreach ($models as $model) {
            expect(ModelCapabilities::get_api_surfaces('openai', $model))->toBe([]);
        }
    });

    it('serves ordinary models through both surfaces', function () {
        foreach (['gpt-5.4', 'gpt-5.6-sol', 'gpt-4o', 'o3', 'o4-mini'] as $model) {
            expect(ModelCapabilities::get_api_surfaces('openai', $model))
                ->toBe([ModelCapabilities::SURFACE_CHAT, ModelCapabilities::SURFACE_RESPONSES]);
        }
    });

    it('treats other providers as a single messages endpoint', function () {
        expect(ModelCapabilities::get_api_surfaces('claude', 'claude-opus-5'))
            ->toBe([ModelCapabilities::SURFACE_CHAT]);
        expect(ModelCapabilities::get_api_surfaces('gemini', 'gemini-3.6-flash'))
            ->toBe([ModelCapabilities::SURFACE_CHAT]);
    });

    it('marks models unusable only when no adapter serves any of their surfaces', function () {
        expect(ModelCapabilities::is_model_usable('openai', 'gpt-5.4'))->toBeTrue();
        expect(ModelCapabilities::is_model_usable('openai', 'o3'))->toBeTrue();
        // Both surfaces have an adapter now, so the responses-only models are usable.
        expect(ModelCapabilities::is_model_usable('openai', 'gpt-5.5-pro'))->toBeTrue();
        expect(ModelCapabilities::is_model_usable('openai', 'gpt-5.3-codex'))->toBeTrue();
        // Still nothing to talk to for a TTS model.
        expect(ModelCapabilities::is_model_usable('openai', 'gpt-4o-mini-tts'))->toBeFalse();
    });

    it('lets a filter withdraw an implemented surface', function () {
        $GLOBALS['polytrans_test_filters']['polytrans_implemented_api_surfaces'] = function () {
            return [ModelCapabilities::SURFACE_CHAT];
        };

        expect(ModelCapabilities::is_model_usable('openai', 'gpt-5.5-pro'))->toBeFalse();
        expect(ModelCapabilities::is_model_usable('openai', 'gpt-5.4'))->toBeTrue();

        unset($GLOBALS['polytrans_test_filters']['polytrans_implemented_api_surfaces']);
    });
});

describe('extensibility', function () {
    it('can be corrected through the capabilities filter', function () {
        $GLOBALS['polytrans_test_filters']['polytrans_model_capabilities'] = function ($capabilities, $provider, $model) {
            if ($provider === 'claude' && strpos($model, 'claude-opus-4-5') === 0) {
                $capabilities['reasoning'] = [
                    'mode' => ModelCapabilities::MODE_EFFORT,
                    'param' => 'effort',
                    'default' => 'high',
                    'levels' => [
                        'low' => ['native' => 'low', 'label' => 'Low (low)'],
                        'medium' => ['native' => 'medium', 'label' => 'Medium (medium)'],
                        'high' => ['native' => 'high', 'label' => 'High (high)'],
                    ],
                ];
            }

            return $capabilities;
        };

        ModelCapabilities::flush_cache();

        $reasoning = ModelCapabilities::resolve_reasoning('claude', 'claude-opus-4-5', 'medium');

        expect($reasoning['param'])->toBe('effort');
        expect($reasoning['value'])->toBe('medium');

        unset($GLOBALS['polytrans_test_filters']['polytrans_model_capabilities']);
        ModelCapabilities::flush_cache();
    });
});

describe('site-wide default effort', function () {
    afterEach(function () {
        unset($GLOBALS['polytrans_test_options']['polytrans_settings']);
    });

    it('applies the configured effort when the caller says nothing', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'high',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.6-luna', []);

        expect($prepared['reasoning']['param'])->toBe('reasoning_effort');
        expect($prepared['reasoning']['value'])->toBe('high');
    });

    it('lets an explicit per-call effort win over the site-wide one', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'high',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.6-luna', [
            'reasoning_effort' => 'low',
        ]);

        expect($prepared['reasoning']['value'])->toBe('low');
    });

    it('wins over the temperature-implies-none inference', function () {
        // A caller's untouched default temperature must not silently disable
        // reasoning the admin deliberately asked for.
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'xhigh',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.6-luna', [
            'temperature' => 0.2,
        ]);

        expect($prepared['reasoning']['value'])->toBe('xhigh');
        expect($prepared['parameters'])->not->toHaveKey('temperature');
    });

    it('still turns reasoning off when nothing is configured', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.6-luna', [
            'temperature' => 0.2,
        ]);

        expect($prepared['reasoning']['value'])->toBe('none');
        expect($prepared['parameters']['temperature'])->toBe(0.2);
    });

    it('is ignored by classic models that have no reasoning control', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'high',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-4o', [
            'temperature' => 0.7,
        ]);

        expect($prepared['reasoning'])->toBeNull();
        expect($prepared['parameters']['temperature'])->toBe(0.7);
    });

    it('routes to the surface that has the configured level', function () {
        // "max" is absent from Chat Completions but present on /responses for 5.6.
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'max',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.6-luna', []);

        expect($prepared['surface'])->toBe(ModelCapabilities::SURFACE_RESPONSES);
        expect($prepared['reasoning']['value'])->toBe('max');
        expect($prepared['reasoning']['param'])->toBe('reasoning.effort');
    });

    it('snaps a level no surface of the model provides', function () {
        // GPT-5.1 tops out at high on both surfaces.
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'max',
        ];

        $prepared = ModelCapabilities::prepare_chat_parameters('openai', 'gpt-5.1', []);

        expect($prepared['surface'])->toBe(ModelCapabilities::SURFACE_CHAT);
        expect($prepared['reasoning']['value'])->toBe('high');
    });

    it('is read per provider', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = [
            'openai_reasoning_effort' => 'low',
            'claude_reasoning_effort' => 'max',
        ];

        expect(ModelCapabilities::get_configured_effort('openai'))->toBe('low');
        expect(ModelCapabilities::get_configured_effort('claude'))->toBe('max');
        expect(ModelCapabilities::get_configured_effort('gemini'))->toBe('');
    });
});

<?php

/**
 * Model Capabilities
 *
 * Provider-independent knowledge base describing how a given AI model expects
 * its "creativity / thinking" to be controlled:
 *
 *  - classic models take a `temperature` (float)
 *  - reasoning models reject `temperature` and take a reasoning effort instead
 *
 * Every provider names those effort levels differently, so PolyTrans stores a
 * canonical level (none/minimal/low/medium/high) and translates it to the
 * provider-native representation right before the API call:
 *
 *  - OpenAI ...... reasoning_effort: none|minimal|low|medium|high|xhigh (model dependent)
 *  - Gemini 3 .... generationConfig.thinkingConfig.thinkingLevel: minimal|low|medium|high
 *  - Gemini 2.5 .. generationConfig.thinkingConfig.thinkingBudget: token budget
 *  - Claude ...... output_config.effort: low|medium|high|xhigh|max
 *  - Claude (<=4.5, no effort) ... thinking.budget_tokens: token budget
 *
 * Temperature and effort are not always mutually exclusive. GPT-5.1+ accepts a
 * temperature only while reasoning is off, which the `requires_effort_none`
 * temperature flag models; Gemini and Claude 4.5/4.6 accept both at once.
 *
 * Providers that report capabilities from their own API take precedence over the
 * static table: Anthropic's /v1/models returns a machine-readable `capabilities`
 * object (per-level effort support, thinking modes) and Gemini's ListModels
 * reports its temperature window. Settings providers normalize that into the
 * schema documented on store_api_metadata().
 *
 * The knowledge base is intentionally declarative and can be extended or
 * corrected without touching the adapters, via the filters:
 *
 *  - `polytrans_model_capability_rules` ($rules_by_provider)
 *  - `polytrans_model_capabilities`     ($capabilities, $provider_id, $model_id)
 *
 * @package PolyTrans
 */

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

class ModelCapabilities
{
    /**
     * Canonical effort levels, ordered from cheapest to most thorough.
     */
    const LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Reasoning control modes.
     */
    const MODE_EFFORT = 'effort';                   // enum sent as-is (OpenAI reasoning_effort, Claude output_config.effort)
    const MODE_THINKING_LEVEL = 'thinking_level';   // enum sent as-is (Gemini thinkingLevel)
    const MODE_THINKING_BUDGET = 'thinking_budget'; // integer token budget (Claude <= 4.5 extended thinking)

    /**
     * Transient prefix used for capability hints harvested from provider /models endpoints.
     */
    const API_METADATA_TRANSIENT = 'polytrans_model_api_meta_';

    /**
     * Runtime cache of resolved capabilities.
     *
     * @var array<string, array>
     */
    private static $resolved = [];

    /**
     * Runtime cache of provider API metadata, keyed by provider ID.
     *
     * @var array<string, array>
     */
    private static $api_metadata = [];

    /**
     * Get full capability descriptor for a provider/model pair.
     *
     * @param string $provider_id Provider ID (openai, claude, gemini, ...).
     * @param string $model_id    Model ID. Empty means "provider default model".
     * @return array {
     *     @type string      $profile     Rule ID that matched.
     *     @type string      $provider    Provider ID.
     *     @type string      $model       Model ID.
     *     @type array       $temperature ['supported', 'min', 'max', 'step', 'default', 'note']
     *     @type array|null  $reasoning   ['mode', 'param', 'default', 'levels', 'disables_temperature', 'note']
     * }
     */
    public static function get_model_capabilities($provider_id, $model_id)
    {
        $provider_id = self::normalize_id($provider_id);
        $model_id = self::normalize_model_id($model_id);

        $cache_key = $provider_id . '|' . $model_id;
        if (isset(self::$resolved[$cache_key])) {
            return self::$resolved[$cache_key];
        }

        $rules = self::get_rules();
        $provider_rules = $rules[$provider_id] ?? [];

        $matched = null;
        foreach ($provider_rules as $rule) {
            if (self::rule_matches($rule, $model_id)) {
                $matched = $rule;
                break;
            }
        }

        if ($matched === null) {
            $matched = self::generic_rule($provider_id);
        }

        $capabilities = [
            'profile' => $matched['id'] ?? 'generic',
            'provider' => $provider_id,
            'model' => $model_id,
            'label' => $matched['label'] ?? '',
            'temperature' => self::normalize_temperature_spec($matched['temperature'] ?? []),
            'reasoning' => self::normalize_reasoning_spec($matched['reasoning'] ?? null),
        ];

        $capabilities = self::apply_api_metadata($capabilities);

        /**
         * Filter resolved model capabilities.
         *
         * @param array  $capabilities Capability descriptor.
         * @param string $provider_id  Provider ID.
         * @param string $model_id     Model ID.
         */
        $capabilities = apply_filters('polytrans_model_capabilities', $capabilities, $provider_id, $model_id);

        self::$resolved[$cache_key] = $capabilities;

        return $capabilities;
    }

    /**
     * Does this model accept a temperature parameter?
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return bool
     */
    public static function supports_temperature($provider_id, $model_id)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        return !empty($capabilities['temperature']['supported']);
    }

    /**
     * Does this model accept a reasoning effort setting?
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return bool
     */
    public static function supports_reasoning_effort($provider_id, $model_id)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        return !empty($capabilities['reasoning']);
    }

    /**
     * Get selectable effort levels for a model, labelled with provider-native naming.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return array List of ['value' => canonical, 'native' => native value, 'label' => string].
     */
    public static function get_effort_levels($provider_id, $model_id)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        if (empty($capabilities['reasoning']['levels'])) {
            return [];
        }

        $levels = [];
        foreach ($capabilities['reasoning']['levels'] as $canonical => $level) {
            $levels[] = [
                'value' => $canonical,
                'native' => $level['native'],
                'label' => $level['label'],
            ];
        }

        return $levels;
    }

    /**
     * Get the UI default effort level for a model (canonical value).
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return string|null
     */
    public static function get_default_effort($provider_id, $model_id)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        return $capabilities['reasoning']['default'] ?? null;
    }

    /**
     * Normalize a stored effort value to a canonical level supported by the model.
     *
     * Accepts canonical values, provider-native values and token budgets, so
     * configurations survive model switches within and across providers.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @param mixed  $value       Stored value.
     * @return string|null Canonical level or null when the model has no reasoning control.
     */
    public static function normalize_effort($provider_id, $model_id, $value)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        if (empty($capabilities['reasoning']['levels'])) {
            return null;
        }

        $levels = $capabilities['reasoning']['levels'];

        if ($value === null || $value === '' || (is_array($value))) {
            return null;
        }

        // Exact canonical match.
        if (is_string($value) && isset($levels[$value])) {
            return $value;
        }

        // Provider-native enum match (e.g. "minimal" on a model that maps it differently).
        if (is_string($value)) {
            foreach ($levels as $canonical => $level) {
                if (is_string($level['native']) && strcasecmp($level['native'], $value) === 0) {
                    return $canonical;
                }
            }
        }

        // Token budget match - snap to the closest configured budget.
        if (is_numeric($value)) {
            $budget = (int) $value;
            $best = null;
            $best_distance = null;
            foreach ($levels as $canonical => $level) {
                if (!is_numeric($level['native'])) {
                    continue;
                }
                $distance = abs(((int) $level['native']) - $budget);
                if ($best_distance === null || $distance < $best_distance) {
                    $best = $canonical;
                    $best_distance = $distance;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        // Canonical value the model does not support (e.g. "medium" on Gemini 3) - snap to nearest.
        if (is_string($value) && in_array($value, self::LEVELS, true)) {
            return self::snap_level($value, array_keys($levels));
        }

        return null;
    }

    /**
     * Resolve the provider-native reasoning payload for a canonical effort level.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @param mixed  $value       Stored effort value.
     * @return array|null ['mode', 'param', 'canonical', 'value'] or null when nothing should be sent.
     */
    public static function resolve_reasoning($provider_id, $model_id, $value)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        if (empty($capabilities['reasoning'])) {
            return null;
        }

        $canonical = self::normalize_effort($provider_id, $model_id, $value);
        if ($canonical === null) {
            return null;
        }

        $reasoning = $capabilities['reasoning'];

        return [
            'mode' => $reasoning['mode'],
            'param' => $reasoning['param'],
            'canonical' => $canonical,
            'value' => $reasoning['levels'][$canonical]['native'],
            'disables_temperature' => !empty($reasoning['disables_temperature']),
        ];
    }

    /**
     * Will the resolved reasoning plan actually make the model think?
     *
     * The `none` level (and a zero token budget) explicitly turns reasoning off,
     * which is what makes a temperature acceptable again on models like GPT-5.1.
     *
     * @param array|null $reasoning Resolved reasoning plan.
     * @return bool
     */
    public static function is_reasoning_active($reasoning)
    {
        if (!is_array($reasoning)) {
            return false;
        }

        if (($reasoning['canonical'] ?? null) === 'none') {
            return false;
        }

        if ($reasoning['mode'] === self::MODE_THINKING_BUDGET && (int) $reasoning['value'] <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a temperature value for a model.
     *
     * @param string $provider_id       Provider ID.
     * @param string $model_id          Model ID.
     * @param mixed  $value             Requested temperature.
     * @param bool   $reasoning_active  Whether a reasoning level will be sent.
     * @return float|null Clamped temperature or null when it must not be sent.
     */
    public static function resolve_temperature($provider_id, $model_id, $value, $reasoning_active = false)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);
        $spec = $capabilities['temperature'];

        if (empty($spec['supported'])) {
            return null;
        }

        if ($reasoning_active && !empty($capabilities['reasoning']['disables_temperature'])) {
            return null;
        }

        if ($spec['fixed'] !== null) {
            return (float) $spec['fixed'];
        }

        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) max($spec['min'], min($spec['max'], (float) $value));
    }

    /**
     * Prepare chat parameters for a provider request.
     *
     * Strips `reasoning_effort` from the parameter bag, resolves it to a native
     * plan, and normalizes (or removes) `temperature` for the target model.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @param array  $parameters  Raw parameters.
     * @return array ['parameters' => array, 'reasoning' => array|null, 'capabilities' => array]
     */
    public static function prepare_chat_parameters($provider_id, $model_id, array $parameters)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        $requested_effort = null;
        foreach (['reasoning_effort', 'effort', 'thinking_level'] as $key) {
            if (array_key_exists($key, $parameters)) {
                if ($requested_effort === null && $parameters[$key] !== '' && $parameters[$key] !== null) {
                    $requested_effort = $parameters[$key];
                }
                unset($parameters[$key]);
            }
        }

        // Models such as GPT-5.1+ accept a temperature only while reasoning is off.
        // Asking for a temperature is therefore a request to turn reasoning off, and
        // saying so explicitly is safer than relying on the provider's default effort.
        if (
            $requested_effort === null
            && !empty($capabilities['temperature']['requires_effort_none'])
            && isset($parameters['temperature'])
            && is_numeric($parameters['temperature'])
            && isset($capabilities['reasoning']['levels']['none'])
        ) {
            $requested_effort = 'none';
        }

        $reasoning = self::resolve_reasoning($provider_id, $model_id, $requested_effort);

        if (array_key_exists('temperature', $parameters)) {
            $temperature = self::resolve_temperature(
                $provider_id,
                $model_id,
                $parameters['temperature'],
                self::is_reasoning_active($reasoning)
            );

            if ($temperature === null) {
                unset($parameters['temperature']);
            } else {
                $parameters['temperature'] = $temperature;
            }
        }

        return [
            'parameters' => $parameters,
            'reasoning' => $reasoning,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Build a compact capability payload for admin JS.
     *
     * Models sharing a profile reference the same entry, so localizing hundreds
     * of models stays cheap.
     *
     * @param string $provider_id Provider ID.
     * @param array  $models      Flat list of model IDs, or grouped ['Group' => ['id' => 'label']].
     * @return array ['profiles' => array, 'models' => array, 'fallback' => string]
     */
    public static function get_capabilities_payload($provider_id, array $models)
    {
        $model_ids = self::flatten_model_ids($models);

        $profiles = [];
        $map = [];

        foreach ($model_ids as $model_id) {
            $capabilities = self::get_model_capabilities($provider_id, $model_id);
            $profile_id = $capabilities['profile'];
            if (!isset($profiles[$profile_id])) {
                $profiles[$profile_id] = self::to_ui_profile($capabilities);
            }
            $map[$model_id] = $profile_id;
        }

        // Always expose the provider default so unknown/global-setting models resolve.
        $fallback = self::get_model_capabilities($provider_id, '');
        if (!isset($profiles[$fallback['profile']])) {
            $profiles[$fallback['profile']] = self::to_ui_profile($fallback);
        }

        return [
            'profiles' => $profiles,
            'models' => $map,
            'fallback' => $fallback['profile'],
        ];
    }

    /**
     * Build capability payloads for several providers at once.
     *
     * @param array $models_by_provider ['openai' => grouped models, ...].
     * @return array ['openai' => payload, ...]
     */
    public static function get_capabilities_payload_for_providers(array $models_by_provider)
    {
        $payload = [];
        foreach ($models_by_provider as $provider_id => $models) {
            $payload[$provider_id] = self::get_capabilities_payload($provider_id, is_array($models) ? $models : []);
        }

        return $payload;
    }

    /**
     * Store capability hints harvested from a provider /models endpoint.
     *
     * Providers report different things, so the schema is a superset and every
     * key is optional:
     *
     *     'model-id' => [
     *         'temperature'           => 1.0,   // Gemini: default temperature
     *         'maxTemperature'        => 2.0,   // Gemini: upper bound
     *         'temperature_supported' => false, // explicit override
     *         'thinking'              => ['adaptive' => true, 'enabled' => false],
     *                                           // or a plain bool (Gemini)
     *         'effort'                => [
     *             'supported' => true,
     *             'levels'    => ['low', 'medium', 'high', 'xhigh', 'max'],
     *             'default'   => 'high',
     *             'param'     => 'output_config.effort',
     *         ],
     *     ]
     *
     * Reported data wins over the static rule table.
     *
     * @param string $provider_id Provider ID.
     * @param array  $metadata    Metadata keyed by model ID.
     * @return void
     */
    public static function store_api_metadata($provider_id, array $metadata)
    {
        $provider_id = self::normalize_id($provider_id);

        self::$api_metadata[$provider_id] = $metadata;

        if (function_exists('set_transient')) {
            $ttl = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
            set_transient(self::API_METADATA_TRANSIENT . $provider_id, $metadata, $ttl);
        }

        // Drop the runtime cache so refreshed metadata is picked up immediately.
        self::$resolved = [];
    }

    /**
     * Get capability hints previously harvested from a provider /models endpoint.
     *
     * @param string $provider_id Provider ID.
     * @return array
     */
    public static function get_api_metadata($provider_id)
    {
        $provider_id = self::normalize_id($provider_id);

        if (isset(self::$api_metadata[$provider_id])) {
            return self::$api_metadata[$provider_id];
        }

        $metadata = function_exists('get_transient')
            ? get_transient(self::API_METADATA_TRANSIENT . $provider_id)
            : false;

        self::$api_metadata[$provider_id] = is_array($metadata) ? $metadata : [];

        return self::$api_metadata[$provider_id];
    }

    /**
     * Reset the runtime cache (used in tests and after settings changes).
     *
     * @return void
     */
    public static function flush_cache()
    {
        self::$resolved = [];
        self::$api_metadata = [];
    }

    /**
     * Human readable summary used in admin descriptions and logs.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return string
     */
    public static function describe($provider_id, $model_id)
    {
        $capabilities = self::get_model_capabilities($provider_id, $model_id);

        $spec = $capabilities['temperature'];

        if (!empty($capabilities['reasoning'])) {
            $natives = [];
            foreach ($capabilities['reasoning']['levels'] as $level) {
                $natives[] = (string) $level['native'];
            }

            if (!empty($spec['supported']) && !empty($spec['requires_effort_none'])) {
                return sprintf(
                    // translators: 1: API parameter name, 2: comma separated list of accepted values
                    __('Reasoning model: uses %1$s (%2$s); a temperature only applies with effort "none".', 'polytrans'),
                    $capabilities['reasoning']['param'],
                    implode(', ', $natives)
                );
            }

            if (!empty($spec['supported'])) {
                return sprintf(
                    // translators: 1: API parameter name, 2: comma separated list of accepted values, 3: minimum temperature, 4: maximum temperature
                    __('Reasoning model: accepts %1$s (%2$s) and a temperature (%3$s-%4$s).', 'polytrans'),
                    $capabilities['reasoning']['param'],
                    implode(', ', $natives),
                    self::format_number($spec['min']),
                    self::format_number($spec['max'])
                );
            }

            return sprintf(
                // translators: 1: API parameter name, 2: comma separated list of accepted values
                __('Reasoning model: uses %1$s (%2$s) instead of temperature.', 'polytrans'),
                $capabilities['reasoning']['param'],
                implode(', ', $natives)
            );
        }

        if (!empty($spec['supported'])) {
            return sprintf(
                // translators: 1: minimum temperature, 2: maximum temperature
                __('Classic model: uses temperature (%1$s-%2$s).', 'polytrans'),
                self::format_number($spec['min']),
                self::format_number($spec['max'])
            );
        }

        return __('This model exposes neither temperature nor reasoning effort.', 'polytrans');
    }

    /**
     * Compact profile representation for the admin UI.
     *
     * @param array $capabilities Capability descriptor.
     * @return array
     */
    private static function to_ui_profile(array $capabilities)
    {
        $profile = [
            'id' => $capabilities['profile'],
            'label' => $capabilities['label'],
            'temperature' => $capabilities['temperature'],
            'reasoning' => null,
            'summary' => self::describe($capabilities['provider'], $capabilities['model']),
        ];

        if (!empty($capabilities['reasoning'])) {
            $levels = [];
            foreach ($capabilities['reasoning']['levels'] as $canonical => $level) {
                $levels[] = [
                    'value' => $canonical,
                    'native' => $level['native'],
                    'label' => $level['label'],
                ];
            }

            $profile['reasoning'] = [
                'mode' => $capabilities['reasoning']['mode'],
                'param' => $capabilities['reasoning']['param'],
                'default' => $capabilities['reasoning']['default'],
                'levels' => $levels,
                'note' => $capabilities['reasoning']['note'],
                'disables_temperature' => !empty($capabilities['reasoning']['disables_temperature']),
            ];
        }

        return $profile;
    }

    /**
     * Refine capabilities with metadata reported by the provider API.
     *
     * @param array $capabilities Capability descriptor.
     * @return array
     */
    private static function apply_api_metadata(array $capabilities)
    {
        if ($capabilities['model'] === '') {
            return $capabilities;
        }

        $metadata = self::get_api_metadata($capabilities['provider']);
        $model_meta = $metadata[$capabilities['model']] ?? null;

        if (!is_array($model_meta)) {
            return $capabilities;
        }

        // Gemini's ListModels reports the supported temperature window.
        if ($capabilities['temperature']['supported']) {
            if (isset($model_meta['maxTemperature']) && is_numeric($model_meta['maxTemperature'])) {
                $capabilities['temperature']['max'] = (float) $model_meta['maxTemperature'];
            }
            if (isset($model_meta['temperature']) && is_numeric($model_meta['temperature'])) {
                $capabilities['temperature']['default'] = (float) $model_meta['temperature'];
            }
        }

        $thinking = $model_meta['thinking'] ?? null;

        // A provider explicitly reporting "no thinking support" wins over pattern matching.
        if ($thinking === false) {
            $capabilities['reasoning'] = null;
        }

        // Anthropic's /v1/models reports the effort levels a model accepts.
        $capabilities = self::apply_api_effort($capabilities, $model_meta['effort'] ?? null);

        if (is_array($thinking)) {
            // A model that only supports adaptive thinking rejects an explicit
            // thinking budget, so a static budget rule cannot apply to it.
            if (
                array_key_exists('enabled', $thinking)
                && !$thinking['enabled']
                && ($capabilities['reasoning']['mode'] ?? null) === self::MODE_THINKING_BUDGET
            ) {
                $capabilities['reasoning'] = null;
            }

            // Verified against the live Anthropic API: every adaptive-thinking-only
            // model (opus-4-7/4-8, opus-5, sonnet-5, fable-5) rejects `temperature`
            // as deprecated, while models that still accept an explicit thinking
            // budget also still accept a temperature.
            if (
                $capabilities['provider'] === 'claude'
                && !empty($thinking['adaptive'])
                && array_key_exists('enabled', $thinking)
                && !$thinking['enabled']
            ) {
                $capabilities['temperature']['supported'] = false;
            }
        }

        // An explicit flag always wins over anything inferred above.
        if (array_key_exists('temperature_supported', $model_meta)) {
            $capabilities['temperature']['supported'] = (bool) $model_meta['temperature_supported'];
        }

        return $capabilities;
    }

    /**
     * Apply reported effort support to a capability descriptor.
     *
     * @param array      $capabilities Capability descriptor.
     * @param array|null $effort       Reported effort metadata.
     * @return array
     */
    private static function apply_api_effort(array $capabilities, $effort)
    {
        if (!is_array($effort) || !array_key_exists('supported', $effort)) {
            return $capabilities;
        }

        $current = $capabilities['reasoning'];

        if (!$effort['supported']) {
            // Sending the effort parameter to such a model is a 400; other
            // reasoning modes (e.g. a thinking budget) stay untouched.
            if (($current['mode'] ?? null) === self::MODE_EFFORT) {
                $capabilities['reasoning'] = null;
            }

            return $capabilities;
        }

        $levels = [];
        foreach ((array) ($effort['levels'] ?? []) as $native) {
            $canonical = self::canonical_from_native($native);
            if ($canonical !== null) {
                $levels[$canonical] = $native;
            }
        }

        if (empty($levels)) {
            return $capabilities;
        }

        $param = $effort['param'] ?? null;
        if ($param === null) {
            $param = ($current['mode'] ?? null) === self::MODE_EFFORT
                ? $current['param']
                : 'reasoning_effort';
        }

        $capabilities['reasoning'] = self::normalize_reasoning_spec([
            'mode' => self::MODE_EFFORT,
            'param' => $param,
            'default' => $effort['default'] ?? ($current['default'] ?? null),
            'levels' => $levels,
            'note' => $current['note'] ?? '',
        ]);

        return $capabilities;
    }

    /**
     * Map a provider-native effort value onto a canonical level.
     *
     * @param mixed $native Native value.
     * @return string|null
     */
    private static function canonical_from_native($native)
    {
        if (!is_string($native)) {
            return null;
        }

        $native = strtolower(trim($native));

        return in_array($native, self::LEVELS, true) ? $native : null;
    }

    /**
     * Knowledge base of capability rules, first match wins per provider.
     *
     * Sources:
     *  - OpenAI reasoning models: https://platform.openai.com/docs/guides/reasoning
     *  - Anthropic extended thinking: https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking
     *  - Gemini thinking: https://ai.google.dev/gemini-api/docs/thinking
     *
     * @return array
     */
    private static function get_rules()
    {
        static $rules = null;

        if ($rules !== null) {
            return $rules;
        }

        $classic_openai = [
            'supported' => true,
            'min' => 0.0,
            'max' => 2.0,
            'default' => 0.7,
        ];

        // GPT-5.1+ accepts a temperature, but only while reasoning is off.
        $reasoning_off_temperature = [
            'supported' => true,
            'min' => 0.0,
            'max' => 2.0,
            'default' => 1.0,
            'requires_effort_none' => true,
            'note' => __('This model only accepts a temperature while reasoning is turned off; PolyTrans then sends reasoning_effort "none" alongside it.', 'polytrans'),
        ];

        $rules = [
            // Verified against the live OpenAI Chat Completions API: `max` does not
            // exist there, the o-series does accept `xhigh`, and GPT-5.1+ accepts a
            // temperature only while reasoning is turned off (reasoning_effort: none).
            'openai' => [
                [
                    // o1-mini / o1-preview accept neither temperature nor reasoning_effort.
                    'id' => 'openai-o1-legacy',
                    'match' => ['/^o1-(mini|preview)/'],
                    'label' => 'OpenAI o1 mini / preview',
                    'temperature' => ['supported' => false],
                    'reasoning' => null,
                ],
                [
                    // GPT-5.2 added the xhigh level.
                    'id' => 'openai-gpt-5-2-plus',
                    'match' => ['/^gpt-5\.[2-9]/', '/^gpt-5-[2-9](-|\.|$)/', '/^gpt-[6-9]/'],
                    'label' => 'OpenAI GPT-5.2+ (reasoning)',
                    'temperature' => $reasoning_off_temperature,
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'reasoning_effort',
                        'default' => 'medium',
                        'disables_temperature' => true,
                        'levels' => [
                            'none' => 'none',
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                            'xhigh' => 'xhigh',
                        ],
                        'note' => __('Accepts reasoning_effort: none, low, medium, high, xhigh. Temperature only works together with effort "none".', 'polytrans'),
                    ],
                ],
                [
                    // GPT-5.1 introduced the "none" level but not xhigh.
                    'id' => 'openai-gpt-5-1',
                    'match' => ['/^gpt-5\.1/', '/^gpt-5-1(-|\.|$)/'],
                    'label' => 'OpenAI GPT-5.1 (reasoning)',
                    'temperature' => $reasoning_off_temperature,
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'reasoning_effort',
                        'default' => 'medium',
                        'disables_temperature' => true,
                        'levels' => [
                            'none' => 'none',
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                        ],
                        'note' => __('Accepts reasoning_effort: none, low, medium, high. Temperature only works together with effort "none".', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'openai-gpt-5',
                    'match' => ['/^gpt-5/'],
                    'label' => 'OpenAI GPT-5 (reasoning)',
                    'temperature' => ['supported' => false],
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'reasoning_effort',
                        'default' => 'medium',
                        'levels' => [
                            'minimal' => 'minimal',
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                        ],
                        'note' => __('GPT-5 rejects temperature and accepts reasoning_effort: minimal, low, medium, high.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'openai-o-series',
                    'match' => ['/^o[1-9](-|$)/', '/^o[1-9]$/'],
                    'label' => 'OpenAI o-series (reasoning)',
                    'temperature' => ['supported' => false],
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'reasoning_effort',
                        'default' => 'medium',
                        'levels' => [
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                            'xhigh' => 'xhigh',
                        ],
                        'note' => __('o-series reasoning models reject temperature and accept reasoning_effort: low, medium, high, xhigh.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'openai-classic',
                    'match' => ['/^(gpt-4|gpt-3\.5|chatgpt-)/'],
                    'label' => 'OpenAI GPT-4 / GPT-3.5',
                    'temperature' => $classic_openai,
                    'reasoning' => null,
                ],
            ],

            // Claude capabilities are normally read from /v1/models (see
            // ClaudeSettingsProvider::load_models); these rules are the offline fallback.
            // Verified against the live API: temperature is rejected outright on
            // adaptive-thinking-only models (opus-4-7/4-8, opus-5, sonnet-5, fable-5)
            // and may only be 1 while thinking is active on the older ones.
            'claude' => [
                [
                    'id' => 'claude-effort-adaptive',
                    'match' => [
                        '/^claude-(opus|sonnet|haiku|fable|mythos)-([5-9]|4-[7-9]|4\.[7-9])/',
                        '/^claude-(fable|mythos)-/',
                    ],
                    'label' => 'Claude (adaptive thinking, effort)',
                    'temperature' => [
                        'supported' => false,
                        'note' => __('Temperature is deprecated on this model - use effort instead.', 'polytrans'),
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'output_config.effort',
                        'default' => 'high',
                        'levels' => [
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                            'xhigh' => 'xhigh',
                            'max' => 'max',
                        ],
                        'note' => __('Claude accepts output_config.effort: low, medium, high, xhigh, max. The API default is high.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'claude-effort-4-6',
                    'match' => [
                        '/^claude-(opus|sonnet|haiku)-4-6/',
                        '/^claude-(opus|sonnet|haiku)-4\.6/',
                    ],
                    'label' => 'Claude 4.6 (effort + temperature)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 1.0,
                        'default' => 0.7,
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'output_config.effort',
                        'default' => 'high',
                        'levels' => [
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                            'max' => 'max',
                        ],
                        'note' => __('Claude 4.6 accepts output_config.effort: low, medium, high, max, and still accepts a temperature.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'claude-effort-opus-4-5',
                    'match' => [
                        '/^claude-opus-4-5/',
                        '/^claude-opus-4\.5/',
                    ],
                    'label' => 'Claude Opus 4.5 (effort + temperature)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 1.0,
                        'default' => 0.7,
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_EFFORT,
                        'param' => 'output_config.effort',
                        'default' => 'high',
                        'levels' => [
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                        ],
                        'note' => __('Claude Opus 4.5 accepts output_config.effort: low, medium, high, and still accepts a temperature.', 'polytrans'),
                    ],
                ],
                [
                    // Older extended-thinking models (Sonnet/Haiku 4.5 and earlier):
                    // no effort parameter, explicit thinking budget only.
                    'id' => 'claude-thinking-budget',
                    'match' => [
                        '/^claude-(opus|sonnet|haiku)-[34]/',
                        '/^claude-3-7-sonnet/',
                        '/^claude-3\.7-sonnet/',
                    ],
                    'label' => 'Claude (extended thinking budget)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 1.0,
                        'default' => 0.7,
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_THINKING_BUDGET,
                        'param' => 'budget_tokens',
                        'default' => 'none',
                        'disables_temperature' => true,
                        'levels' => [
                            'none' => 0,
                            'low' => 2048,
                            'medium' => 8192,
                            'high' => 16384,
                        ],
                        'note' => __('This model has no effort parameter - effort maps to thinking.budget_tokens (minimum 1024). Temperature may only be 1 while thinking, so PolyTrans omits it.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'claude-classic',
                    'match' => ['/^claude-/'],
                    'label' => 'Claude 3 / 3.5',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 1.0,
                        'default' => 0.7,
                    ],
                    'reasoning' => null,
                ],
            ],

            // Verified against the live Gemini API: 3.x accepts thinkingLevel
            // (minimal-high on Flash) and cannot disable thinking at all, while 2.5
            // rejects thinkingLevel outright and still uses a thinkingBudget.
            'gemini' => [
                [
                    // Gemini 3.x flash models accept minimal through high.
                    'id' => 'gemini-3-flash',
                    'match' => ['/^gemini-[3-9](\.[0-9]+)?-flash/'],
                    'label' => 'Gemini 3 Flash (thinking level)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 2.0,
                        'default' => 1.0,
                        'note' => __('Google recommends leaving temperature at 1.0 for Gemini 3 models.', 'polytrans'),
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_THINKING_LEVEL,
                        'param' => 'thinkingLevel',
                        'default' => 'medium',
                        'levels' => [
                            'minimal' => 'minimal',
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                        ],
                        'note' => __('Accepts thinkingConfig.thinkingLevel: minimal, low, medium, high. Thinking cannot be disabled on Gemini 3.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'gemini-3',
                    'match' => ['/^gemini-[3-9]/'],
                    'label' => 'Gemini 3 Pro (thinking level)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 2.0,
                        'default' => 1.0,
                        'note' => __('Google recommends leaving temperature at 1.0 for Gemini 3 models.', 'polytrans'),
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_THINKING_LEVEL,
                        'param' => 'thinkingLevel',
                        'default' => 'high',
                        'levels' => [
                            'low' => 'low',
                            'medium' => 'medium',
                            'high' => 'high',
                        ],
                        'note' => __('Gemini Pro accepts thinkingConfig.thinkingLevel: low, medium, high. Thinking cannot be disabled on Gemini 3.', 'polytrans'),
                    ],
                ],
                [
                    // Gemini 2.5 Pro always thinks - its budget cannot be set to 0.
                    'id' => 'gemini-2-5-pro',
                    'match' => ['/^gemini-2\.5-pro/', '/^gemini-2-5-pro/'],
                    'label' => 'Gemini 2.5 Pro (thinking budget)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 2.0,
                        'default' => 1.0,
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_THINKING_BUDGET,
                        'param' => 'thinkingBudget',
                        'default' => 'medium',
                        'levels' => [
                            'low' => 2048,
                            'medium' => 8192,
                            'high' => 32768,
                        ],
                        'note' => __('Gemini 2.5 rejects thinkingLevel and uses thinkingConfig.thinkingBudget. Pro always thinks, so the budget cannot be 0.', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'gemini-2-5',
                    'match' => ['/^gemini-2\.5/', '/^gemini-2-5/'],
                    'label' => 'Gemini 2.5 Flash (thinking budget)',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 2.0,
                        'default' => 1.0,
                    ],
                    'reasoning' => [
                        'mode' => self::MODE_THINKING_BUDGET,
                        'param' => 'thinkingBudget',
                        'default' => 'none',
                        'levels' => [
                            'none' => 0,
                            'low' => 2048,
                            'medium' => 8192,
                            'high' => 24576,
                        ],
                        'note' => __('Gemini 2.5 rejects thinkingLevel and uses thinkingConfig.thinkingBudget (0 disables thinking on Flash).', 'polytrans'),
                    ],
                ],
                [
                    'id' => 'gemini-classic',
                    'match' => ['/^gemini-/'],
                    'label' => 'Gemini 1.5 / 2.0',
                    'temperature' => [
                        'supported' => true,
                        'min' => 0.0,
                        'max' => 2.0,
                        'default' => 0.7,
                    ],
                    'reasoning' => null,
                ],
            ],
        ];

        /**
         * Filter the model capability rule set.
         *
         * Each provider holds an ordered list of rules; the first rule whose
         * `match` patterns hit the model ID wins.
         *
         * @param array $rules Rules grouped by provider ID.
         */
        $rules = apply_filters('polytrans_model_capability_rules', $rules);

        return $rules;
    }

    /**
     * Fallback rule for unknown models/providers: classic temperature control.
     *
     * @param string $provider_id Provider ID.
     * @return array
     */
    private static function generic_rule($provider_id)
    {
        return [
            'id' => $provider_id . '-generic',
            'label' => __('Unknown model (assuming temperature support)', 'polytrans'),
            'temperature' => [
                'supported' => true,
                'min' => 0.0,
                'max' => 2.0,
                'default' => 0.7,
            ],
            'reasoning' => null,
        ];
    }

    /**
     * Does a rule match the given model ID?
     *
     * @param array  $rule     Rule definition.
     * @param string $model_id Model ID.
     * @return bool
     */
    private static function rule_matches(array $rule, $model_id)
    {
        if ($model_id === '') {
            return false;
        }

        foreach ((array) ($rule['match'] ?? []) as $pattern) {
            if (@preg_match($pattern, $model_id) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill defaults in a temperature spec.
     *
     * @param array $spec Raw spec.
     * @return array
     */
    private static function normalize_temperature_spec(array $spec)
    {
        $supported = array_key_exists('supported', $spec) ? (bool) $spec['supported'] : true;

        return [
            'supported' => $supported,
            'min' => isset($spec['min']) ? (float) $spec['min'] : 0.0,
            'max' => isset($spec['max']) ? (float) $spec['max'] : 2.0,
            'step' => isset($spec['step']) ? (float) $spec['step'] : 0.1,
            'default' => isset($spec['default']) ? (float) $spec['default'] : 0.7,
            'fixed' => isset($spec['fixed']) ? (float) $spec['fixed'] : null,
            'requires_effort_none' => !empty($spec['requires_effort_none']),
            'note' => $spec['note'] ?? '',
        ];
    }

    /**
     * Fill defaults and generate native labels in a reasoning spec.
     *
     * @param array|null $spec Raw spec.
     * @return array|null
     */
    private static function normalize_reasoning_spec($spec)
    {
        if (empty($spec) || empty($spec['levels']) || !is_array($spec['levels'])) {
            return null;
        }

        $mode = $spec['mode'] ?? self::MODE_EFFORT;
        $levels = [];

        foreach (self::LEVELS as $canonical) {
            if (!array_key_exists($canonical, $spec['levels'])) {
                continue;
            }

            $native = $spec['levels'][$canonical];

            $levels[$canonical] = [
                'native' => $native,
                'label' => self::build_level_label($canonical, $native, $mode),
            ];
        }

        if (empty($levels)) {
            return null;
        }

        $default = $spec['default'] ?? null;
        if ($default === null || !isset($levels[$default])) {
            $default = self::snap_level($default ?? 'medium', array_keys($levels));
        }

        return [
            'mode' => $mode,
            'param' => $spec['param'] ?? 'reasoning_effort',
            'default' => $default,
            'levels' => $levels,
            'disables_temperature' => !empty($spec['disables_temperature']),
            'note' => $spec['note'] ?? '',
        ];
    }

    /**
     * Build a label exposing the provider-native value to the admin.
     *
     * @param string $canonical Canonical level.
     * @param mixed  $native    Native value.
     * @param string $mode      Reasoning mode.
     * @return string
     */
    private static function build_level_label($canonical, $native, $mode)
    {
        $names = [
            'none' => __('None', 'polytrans'),
            'minimal' => __('Minimal', 'polytrans'),
            'low' => __('Low', 'polytrans'),
            'medium' => __('Medium', 'polytrans'),
            'high' => __('High', 'polytrans'),
            'xhigh' => __('Extra high', 'polytrans'),
            'max' => __('Maximum', 'polytrans'),
        ];

        $name = $names[$canonical] ?? ucfirst((string) $canonical);

        if ($mode === self::MODE_THINKING_BUDGET) {
            if ((int) $native === 0) {
                return sprintf(
                    // translators: %s is the canonical level name
                    __('%s (thinking disabled)', 'polytrans'),
                    $name
                );
            }

            return sprintf(
                // translators: 1: canonical level name, 2: number of thinking tokens
                __('%1$s (%2$s thinking tokens)', 'polytrans'),
                $name,
                self::format_token_count((int) $native)
            );
        }

        // Enum modes: show the exact value the provider expects.
        return sprintf('%s (%s)', $name, (string) $native);
    }

    /**
     * Snap a canonical level to the closest level supported by a model.
     *
     * Ties resolve downwards (cheaper option).
     *
     * @param string $level     Requested canonical level.
     * @param array  $available Available canonical levels.
     * @return string|null
     */
    private static function snap_level($level, array $available)
    {
        if (empty($available)) {
            return null;
        }

        if (in_array($level, $available, true)) {
            return $level;
        }

        $requested_index = array_search($level, self::LEVELS, true);
        if ($requested_index === false) {
            return $available[0];
        }

        $best = null;
        $best_distance = null;
        foreach ($available as $candidate) {
            $candidate_index = array_search($candidate, self::LEVELS, true);
            if ($candidate_index === false) {
                continue;
            }
            $distance = abs($candidate_index - $requested_index);
            if ($best_distance === null || $distance < $best_distance) {
                $best = $candidate;
                $best_distance = $distance;
            }
        }

        return $best ?? $available[0];
    }

    /**
     * Flatten grouped or flat model structures into a list of model IDs.
     *
     * @param array $models Flat list or grouped ['Group' => ['id' => 'label']].
     * @return array
     */
    private static function flatten_model_ids(array $models)
    {
        $ids = [];

        foreach ($models as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $model_id => $label) {
                    $model_id = self::normalize_model_id(is_string($model_id) ? $model_id : (string) $label);
                    if ($model_id !== '') {
                        $ids[$model_id] = true;
                    }
                }
                continue;
            }

            // Flat map ['model-id' => 'Label'] or list ['model-id', ...].
            $model_id = self::normalize_model_id(is_string($key) ? $key : (string) $value);
            if ($model_id !== '') {
                $ids[$model_id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Normalize a provider ID.
     *
     * @param string $provider_id Provider ID.
     * @return string
     */
    private static function normalize_id($provider_id)
    {
        $provider_id = is_string($provider_id) ? strtolower(trim($provider_id)) : '';

        return $provider_id !== '' ? $provider_id : 'openai';
    }

    /**
     * Normalize a model ID (Gemini reports "models/gemini-x", OpenAI is plain).
     *
     * @param string $model_id Model ID.
     * @return string
     */
    private static function normalize_model_id($model_id)
    {
        if (!is_string($model_id)) {
            return '';
        }

        $model_id = strtolower(trim($model_id));

        if (strpos($model_id, 'models/') === 0) {
            $model_id = substr($model_id, 7);
        }

        return $model_id;
    }

    /**
     * Format a float for display without trailing zeros.
     *
     * @param float $value Value.
     * @return string
     */
    private static function format_number($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * Format a thinking token budget for display.
     *
     * @param int $number Token count.
     * @return string
     */
    private static function format_token_count($number)
    {
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number);
        }

        return number_format($number);
    }
}

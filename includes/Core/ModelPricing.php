<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Model Pricing
 *
 * Turns provider token counts into an estimated cost in USD.
 *
 * No provider publishes prices through its API - verified against OpenAI, whose
 * /v1/models returns only id/object/created/owned_by. OpenRouter does publish a
 * machine-readable catalogue covering all three providers, including the two
 * dimensions a plain input/output table misses: cached input and reasoning.
 * Those are OpenRouter's prices, so treat every figure here as an approximation
 * rather than a billing record.
 *
 * Prices are per token, as strings, to keep the small magnitudes (1e-8) exact
 * until they are used. Costs must be stored at the time of the request, since a
 * later catalogue refresh would otherwise rewrite historical reports.
 */
class ModelPricing
{
    const CATALOG_URL = 'https://openrouter.ai/api/v1/models';
    const TRANSIENT_KEY = 'polytrans_model_pricing_catalog';
    const TRANSIENT_TTL = DAY_IN_SECONDS;

    const SOURCE_CATALOG = 'openrouter';
    const SOURCE_OVERRIDE = 'override';
    const SOURCE_UNKNOWN = 'unknown';

    /**
     * Runtime cache so one request does not hit the transient repeatedly.
     *
     * @var array|null
     */
    private static $catalog = null;

    /**
     * Drop the in-process cache (tests, and after an explicit refresh).
     *
     * @return void
     */
    public static function flush_cache()
    {
        self::$catalog = null;
    }

    /**
     * Get the price catalogue, keyed by OpenRouter model slug.
     *
     * @param bool $force_refresh Bypass the cached copy.
     * @return array
     */
    public static function get_catalog($force_refresh = false)
    {
        if (!$force_refresh && is_array(self::$catalog)) {
            return self::$catalog;
        }

        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                self::$catalog = $cached;
                return self::$catalog;
            }
        }

        $fetched = self::fetch_catalog();

        if (!empty($fetched)) {
            set_transient(self::TRANSIENT_KEY, $fetched, self::TRANSIENT_TTL);
            self::$catalog = $fetched;
            return self::$catalog;
        }

        // Keep serving a stale copy rather than losing pricing entirely; the
        // transient may have expired while the network is unavailable.
        $stale = get_transient(self::TRANSIENT_KEY);
        self::$catalog = is_array($stale) ? $stale : [];

        return self::$catalog;
    }

    /**
     * Fetch and reduce the catalogue to the fields that carry a price.
     *
     * @return array
     */
    private static function fetch_catalog()
    {
        $response = wp_remote_get(self::CATALOG_URL, ['timeout' => 15]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            return [];
        }

        $catalog = [];
        foreach ($decoded['data'] as $entry) {
            $id = $entry['id'] ?? '';
            $pricing = $entry['pricing'] ?? null;

            // ":batch" and similar suffixes are separate SKUs we never call.
            if (!is_string($id) || $id === '' || !is_array($pricing) || strpos($id, ':') !== false) {
                continue;
            }

            $catalog[$id] = [
                'input' => $pricing['prompt'] ?? null,
                'output' => $pricing['completion'] ?? null,
                'cached_read' => $pricing['input_cache_read'] ?? null,
                'cached_write' => $pricing['input_cache_write'] ?? null,
                'reasoning' => $pricing['internal_reasoning'] ?? null,
                'context_length' => $entry['context_length'] ?? null,
            ];
        }

        return $catalog;
    }

    /**
     * Map a provider's model ID onto an OpenRouter slug.
     *
     * The naming only partly lines up: OpenAI matches on a prefix, Anthropic
     * writes versions with dots where the API uses dashes (claude-opus-4-6 ->
     * claude-opus-4.6), and Gemini diverges enough that some models have no
     * counterpart at all. A miss returns null so the caller can record "unknown"
     * instead of inventing a price.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID as the provider names it.
     * @return string|null OpenRouter slug.
     */
    public static function resolve_catalog_key($provider_id, $model_id)
    {
        $model_id = is_string($model_id) ? trim($model_id) : '';
        if ($model_id === '') {
            return null;
        }

        // Gemini model IDs sometimes arrive fully qualified.
        $model_id = preg_replace('#^models/#', '', $model_id);

        $catalog = self::get_catalog();
        $candidates = [];

        switch ($provider_id) {
            case 'openai':
                $candidates[] = 'openai/' . $model_id;
                // Dated snapshots (gpt-5.2-2026-01-30) are priced as the base model.
                $candidates[] = 'openai/' . preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model_id);
                break;

            case 'claude':
            case 'anthropic':
                $base = preg_replace('/-\d{8}$/', '', $model_id);      // claude-opus-4-6-20260514
                $base = preg_replace('/-latest$/', '', $base);
                $candidates[] = 'anthropic/' . $base;
                // claude-opus-4-6 -> claude-opus-4.6
                $candidates[] = 'anthropic/' . preg_replace('/-(\d+)-(\d+)$/', '-$1.$2', $base);
                break;

            case 'gemini':
            case 'google':
                $base = preg_replace('/-(preview|latest)(-\d{2}-\d{2})?$/', '', $model_id);
                $candidates[] = 'google/' . $base;
                $candidates[] = 'google/' . $model_id;
                break;

            default:
                $candidates[] = $provider_id . '/' . $model_id;
                break;
        }

        foreach ($candidates as $candidate) {
            if (isset($catalog[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Get per-token prices for a model.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @return array|null Prices plus 'source' and 'catalog_key', or null when unpriced.
     */
    public static function get_pricing($provider_id, $model_id)
    {
        /**
         * Filter model prices, e.g. to pin negotiated rates or cover a model the
         * catalogue does not list.
         *
         * @param array|null $pricing     Price set or null.
         * @param string     $provider_id Provider ID.
         * @param string     $model_id    Model ID.
         */
        $override = apply_filters('polytrans_model_pricing', null, $provider_id, $model_id);
        if (is_array($override)) {
            $override['source'] = $override['source'] ?? self::SOURCE_OVERRIDE;
            return $override;
        }

        $key = self::resolve_catalog_key($provider_id, $model_id);
        if ($key === null) {
            return null;
        }

        $catalog = self::get_catalog();
        $pricing = $catalog[$key];
        $pricing['source'] = self::SOURCE_CATALOG;
        $pricing['catalog_key'] = $key;

        return $pricing;
    }

    /**
     * Reduce a provider's usage payload to one shape.
     *
     * Every provider counts differently, and the reasoning tokens are the trap:
     * OpenAI and Anthropic report them inside the output count, while Gemini
     * reports thoughts separately from the candidates. Billing them twice would
     * inflate exactly the models where reasoning dominates the bill, so the
     * normalised shape records whether output already contains them.
     *
     * @param string $provider_id Provider ID.
     * @param mixed  $usage       Raw usage payload from the API response.
     * @return array
     */
    public static function normalize_usage($provider_id, $usage)
    {
        $normalized = [
            'input' => 0,
            'output' => 0,
            'cached_read' => 0,
            'cached_write' => 0,
            'reasoning' => 0,
            'reasoning_in_output' => true,
        ];

        if (!is_array($usage)) {
            return $normalized;
        }

        switch ($provider_id) {
            case 'claude':
            case 'anthropic':
                $normalized['input'] = (int) ($usage['input_tokens'] ?? 0);
                $normalized['output'] = (int) ($usage['output_tokens'] ?? 0);
                $normalized['cached_read'] = (int) ($usage['cache_read_input_tokens'] ?? 0);
                $normalized['cached_write'] = (int) ($usage['cache_creation_input_tokens'] ?? 0);
                break;

            case 'gemini':
            case 'google':
                $normalized['input'] = (int) ($usage['promptTokenCount'] ?? 0);
                $normalized['output'] = (int) ($usage['candidatesTokenCount'] ?? 0);
                $normalized['cached_read'] = (int) ($usage['cachedContentTokenCount'] ?? 0);
                $normalized['reasoning'] = (int) ($usage['thoughtsTokenCount'] ?? 0);
                $normalized['reasoning_in_output'] = false;
                break;

            case 'openai':
            default:
                // Chat Completions and /responses name the same numbers differently.
                $normalized['input'] = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
                $normalized['output'] = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
                $normalized['cached_read'] = (int) (
                    $usage['prompt_tokens_details']['cached_tokens']
                    ?? $usage['input_tokens_details']['cached_tokens']
                    ?? 0
                );
                $normalized['cached_write'] = (int) (
                    $usage['prompt_tokens_details']['cache_write_tokens']
                    ?? $usage['input_tokens_details']['cache_write_tokens']
                    ?? 0
                );
                $normalized['reasoning'] = (int) (
                    $usage['completion_tokens_details']['reasoning_tokens']
                    ?? $usage['output_tokens_details']['reasoning_tokens']
                    ?? 0
                );
                break;
        }

        // Cached input is reported as a subset of the input count, and is charged
        // at its own rate, so the remainder is what costs full price.
        $normalized['input_uncached'] = max(0, $normalized['input'] - $normalized['cached_read']);

        return $normalized;
    }

    /**
     * Estimate the cost of one API call.
     *
     * @param string $provider_id Provider ID.
     * @param string $model_id    Model ID.
     * @param mixed  $usage       Raw usage payload, or an already normalised array.
     * @return array{cost_usd: string|null, source: string, tokens: array, catalog_key: string|null}
     */
    public static function estimate_cost($provider_id, $model_id, $usage)
    {
        $tokens = isset($usage['reasoning_in_output'])
            ? $usage
            : self::normalize_usage($provider_id, $usage);

        $pricing = self::get_pricing($provider_id, $model_id);

        if ($pricing === null) {
            // No price beats a wrong price: a zero would silently understate totals.
            return [
                'cost_usd' => null,
                'source' => self::SOURCE_UNKNOWN,
                'tokens' => $tokens,
                'catalog_key' => null,
            ];
        }

        $cost = 0.0;
        $cost += $tokens['input_uncached'] * (float) ($pricing['input'] ?? 0);
        $cost += $tokens['cached_read'] * (float) ($pricing['cached_read'] ?? $pricing['input'] ?? 0);
        $cost += $tokens['cached_write'] * (float) ($pricing['cached_write'] ?? $pricing['input'] ?? 0);
        $cost += $tokens['output'] * (float) ($pricing['output'] ?? 0);

        // Only bill reasoning separately where the provider reports it separately;
        // for OpenAI and Anthropic it is already inside the output count.
        if (!$tokens['reasoning_in_output'] && $tokens['reasoning'] > 0) {
            $reasoning_rate = $pricing['reasoning'] ?? null;
            if ($reasoning_rate === null || (float) $reasoning_rate <= 0) {
                $reasoning_rate = $pricing['output'] ?? 0;
            }
            $cost += $tokens['reasoning'] * (float) $reasoning_rate;
        }

        return [
            'cost_usd' => number_format($cost, 10, '.', ''),
            'source' => $pricing['source'],
            'tokens' => $tokens,
            'catalog_key' => $pricing['catalog_key'] ?? null,
        ];
    }
}

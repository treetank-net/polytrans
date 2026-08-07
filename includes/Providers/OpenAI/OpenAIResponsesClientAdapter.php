<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Core\ModelCapabilities;
use PolyTrans\Providers\ChatClientInterface;

/**
 * OpenAI Responses Client Adapter
 *
 * Speaks OpenAI's /responses endpoint while presenting the same ChatClientInterface
 * as the Chat Completions adapter, so callers do not care which one they got.
 *
 * Two reasons this endpoint is needed:
 *  - the `-pro` and `-codex` models are served through it exclusively;
 *  - it is the only surface offering the `max` effort level (GPT-5.6).
 *
 * The request shape differs from Chat Completions in ways that matter:
 *  - `messages` becomes `input` (same role/content array is accepted)
 *  - `max_tokens` / `max_completion_tokens` become `max_output_tokens`
 *  - `response_format` becomes `text.format`
 *  - the effort is nested: `reasoning: {effort: ...}`
 * The response is an `output` array of items rather than `choices`, and the
 * reasoning item comes before the message item.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAIResponsesClientAdapter implements ChatClientInterface
{
    private $api_key;
    private $base_url;

    public function __construct($api_key, $base_url = 'https://api.openai.com/v1')
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }

    public function get_provider_id()
    {
        return 'openai';
    }

    public function chat_completion($messages, $parameters)
    {
        $model = $parameters['model'] ?? '';
        if (empty($model)) {
            $settings = get_option('polytrans_settings', []);
            $model = $settings['openai_model'] ?? '';
        }

        if (empty($model)) {
            return [
                'success' => false,
                'data' => null,
                'error' => __('OpenAI model is not selected. Please select a model in settings.', 'polytrans'),
                'error_code' => 'model_not_selected',
            ];
        }

        $prepared = ModelCapabilities::prepare_chat_parameters(
            'openai',
            $model,
            $parameters,
            ModelCapabilities::SURFACE_RESPONSES
        );

        $body = $this->build_body($messages, $model, $prepared);

        $settings = get_option('polytrans_settings', []);
        $api_timeout = absint($settings['api_timeout'] ?? 180);
        // Reasoning at high effort is slow; allow the same window as Chat Completions.
        $api_timeout = max(30, min(600, $api_timeout));

        $max_attempts = 2; // Initial attempt + 1 retry
        $last_response = null;
        $response = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_post(
                $this->base_url . '/responses',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key,
                    ],
                    'body' => wp_json_encode($body),
                    'timeout' => $api_timeout,
                ]
            );

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();

                if (
                    $attempt < $max_attempts
                    && (strpos(strtolower($error_message), 'timeout') !== false
                        || strpos(strtolower($error_message), 'timed out') !== false)
                ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                    error_log(sprintf(
                        '[PolyTrans OpenAI Responses] Request timeout on attempt %d/%d, retrying...',
                        $attempt,
                        $max_attempts
                    ));
                    $last_response = $response;
                    continue;
                }

                return [
                    'success' => false,
                    'data' => null,
                    'error' => $error_message,
                ];
            }

            break;
        }

        if (is_wp_error($response) && $last_response) {
            $response = $last_response;
        }

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'data' => null,
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = $body_data['error']['message'] ?? 'Unknown API error';
            $error_code = $status_code === 429 ? 'rate_limit' : 'api_error';

            return [
                'success' => false,
                'data' => null,
                'error' => $error_message,
                'error_code' => $error_code,
                'status' => $status_code,
                'retry_after' => wp_remote_retrieve_header($response, 'retry-after'),
            ];
        }

        // A 200 can still carry an unusable result: the model may have spent the
        // whole budget on reasoning and returned no message at all.
        if (($body_data['status'] ?? '') === 'incomplete') {
            $reason = $body_data['incomplete_details']['reason'] ?? 'unknown';

            return [
                'success' => false,
                'data' => $body_data,
                'error' => sprintf(
                    // translators: %s: reason reported by the API, e.g. max_output_tokens
                    __('OpenAI returned an incomplete response (%s). Try a lower reasoning effort or a higher token limit.', 'polytrans'),
                    $reason
                ),
                'error_code' => 'incomplete_response',
            ];
        }

        return [
            'success' => true,
            'data' => $body_data,
            'error' => null,
        ];
    }

    /**
     * Translate a Chat Completions style parameter bag into a /responses body.
     *
     * @param array  $messages Chat messages.
     * @param string $model    Model ID.
     * @param array  $prepared Result of ModelCapabilities::prepare_chat_parameters().
     * @return array
     */
    private function build_body($messages, $model, array $prepared)
    {
        $parameters = $prepared['parameters'];

        $body = [
            'model' => $model,
            'input' => $this->build_input($messages),
        ];

        // Token limit: on /responses this budget covers reasoning tokens too, which
        // Chat Completions' max_tokens never did. Carrying the configured number over
        // silently starves the answer - measured on gpt-5.6-luna at `max` effort, a
        // 4000 budget was spent entirely on reasoning (reasoning_tokens: 4000) and the
        // response came back `incomplete` with no message at all, while the same
        // request actually needed ~9200 reasoning tokens on top of the answer.
        // The parameter is optional, so when reasoning is active it is left out and
        // the model's own default ceiling applies. Without reasoning the two
        // parameters mean the same thing, so the caller's limit is honoured.
        // Asking whether reasoning is *active*, not merely present: an explicit
        // `none` level still travels in the body but spends no reasoning tokens,
        // so there the configured limit is safe to honour.
        if (!ModelCapabilities::is_reasoning_active($prepared['reasoning'])) {
            foreach (['max_output_tokens', 'max_completion_tokens', 'max_tokens'] as $key) {
                if (isset($parameters[$key]) && is_numeric($parameters[$key])) {
                    $body['max_output_tokens'] = (int) $parameters[$key];
                    break;
                }
            }
        }
        unset($parameters['max_output_tokens'], $parameters['max_completion_tokens'], $parameters['max_tokens']);

        // JSON mode moved under `text`. Note the API additionally requires the word
        // "json" to appear in the input, which is the caller's prompt to get right.
        if (isset($parameters['response_format'])) {
            $format = $parameters['response_format'];
            if (is_array($format) && isset($format['type'])) {
                $body['text']['format'] = $format;
            }
            unset($parameters['response_format']);
        }

        if (isset($parameters['verbosity'])) {
            $body['text']['verbosity'] = $parameters['verbosity'];
            unset($parameters['verbosity']);
        }

        // Parameters Chat Completions accepts but /responses does not.
        unset($parameters['messages'], $parameters['model'], $parameters['n'], $parameters['stop'], $parameters['stream']);

        $body = array_merge($parameters, $body);

        if ($prepared['reasoning'] !== null) {
            $this->set_nested_parameter($body, $prepared['reasoning']['param'], $prepared['reasoning']['value']);
        }

        return $body;
    }

    /**
     * Build the `input` array from chat messages.
     *
     * /responses accepts the same role/content shape, including `system`, so the
     * messages pass through; anything malformed is dropped rather than sent.
     *
     * @param mixed $messages Chat messages.
     * @return array|string
     */
    private function build_input($messages)
    {
        if (is_string($messages)) {
            return $messages;
        }

        if (!is_array($messages)) {
            return '';
        }

        $input = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['role'])) {
                continue;
            }

            $content = $message['content'] ?? '';
            if (!is_string($content) && !is_array($content)) {
                continue;
            }

            $input[] = [
                'role' => $message['role'],
                'content' => $content,
            ];
        }

        return $input;
    }

    /**
     * Write a dotted parameter path as a nested object.
     *
     * `reasoning.effort` has to become {"reasoning": {"effort": "max"}}; a flat
     * key of that name is rejected.
     *
     * @param array  $body  Request body, by reference.
     * @param string $path  Dotted path.
     * @param mixed  $value Value to set.
     * @return void
     */
    private function set_nested_parameter(array &$body, $path, $value)
    {
        $segments = explode('.', (string) $path);
        $cursor = &$body;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        unset($cursor);
    }

    public function extract_content($response)
    {
        if (!is_array($response)) {
            return null;
        }

        // Convenience field some SDKs add; the raw API does not send it.
        if (isset($response['output_text']) && is_string($response['output_text']) && $response['output_text'] !== '') {
            return $response['output_text'];
        }

        if (!isset($response['output']) || !is_array($response['output'])) {
            return null;
        }

        // Skip the reasoning item and collect the assistant message text.
        $parts = [];
        foreach ($response['output'] as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $chunk) {
                if (is_array($chunk) && ($chunk['type'] ?? '') === 'output_text' && isset($chunk['text'])) {
                    $parts[] = (string) $chunk['text'];
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return implode('', $parts);
    }
}

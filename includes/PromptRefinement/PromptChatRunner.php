<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

use PolyTrans\Core\ChatClientFactory;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptChatRunner
{
    /**
     * Execute a plain-text prompt using the same provider/model as the assistant.
     *
     * @param array<string,mixed> $assistant Assistant configuration.
     * @return array<string,mixed>|\WP_Error
     */
    public static function execute(array $assistant, string $userPrompt, string $systemPrompt, string $errorPrefix)
    {
        $provider = (string) ($assistant['provider'] ?? 'openai');
        $settings = get_option('polytrans_settings', []);
        $client = ChatClientFactory::create($provider, $settings);

        if (!$client) {
            return new \WP_Error(
                $errorPrefix . '_provider_unavailable',
                sprintf(
                    /* translators: %s: provider ID */
                    __('Provider "%s" is not available for refinement.', 'polytrans'),
                    $provider
                )
            );
        }

        $parameters = isset($assistant['api_parameters']) && is_array($assistant['api_parameters'])
            ? $assistant['api_parameters']
            : [];
        unset($parameters['migrated_from']);

        $model = isset($parameters['model']) ? trim((string) $parameters['model']) : '';
        if ($model === '') {
            $setting_key = $provider . '_model';
            $model = isset($settings[$setting_key]) ? trim((string) $settings[$setting_key]) : '';
        }
        if ($model !== '') {
            $parameters['model'] = $model;
        }

        $result = $client->chat_completion([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ], $parameters);

        if (empty($result['success'])) {
            return new \WP_Error(
                $errorPrefix . '_api_error',
                (string) ($result['error'] ?? __('Prompt refinement request failed.', 'polytrans'))
            );
        }

        $raw = $result['data'] ?? [];
        $content = $client->extract_content($raw);
        if ($content === null || trim((string) $content) === '') {
            return new \WP_Error($errorPrefix . '_empty_response', __('Prompt refinement returned an empty response.', 'polytrans'));
        }

        return [
            'content' => (string) $content,
            'provider' => $provider,
            'model' => $model,
            'usage' => is_array($raw) && isset($raw['usage']) ? $raw['usage'] : [],
        ];
    }
}

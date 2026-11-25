<?php

/**
 * Whether a managed assistant can be picked for a translation right now.
 *
 * @package PolyTrans
 * @subpackage Assistants
 */

namespace PolyTrans\Assistants;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * One rule, shared by the assistant picker and the assistant list.
 *
 * An unavailable assistant used to vanish from the picker without a word, so a
 * user who had just created one saw an empty dropdown and no reason. The picker
 * now lists it disabled, with the reason this class returns.
 */
class AssistantAvailability
{
    /**
     * Why the assistant cannot be used, or null when it can.
     *
     * @param array  $assistant         Row from AssistantManager (needs 'provider').
     * @param array  $enabled_providers `enabled_translation_providers` setting.
     * @param string $openai_api_key    OpenAI key in effect (form value or saved setting).
     * @param array  $provider_names    id => display name, from ProviderRegistry::get_provider_choices().
     */
    public static function unavailable_reason(
        array $assistant,
        array $enabled_providers,
        string $openai_api_key,
        array $provider_names = []
    ): ?string
    {
        $provider = $assistant['provider'] ?? 'openai';

        if (!in_array($provider, $enabled_providers, true)) {
            return sprintf(
                /* translators: %s: provider name, e.g. "OpenAI" */
                __('provider %s is not enabled', 'treetank-trans'),
                $provider_names[$provider] ?? ucfirst($provider)
            );
        }

        if ($provider === 'openai' && $openai_api_key === '') {
            return __('no OpenAI API key', 'treetank-trans');
        }

        return null;
    }
}

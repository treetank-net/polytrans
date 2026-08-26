<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides whether the REST endpoints may accept unauthenticated requests.
 *
 * "none" used to be one dropdown away in the settings UI, which meant a single
 * wrong click turned `POST /polytrans/v1/translation/translate` and
 * `.../translation/receive-post` into public endpoints that create posts and
 * start billable provider calls.
 *
 * The mode itself is legitimate — a translator and a receiver on an internal
 * network, reachable only from fixed addresses, have nothing to authenticate
 * against — so it stays. It just is not a UI decision any more; it takes
 * filesystem access:
 *
 *     define('POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS', true);
 *
 * in `wp-config.php`. Without the constant a stored "none" is treated as an
 * unfinished configuration and the endpoint stays closed — never as consent.
 * Pair it with the IP allow-list under PolyTrans → Settings → Advanced.
 */
class EndpointAuth
{
    /** Name of the constant that opens the endpoints. */
    public const CONSTANT = 'POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS';

    /** Value of `translation_receiver_secret_method` that means "no authentication". */
    public const METHOD_NONE = 'none';

    /**
     * True when the site owner has deliberately opened the endpoints in wp-config.php.
     *
     * Identity comparison on purpose: a truthy string in wp-config.php is a typo,
     * not a decision.
     */
    public static function allows_unauthenticated(): bool
    {
        return defined(self::CONSTANT) && constant(self::CONSTANT) === true;
    }

    /**
     * True when the stored method asks for unauthenticated access.
     *
     * @param mixed $method Value of `translation_receiver_secret_method`.
     */
    public static function is_unauthenticated_method($method): bool
    {
        return self::METHOD_NONE === $method;
    }

    /**
     * True when the configuration asks for unauthenticated access but the constant is absent.
     *
     * This is the state worth reporting: somebody chose "none" — on this version,
     * or on an older one where the UI still offered it — and the endpoints are
     * closed because of it.
     *
     * @param array $settings The `polytrans_settings` option.
     */
    public static function is_refused(array $settings): bool
    {
        $method = $settings['translation_receiver_secret_method'] ?? 'header_bearer';

        return self::is_unauthenticated_method($method) && !self::allows_unauthenticated();
    }

    /**
     * Explanation logged, and shown on the settings page, when a stored "none" is refused.
     */
    public static function refusal_message(): string
    {
        return sprintf(
            /* translators: %s: name of the PHP constant that enables unauthenticated endpoints. */
            __(
                'TreeTank: the receiver authentication method is set to "none", so the translation endpoints are closed. Set a secret under TreeTank → Settings → Advanced, or define %s as true in wp-config.php if this site really should accept unauthenticated requests.',
                'treetank-trans'
            ),
            self::CONSTANT
        );
    }
}

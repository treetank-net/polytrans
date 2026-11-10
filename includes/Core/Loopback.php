<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that sends a fire-and-forget request from the site to itself.
 *
 * Background translations and async jobs start their worker with a loopback request
 * to the site's public URL. Some hosts cannot reach that URL from inside: a container
 * behind a gateway or load balancer, where the public host name resolves to something
 * the container has no route to, or where the extra hop (DNS, TLS) does not complete
 * within the non-blocking timeout. The request is then dropped before WordPress sees
 * it, and because it is non-blocking, `wp_remote_post()` reports no error — the
 * translation stays "queued" forever and nothing is logged.
 *
 * Such a site sets the address the server answers on locally:
 *
 *     define('TREETANK_TRANS_LOOPBACK_URL', 'http://127.0.0.1');
 *
 * The request then goes to that scheme, host and port, with the original path and
 * query, and carries the public host name in the `Host` header, so WordPress builds
 * the same `home_url()` as for any other request. The `polytrans_loopback_url`
 * filter can set the same thing from code.
 */
class Loopback
{
    /**
     * Long enough for a request to be fully sent to a local server; the worker keeps
     * running after the connection is dropped (ignore_user_abort).
     */
    private const TIMEOUT = 0.5;

    /**
     * Send a non-blocking POST to a URL on this site.
     *
     * @param string $url  URL built from home_url()/admin_url().
     * @param array  $args wp_remote_post() arguments; timeout and blocking are set here.
     * @return array|\WP_Error
     */
    public static function post(string $url, array $args = [])
    {
        [$target, $host] = self::route($url);

        $args['timeout'] = self::TIMEOUT;
        $args['blocking'] = false;
        if ($host !== '') {
            $args['headers'] = array_merge($args['headers'] ?? [], ['Host' => $host]);
        }

        return wp_remote_post($target, $args);
    }

    /**
     * Where a loopback to $url actually goes.
     *
     * @param string $url URL on this site.
     * @return array{0: string, 1: string} The URL to request, and the Host header to
     *                                     send ('' to leave it to the HTTP client).
     */
    public static function route(string $url): array
    {
        $base = defined('TREETANK_TRANS_LOOPBACK_URL') ? (string) constant('TREETANK_TRANS_LOOPBACK_URL') : '';
        $base = (string) apply_filters('polytrans_loopback_url', $base, $url);

        $override = $base !== '' ? wp_parse_url($base) : false;
        $original = wp_parse_url($url);
        if (empty($override['host']) || empty($original['host'])) {
            return [$url, ''];
        }

        $host = $original['host'] . (isset($original['port']) ? ':' . $original['port'] : '');
        $target = ($override['scheme'] ?? 'http') . '://' . $override['host']
            . (isset($override['port']) ? ':' . $override['port'] : '')
            . ($original['path'] ?? '/')
            . (isset($original['query']) ? '?' . $original['query'] : '');

        return [$target, $host];
    }
}

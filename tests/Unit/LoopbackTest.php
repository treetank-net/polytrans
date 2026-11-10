<?php

declare(strict_types=1);

use PolyTrans\Core\Loopback;

/**
 * A loopback to the public URL is dropped silently on hosts that cannot reach
 * themselves under that name (a container behind a gateway). Background
 * translations then stay "queued" with nothing logged — trans.info, 1.24.2.
 * TREETANK_TRANS_LOOPBACK_URL / polytrans_loopback_url redirect the request to a
 * local address while keeping what WordPress sees: path, query and Host.
 */
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

afterEach(function () {
    unset($GLOBALS['polytrans_test_filters']['polytrans_loopback_url']);
});

it('leaves the URL alone when no loopback address is configured', function () {
    $url = 'https://news.example.test/?polytrans_bg=1&token=abc';
    expect(Loopback::route($url))->toBe([$url, '']);
});

it('sends the request to the configured address with the public Host header', function () {
    $GLOBALS['polytrans_test_filters']['polytrans_loopback_url'] = fn () => 'http://127.0.0.1';

    [$target, $host] = Loopback::route('https://news.example.test/?polytrans_bg=1&token=abc&signature=s');

    expect($target)->toBe('http://127.0.0.1/?polytrans_bg=1&token=abc&signature=s');
    expect($host)->toBe('news.example.test');
});

it('keeps the path and both ports', function () {
    $GLOBALS['polytrans_test_filters']['polytrans_loopback_url'] = fn () => 'http://localhost:8080';

    [$target, $host] = Loopback::route('http://site.test:8443/wp/wp-admin/admin-ajax.php');

    expect($target)->toBe('http://localhost:8080/wp/wp-admin/admin-ajax.php');
    expect($host)->toBe('site.test:8443');
});

it('ignores a configured value that is not a URL', function () {
    $GLOBALS['polytrans_test_filters']['polytrans_loopback_url'] = fn () => 'not a url';
    $url = 'https://news.example.test/';
    expect(Loopback::route($url))->toBe([$url, '']);
});

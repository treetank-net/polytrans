<?php

declare(strict_types=1);

/**
 * Architecture test for AJAX authorization.
 *
 * Every literal `wp_ajax_*` registration must land in a handler that verifies a
 * nonce AND a capability. Both halves matter: a nonce alone proves the request
 * came from our own screen, not that the user is allowed to act on the target.
 *
 * The check is static, so it follows one level of delegation — several handlers
 * are thin wrappers around a method that does the real work.
 */

/**
 * Extract the body of every `function <name>(` in the given sources.
 *
 * @return array<int, string> One entry per definition found.
 */
function polytrans_test_method_bodies(array $sources, string $method): array
{
    $bodies = [];

    foreach ($sources as $source) {
        $offset = 0;
        $needle = '/function\s+' . preg_quote($method, '/') . '\s*\(/';

        while (preg_match($needle, $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = strpos($source, '{', $m[0][1]);
            $offset = $m[0][1] + strlen($m[0][0]);

            if ($start === false) {
                continue;
            }

            $depth = 0;
            $length = strlen($source);

            for ($i = $start; $i < $length; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $bodies[] = substr($source, $start, $i - $start + 1);
                        $offset = $i;
                        break;
                    }
                }
            }
        }
    }

    return $bodies;
}

test('every AJAX handler verifies a nonce and a capability', function () {
    $root = dirname(__DIR__, 2);
    $files = [$root . '/polytrans.php'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $sources = [];
    foreach ($files as $file) {
        $sources[$file] = file_get_contents($file);
    }

    // Registrations whose handler authenticates by another documented means.
    $exempt = [
        // Loopback worker: the request carries no nonce and, on the nopriv
        // variant, no user session either. It authenticates with a per-job HMAC
        // token compared via hash_equals(). See AsyncJobRunner::executeWorker().
        'wp_ajax_polytrans_async_worker',
        'wp_ajax_nopriv_polytrans_async_worker',
    ];

    $nonce_pattern = '/check_ajax_referer|wp_verify_nonce|check_admin_referer/';
    $capability_pattern = '/current_user_can/';

    $registrations = [];
    $pattern = '/add_action\s*\(\s*([\'"])(wp_ajax(?:_nopriv)?_[a-z0-9_]+)\1\s*,\s*(?:\[[^\]]*?([\'"])([A-Za-z0-9_]+)\3\s*\]|([\'"])([A-Za-z0-9_]+)\5)/';

    foreach ($sources as $file => $source) {
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $registrations[$match[2]] = [
                'file' => $file,
                'method' => $match[4] !== '' ? $match[4] : $match[6],
            ];
        }
    }

    expect($registrations)->not->toBeEmpty();

    $unprotected = [];

    foreach ($registrations as $hook => $registration) {
        if (in_array($hook, $exempt, true)) {
            continue;
        }

        $method = $registration['method'];
        $bodies = polytrans_test_method_bodies([$sources[$registration['file']]], $method)
            ?: polytrans_test_method_bodies($sources, $method);

        if ($bodies === []) {
            $unprotected[$hook] = 'handler ' . $method . '() not found';
            continue;
        }

        foreach ($bodies as $body) {
            $missing = [];

            if (!preg_match($nonce_pattern, $body)) {
                $missing[] = 'nonce';
            }

            if (!preg_match($capability_pattern, $body)) {
                $missing[] = 'capability';
            }

            if ($missing === []) {
                continue;
            }

            // Thin wrapper: follow the methods it calls, one level down.
            preg_match_all('/(?:->|::)([A-Za-z0-9_]+)\s*\(/', $body, $calls);
            $delegated = '';

            foreach (array_unique($calls[1]) as $callee) {
                if ($callee === $method) {
                    continue;
                }

                foreach (polytrans_test_method_bodies($sources, $callee) as $callee_body) {
                    $delegated .= $callee_body;
                }
            }

            $missing = array_values(array_filter($missing, static function (string $requirement) use ($delegated, $nonce_pattern, $capability_pattern): bool {
                $needle = $requirement === 'nonce' ? $nonce_pattern : $capability_pattern;

                return !preg_match($needle, $delegated);
            }));

            if ($missing !== []) {
                $unprotected[$hook] = $method . '() is missing: ' . implode(', ', $missing);
            }
        }
    }

    expect($unprotected)->toBe([]);
});

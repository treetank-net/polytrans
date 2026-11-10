<?php
/**
 * HTTP helpers shared by crawl.php and translate.php: a cookie-keeping client that
 * honours SMOKE_CONNECT, failure reporting, and the PHP-error scan of a page.
 * Expects the globals $base, $jar, $failures, $baseline and $recording_baseline.
 * Runs inside the WP-CLI container, outside WordPress; never shipped.
 */

function smoke_request(string $method, string $path, ?array $form = null): array
{
    global $base, $jar;

    $url = preg_match('#^https?://#', $path) ? $path : $base . '/' . ltrim($path, '/');
    $headers = ['User-Agent: treetank-smoke'];
    // SMOKE_CONNECT: the site's public name is unreachable from here (see
    // inside-container.sh), so connect to this address and name the site in Host.
    $connect = (string) getenv('SMOKE_CONNECT');
    if ($connect !== '' && preg_match('#^(https?://)([^/]+)(.*)$#', $url, $parts)) {
        $headers[] = 'Host: ' . $parts[2];
        $url = 'http://' . $connect . $parts[3];
    }
    if ($jar) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $content = '';
    if ($form !== null) {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 120,
    ]]);
    $body = @file_get_contents($url, false, $context);

    $status = 0;
    $location = null;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        } elseif (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($header, 11));
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($value === '' || $value === 'deleted' || preg_match('/expires=[^;]*1970|max-age=0/i', $cookie)) {
                unset($jar[$name]);
            } else {
                $jar[$name] = $value;
            }
        }
    }

    return [$status, $location, (string) $body];
}

/** Report a failure; one that repeats on later screens is counted, not reprinted. */
function smoke_fail(string $message, string $where = ''): void
{
    global $failures;
    if (isset($failures[$message])) {
        $failures[$message]++;
        return;
    }
    $failures[$message] = 1;
    echo '  ✗ ' . ($where !== '' ? "{$where}: " : '') . "{$message}\n";
}

/** PHP errors printed into the page (WP_DEBUG_DISPLAY is on) and core's fatal-error screen. */
function smoke_check_page(string $label, int $status, string $body, array $ok_status = [200]): void
{
    global $recording_baseline;
    if (!in_array($status, $ok_status, true) && !$recording_baseline) {
        smoke_fail("HTTP {$status}", $label);
    }
    if (preg_match_all('/(Fatal error|Warning|Notice|Deprecated|Parse error)(?:<\/b>)?:\s+(.{0,300}?)\s+in\s+(?:<b>)?(\/[^<\s]+)(?:<\/b>)?\s+on line/s', $body, $m, PREG_SET_ORDER)) {
        global $baseline, $recording_baseline;
        foreach ($m as $error) {
            $message = "PHP {$error[1]}: " . strip_tags($error[2]) . " ({$error[3]})";
            if ($recording_baseline) {
                $baseline[$message] = true;
            } elseif (!isset($baseline[$message])) {
                smoke_fail($message, $label);
            }
        }
    }
    if (strpos($body, 'There has been a critical error on this website') !== false) {
        smoke_fail("WordPress critical error screen", $label);
    }
}

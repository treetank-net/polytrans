<?php
/**
 * Activation smoke crawler — what a WordPress.org reviewer does by hand, as a script.
 *
 * Logs in as the administrator, activates the plugin from plugins.php the way a person
 * would (so "unexpected output" is caught exactly as WordPress reports it), then opens
 * the dashboard, the core screens and every screen the plugin adds to the admin menu.
 *
 * Usage: php crawl.php <base-url> <smoke-dir>
 * Exit status: 0 when every check passed, 1 otherwise. Findings go to stdout.
 *
 * Runs inside the WP-CLI container, outside WordPress; never shipped.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$smoke_dir = rtrim($argv[2] ?? '/var/www/html/smoke', '/');
$plugin = 'treetank-trans/treetank-trans.php';

$jar = [];
$failures = [];
// PHP errors core prints before the plugin is active. On an old WordPress running a newer
// PHP these are many (6.0 on 8.1 logs Requests deprecations on every screen); only what
// appears after activation is the plugin's.
$baseline = [];
$recording_baseline = false;

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

// 1. Log in.
smoke_request('GET', 'wp-login.php');
[$status, $location] = smoke_request('POST', 'wp-login.php', [
    'log' => 'a',
    'pwd' => 'a',
    'wp-submit' => 'Log In',
    'redirect_to' => $base . '/wp-admin/',
    'testcookie' => '1',
]);
if ($status !== 302) {
    fwrite(STDERR, "Login failed (HTTP {$status}); cannot continue.\n");
    exit(1);
}
echo "Logged in as administrator\n";

$core_screens = [
    'wp-admin/index.php',
    'wp-admin/edit.php',
    'wp-admin/post-new.php',
    'wp-admin/post.php?post=1&action=edit',
    'wp-admin/edit.php?post_type=page',
    'wp-admin/post-new.php?post_type=page',
    'wp-admin/edit-tags.php?taxonomy=category',
    'wp-admin/edit-tags.php?taxonomy=post_tag',
    'wp-admin/upload.php',
    'wp-admin/users.php',
    'wp-admin/profile.php',
    'wp-admin/tools.php',
    'wp-admin/options-general.php',
    'wp-admin/plugins.php',
    'wp-admin/themes.php',
];

// 2. Baseline: the same screens with the plugin inactive. Then mark debug.log, so
//    inside-container.sh compares only what was logged after activation.
echo "Baseline (plugin inactive)\n";
$recording_baseline = true;
foreach ($core_screens as $screen) {
    [$status, , $body] = smoke_request('GET', $screen);
    smoke_check_page($screen, $status, $body);
}
$recording_baseline = false;
echo '  ' . count($baseline) . " distinct PHP message(s) from core alone\n";
file_put_contents($smoke_dir . '/debug.log', "=== TREETANK SMOKE: ACTIVATION ===\n", FILE_APPEND);

// 3. Activate from plugins.php, through the link a person clicks.
echo "Activation\n";
[$status, , $body] = smoke_request('GET', 'wp-admin/plugins.php');
smoke_check_page('plugins.php (before activation)', $status, $body);
if (!preg_match('#href="([^"]*action=activate[^"]*plugin=' . preg_quote(rawurlencode($plugin), '#') . '[^"]*)"#', $body, $m)) {
    fwrite(STDERR, "No activation link for {$plugin} on plugins.php; is it installed and inactive?\n");
    exit(1);
}
$activate = html_entity_decode($m[1]);
[$status, $location, $body] = smoke_request('GET', 'wp-admin/' . ltrim($activate, '/'));
if ($status !== 302 || $location === null) {
    smoke_check_page('activation', $status, $body, [302]);
    smoke_fail("activation did not redirect (HTTP {$status})");
} elseif (strpos($location, 'error=true') !== false) {
    // WordPress redirects here with charsout=N when activation printed N characters.
    smoke_fail("activation failed or produced output: {$location}");
    [, , $error_body] = smoke_request('GET', $location);
    if (preg_match('#<div[^>]*id="message"[^>]*>(.*?)</div>#s', $error_body, $msg)) {
        echo '    ' . trim(strip_tags($msg[1])) . "\n";
    }
} else {
    echo "  ✓ activated without output\n";
    [$status, , $body] = smoke_request('GET', $location);
    smoke_check_page('plugins.php (after activation)', $status, $body);
}

// 4. The first admin_init after activation runs on the dashboard — the reviewer's case.
echo "Admin screens\n";
[$status, , $dashboard] = smoke_request('GET', 'wp-admin/');
smoke_check_page('dashboard', $status, $dashboard);


// Every admin.php?page=… link in the menu. A fresh install has none of its own,
// so all of them belong to the plugin.
preg_match_all('#href=["\'](?:[^"\']*/wp-admin/)?(admin\.php\?page=[^"\'&\#]+)#', $dashboard, $links);
$plugin_screens = array_values(array_unique(array_map(static fn ($l) => 'wp-admin/' . html_entity_decode($l), $links[1])));
if (!$plugin_screens) {
    smoke_fail('no plugin screens found in the admin menu');
}

foreach (array_merge($core_screens, $plugin_screens) as $screen) {
    [$status, , $body] = smoke_request('GET', $screen);
    smoke_check_page($screen, $status, $body);
    echo "  · {$screen} (HTTP {$status})\n";
}

// 5. Prompt round-trip. Prompts carry XML-style tags (<prompt>, <assistant>, <schema>) on
//    purpose, and the editors are printed through wp_kses(). The tags must reach the
//    browser as text — entity-encoded in a field, or JSON-encoded in a script — never be
//    parsed as markup and stripped.
echo "Prompt round-trip\n";
$seed = trim((string) shell_exec('php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html eval-file /smoke-tools/seed-prompts.php 2>&1'));
if (!preg_match('/^(\d+) (\S+)$/', $seed, $ids)) {
    smoke_fail("could not seed prompts: {$seed}");
} else {
    $prompt = file_get_contents(__DIR__ . '/prompt-fixture.txt');
    $editors = [
        'assistant editor' => "wp-admin/admin.php?page=polytrans-assistants&action=edit&assistant_id={$ids[1]}",
        'workflow editor' => "wp-admin/admin.php?page=polytrans-workflows&action=edit&workflow_id={$ids[2]}",
        'settings textarea' => 'wp-admin/admin.php?page=polytrans-settings',
        'logs table' => 'wp-admin/admin.php?page=polytrans-logs',
    ];
    foreach ($editors as $label => $screen) {
        [$status, , $body] = smoke_request('GET', $screen);
        smoke_check_page($label, $status, $body);
        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $found = [];
        if (strpos($decoded, $prompt) !== false && strpos($body, $prompt) === false) {
            $found[] = 'entity-encoded';
        } elseif ($label === 'logs table' && strpos($decoded, html_entity_decode($prompt, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== false) {
            // esc_html() does not double-encode, so a literal "&amp;" in a log message
            // shows as "&". Display only, and older than wp_kses here; tags are intact.
            $found[] = 'entity-encoded, esc_html';
        }
        // Any JSON string literal that decodes to the prompt, however it was escaped
        // (\u00f3, <\/prompt>, WordPress's <\u0073cript>), in a script or an attribute.
        foreach ([$body, $decoded] as $haystack) {
            if (preg_match_all('/"((?:[^"\\\\]|\\\\.){100,})"/s', $haystack, $literals)) {
                foreach ($literals[1] as $literal) {
                    if (json_decode('"' . $literal . '"') === $prompt) {
                        $found[] = 'JSON';
                        break 2;
                    }
                }
            }
        }
        if (strpos($body, $prompt) !== false) {
            // Raw in the page means the browser parses it as markup.
            smoke_fail('prompt printed as raw markup, not as text', $label);
        } elseif (!$found) {
            $dump = $smoke_dir . '/' . str_replace(' ', '-', $label) . '.html';
            file_put_contents($dump, $body);
            smoke_fail("prompt text altered or missing — tags stripped by escaping? (page saved as {$dump})", $label);
        } else {
            echo "  ✓ {$label}: prompt intact (" . implode(', ', array_unique($found)) . ")\n";
        }
    }
    // The seed above writes through AssistantManager; the editor saves through admin-ajax.
    // 1.24.0 passed every check above while that save path ran sanitize_textarea_field()
    // over the prompt, deleting every tag and <!--more--> before it reached the database.
    [, , $editor] = smoke_request('GET', "wp-admin/admin.php?page=polytrans-assistants&action=edit&assistant_id={$ids[1]}");
    if (!preg_match('/var polytransAssistants = \{.*?"nonce":"([a-f0-9]+)"/s', $editor, $nonce)) {
        smoke_fail('no polytransAssistants nonce on the assistant editor', 'assistant save');
    } else {
        foreach (['update' => (int) $ids[1], 'create' => 0] as $mode => $id) {
            [$status, , $body] = smoke_request('POST', 'wp-admin/admin-ajax.php', [
                'action' => 'polytrans_save_assistant',
                'nonce' => $nonce[1],
                'assistant_id' => $id,
                'name' => 'Smoke prompt save (' . $mode . ')',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'system_prompt' => $prompt,
                'user_message_template' => $prompt,
                'response_format' => 'text',
            ]);
            $reply = json_decode($body, true);
            $saved_id = $id ?: (int) ($reply['data']['assistant_id'] ?? $reply['data']['id'] ?? 0);
            if ($status !== 200 || empty($reply['success']) || $saved_id <= 0) {
                smoke_fail("admin-ajax save failed (HTTP {$status}): " . substr($body, 0, 300), "assistant save ({$mode})");
                continue;
            }
            $stored = (string) shell_exec('php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html eval '
                . escapeshellarg('$a = \\PolyTrans\\Assistants\\AssistantManager::get_assistant(' . $saved_id . '); echo wp_json_encode([$a["system_prompt"] ?? null, $a["user_message_template"] ?? null]);') . ' 2>&1');
            $fields = json_decode($stored, true);
            if (!is_array($fields) || $fields[0] !== $prompt || $fields[1] !== $prompt) {
                $dump = $smoke_dir . "/assistant-save-{$mode}.json";
                file_put_contents($dump, $stored);
                smoke_fail("prompt altered by the admin save (stored value in {$dump})", "assistant save ({$mode})");
            } else {
                echo "  ✓ assistant save ({$mode}): prompt stored byte for byte\n";
            }
        }
    }
}

// 5b. Background work actually runs. 1.24.2 passed everything above on trans.info
// while every background translation stayed "queued": the loopback to the public URL
// never reached the server, and a non-blocking request reports no error. Seeing the
// dispatch succeed proves nothing; the worker has to have done the job.
echo "Background worker\n";
[, , $editor] = smoke_request('GET', 'wp-admin/admin.php?page=polytrans-assistants&action=new');
if (!preg_match('/var polytransAssistants = \{.*?"nonce":"([a-f0-9]+)"/s', $editor, $nonce)) {
    smoke_fail('no polytransAssistants nonce on the assistant editor', 'background worker');
} else {
    [$status, , $body] = smoke_request('POST', 'wp-admin/admin-ajax.php', [
        'action' => 'polytrans_dispatch_assistant_job',
        'nonce' => $nonce[1],
        'job_type' => 'assistant_test',
        'assistant_id' => (int) ($ids[1] ?? 0),
        'source_language' => 'en',
        'target_language' => 'pl',
        'title' => 'Hello',
        'content' => '<p>Hello</p>',
    ]);
    $job_id = (string) (json_decode($body, true)['data']['job_id'] ?? '');
    if ($job_id === '') {
        smoke_fail("dispatch failed (HTTP {$status}): " . substr($body, 0, 300), 'background worker');
    } else {
        $state = 'pending';
        for ($i = 0; $i < 30 && in_array($state, ['pending', 'running'], true); $i++) {
            sleep(1);
            [, , $body] = smoke_request('POST', 'wp-admin/admin-ajax.php', [
                'action' => 'polytrans_poll_assistant_job',
                'nonce' => $nonce[1],
                'job_id' => $job_id,
            ]);
            $state = (string) (json_decode($body, true)['data']['status'] ?? 'missing');
        }
        // No API key is configured, so the job is expected to end as failed — what
        // matters is that a worker picked it up at all.
        if (in_array($state, ['pending', 'running', 'missing'], true)) {
            smoke_fail("job {$job_id} still {$state} after 30 s: no worker ran it", 'background worker');
        } else {
            echo "  ✓ async job picked up by a worker (final status: {$state})\n";
        }
        $ran = trim((string) shell_exec('php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html eval '
            . escapeshellarg('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}polytrans_logs WHERE message LIKE \'Started background task processing: async_job%\'");') . ' 2>&1'));
        if ($ran === '0' || !ctype_digit($ran)) {
            smoke_fail("the background endpoint (?polytrans_bg=1) never ran a task: {$ran}", 'background worker');
        } else {
            echo "  ✓ background endpoint ran {$ran} task(s)\n";
        }
    }
}

// 6. Assets only on the plugin's own screens and the post editor (its meta boxes).
echo "Asset scoping\n";
$asset_log = $smoke_dir . '/assets.jsonl';
$scoped = 0;
foreach (is_file($asset_log) ? file($asset_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
    $entry = json_decode($line, true);
    if (!is_array($entry) || empty($entry['assets'])) {
        continue;
    }
    $uri = (string) $entry['uri'];
    $allowed = preg_match('#/wp-admin/(admin\.php\?page=|post\.php|post-new\.php)#', $uri);
    if (!$allowed) {
        smoke_fail("plugin assets on {$uri} (screen {$entry['screen']}): " . implode(', ', $entry['assets']));
    }
    $scoped++;
}
echo "  {$scoped} screen(s) printed plugin assets\n";

echo "\n";
if ($failures) {
    echo count($failures) . ' distinct crawler failure(s), ' . array_sum($failures) . " in total\n";
    exit(1);
}
echo "Crawler: all checks passed\n";
exit(0);

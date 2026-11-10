<?php
/**
 * Background translation, end to end: what an editor does on trans.info, as a script.
 *
 * Logs in, opens the source post in the editor, schedules a translation the way the
 * meta box does (admin-ajax polytrans_schedule_translation), and waits for the result.
 * The translation runs in the BackgroundProcessor loopback (?polytrans_bg=1), then the
 * workflow triggered on completion writes smoke_workflow_marker on the translated post.
 *
 * Both failures of 1.24.2–1.24.3 end here, silently, as "still translating" or as a
 * translated post without the marker — the dispatch itself always reported success:
 *  - the loopback never reached the server (public URL unreachable from inside);
 *  - the endpoint ran on init priority 5, before WorkflowManager hooked
 *    polytrans_translation_completed, so no workflow ever started.
 *
 * Usage: php translate.php <base-url> <smoke-dir> <source-post-id>
 * Runs inside the WP-CLI container, outside WordPress; never shipped.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$smoke_dir = rtrim($argv[2] ?? '/var/www/html/smoke', '/');
$post_id = (int) ($argv[3] ?? 0);
$jar = [];
$failures = [];
// Core's own messages (an old WordPress on a newer PHP), recorded by crawl.php.
$baseline = array_fill_keys((array) json_decode((string) @file_get_contents($smoke_dir . '/baseline.json'), true), true);
$recording_baseline = false;
require __DIR__ . '/smoke-lib.php';

/** Evaluate PHP inside WordPress and decode its JSON output. */
function smoke_wp_json(string $php)
{
    $out = (string) shell_exec('php -d memory_limit=1G /usr/local/bin/wp --allow-root --path=/var/www/html eval '
        . escapeshellarg($php) . ' 2>&1');
    $decoded = json_decode(trim($out), true);
    return $decoded === null ? ['raw' => trim($out)] : $decoded;
}

smoke_request('GET', 'wp-login.php');
[$status] = smoke_request('POST', 'wp-login.php', [
    'log' => 'a', 'pwd' => 'a', 'wp-submit' => 'Log In', 'redirect_to' => $base . '/wp-admin/', 'testcookie' => '1',
]);
if ($status !== 302) {
    smoke_fail("login failed (HTTP {$status})", 'background translation');
    exit(1);
}

[$status, , $editor] = smoke_request('GET', "wp-admin/post.php?post={$post_id}&action=edit");
smoke_check_page('post editor (Polylang)', $status, $editor);
if (!preg_match('/var PolyTransScheduler = \{.*?"nonce":"([a-f0-9]+)"/s', $editor, $nonce)) {
    smoke_fail('no PolyTransScheduler nonce on the post editor', 'background translation');
    exit(1);
}

[$status, , $body] = smoke_request('POST', 'wp-admin/admin-ajax.php', [
    'action' => 'polytrans_schedule_translation',
    '_ajax_nonce' => $nonce[1],
    'post_id' => $post_id,
    'scope' => 'local',
    'targets' => ['de'],
]);
$reply = json_decode($body, true);
if ($status !== 200 || empty($reply['success'])) {
    smoke_fail("scheduling failed (HTTP {$status}): " . substr($body, 0, 300), 'background translation');
    exit(1);
}
echo "  ✓ translation en → de scheduled through admin-ajax\n";

$state = [];
for ($i = 0; $i < 60; $i++) {
    sleep(1);
    $state = smoke_wp_json('$s = get_post_meta(' . $post_id . ', "_polytrans_translation_status_de", true);'
        . ' $t = (int) get_post_meta(' . $post_id . ', "_polytrans_translation_post_id_de", true);'
        . ' echo wp_json_encode(["status" => $s, "post" => $t, "title" => $t ? get_the_title($t) : null,'
        . ' "lang" => $t && function_exists("pll_get_post_language") ? pll_get_post_language($t) : null,'
        . ' "marker" => $t ? get_post_meta($t, "smoke_workflow_marker", true) : null]);');
    if (($state['status'] ?? '') === 'failed' || (!empty($state['post']) && ($state['marker'] ?? '') !== '')) {
        break;
    }
}

$log = smoke_wp_json('global $wpdb; echo wp_json_encode($wpdb->get_col("SELECT message FROM {$wpdb->prefix}polytrans_logs'
    . ' WHERE message LIKE \'Workflow summary%\' OR message LIKE \'Started background task processing%\''
    . ' OR level = \'error\' ORDER BY id"));');

if (($state['status'] ?? '') !== 'completed' || empty($state['post'])) {
    smoke_fail('translation not completed after 60 s (status: ' . ($state['status'] ?? $state['raw'] ?? '?')
        . ') — the background endpoint never ran it', 'background translation');
} else {
    echo "  ✓ translated in the background: post {$state['post']} \"{$state['title']}\" ({$state['lang']})\n";
    if (($state['lang'] ?? '') !== 'de') {
        smoke_fail('translated post is not assigned to Polylang language de', 'background translation');
    }
    if (($state['marker'] ?? '') !== 'smoke-workflow-ok') {
        smoke_fail('the workflow triggered on translation completion did not run (no smoke_workflow_marker on the translated post)', 'background translation');
    } else {
        echo "  ✓ workflow after translation ran: smoke_workflow_marker = {$state['marker']}\n";
    }
}
if ($failures) {
    echo "  log:\n";
    foreach ((array) $log as $line) {
        echo '    ' . substr((string) $line, 0, 200) . "\n";
    }
    exit(1);
}
exit(0);

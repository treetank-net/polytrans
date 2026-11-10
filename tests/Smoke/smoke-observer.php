<?php
/**
 * Plugin Name: TreeTank smoke observer
 * Description: Must-use plugin installed only by tests/Smoke/activation-smoke.sh. Never shipped.
 *
 * Records two things the crawler cannot see in the HTML alone:
 *  - every external HTTP request the plugin makes (and blocks it), for Guideline 7;
 *  - which of the plugin's styles and scripts each admin screen printed.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TREETANK_SMOKE_DIR', ABSPATH . 'smoke');

/** True when the current call stack passes through the plugin under test. */
function treetank_smoke_called_from_plugin(): bool
{
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Test tooling.
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        if (isset($frame['file']) && strpos($frame['file'], '/plugins/treetank-trans/') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * The key seed-translation.php configures. Only requests carrying it are answered by the
 * stub below, so the unconfigured part of the test still sees every external request.
 */
define('TREETANK_SMOKE_STUB_KEY', 'sk-smoke-stub-not-a-real-key');

/**
 * Canned OpenAI answers for the background translation check (translate.php): the
 * translation step gets the JSON a managed assistant returns, the workflow step a fixed
 * marker its output action writes to post meta. Both API surfaces are answered, so the
 * test does not depend on which one ModelCapabilities routes the model to. The price
 * list (openrouter.ai) is answered empty: calls are then recorded as unpriced.
 */
add_filter('pre_http_request', static function ($pre, $args, $url) {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    $settings = get_option('polytrans_settings', []);
    if (($settings['openai_api_key'] ?? '') !== TREETANK_SMOKE_STUB_KEY) {
        return $pre;
    }
    if ($host === 'openrouter.ai') {
        return ['headers' => [], 'body' => '{"data":[]}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
    if ($host !== 'api.openai.com') {
        return $pre;
    }
    $auth = '';
    foreach ((array) ($args['headers'] ?? []) as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
            $auth = (string) $value;
        }
    }
    if (strpos($auth, TREETANK_SMOKE_STUB_KEY) === false) {
        return $pre;
    }

    $request = is_string($args['body'] ?? null) ? $args['body'] : wp_json_encode($args['body'] ?? '');
    $text = strpos($request, 'SMOKE-TRANSLATE') !== false
        ? wp_json_encode(['title' => 'Smoke Beitrag', 'content' => '<p>Hallo vom Smoke-Test.</p>', 'excerpt' => 'Smoke Auszug'])
        : 'smoke-workflow-ok';
    file_put_contents(TREETANK_SMOKE_DIR . '/openai-stub.log', $url . ' ' . (strpos($request, 'SMOKE-TRANSLATE') !== false ? 'translate' : 'other') . "\n", FILE_APPEND);

    if (substr((string) wp_parse_url($url, PHP_URL_PATH), -strlen('/responses')) === '/responses') {
        $body = [
            'id' => 'resp_smoke', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o-mini-2024-07-18',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'output_text' => $text,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ];
    } else {
        $body = [
            'id' => 'chatcmpl-smoke', 'object' => 'chat.completion', 'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    return ['headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 0, 3);

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre !== false) {
        return $pre;
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    $site = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if ($host === $site || in_array($host, ['localhost', '127.0.0.1'], true) || !treetank_smoke_called_from_plugin()) {
        return $pre;
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli';
    file_put_contents(TREETANK_SMOKE_DIR . '/external-http.log', $uri . ' -> ' . $url . "\n" . (new Exception())->getTraceAsString() . "\n\n", FILE_APPEND);

    return new WP_Error('treetank_smoke_blocked', 'External request blocked by the smoke test.');
}, 1, 3);

add_action('admin_print_footer_scripts', static function () {
    $ours = [];
    foreach (['style' => wp_styles(), 'script' => wp_scripts()] as $kind => $dependencies) {
        foreach ($dependencies->done as $handle) {
            $src = isset($dependencies->registered[$handle]) ? (string) $dependencies->registered[$handle]->src : '';
            if (strpos($handle, 'polytrans') === 0 || strpos($handle, 'treetank') === 0 || strpos($src, '/plugins/treetank-trans/') !== false) {
                $ours[] = $kind . ':' . $handle;
            }
        }
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    file_put_contents(TREETANK_SMOKE_DIR . '/assets.jsonl', wp_json_encode([
        'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'screen' => $screen ? $screen->id : '',
        'assets' => $ours,
    ]) . "\n", FILE_APPEND);
}, PHP_INT_MAX);

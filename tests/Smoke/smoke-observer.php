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

add_filter('pre_http_request', static function ($pre, $args, $url) {
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

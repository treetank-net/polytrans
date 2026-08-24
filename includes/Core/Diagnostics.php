<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's only route to the server's PHP error log.
 *
 * PolyTrans has its own log (see LogsManager), which is where anything a site
 * owner needs to read belongs. What is left are developer breadcrumbs about the
 * plugin's own plumbing — table migrations, settings round-trips, provider
 * responses that did not parse. Those used to call `error_log()` directly from
 * 109 places, each with its own `phpcs:ignore`, and each writing to the host's
 * error log on every production request that reached it.
 *
 * Now they go through here and are silent unless the site has debug logging
 * switched on. One decision, one place, one annotation.
 */
class Diagnostics
{
    /**
     * Write a developer breadcrumb to the PHP error log when debug logging is on.
     *
     * @param string $message Message to record. The plugin prefix is added here.
     */
    public static function log($message)
    {
        if (!self::enabled()) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The single, debug-gated route to the PHP error log; see the class docblock.
        error_log('[polytrans] ' . $message);
    }

    /**
     * True when the site asked for debug logging.
     *
     * WP_DEBUG alone is not enough: with WP_DEBUG_DISPLAY on and WP_DEBUG_LOG off,
     * writing to the error log is exactly what the site owner did not ask for.
     */
    private static function enabled()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        return !defined('WP_DEBUG_LOG') || WP_DEBUG_LOG;
    }
}

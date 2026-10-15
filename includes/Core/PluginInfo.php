<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facts about the installed plugin, read from the main file's header.
 *
 * The header is the only place the product name is written down. Code that shows
 * the name asks here instead of spelling it, so a package built under a different
 * name (the build rewrites `Plugin Name:`) shows that name everywhere.
 */
class PluginInfo
{
    /** @var string|null */
    private static $name = null;

    /** The `Plugin Name:` from the main file's header. */
    public static function name(): string
    {
        if (self::$name === null) {
            $header = get_file_data(POLYTRANS_PLUGIN_FILE, ['name' => 'Plugin Name']);
            self::$name = trim((string) ($header['name'] ?? ''));
        }

        return self::$name !== '' ? self::$name : __('Translations', 'treetank-trans');
    }
}

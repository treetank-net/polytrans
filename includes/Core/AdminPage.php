<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recognises the plugin's admin screens in `admin_enqueue_scripts`.
 *
 * WordPress names a submenu screen `{parent}_page_{slug}`, where `{parent}` is
 * sanitize_title() of the top-level menu's *title*, not its slug. That title is
 * translated and has changed with the product name ("PolyTrans" → "TreeTank" →
 * "Translations"), and each change silently stopped every screen comparing against
 * a literal `polytrans_page_…` from loading its scripts. Match on the page slug,
 * which is stable, and never spell the prefix.
 */
class AdminPage
{
    /**
     * @param string $hook_suffix The `$hook_suffix` passed to admin_enqueue_scripts.
     * @param string $page_slug   The menu slug the page was registered with.
     */
    public static function is($hook_suffix, string $page_slug): bool
    {
        $hook_suffix = (string) $hook_suffix;

        return $hook_suffix === 'toplevel_page_' . $page_slug
            || str_ends_with($hook_suffix, '_page_' . $page_slug);
    }
}

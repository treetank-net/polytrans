<?php

/**
 * Template Assets Manager for PolyTrans
 * 
 * Handles asset enqueuing and localization for Twig templates
 * 
 * @package PolyTrans
 * @subpackage Templating
 * @since 1.7.0
 */

namespace PolyTrans\Templating;

if (!defined('ABSPATH')) {
    exit;
}

class TemplateAssets
{
    /**
     * Registered assets for current template
     *
     * @var array<string, array>
     */
    private static array $assets = [];

    /**
     * Localized script data
     *
     * @var array<string, array>
     */
    private static array $localized_data = [];

    /**
     * Register assets to be enqueued
     *
     * @param array<string, mixed> $assets_config Assets configuration
     * @return void
     */
    public static function register_assets(array $assets_config): void
    {
        self::$assets = $assets_config;
    }

    /**
     * Register localized script data
     *
     * @param string $script_handle Script handle to localize
     * @param string $object_name JavaScript object name
     * @param array<string, mixed> $data Data to localize
     * @return void
     */
    public static function register_localized_data(string $script_handle, string $object_name, array $data): void
    {
        if (!isset(self::$localized_data[$script_handle])) {
            self::$localized_data[$script_handle] = [];
        }
        self::$localized_data[$script_handle][$object_name] = $data;
    }

    /**
     * Enqueue all registered assets
     *
     * @return void
     */
    public static function enqueue_all(): void
    {
        // Enqueue scripts
        if (isset(self::$assets['scripts'])) {
            foreach (self::$assets['scripts'] as $handle => $config) {
                $src = $config['src'] ?? '';
                $deps = $config['deps'] ?? [];
                $version = $config['version'] ?? POLYTRANS_VERSION;
                $in_footer = $config['in_footer'] ?? true;

                wp_enqueue_script($handle, $src, $deps, $version, $in_footer);
            }
        }

        // Enqueue styles
        if (isset(self::$assets['styles'])) {
            foreach (self::$assets['styles'] as $handle => $config) {
                $src = $config['src'] ?? '';
                $deps = $config['deps'] ?? [];
                $version = $config['version'] ?? POLYTRANS_VERSION;

                wp_enqueue_style($handle, $src, $deps, $version);
            }
        }

        // Localize scripts
        foreach (self::$localized_data as $script_handle => $localizations) {
            foreach ($localizations as $object_name => $data) {
                wp_localize_script($script_handle, $object_name, $data);
            }
        }
    }

    /**
     * Add inline script
     *
     * @param string $handle Script handle
     * @param string $data JavaScript code
     * @param string $position Position (before or after)
     * @return void
     */
    public static function add_inline_script(string $handle, string $data, string $position = 'after'): void
    {
        wp_add_inline_script($handle, $data, $position);
    }

    /**
     * Reset assets (for testing or cleanup)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$assets = [];
        self::$localized_data = [];
    }

    /**
     * Get registered assets
     *
     * @return array<string, array>
     */
    public static function get_assets(): array
    {
        return self::$assets;
    }
}

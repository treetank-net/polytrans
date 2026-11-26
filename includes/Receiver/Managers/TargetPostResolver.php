<?php

namespace PolyTrans\Receiver\Managers;

/**
 * Decides whether a received translation creates a new post or updates the
 * translation that already exists for the target language.
 *
 * "create" (default) is what PolyTrans has always done: every run produces a new
 * post and Polylang's group is repointed at it. "update" writes into the post the
 * group already holds for that language, so translating the same content again -
 * possibly from a different source language - refreshes it instead of leaving the
 * previous translation orphaned.
 *
 * The mode is a setting (`translation_target_mode`) and can be overridden per post
 * through the `polytrans_translation_target_mode` filter, e.g. by an add-on that
 * wants update semantics for its own post type only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TargetPostResolver
{
    public const MODE_CREATE = 'create';
    public const MODE_UPDATE = 'update';

    /**
     * @return string self::MODE_CREATE or self::MODE_UPDATE
     */
    public static function mode($original_post_id, $target_language)
    {
        $settings = get_option('polytrans_settings', []);
        $mode = is_array($settings) ? ($settings['translation_target_mode'] ?? self::MODE_CREATE) : self::MODE_CREATE;

        /**
         * Filter whether a translation creates a new post or updates the existing one.
         *
         * @param string $mode             'create' or 'update'
         * @param int    $original_post_id Post the translation was made from
         * @param string $target_language  Language being delivered
         */
        $mode = apply_filters('polytrans_translation_target_mode', $mode, (int) $original_post_id, (string) $target_language);

        return $mode === self::MODE_UPDATE ? self::MODE_UPDATE : self::MODE_CREATE;
    }

    /**
     * The post to update for this delivery, or 0 to create a new one.
     */
    public static function resolve($original_post_id, $target_language)
    {
        if (self::mode($original_post_id, $target_language) !== self::MODE_UPDATE) {
            return 0;
        }

        return self::find_existing($original_post_id, $target_language);
    }

    /**
     * The existing translation of $original_post_id into $target_language, or 0.
     *
     * Polylang's group is asked first; the original's own pointer is the fallback for
     * sites where the group was not saved. A candidate must be another post of the same
     * type, not in the trash, and - when Polylang can tell - in the target language.
     * Without the original on this site (separate-database receiver) there is nothing
     * to look the group up by, so a new post is created as before.
     */
    public static function find_existing($original_post_id, $target_language)
    {
        $original_post_id = (int) $original_post_id;
        $original = get_post($original_post_id);
        if (!$original) {
            return 0;
        }

        $candidates = [];
        if (function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($original_post_id);
            if (is_array($translations) && !empty($translations[$target_language])) {
                $candidates[] = (int) $translations[$target_language];
            }
        }
        $candidates[] = (int) get_post_meta($original_post_id, '_polytrans_translation_post_id_' . $target_language, true);

        foreach ($candidates as $candidate_id) {
            if ($candidate_id <= 0 || $candidate_id === $original_post_id) {
                continue;
            }

            $candidate = get_post($candidate_id);
            if (!$candidate || ($candidate->post_status ?? '') === 'trash') {
                continue;
            }

            if (($candidate->post_type ?? null) !== ($original->post_type ?? null)) {
                continue;
            }

            if (function_exists('pll_get_post_language')) {
                $language = pll_get_post_language($candidate_id, 'slug');
                if ($language && $language !== $target_language) {
                    continue;
                }
            }

            return $candidate_id;
        }

        return 0;
    }
}

<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta Cleanup
 *
 * Removes the orphaned per-post state that older versions copied from an original
 * onto every translation it produced.
 *
 * `MetadataManager::should_skip_meta_key()` used to compare keys exactly against
 * '_polytrans_translation_status', while the real keys carry a language suffix, so
 * the filter never fired: each translated post inherited the original's translation
 * status, log, completion timestamp and pointers. Nothing updates those copies
 * afterwards, because status is only ever written on the original, so they sit there
 * reading 'translating' forever - and the stuck-translation scanners, which search
 * `meta_key LIKE '_polytrans_translation_status_%'` across the whole table, treat
 * them as real work in progress.
 *
 * The first version of this cleanup only removed entries naming the post's *own*
 * language, on the theory that a post can never be the origin of a translation into
 * the language it is already written in. That criterion is sound but it never fires:
 * a dry run over 72 real translations deleted 0 keys and left 462. The reason is the
 * order of writes - `_polytrans_translation_status_<lang>` appears on the original
 * only once the target post exists, so the meta that got copied onto that target
 * never contained the target's own language. Real orphans always name *other*
 * languages: an English post carrying `_polytrans_translation_post_id_it` copied
 * from the Italian job its Polish original once ran.
 *
 * So the criterion is now the pointer itself, which is checkable rather than
 * plausible. Each language group on a translation either points at a post that names
 * this post as its source - a genuine relay hub, `pl → en → de`, which the earlier
 * version was right to be afraid of - or it points somewhere else, in which case the
 * pointer belongs to another post (nearly always the original) and the whole group
 * is a copy. Where a group has no pointer at all there is nothing to check, so the
 * values are compared against the original's: byte-identical means copied, anything
 * else is left alone and reported.
 */
class MetaCleanup
{
    const OPTION_VERSION = 'polytrans_meta_cleanup_version';
    const OPTION_CURSOR = 'polytrans_meta_cleanup_cursor';

    /**
     * Bump to re-run the cleanup on existing installations.
     *
     * Version 2 replaced the own-language criterion, which recorded itself as done
     * without having deleted anything.
     */
    const VERSION = 2;

    /**
     * Posts processed per run, so a large site does not stall one admin request.
     */
    const BATCH_SIZE = 200;

    /**
     * Key prefixes that carry a target language as their suffix.
     *
     * Each is written on the post that *requested* a translation, never on the
     * translation itself - unless that translation went on to request one of its own.
     *
     * @var array
     */
    private static $language_suffixed_prefixes = [
        '_polytrans_translation_status_',
        '_polytrans_translation_log_',
        '_polytrans_translation_completed_',
        '_polytrans_translation_error_',
        '_polytrans_translation_needs_review_',
        '_polytrans_translation_target_',
        '_polytrans_translation_post_id_',
    ];

    /**
     * State the original keeps without a language suffix.
     *
     * Deliberately a short explicit list rather than everything under the prefix:
     * `_polytrans_original_post_id` and `_polytrans_source_language` also carry no
     * suffix, but they belong on the translation and deleting them would break the
     * testing and workflow-context lookups that read them.
     *
     * @var array
     */
    private static $unsuffixed_keys = [
        '_polytrans_translation_langs',
        '_polytrans_author_notified',
    ];

    /**
     * Run the cleanup once per version, continuing across requests until finished.
     *
     * @return void
     */
    public static function maybe_run()
    {
        if ((int) get_option(self::OPTION_VERSION, 0) >= self::VERSION) {
            return;
        }

        self::run();
    }

    /**
     * Process one batch.
     *
     * @param bool $dry_run When true, report what would change without changing it.
     * @return array Report for this batch.
     */
    public static function run($dry_run = false)
    {
        $cursor = (int) get_option(self::OPTION_CURSOR, 0);
        $posts = self::find_translation_posts($cursor, self::BATCH_SIZE);

        $report = [
            'posts_scanned' => 0,
            'posts_changed' => 0,
            'keys_deleted' => 0,
            'summaries_rebuilt' => 0,
            'unknown_language' => 0,
            'relay_keys_kept' => 0,
            'ambiguous_keys_kept' => 0,
            'finished' => false,
            'dry_run' => (bool) $dry_run,
        ];

        foreach ($posts as $post_id) {
            $report['posts_scanned']++;
            $cursor = max($cursor, $post_id);

            $language = self::resolve_target_language($post_id);

            if ($language === null) {
                // Only the own-language shortcut needs it; the pointer checks below do
                // not, so the post is still worth scanning.
                $report['unknown_language']++;
            }

            $outcome = self::clean_post($post_id, $language, $dry_run);

            $report['keys_deleted'] += $outcome['deleted'];
            $report['relay_keys_kept'] += $outcome['relay_kept'];
            $report['ambiguous_keys_kept'] += $outcome['ambiguous_kept'];

            if ($outcome['deleted'] > 0) {
                $report['posts_changed']++;
            }

            if (self::rebuild_usage_summary($post_id, $dry_run)) {
                $report['summaries_rebuilt']++;
            }
        }

        $report['finished'] = count($posts) < self::BATCH_SIZE;

        if (!$dry_run) {
            if ($report['finished']) {
                update_option(self::OPTION_VERSION, self::VERSION);
                delete_option(self::OPTION_CURSOR);
            } else {
                update_option(self::OPTION_CURSOR, $cursor);
            }
        }

        if ($report['posts_scanned'] > 0) {
            LogsManager::log(
                sprintf(
                    'Meta cleanup: scanned %d translated posts, removed %d copied keys from %d posts, '
                    . 'kept %d relay keys and %d ambiguous keys, rebuilt %d cost summaries%s',
                    $report['posts_scanned'],
                    $report['keys_deleted'],
                    $report['posts_changed'],
                    $report['relay_keys_kept'],
                    $report['ambiguous_keys_kept'],
                    $report['summaries_rebuilt'],
                    $report['finished'] ? '' : ' (more to do on the next request)'
                ),
                'info',
                array_merge($report, ['source' => 'meta_cleanup'])
            );
        } elseif (!$dry_run && $report['finished']) {
            update_option(self::OPTION_VERSION, self::VERSION);
        }

        return $report;
    }

    /**
     * IDs of posts marked as translation targets, ordered so a cursor can resume.
     *
     * @param int $after Only posts with a higher ID.
     * @param int $limit Maximum posts.
     * @return array
     */
    private static function find_translation_posts($after, $limit)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = 'polytrans_is_translation_target'
            AND meta_value = '1'
            AND post_id > %d
            ORDER BY post_id ASC
            LIMIT %d",
            (int) $after,
            (int) $limit
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Work out which language a translated post is written in.
     *
     * Asks the original first: it holds a pointer per target language, so the entry
     * pointing back at this post names its language. That works without Polylang and
     * without trusting a stored language field. Polylang is the fallback.
     *
     * @param int $post_id Translated post ID.
     * @return string|null Language code, or null when it cannot be established.
     */
    private static function resolve_target_language($post_id)
    {
        $source_id = (int) get_post_meta($post_id, 'polytrans_translation_source', true);

        if ($source_id > 0) {
            $source_meta = get_post_meta($source_id);

            if (is_array($source_meta)) {
                foreach ($source_meta as $key => $values) {
                    foreach (['_polytrans_translation_post_id_', '_polytrans_translation_target_'] as $prefix) {
                        if (strpos($key, $prefix) !== 0) {
                            continue;
                        }

                        $value = is_array($values) ? ($values[0] ?? null) : $values;

                        if ((int) $value === (int) $post_id) {
                            return substr($key, strlen($prefix));
                        }
                    }
                }
            }
        }

        if (function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($post_id);

            if (is_string($language) && $language !== '') {
                return $language;
            }
        }

        return null;
    }

    /**
     * Remove the copied state from one post, one language group at a time.
     *
     * A group is all or nothing: deleting only the status while leaving the pointer
     * behind would keep the post looking like the origin of a translation it never
     * requested.
     *
     * @param int         $post_id  Post ID.
     * @param string|null $language The post's own language, when it could be resolved.
     * @param bool        $dry_run  When true, count without deleting.
     * @return array ['deleted' => int, 'relay_kept' => int, 'ambiguous_kept' => int]
     */
    private static function clean_post($post_id, $language, $dry_run)
    {
        $outcome = ['deleted' => 0, 'relay_kept' => 0, 'ambiguous_kept' => 0];
        $meta = get_post_meta($post_id);

        if (!is_array($meta)) {
            return $outcome;
        }

        $source_meta = self::source_meta($post_id);

        foreach (self::group_by_language($meta) as $group_language => $keys) {
            $verdict = self::classify_group($post_id, $group_language, $language, $keys, $meta, $source_meta);

            if ($verdict === 'relay') {
                $outcome['relay_kept'] += count($keys);
                continue;
            }

            if ($verdict === 'ambiguous') {
                $outcome['ambiguous_kept'] += count($keys);
                continue;
            }

            foreach ($keys as $key) {
                if (!$dry_run) {
                    delete_post_meta($post_id, $key);
                }
                $outcome['deleted']++;
            }
        }

        foreach (self::$unsuffixed_keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            // Nothing here names a language, so the only evidence available is whether
            // the value is the original's verbatim.
            if (!self::matches_source($key, $meta, $source_meta)) {
                $outcome['ambiguous_kept']++;
                continue;
            }

            if (!$dry_run) {
                delete_post_meta($post_id, $key);
            }
            $outcome['deleted']++;
        }

        return $outcome;
    }

    /**
     * Decide what a single language group on a translation is.
     *
     * @param int         $post_id        Post being cleaned.
     * @param string      $group_language Language named by the group's suffixes.
     * @param string|null $own_language   The post's own language, when known.
     * @param array       $keys           Keys belonging to the group.
     * @param array       $meta           All meta of the post.
     * @param array       $source_meta    All meta of the post's original, or [].
     * @return string 'copy', 'relay' or 'ambiguous'.
     */
    private static function classify_group($post_id, $group_language, $own_language, array $keys, array $meta, array $source_meta)
    {
        if ($own_language !== null && $group_language === $own_language) {
            // A German post cannot be the origin of a translation into German. Rare in
            // practice - the copy is taken before the original records its own status -
            // but when it does appear it needs no corroboration.
            return 'copy';
        }

        $pointer = (int) self::value($meta, '_polytrans_translation_post_id_' . $group_language);

        if ($pointer <= 0) {
            $pointer = (int) self::value($meta, '_polytrans_translation_target_' . $group_language);
        }

        if ($pointer > 0) {
            if (!get_post($pointer)) {
                // The pointer survived the post it named, so it cannot be this post's
                // own bookkeeping either.
                return 'copy';
            }

            $pointed_source = (int) get_post_meta($pointer, 'polytrans_translation_source', true);

            // Only the post that actually commissioned the translation is named as its
            // source. Anyone else holding the pointer inherited it.
            return $pointed_source === (int) $post_id ? 'relay' : 'copy';
        }

        if ($source_meta === []) {
            return 'ambiguous';
        }

        foreach ($keys as $key) {
            if (!self::matches_source($key, $meta, $source_meta)) {
                return 'ambiguous';
            }
        }

        return 'copy';
    }

    /**
     * Index a post's language-suffixed keys by the language they name.
     *
     * @param array $meta All meta of the post.
     * @return array Language code => list of keys.
     */
    private static function group_by_language(array $meta)
    {
        $groups = [];

        foreach (array_keys($meta) as $key) {
            foreach (self::$language_suffixed_prefixes as $prefix) {
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }

                $language = substr($key, strlen($prefix));

                if ($language === '' || $language === false) {
                    break;
                }

                $groups[$language][] = $key;
                break;
            }
        }

        return $groups;
    }

    /**
     * All meta of the post's original, or an empty array when it cannot be reached.
     *
     * @param int $post_id Translated post ID.
     * @return array
     */
    private static function source_meta($post_id)
    {
        $source_id = (int) get_post_meta($post_id, 'polytrans_translation_source', true);

        if ($source_id <= 0) {
            return [];
        }

        $meta = get_post_meta($source_id);

        return is_array($meta) ? $meta : [];
    }

    /**
     * Whether a key holds exactly what the original holds under the same key.
     *
     * @param string $key         Meta key.
     * @param array  $meta        All meta of the post.
     * @param array  $source_meta All meta of the original.
     * @return bool
     */
    private static function matches_source($key, array $meta, array $source_meta)
    {
        if (!array_key_exists($key, $source_meta)) {
            return false;
        }

        $mine = self::value($meta, $key);
        $theirs = self::value($source_meta, $key);

        if (is_scalar($mine) && is_scalar($theirs)) {
            // The database stores everything as a string, so a copy can differ from its
            // source in type alone.
            return (string) $mine === (string) $theirs;
        }

        return serialize($mine) === serialize($theirs);
    }

    /**
     * First value of a key in a `get_post_meta($id)` style array.
     *
     * @param array  $meta Meta array.
     * @param string $key  Meta key.
     * @return mixed Null when absent.
     */
    private static function value(array $meta, $key)
    {
        if (!array_key_exists($key, $meta)) {
            return null;
        }

        $values = $meta[$key];

        return is_array($values) ? ($values[0] ?? null) : $values;
    }

    /**
     * Recompute a translated post's cost summary from the usage table.
     *
     * The summary was copied along with everything else, so a translation could show
     * the original's costs for every language. It is not simply deleted, because the
     * post may also have costs that are genuinely its own - workflow steps that ran
     * on it. The table knows which are which.
     *
     * @param int  $post_id Post ID.
     * @param bool $dry_run When true, report without writing.
     * @return bool Whether a summary was present and needed rebuilding.
     */
    private static function rebuild_usage_summary($post_id, $dry_run)
    {
        if (!metadata_exists('post', $post_id, UsageRecorder::META_SUMMARY)) {
            return false;
        }

        if ($dry_run) {
            return true;
        }

        UsageRecorder::rebuild_post_summary($post_id);

        return true;
    }
}

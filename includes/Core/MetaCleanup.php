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
 * The deletion criterion is deliberately narrow. A translated post is removed only
 * of the keys for *its own* language, because a post can never be the origin of a
 * translation into the language it is already written in - that makes those entries
 * impossible rather than merely suspicious. Entries for other languages are counted
 * and reported but never touched: a translation can legitimately be re-translated
 * onwards (pl → en → de), which would give the intermediate post a genuine hub of
 * its own, and no value in the row distinguishes that from a copy.
 */
class MetaCleanup
{
    const OPTION_VERSION = 'polytrans_meta_cleanup_version';
    const OPTION_CURSOR = 'polytrans_meta_cleanup_cursor';

    /**
     * Bump to re-run the cleanup on existing installations.
     */
    const VERSION = 1;

    /**
     * Posts processed per run, so a large site does not stall one admin request.
     */
    const BATCH_SIZE = 200;

    /**
     * Key prefixes that are deleted for the post's own language.
     *
     * Each is written on the original that requested the translation, never on the
     * translation itself.
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
            'skipped_unknown_language' => 0,
            'other_language_keys_left' => 0,
            'finished' => false,
            'dry_run' => (bool) $dry_run,
        ];

        foreach ($posts as $post_id) {
            $report['posts_scanned']++;
            $cursor = max($cursor, $post_id);

            $language = self::resolve_target_language($post_id);

            if ($language === null) {
                // Without knowing the post's language there is no safe criterion, so
                // it is left exactly as it is.
                $report['skipped_unknown_language']++;
                continue;
            }

            $deleted = self::clean_post($post_id, $language, $dry_run);

            $report['keys_deleted'] += $deleted['deleted'];
            $report['other_language_keys_left'] += $deleted['other_language'];

            if ($deleted['deleted'] > 0) {
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
                    'Meta cleanup: scanned %d translated posts, removed %d orphaned keys from %d posts, rebuilt %d cost summaries%s',
                    $report['posts_scanned'],
                    $report['keys_deleted'],
                    $report['posts_changed'],
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
     * Delete the impossible keys from one post.
     *
     * @param int    $post_id  Post ID.
     * @param string $language The post's own language.
     * @param bool   $dry_run  When true, count without deleting.
     * @return array ['deleted' => int, 'other_language' => int]
     */
    private static function clean_post($post_id, $language, $dry_run)
    {
        $meta = get_post_meta($post_id);
        $deleted = 0;
        $other_language = 0;

        if (!is_array($meta)) {
            return ['deleted' => 0, 'other_language' => 0];
        }

        foreach (array_keys($meta) as $key) {
            foreach (self::$language_suffixed_prefixes as $prefix) {
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }

                if (substr($key, strlen($prefix)) === $language) {
                    // This post cannot be the origin of a translation into its own
                    // language, so the entry can only be a copy.
                    if (!$dry_run) {
                        delete_post_meta($post_id, $key);
                    }
                    $deleted++;
                } else {
                    // Possibly a copy, possibly a real onward translation. Left alone.
                    $other_language++;
                }

                break;
            }
        }

        return ['deleted' => $deleted, 'other_language' => $other_language];
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

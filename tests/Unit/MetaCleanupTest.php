<?php

declare(strict_types=1);

/**
 * Unit Tests: Orphaned meta cleanup
 *
 * This deletes post meta, so the tests are mostly about what it must NOT touch: the
 * original's own state, and entries on an intermediate post that re-translates
 * onwards. The one thing it may delete is an entry naming the post's own language,
 * which no legitimate flow can produce.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Core\MetaCleanup;

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        $meta = $GLOBALS['polytrans_test_post_meta'][$post_id] ?? [];

        if ($key === '') {
            return $meta;
        }

        $values = $meta[$key] ?? [];

        return $single ? ($values[0] ?? '') : $values;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        $GLOBALS['polytrans_test_post_meta'][$post_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        return $GLOBALS['polytrans_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key, $value = '')
    {
        unset($GLOBALS['polytrans_test_post_meta'][$post_id][$key]);
        return true;
    }
}

if (!function_exists('metadata_exists')) {
    function metadata_exists($type, $post_id, $key)
    {
        return isset($GLOBALS['polytrans_test_post_meta'][$post_id][$key]);
    }
}

if (!function_exists('pll_get_post_language')) {
    function pll_get_post_language($post_id, $field = 'slug')
    {
        return $GLOBALS['polytrans_test_pll_language'] ?? false;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        unset($GLOBALS['polytrans_test_options'][$option]);
        return true;
    }
}

/**
 * Returns the post IDs that carry the translation-target marker.
 */
final class PolyTransCleanupWpdb
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';

    public function prepare($query, ...$args)
    {
        $values = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;

        foreach ($values as $value) {
            $replacement = is_int($value) || is_float($value) ? (string) $value : "'" . $value . "'";
            $query = preg_replace('/%[dsf]/', $replacement, $query, 1);
        }

        return $query;
    }

    public function get_col($query)
    {
        $ids = [];

        foreach ($GLOBALS['polytrans_test_post_meta'] as $post_id => $meta) {
            if (($meta['polytrans_is_translation_target'][0] ?? null) == 1) {
                $ids[] = (int) $post_id;
            }
        }

        sort($ids);

        return $ids;
    }

    public function get_var($query)
    {
        // No usage table in these tests, so the rebuild step is a no-op.
        return null;
    }

    public function get_results($query, $output = null)
    {
        return [];
    }
}

function polytrans_set_meta(int $post_id, array $meta): void
{
    foreach ($meta as $key => $value) {
        $GLOBALS['polytrans_test_post_meta'][$post_id][$key] = [$value];
    }
}

function polytrans_meta_keys(int $post_id): array
{
    $keys = array_keys($GLOBALS['polytrans_test_post_meta'][$post_id] ?? []);
    sort($keys);

    return $keys;
}

beforeEach(function () {
    global $wpdb;

    $wpdb = new PolyTransCleanupWpdb();
    $GLOBALS['polytrans_test_post_meta'] = [];
    $GLOBALS['polytrans_test_options'] = [];
    $GLOBALS['polytrans_test_posts'] = [];

    // A Polish original translated into German and French. The German post carries
    // the copies an older version left behind.
    polytrans_set_meta(11, [
        '_polytrans_translation_status_de' => 'completed',
        '_polytrans_translation_status_fr' => 'translating',
        '_polytrans_translation_log_de' => 'log-de',
        '_polytrans_translation_post_id_de' => 22,
        '_polytrans_translation_target_de' => 22,
        '_polytrans_translation_langs' => 'de,fr',
    ]);

    polytrans_set_meta(22, [
        'polytrans_is_translation_target' => 1,
        'polytrans_translation_source' => 11,
        'rank_math_title' => 'Deutscher Titel',
        // Copied from the original while it still read 'translating'.
        '_polytrans_translation_status_de' => 'translating',
        '_polytrans_translation_log_de' => 'log-de',
        '_polytrans_translation_completed_de' => '1700000000',
        '_polytrans_translation_post_id_de' => 22,
        '_polytrans_translation_target_de' => 22,
    ]);
});

describe('what it removes', function () {
    it('removes the entries naming the post\'s own language', function () {
        // A German post cannot be the origin of a translation into German.
        $report = MetaCleanup::run();

        expect(polytrans_meta_keys(22))->toBe([
            'polytrans_is_translation_target',
            'polytrans_translation_source',
            'rank_math_title',
        ]);
        expect($report['keys_deleted'])->toBe(5);
        expect($report['posts_changed'])->toBe(1);
    });

    it('leaves the original untouched', function () {
        // The original is where this state belongs; losing it would break the UI.
        $before = polytrans_meta_keys(11);

        MetaCleanup::run();

        expect(polytrans_meta_keys(11))->toBe($before);
        expect($GLOBALS['polytrans_test_post_meta'][11]['_polytrans_translation_status_de'][0])->toBe('completed');
    });

    it('keeps content meta on the translation', function () {
        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22]['rank_math_title'][0])->toBe('Deutscher Titel');
    });
});

describe('what it refuses to remove', function () {
    it('leaves other languages alone, since the post may translate onwards', function () {
        // pl → en → de: the English post legitimately owns a German hub of its own,
        // and nothing in the row distinguishes that from a copy.
        polytrans_set_meta(22, ['_polytrans_translation_status_fr' => 'translating']);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_fr');
        expect($report['other_language_keys_left'])->toBe(1);
    });

    it('skips a post whose language cannot be established', function () {
        // No pointer back from an original and no Polylang: no safe criterion.
        $GLOBALS['polytrans_test_post_meta'] = [];
        polytrans_set_meta(33, [
            'polytrans_is_translation_target' => 1,
            '_polytrans_translation_status_de' => 'translating',
        ]);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][33])->toHaveKey('_polytrans_translation_status_de');
        expect($report['skipped_unknown_language'])->toBe(1);
        expect($report['keys_deleted'])->toBe(0);
    });

    it('ignores posts that are not translations at all', function () {
        $report = MetaCleanup::run();

        // Only post 22 carries the marker.
        expect($report['posts_scanned'])->toBe(1);
    });

    it('changes nothing on a dry run', function () {
        $report = MetaCleanup::run(true);

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_de');
        expect($report['keys_deleted'])->toBe(5);
        expect($report['dry_run'])->toBeTrue();
        expect(get_option(MetaCleanup::OPTION_VERSION, 0))->toBe(0);
    });
});

describe('language resolution', function () {
    it('reads the language from the original\'s pointer rather than a stored field', function () {
        // polytrans_translation_lang holds the SOURCE language; trusting it would
        // delete the wrong keys.
        polytrans_set_meta(22, ['polytrans_translation_lang' => 'pl']);

        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_de');
    });

    it('falls back to Polylang when the original has no pointer', function () {
        $GLOBALS['polytrans_test_post_meta'] = [];
        polytrans_set_meta(44, [
            'polytrans_is_translation_target' => 1,
            '_polytrans_translation_status_es' => 'translating',
        ]);
        $GLOBALS['polytrans_test_pll_language'] = 'es';

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][44])->not->toHaveKey('_polytrans_translation_status_es');
        expect($report['keys_deleted'])->toBe(1);

        unset($GLOBALS['polytrans_test_pll_language']);
    });
});

describe('run bookkeeping', function () {
    it('records the version once finished so it does not run again', function () {
        MetaCleanup::run();

        expect(get_option(MetaCleanup::OPTION_VERSION, 0))->toBe(MetaCleanup::VERSION);
        expect(get_option(MetaCleanup::OPTION_CURSOR, null))->toBeNull();
    });

    it('does nothing once the recorded version is current', function () {
        update_option(MetaCleanup::OPTION_VERSION, MetaCleanup::VERSION);

        MetaCleanup::maybe_run();

        // Untouched, because maybe_run() returned before scanning.
        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_de');
    });

    it('runs when the recorded version is behind', function () {
        update_option(MetaCleanup::OPTION_VERSION, 0);

        MetaCleanup::maybe_run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_de');
    });
});

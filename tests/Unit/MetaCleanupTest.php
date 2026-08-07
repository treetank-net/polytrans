<?php

declare(strict_types=1);

/**
 * Unit Tests: Orphaned meta cleanup
 *
 * This deletes post meta, so the tests are mostly about what it must NOT touch: the
 * original's own state, and the state of an intermediate post that re-translates
 * onwards. What it may delete is a language group whose pointer proves it belongs to
 * another post, plus groups that are byte-identical to the original's.
 *
 * The fixture mirrors what the real database actually contains: an English
 * translation carrying the Italian job its Polish original once ran. The earlier
 * criterion - only entries naming the post's own language - never matched that,
 * because the copy is taken before the original records the new post's status.
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

/**
 * The pointer criterion asks whether the pointed-at post exists, so a fixture that
 * only sets meta would look like a dangling pointer.
 */
function polytrans_register_post(int $post_id): void
{
    $GLOBALS['polytrans_test_posts'][$post_id] = (object) ['ID' => $post_id];
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
    unset($GLOBALS['polytrans_test_pll_language']);

    // A Polish original (11) translated into English (22) and Italian (33). The
    // English post carries the Italian bookkeeping an older version copied onto it.
    polytrans_register_post(11);
    polytrans_register_post(22);
    polytrans_register_post(33);

    polytrans_set_meta(11, [
        '_polytrans_translation_status_en' => 'completed',
        '_polytrans_translation_status_it' => 'completed',
        '_polytrans_translation_log_it' => 'log-it',
        '_polytrans_translation_post_id_en' => 22,
        '_polytrans_translation_post_id_it' => 33,
        '_polytrans_translation_target_it' => 33,
        '_polytrans_translation_langs' => 'en,it',
    ]);

    polytrans_set_meta(22, [
        'polytrans_is_translation_target' => 1,
        'polytrans_translation_source' => 11,
        'rank_math_title' => 'English Title',
        '_polytrans_translation_status_it' => 'completed',
        '_polytrans_translation_log_it' => 'log-it',
        '_polytrans_translation_post_id_it' => 33,
        '_polytrans_translation_target_it' => 33,
        '_polytrans_translation_langs' => 'en,it',
    ]);

    polytrans_set_meta(33, [
        'polytrans_is_translation_target' => 1,
        'polytrans_translation_source' => 11,
        'rank_math_title' => 'Titolo italiano',
    ]);
});

describe('copies recognised by their pointer', function () {
    it('removes the whole group when the pointer names a post translated from elsewhere', function () {
        // Post 33 names 11 as its source, not 22, so 22 never commissioned it.
        $report = MetaCleanup::run();

        expect(polytrans_meta_keys(22))->toBe([
            'polytrans_is_translation_target',
            'polytrans_translation_source',
            'rank_math_title',
        ]);
        // Four Italian keys plus the language list, which matched the original's.
        expect($report['keys_deleted'])->toBe(5);
        expect($report['posts_changed'])->toBe(1);
    });

    it('removes a group whose pointer names a post that no longer exists', function () {
        // A dangling pointer cannot be this post's own bookkeeping either.
        polytrans_set_meta(22, [
            '_polytrans_translation_status_es' => 'translating',
            '_polytrans_translation_post_id_es' => 999,
        ]);

        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_es');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_post_id_es');
    });
});

describe('relay hubs', function () {
    it('keeps the group of a translation that commissioned one of its own', function () {
        // pl (11) → en (22) → de (44): post 44 names 22 as its source, so the German
        // hub on 22 is genuinely its own.
        polytrans_register_post(44);
        polytrans_set_meta(44, [
            'polytrans_is_translation_target' => 1,
            'polytrans_translation_source' => 22,
        ]);
        polytrans_set_meta(22, [
            '_polytrans_translation_status_de' => 'completed',
            '_polytrans_translation_post_id_de' => 44,
        ]);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_de');
        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_post_id_de');
        expect($report['relay_keys_kept'])->toBe(2);
        // The copied Italian group still goes.
        expect($report['keys_deleted'])->toBe(5);
    });

    it('falls back to the target pointer when no post_id pointer was written', function () {
        polytrans_register_post(44);
        polytrans_set_meta(44, [
            'polytrans_is_translation_target' => 1,
            'polytrans_translation_source' => 22,
        ]);
        polytrans_set_meta(22, [
            '_polytrans_translation_status_de' => 'translating',
            '_polytrans_translation_target_de' => 44,
        ]);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_de');
        expect($report['relay_keys_kept'])->toBe(2);
    });
});

describe('groups with no pointer at all', function () {
    it('removes a group whose values are the original\'s verbatim', function () {
        // No pointer to check, so identical values are the only evidence - and they
        // are conclusive enough, since nothing writes the same log twice.
        polytrans_set_meta(11, ['_polytrans_translation_status_fr' => 'translating']);
        polytrans_set_meta(22, ['_polytrans_translation_status_fr' => 'translating']);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_fr');
        expect($report['keys_deleted'])->toBe(6);
        expect($report['ambiguous_keys_kept'])->toBe(0);
    });

    it('keeps a group whose values diverge from the original\'s', function () {
        polytrans_set_meta(11, ['_polytrans_translation_status_fr' => 'translating']);
        polytrans_set_meta(22, ['_polytrans_translation_status_fr' => 'completed']);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_fr');
        expect($report['ambiguous_keys_kept'])->toBe(1);
        expect($report['keys_deleted'])->toBe(5);
    });

    it('keeps a group when the original cannot be reached', function () {
        // No source pointer and no Polylang: nothing to compare against, and no way to
        // rule the language out either.
        $GLOBALS['polytrans_test_post_meta'] = [];
        polytrans_register_post(55);
        polytrans_set_meta(55, [
            'polytrans_is_translation_target' => 1,
            '_polytrans_translation_status_de' => 'translating',
        ]);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][55])->toHaveKey('_polytrans_translation_status_de');
        expect($report['unknown_language'])->toBe(1);
        expect($report['ambiguous_keys_kept'])->toBe(1);
        expect($report['keys_deleted'])->toBe(0);
    });
});

describe('the post\'s own language', function () {
    it('removes a group naming the language the post is written in', function () {
        // A group with no pointer and a value that differs from the original's would
        // otherwise be left alone; the post being English settles it.
        polytrans_set_meta(22, ['_polytrans_translation_status_en' => 'translating']);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_en');
        expect($report['keys_deleted'])->toBe(6);
        expect($report['ambiguous_keys_kept'])->toBe(0);
    });

    it('reads the language from the original\'s pointer rather than a stored field', function () {
        // polytrans_translation_lang holds the SOURCE language; trusting it would make
        // the cleanup treat the Polish group as impossible and the English one as real.
        polytrans_set_meta(22, [
            'polytrans_translation_lang' => 'pl',
            '_polytrans_translation_status_en' => 'translating',
        ]);

        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_en');
    });

    it('falls back to Polylang when the original has no pointer back', function () {
        $GLOBALS['polytrans_test_post_meta'] = [];
        polytrans_register_post(66);
        polytrans_set_meta(66, [
            'polytrans_is_translation_target' => 1,
            '_polytrans_translation_status_es' => 'translating',
        ]);
        $GLOBALS['polytrans_test_pll_language'] = 'es';

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][66])->not->toHaveKey('_polytrans_translation_status_es');
        expect($report['keys_deleted'])->toBe(1);
    });
});

describe('keys without a language suffix', function () {
    it('removes the notification flag when it matches the original\'s', function () {
        polytrans_set_meta(11, ['_polytrans_author_notified' => 1]);
        polytrans_set_meta(22, ['_polytrans_author_notified' => 1]);

        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_author_notified');
    });

    it('keeps the notification flag when it differs from the original\'s', function () {
        // Someone was notified about this post specifically, so the flag is its own.
        polytrans_set_meta(11, ['_polytrans_author_notified' => 1]);
        polytrans_set_meta(22, ['_polytrans_author_notified' => 2]);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_author_notified');
        expect($report['ambiguous_keys_kept'])->toBe(1);
    });

    it('keeps a language list the post filled in itself', function () {
        polytrans_set_meta(22, ['_polytrans_translation_langs' => 'de']);

        $report = MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22]['_polytrans_translation_langs'][0])->toBe('de');
        // Only the four Italian keys this time.
        expect($report['keys_deleted'])->toBe(4);
        expect($report['ambiguous_keys_kept'])->toBe(1);
    });
});

describe('what it never touches', function () {
    it('leaves the original untouched', function () {
        // The original is where this state belongs; losing it would break the UI.
        $before = polytrans_meta_keys(11);

        MetaCleanup::run();

        expect(polytrans_meta_keys(11))->toBe($before);
        expect($GLOBALS['polytrans_test_post_meta'][11]['_polytrans_translation_status_it'][0])->toBe('completed');
    });

    it('keeps content meta on the translation', function () {
        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22]['rank_math_title'][0])->toBe('English Title');
    });

    it('leaves cost meta to the usage table', function () {
        // Deleting it would lose the costs the post really did incur from workflow
        // steps of its own; the table is what decides.
        polytrans_set_meta(22, ['_polytrans_cost_usd' => '0.42']);

        MetaCleanup::run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_cost_usd');
    });

    it('ignores posts that are not translations at all', function () {
        $report = MetaCleanup::run();

        // Posts 22 and 33 carry the marker; the original does not.
        expect($report['posts_scanned'])->toBe(2);
    });

    it('changes nothing on a dry run', function () {
        $report = MetaCleanup::run(true);

        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_it');
        expect($report['keys_deleted'])->toBe(5);
        expect($report['dry_run'])->toBeTrue();
        expect(get_option(MetaCleanup::OPTION_VERSION, 0))->toBe(0);
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
        expect($GLOBALS['polytrans_test_post_meta'][22])->toHaveKey('_polytrans_translation_status_it');
    });

    it('runs when the recorded version is behind', function () {
        // Installations that finished version 1 recorded it as done without deleting
        // anything, so the new criterion has to be able to run again.
        update_option(MetaCleanup::OPTION_VERSION, 1);

        MetaCleanup::maybe_run();

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_it');
    });
});

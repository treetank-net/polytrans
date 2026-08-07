<?php

declare(strict_types=1);

/**
 * Unit Tests: Metadata copying onto a translated post
 *
 * The original's meta is copied wholesale, so the skip list is the only thing that
 * keeps the plugin's own per-post state from being duplicated onto every
 * translation. It used to compare '_polytrans_translation_status' exactly, while the
 * real keys carry a language suffix, so it never matched anything.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Receiver\Managers\MetadataManager;

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

if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr, $wp_error = false)
    {
        return is_array($postarr) ? (int) ($postarr['ID'] ?? 0) : 0;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value)
    {
        $user = new WP_User(1, 'author@example.com');
        $user->display_name = 'Author';

        return $user;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($value)
    {
        if (is_string($value) && preg_match('/^[aOsbdi]:\d+[:;]/', trim($value))) {
            return @unserialize(trim($value));
        }

        return $value;
    }
}

// The class under test logs through the legacy alias, which Bootstrap registers in
// production but the unit bootstrap does not load.
if (!class_exists('PolyTrans_Logs_Manager')) {
    class_alias(\PolyTrans\Core\LogsManager::class, 'PolyTrans_Logs_Manager');
}

beforeEach(function () {
    $GLOBALS['polytrans_test_posts'] = [
        11 => (object) ['ID' => 11, 'post_author' => 7],
        22 => (object) ['ID' => 22, 'post_author' => 7],
    ];

    // A Polish original mid-way through being translated into German and French.
    $GLOBALS['polytrans_test_post_meta'] = [
        11 => [
            'rank_math_title' => ['Polski tytuł'],
            'rank_math_description' => ['Polski opis'],
            'custom_field' => ['keep me'],
            '_polytrans_translation_status_de' => ['translating'],
            '_polytrans_translation_status_fr' => ['translating'],
            '_polytrans_translation_log_de' => [['entry']],
            '_polytrans_translation_completed_de' => ['1700000000'],
            '_polytrans_translation_post_id_de' => [22],
            '_polytrans_translation_target_de' => [22],
            '_polytrans_translation_langs' => [['de', 'fr']],
            '_polytrans_translation_needs_review_de' => ['1'],
            '_polytrans_author_notified' => ['1'],
            '_polytrans_usage_summary' => [['version' => 1, 'total_usd' => '9.99']],
            '_polytrans_cost_usd' => ['9.99'],
            '_edit_lock' => ['1700000000:1'],
            '_edit_last' => ['1'],
            '_thumbnail_id' => ['99'],
        ],
        22 => [],
    ];
});

/**
 * @return array Meta keys now present on the translated post.
 */
function polytrans_translated_meta_keys(): array
{
    $keys = array_keys($GLOBALS['polytrans_test_post_meta'][22] ?? []);
    sort($keys);

    return $keys;
}

describe('what is carried over', function () {
    it('copies content meta from the original', function () {
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        expect($GLOBALS['polytrans_test_post_meta'][22]['rank_math_title'][0])->toBe('Polski tytuł');
        expect($GLOBALS['polytrans_test_post_meta'][22]['custom_field'][0])->toBe('keep me');
    });

    it('prefers the translated value where one was returned', function () {
        (new MetadataManager())->setup_metadata(22, 11, 'pl', [
            'meta' => ['rank_math_title' => 'Deutscher Titel'],
        ]);

        expect($GLOBALS['polytrans_test_post_meta'][22]['rank_math_title'][0])->toBe('Deutscher Titel');
        // Untranslated keys still come from the original.
        expect($GLOBALS['polytrans_test_post_meta'][22]['rank_math_description'][0])->toBe('Polski opis');
    });

    it('sets its own translation markers', function () {
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        $meta = $GLOBALS['polytrans_test_post_meta'][22];

        expect($meta['polytrans_is_translation_target'][0])->toBe(1);
        expect($meta['polytrans_translation_source'][0])->toBe(11);
        expect($meta['polytrans_translation_lang'][0])->toBe('pl');
        expect($meta['translated_by_machine'][0])->toBe('true');
    });
});

describe('what must not be carried over', function () {
    it('leaves behind every piece of the plugin\'s own per-post state', function () {
        // All of these live on the original by design. Copied onto a translation they
        // are never updated again, because status is only ever written on the original.
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        foreach (polytrans_translated_meta_keys() as $key) {
            expect($key)->not->toStartWith('_polytrans_');
        }
    });

    it('does not leave a translation reading "translating" forever', function () {
        // This is what made the stuck-translation scanners pick up orphans: they search
        // meta_key LIKE '_polytrans_translation_status_%' across the whole table.
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_de');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_status_fr');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_log_de');
    });

    it('does not inherit the original\'s cost summary', function () {
        // Otherwise a translation would show what the original cost across every
        // language, and its own recorded costs would be added on top of that.
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_usage_summary');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_cost_usd');
    });

    it('does not inherit the pointers to other translations', function () {
        // These name the posts created for each language; on a translation they would
        // point at a sibling, or at the post itself.
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_post_id_de');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_target_de');
        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_translation_langs');
    });

    it('does not inherit the notified flag, which would suppress a notification', function () {
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        expect($GLOBALS['polytrans_test_post_meta'][22])->not->toHaveKey('_polytrans_author_notified');
    });

    it('still skips the WordPress keys it always skipped', function () {
        (new MetadataManager())->setup_metadata(22, 11, 'pl', []);

        $meta = $GLOBALS['polytrans_test_post_meta'][22];

        expect($meta)->not->toHaveKey('_edit_lock');
        expect($meta)->not->toHaveKey('_edit_last');
        // The featured image is handled by the media manager.
        expect($meta)->not->toHaveKey('_thumbnail_id');
    });
});

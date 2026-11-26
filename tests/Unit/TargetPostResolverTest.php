<?php

declare(strict_types=1);

/**
 * Unit Tests: create a new translated post, or update the existing one
 *
 * "create" is the historical behaviour and must stay the default. "update" has to
 * find the post Polylang's group holds for the target language - and must refuse
 * anything that is not really that translation, because whatever it returns gets
 * its title and content overwritten.
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

use PolyTrans\Receiver\Managers\MetadataManager;
use PolyTrans\Receiver\Managers\TargetPostResolver;

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

if (!function_exists('pll_get_post_translations')) {
    function pll_get_post_translations($post_id)
    {
        return $GLOBALS['polytrans_test_pll_groups'][$post_id] ?? [];
    }
}

if (!function_exists('pll_get_post_language')) {
    function pll_get_post_language($post_id, $field = 'slug')
    {
        return $GLOBALS['polytrans_test_pll_languages'][$post_id] ?? false;
    }
}

function polytrans_test_post(int $id, string $type = 'tt_asset', string $status = 'draft'): object
{
    return (object) ['ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_author' => 1];
}

beforeEach(function () {
    // An English banner with a German translation in its Polylang group.
    $GLOBALS['polytrans_test_posts'] = [
        10 => polytrans_test_post(10),
        20 => polytrans_test_post(20),
    ];
    $GLOBALS['polytrans_test_post_meta'] = [10 => [], 20 => []];
    $GLOBALS['polytrans_test_pll_groups'] = [10 => ['en' => 10, 'de' => 20]];
    $GLOBALS['polytrans_test_pll_languages'] = [10 => 'en', 20 => 'de'];
    $GLOBALS['polytrans_test_options'] = [];
    $GLOBALS['polytrans_test_filters'] = [];
});

describe('mode', function () {
    it('creates a new post unless told otherwise', function () {
        expect(TargetPostResolver::mode(10, 'de'))->toBe(TargetPostResolver::MODE_CREATE);
        expect(TargetPostResolver::resolve(10, 'de'))->toBe(0);
    });

    it('updates when the setting says so', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = ['translation_target_mode' => 'update'];

        expect(TargetPostResolver::resolve(10, 'de'))->toBe(20);
    });

    it('lets a filter decide per post, e.g. for an add-on\'s own post type', function () {
        $GLOBALS['polytrans_test_filters']['polytrans_translation_target_mode'] =
            fn ($mode, $post_id) => get_post($post_id)->post_type === 'tt_asset' ? 'update' : $mode;

        expect(TargetPostResolver::resolve(10, 'de'))->toBe(20);
    });

    it('treats an unknown value as create', function () {
        $GLOBALS['polytrans_test_options']['polytrans_settings'] = ['translation_target_mode' => 'overwrite-everything'];

        expect(TargetPostResolver::mode(10, 'de'))->toBe(TargetPostResolver::MODE_CREATE);
    });
});

describe('which post gets updated', function () {
    it('creates when the language has no translation yet', function () {
        expect(TargetPostResolver::find_existing(10, 'fr'))->toBe(0);
    });

    it('falls back to the original\'s pointer when Polylang has no group', function () {
        $GLOBALS['polytrans_test_pll_groups'] = [];
        $GLOBALS['polytrans_test_post_meta'][10]['_polytrans_translation_post_id_de'] = [20];

        expect(TargetPostResolver::find_existing(10, 'de'))->toBe(20);
    });

    it('never returns the source post itself', function () {
        $GLOBALS['polytrans_test_pll_groups'] = [10 => ['de' => 10]];

        expect(TargetPostResolver::find_existing(10, 'de'))->toBe(0);
    });

    it('skips a translation in the trash', function () {
        $GLOBALS['polytrans_test_posts'][20] = polytrans_test_post(20, 'tt_asset', 'trash');

        expect(TargetPostResolver::find_existing(10, 'de'))->toBe(0);
    });

    it('skips a post of another type', function () {
        $GLOBALS['polytrans_test_posts'][20] = polytrans_test_post(20, 'page');

        expect(TargetPostResolver::find_existing(10, 'de'))->toBe(0);
    });

    it('skips a stale pointer to a post in another language', function () {
        $GLOBALS['polytrans_test_pll_groups'] = [];
        $GLOBALS['polytrans_test_post_meta'][10]['_polytrans_translation_post_id_de'] = [20];
        $GLOBALS['polytrans_test_pll_languages'][20] = 'fr';
        // MetaCleanupTest's stub of the same function reads one global language; whichever
        // file loads first defines it, so both shapes are set.
        $GLOBALS['polytrans_test_pll_language'] = 'fr';

        try {
            expect(TargetPostResolver::find_existing(10, 'de'))->toBe(0);
        } finally {
            unset($GLOBALS['polytrans_test_pll_language']);
        }
    });

    it('creates when the original is not on this site (separate-database receiver)', function () {
        unset($GLOBALS['polytrans_test_posts'][10]);

        expect(TargetPostResolver::find_existing(10, 'de'))->toBe(0);
    });
});

describe('refreshing the metadata of an existing translation', function () {
    it('writes translated fields and leaves the rest of the post alone', function () {
        $GLOBALS['polytrans_test_post_meta'][20] = [
            'headline' => ['Alter Titel'],
            'background_color' => ['#ff0000'],
        ];

        (new MetadataManager())->update_metadata(20, 10, 'en', [
            'meta' => ['headline' => 'Neuer Titel', '_polytrans_translation_status_de' => 'completed'],
        ]);

        $meta = $GLOBALS['polytrans_test_post_meta'][20];
        expect($meta['headline'][0])->toBe('Neuer Titel');
        // Not in the translation, so a per-language change survives.
        expect($meta['background_color'][0])->toBe('#ff0000');
        // The plugin's own state is never written onto a translation.
        expect($meta)->not->toHaveKey('_polytrans_translation_status_de');
    });

    it('records the post this content was last translated from', function () {
        (new MetadataManager())->update_metadata(20, 10, 'en', []);

        $meta = $GLOBALS['polytrans_test_post_meta'][20];
        expect($meta['polytrans_translation_source'][0])->toBe(10);
        expect($meta['polytrans_translation_lang'][0])->toBe('en');
    });
});

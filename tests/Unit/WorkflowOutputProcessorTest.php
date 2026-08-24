<?php

declare(strict_types=1);

use PolyTrans\PostProcessing\WorkflowOutputProcessor;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return $GLOBALS['polytrans_test_current_user'] ?? 1;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        return $GLOBALS['polytrans_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        $GLOBALS['polytrans_test_post_meta'][$post_id][$meta_key] = [$meta_value];
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        $meta = $GLOBALS['polytrans_test_post_meta'][$post_id] ?? [];

        if ($key === '') {
            return $meta;
        }

        $values = $meta[$key] ?? [];
        if ($single) {
            return $values[0] ?? '';
        }

        return $values;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($value)
    {
        return $value;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata($user_id)
    {
        return (object) [
            'display_name' => 'Test User',
            'user_email' => 'test@example.com',
        ];
    }
}

if (!function_exists('get_the_category')) {
    function get_the_category($post_id)
    {
        return [];
    }
}

if (!function_exists('get_the_tags')) {
    function get_the_tags($post_id)
    {
        return [];
    }
}

if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post_id)
    {
        return false;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post_id)
    {
        return 'https://example.com/post-' . $post_id;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($post_id)
    {
        return 'https://example.com/wp-admin/post.php?post=' . $post_id;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text)
    {
        return strip_tags((string) $text);
    }
}

// --- Atrybucja: śledzimy każdą podmianę użytkownika, żeby dało się sprawdzić zakres. ---

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($user_id)
    {
        $GLOBALS['polytrans_test_current_user'] = (int) $user_id;
        $GLOBALS['polytrans_test_user_swaps'][] = (int) $user_id;

        return (object) ['ID' => (int) $user_id];
    }
}

// get_user_by() is stubbed by whichever unit-test file happens to declare it first
// (MetadataManagerTest does), so this file must not assume which user object comes back.
// What it does control is the capability answer, which is the branch under test.
if (!function_exists('user_can')) {
    function user_can($user, $capability, ...$args)
    {
        return (bool) ($GLOBALS['polytrans_test_user_can'] ?? false);
    }
}

beforeEach(function () {
    $GLOBALS['polytrans_test_posts'] = [
        123 => (object) [
            'ID' => 123,
            'post_author' => 1,
            'post_title' => 'Translated title',
            'post_content' => 'Translated content',
            'post_excerpt' => '',
            'post_name' => 'translated-title',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_date' => '2026-04-24 10:00:00',
            'post_date_gmt' => '2026-04-24 08:00:00',
            'post_modified' => '2026-04-24 10:00:00',
            'post_modified_gmt' => '2026-04-24 08:00:00',
            'post_parent' => 0,
            'menu_order' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ],
    ];
    $GLOBALS['polytrans_test_post_meta'] = [
        123 => [],
    ];
    $GLOBALS['polytrans_test_current_user'] = 7;   // who actually made the request
    $GLOBALS['polytrans_test_user_swaps'] = [];
    $GLOBALS['polytrans_test_user_can'] = false;   // each test states what it needs
});

it('preserves uppercase post meta keys in production context refresh', function () {
    $processor = WorkflowOutputProcessor::get_instance();

    $result = $processor->process_step_outputs(
        [
            'success' => true,
            'data' => [
                'ai_response' => 'Review notes from the first assistant step.',
            ],
        ],
        [
            [
                'type' => 'update_post_meta',
                'source_variable' => 'ai_response',
                'target' => 'TRANSLATION_REVIEW',
            ],
        ],
        [
            'translated_post_id' => 123,
            'target_language' => 'pl',
        ],
        false
    );

    expect($result['success'])->toBeTrue();
    expect($GLOBALS['polytrans_test_post_meta'][123])->toHaveKey('TRANSLATION_REVIEW');
    expect($GLOBALS['polytrans_test_post_meta'][123])->not->toHaveKey('translation_review');
    expect($result['updated_context']['translated']['meta']['TRANSLATION_REVIEW'])
        ->toBe('Review notes from the first assistant step.');
});


/*
 * Atrybucja kredytuje zmianę, ale nie pożycza uprawnień.
 *
 * wp_set_current_user() odpowiada na dwa pytania naraz: kto jest zapisany jako autor
 * zmiany i czyje uprawnienia obowiązują w czasie jej wykonywania. Feature potrzebuje
 * tylko pierwszego, dlatego podmiana obejmuje wyłącznie sam zapis i zawsze się cofa.
 */

it('credits the attributed user, and only for the duration of the write', function () {
    $GLOBALS['polytrans_test_user_can'] = true;
    $processor = WorkflowOutputProcessor::get_instance();

    $result = $processor->process_step_outputs(
        ['success' => true, 'data' => ['ai_response' => 'Notes']],
        [['type' => 'update_post_meta', 'source_variable' => 'ai_response', 'target' => 'NOTES']],
        ['translated_post_id' => 123, 'target_language' => 'pl'],
        false,
        ['name' => 'W', 'attribution_user' => 42]
    );

    expect($result['success'])->toBeTrue();
    // Swapped in for the write, swapped straight back out.
    expect($GLOBALS['polytrans_test_user_swaps'])->toBe([42, 7]);
    expect(get_current_user_id())->toBe(7);
});

it('ignores an attribution user who cannot edit the post', function () {
    $GLOBALS['polytrans_test_user_can'] = false;
    $processor = WorkflowOutputProcessor::get_instance();

    $result = $processor->process_step_outputs(
        ['success' => true, 'data' => ['ai_response' => 'Notes']],
        [['type' => 'update_post_meta', 'source_variable' => 'ai_response', 'target' => 'NOTES']],
        ['translated_post_id' => 123, 'target_language' => 'pl'],
        false,
        ['name' => 'W', 'attribution_user' => 42]
    );

    // The write still happens — as the requesting user, with no borrowed identity.
    expect($result['success'])->toBeTrue();
    expect($GLOBALS['polytrans_test_user_swaps'])->toBe([]);
    expect(get_current_user_id())->toBe(7);
});

it('does not swap users in test mode', function () {
    $GLOBALS['polytrans_test_user_can'] = true;
    $processor = WorkflowOutputProcessor::get_instance();

    $processor->process_step_outputs(
        ['success' => true, 'data' => ['ai_response' => 'Notes']],
        [['type' => 'update_post_meta', 'source_variable' => 'ai_response', 'target' => 'NOTES']],
        ['translated_post_id' => 123, 'target_language' => 'pl'],
        true,
        ['name' => 'W', 'attribution_user' => 42]
    );

    expect($GLOBALS['polytrans_test_user_swaps'])->toBe([]);
});

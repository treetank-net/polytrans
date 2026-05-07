<?php

/**
 * Post Data Provider Unit Tests
 * 
 * Tests for Phase 0.1 Day 2 - Variable Structure Refactor
 */

use PolyTrans\PostProcessing\Providers\PostDataProvider;

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        return $GLOBALS['polytrans_post_data_provider_posts'][$post_id] ?? null;
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

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        return $key === '' ? [] : ($single ? '' : []);
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($value)
    {
        return $value;
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

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['polytrans_post_data_provider_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        $GLOBALS['polytrans_post_data_provider_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        unset($GLOBALS['polytrans_post_data_provider_transients'][$key]);
        return true;
    }
}

if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post_id)
    {
        $GLOBALS['polytrans_post_data_provider_cleaned_posts'][] = $post_id;
    }
}

beforeEach(function () {
    $this->provider = new PostDataProvider();
    $GLOBALS['polytrans_post_data_provider_transients'] = [];
    $GLOBALS['polytrans_post_data_provider_cleaned_posts'] = [];
    $GLOBALS['polytrans_post_data_provider_posts'] = [];
});

describe('Variable Aliases', function () {
    it('provides top-level aliases', function () {
        $context = [
            'translated_post' => [
                'title' => 'Test Title',
                'content' => 'Test Content',
                'excerpt' => 'Test Excerpt'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('title');
        expect($variables)->toHaveKey('content');
        expect($variables)->toHaveKey('excerpt');
        expect($variables['title'])->toBe('Test Title');
        expect($variables['content'])->toBe('Test Content');
        expect($variables['excerpt'])->toBe('Test Excerpt');
    });
    
    it('provides original alias', function () {
        $context = [
            'original_post' => [
                'title' => 'Original Title',
                'content' => 'Original Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('original');
        expect($variables['original'])->toBe($context['original_post']);
    });
    
    it('provides translated alias', function () {
        $context = [
            'translated_post' => [
                'title' => 'Translated Title',
                'content' => 'Translated Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        expect($variables)->toHaveKey('translated');
        expect($variables['translated'])->toBe($context['translated_post']);
    });
});

describe('Available Variables', function () {
    it('lists all available variables', function () {
        $available = $this->provider->get_available_variables();
        
        expect($available)->toBeArray();
        expect($available)->toContain('title');
        expect($available)->toContain('content');
        expect($available)->toContain('original.title');
        expect($available)->toContain('translated.content');
        expect($available)->toContain('original.meta');
    });
});

describe('Variable Documentation', function () {
    it('provides documentation for variables', function () {
        $docs = $this->provider->get_variable_documentation();
        
        expect($docs)->toBeArray();
        expect($docs)->toHaveKey('title');
        expect($docs)->toHaveKey('original.title');
        expect($docs['title'])->toHaveKey('description');
        expect($docs['title'])->toHaveKey('example');
    });
});

describe('Backward Compatibility', function () {
    it('maintains original_post structure', function () {
        $context = [
            'original_post' => [
                'title' => 'Original',
                'content' => 'Content'
            ]
        ];
        
        $variables = $this->provider->get_variables($context);
        
        // Both old and new should work
        expect($variables)->toHaveKey('original_post');
        expect($variables)->toHaveKey('original');
        expect($variables['original_post'])->toBe($variables['original']);
    });
});

describe('Cache Invalidation', function () {
    it('clears cached post snapshots so following reads see updated post data', function () {
        $GLOBALS['polytrans_post_data_provider_posts'][123] = (object) [
            'ID' => 123,
            'post_author' => 1,
            'post_title' => 'Before workflow',
            'post_content' => 'Content',
            'post_excerpt' => '',
            'post_name' => 'before-workflow',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_date' => '2026-05-07 10:00:00',
            'post_date_gmt' => '2026-05-07 08:00:00',
            'post_modified' => '2026-05-07 10:00:00',
            'post_modified_gmt' => '2026-05-07 08:00:00',
            'post_parent' => 0,
            'menu_order' => 0,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ];

        $first = $this->provider->get_variables(['translated_post_id' => 123]);
        expect($first['translated']['title'])->toBe('Before workflow');

        $GLOBALS['polytrans_post_data_provider_posts'][123]->post_title = 'After workflow';

        $cached = $this->provider->get_variables(['translated_post_id' => 123]);
        expect($cached['translated']['title'])->toBe('Before workflow');

        PostDataProvider::invalidate_post_cache(123);

        $fresh = $this->provider->get_variables(['translated_post_id' => 123]);

        expect($fresh['translated']['title'])->toBe('After workflow');
        expect($GLOBALS['polytrans_post_data_provider_cleaned_posts'])->toContain(123);
    });
});

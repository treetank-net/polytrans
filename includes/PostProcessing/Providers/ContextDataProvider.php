<?php

namespace PolyTrans\PostProcessing\Providers;

use PolyTrans\PostProcessing\VariableProviderInterface;

/**
 * Context Data Provider
 * 
 * Provides site context and recent posts data to workflow execution context.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContextDataProvider implements VariableProviderInterface
{
    /**
     * Get the provider identifier
     */
    public function get_provider_id()
    {
        return 'context_data';
    }

    /**
     * Get the provider name
     */
    public function get_provider_name()
    {
        return __('Context Data Provider', 'treetank-trans');
    }

    /**
     * Get variables provided by this provider
     */
    public function get_variables($context)
    {
        $variables = [];

        // Site context
        $variables['site_context'] = $this->get_site_context($context);

        // Recent posts
        $variables['recent_posts'] = $this->get_recent_posts($context);

        // Translation context
        if (isset($context['target_language'])) {
            $variables['translation_context'] = $this->get_translation_context($context);
        }

        return $variables;
    }

    /**
     * Get list of variable names this provider can supply
     */
    public function get_available_variables()
    {
        return [
            'site_context',
            'site_context.name',
            'site_context.url',
            'site_context.description',
            'site_context.language',
            'site_context.admin_email',
            'site_context.timezone',
            'recent_posts',
            'translation_context',
            'translation_context.source_language',
            'translation_context.target_language',
            'translation_context.trigger'
        ];
    }

    /**
     * Check if provider can supply variables for given context
     */
    public function can_provide($context)
    {
        // This provider can always provide basic site context
        return true;
    }

    /**
     * Get variable documentation for UI display
     */
    public function get_variable_documentation()
    {
        return [
            'site_context' => [
                'description' => __('Complete site context information', 'treetank-trans'),
                'example' => '{site_context.name} - {site_context.description}'
            ],
            'site_context.name' => [
                'description' => __('Site name/title', 'treetank-trans'),
                'example' => '{site_context.name}'
            ],
            'site_context.url' => [
                'description' => __('Site URL', 'treetank-trans'),
                'example' => '{site_context.url}'
            ],
            'site_context.language' => [
                'description' => __('Site default language', 'treetank-trans'),
                'example' => '{site_context.language}'
            ],
            'recent_posts' => [
                'description' => __('Array of recent posts (last 20)', 'treetank-trans'),
                'example' => 'Context: Latest posts include {recent_posts}'
            ],
            'translation_context.source_language' => [
                'description' => __('Source language of the translation', 'treetank-trans'),
                'example' => '{translation_context.source_language}'
            ],
            'translation_context.target_language' => [
                'description' => __('Target language of the translation', 'treetank-trans'),
                'example' => '{translation_context.target_language}'
            ]
        ];
    }

    /**
     * Get site context information
     * 
     * @param array $context Execution context
     * @return array Site context data
     */
    private function get_site_context($context)
    {
        return [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => get_site_url(),
            'home_url' => get_home_url(),
            'admin_url' => admin_url(),
            'admin_email' => get_option('admin_email'),
            'language' => get_locale(),
            'timezone' => get_option('timezone_string') ?: 'UTC',
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'start_of_week' => get_option('start_of_week'),
            'current_time' => current_time('Y-m-d H:i:s'),
            'current_user_id' => get_current_user_id(),
            'is_multisite' => is_multisite(),
            'wordpress_version' => get_bloginfo('version'),
            'active_theme' => wp_get_theme()->get('Name'),
            'active_plugins' => $this->get_active_plugin_names()
        ];
    }

    /**
     * Get recent posts
     * 
     * @param array $context Execution context
     * @return array Recent posts data
     */
    private function get_recent_posts($context)
    {
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        // If we have a target language, try to get posts in that language
        if (isset($context['target_language']) && function_exists('pll_get_post')) {
            $args['lang'] = $context['target_language'];
        }

        $posts = get_posts($args);
        $recent_posts = [];

        foreach ($posts as $post) {
            $recent_posts[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'excerpt' => wp_trim_words($post->post_content, 20),
                'date' => $post->post_date,
                'author' => get_the_author_meta('display_name', $post->post_author),
                'categories' => array_map(function ($cat) {
                    return $cat->name;
                }, get_the_category($post->ID)),
                'permalink' => get_permalink($post->ID)
            ];
        }

        return $recent_posts;
    }

    /**
     * Get translation context
     * 
     * @param array $context Execution context
     * @return array Translation context data
     */
    private function get_translation_context($context)
    {
        $translation_context = [
            'target_language' => $context['target_language'] ?? '',
            'trigger' => $context['trigger'] ?? 'unknown',
            'execution_time' => current_time('Y-m-d H:i:s')
        ];

        // Try to determine source language
        if (isset($context['original_post_id']) && function_exists('pll_get_post_language')) {
            $source_language = pll_get_post_language($context['original_post_id']);
            $translation_context['source_language'] = $source_language ?: 'unknown';
        } else {
            $translation_context['source_language'] = 'unknown';
        }

        // Get language names if Polylang is available
        if (function_exists('pll_languages_list')) {
            $languages = pll_languages_list(['fields' => null]);
            if (is_array($languages)) {
                foreach ($languages as $lang) {
                    if (is_object($lang) && isset($lang->slug, $lang->name)) {
                        if ($lang->slug === $translation_context['source_language']) {
                            $translation_context['source_language_name'] = $lang->name;
                        }
                        if ($lang->slug === $translation_context['target_language']) {
                            $translation_context['target_language_name'] = $lang->name;
                        }
                    }
                }
            }
        }

        return $translation_context;
    }

    /**
     * Get active plugin names
     * 
     * @return array Array of active plugin names
     */
    private function get_active_plugin_names()
    {
        $active_plugins = get_option('active_plugins', []);
        $plugin_names = [];

        foreach ($active_plugins as $plugin) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            if (!empty($plugin_data['Name'])) {
                $plugin_names[] = $plugin_data['Name'];
            }
        }

        return $plugin_names;
    }
}

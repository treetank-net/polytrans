<?php

namespace PolyTrans\PostProcessing\Providers;

use PolyTrans\Core\LogsManager;
use PolyTrans\PostProcessing\VariableProviderInterface;

/**
 * Articles Data Provider
 * 
 * Provides recent published articles for context in workflows.
 * Useful for SEO internal linking and content analysis.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ArticlesDataProvider implements VariableProviderInterface
{
    /**
     * Get the provider identifier
     */
    public function get_provider_id()
    {
        return 'articles_data';
    }

    /**
     * Get the provider name
     */
    public function get_provider_name()
    {
        return __('Recent Articles Provider', 'polytrans');
    }

    /**
     * Get variables provided by this provider
     */
    public function get_variables($context)
    {
        $variables = [];

        // Get configuration from context or use defaults
        $article_count = $context['articles_count'] ?? 20;
        $exclude_current = $context['post_id'] ?? null;
        $post_types = $context['article_post_types'] ?? ['post'];
        $language = $context['target_language'] ?? null;

        // Get recent articles
        $recent_articles = $this->get_recent_articles($article_count, $exclude_current, $post_types, $language);

        $variables['recent_articles'] = $recent_articles;
        $variables['recent_articles_count'] = count($recent_articles);

        // Create formatted article summaries for AI prompts
        $variables['recent_articles_summary'] = $this->format_articles_for_ai($recent_articles);

        return $variables;
    }

    /**
     * Get list of variable names this provider can supply
     */
    public function get_available_variables()
    {
        return [
            'recent_articles',
            'recent_articles_count',
            'recent_articles_summary',
            'recent_articles.0.title',
            'recent_articles.0.excerpt',
            'recent_articles.0.url',
            'recent_articles.0.categories',
            'recent_articles.0.tags'
        ];
    }

    /**
     * Check if provider can supply variables for given context
     */
    public function can_provide($context)
    {
        // This provider can always provide articles
        return true;
    }

    /**
     * Get variable documentation for UI display
     */
    public function get_variable_documentation()
    {
        return [
            'recent_articles' => [
                'description' => __('Array of recent published articles with full data', 'polytrans'),
                'example' => '{recent_articles.0.title} or {recent_articles.1.excerpt}'
            ],
            'recent_articles_count' => [
                'description' => __('Number of recent articles retrieved', 'polytrans'),
                'example' => '{recent_articles_count}'
            ],
            'recent_articles_summary' => [
                'description' => __('Formatted summary of recent articles for AI processing', 'polytrans'),
                'example' => '{recent_articles_summary}'
            ],
            'recent_articles.X.title' => [
                'description' => __('Title of article at index X (0-based)', 'polytrans'),
                'example' => '{recent_articles.0.title}'
            ],
            'recent_articles.X.excerpt' => [
                'description' => __('Excerpt of article at index X', 'polytrans'),
                'example' => '{recent_articles.0.excerpt}'
            ],
            'recent_articles.X.url' => [
                'description' => __('URL of article at index X', 'polytrans'),
                'example' => '{recent_articles.0.url}'
            ],
            'recent_articles.X.categories' => [
                'description' => __('Categories of article at index X', 'polytrans'),
                'example' => '{recent_articles.0.categories}'
            ]
        ];
    }

    /**
     * Get recent published articles
     * 
     * @param int $count Number of articles to retrieve
     * @param int|null $exclude_post_id Post ID to exclude (e.g., current post)
     * @param array $post_types Post types to include
     * @param string|null $language Language code if multilingual site
     * @return array Array of formatted article data
     */
    private function get_recent_articles($count = 20, $exclude_post_id = null, $post_types = ['post'], $language = null)
    {
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $count + 1, // Get one extra in case we need to exclude current
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        // Exclude current post if specified
        if ($exclude_post_id) {
            $args['post__not_in'] = [$exclude_post_id];
        }

        // Handle multilingual sites (Polylang)
        if ($language && function_exists('pll_get_post_language')) {
            $args['lang'] = $language;
        }

        $posts = get_posts($args);
        $articles = [];
        LogsManager::log("Found " . count($posts) . " recent posts for articles data provider", 'debug', [
            'source' => 'articles_data_provider',
            'post_count' => count($posts),
            'query_args' => $args
        ]);

        foreach ($posts as $post) {
            // Skip if we have enough articles
            if (count($articles) >= $count) {
                break;
            }

            // Skip if this is the excluded post
            if ($exclude_post_id && $post->ID == $exclude_post_id) {
                continue;
            }

            $articles[] = $this->format_article_data($post);
        }

        return $articles;
    }

    /**
     * Format article data for variable context
     * 
     * @param WP_Post $post Post object
     * @return array Formatted article data
     */
    private function format_article_data($post)
    {
        // Get categories
        $categories = [];
        $post_categories = get_the_category($post->ID);
        foreach ($post_categories as $category) {
            $categories[] = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug
            ];
        }

        // Get tags
        $tags = [];
        $post_tags = get_the_tags($post->ID);
        if ($post_tags) {
            foreach ($post_tags as $tag) {
                $tags[] = [
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug
                ];
            }
        }

        // Get excerpt
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }

        // Get some useful meta data
        $meta = [
            'reading_time' => $this->estimate_reading_time($post->post_content),
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content))
        ];

        // Add SEO title if available (Yoast)
        $seo_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        if ($seo_title) {
            $meta['seo_title'] = $seo_title;
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'excerpt' => $excerpt,
            'content' => $post->post_content,
            'url' => get_permalink($post->ID),
            'slug' => $post->post_name,
            'date' => $post->post_date,
            'author_id' => $post->post_author,
            'author_name' => get_the_author_meta('display_name', $post->post_author),
            'categories' => $categories,
            'tags' => $tags,
            'meta' => $meta,
            'post_type' => $post->post_type
        ];
    }

    /**
     * Format articles for AI processing
     * 
     * @param array $articles Array of article data
     * @return string Formatted string for AI prompts
     */
    private function format_articles_for_ai($articles)
    {
        if (empty($articles)) {
            return 'No recent articles available.';
        }

        $formatted = "\n";
        foreach ($articles as $index => $article) {
            $formatted .= sprintf(
                "%d. **%s**\n   - Post ID: %d\n   - Slug: %s\n   - URL: %s\n   - Excerpt: %s\n   - First 500 chars: %s\n   - Categories: %s\n   - Tags: %s\n   - Word Count: %d\n\n",
                $index + 1,
                $article['title'],
                $article['id'],
                $article['slug'],
                $article['url'],
                wp_trim_words($article['excerpt'], 15),
                mb_substr(wp_strip_all_tags($article['content']), 0, 500),
                !empty($article['categories']) ? implode(', ', array_column($article['categories'], 'name')) : 'None',
                !empty($article['tags']) ? implode(', ', array_column($article['tags'], 'name')) : 'None',
                $article['meta']['word_count'] ?? 0
            );
        }

        return $formatted;
    }

    /**
     * Estimate reading time in minutes
     * 
     * @param string $content Post content
     * @return int Estimated reading time in minutes
     */
    private function estimate_reading_time($content)
    {
        $word_count = str_word_count(wp_strip_all_tags($content));
        $reading_speed = 200; // Average words per minute
        return max(1, ceil($word_count / $reading_speed));
    }
}

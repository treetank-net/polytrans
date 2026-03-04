<?php

namespace PolyTrans\Receiver\Managers;

/**
 * Handles taxonomy (categories and tags) setup for translated posts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TaxonomyManager
{
    /**
     * Sets up categories and tags for the translated post in the target language.
     * 
     * @param int $new_post_id New translated post ID
     * @param int $original_post_id Original post ID
     * @param string $target_language Target language code
     */
    public function setup_taxonomies($new_post_id, $original_post_id, $target_language)
    {
        $this->setup_translated_categories($new_post_id, $original_post_id, $target_language);
        $this->setup_translated_tags($new_post_id, $original_post_id, $target_language);
    }

    /**
     * Sets up translated categories for the new post.
     * 
     * @param int $new_post_id New translated post ID
     * @param int $original_post_id Original post ID
     * @param string $target_language Target language code
     */
    private function setup_translated_categories($new_post_id, $original_post_id, $target_language)
    {
        $orig_cats = wp_get_post_categories($original_post_id, ['fields' => 'all']);
        $translated_cat_ids = [];
        $categories_found = [];
        $categories_not_found = [];

        foreach ($orig_cats as $cat) {
            $cat_obj = get_category($cat);
            if (!$cat_obj) continue;

            $translated_cat_id = $this->find_translated_category($cat_obj, $target_language);
            if ($translated_cat_id) {
                $translated_cat_ids[] = $translated_cat_id;
                $categories_found[] = $cat_obj->name;
            } else {
                $categories_not_found[] = $cat_obj->name;
            }
        }

        if ($translated_cat_ids) {
            wp_set_post_categories($new_post_id, $translated_cat_ids);
        }
        if (!empty($categories_not_found)) {
            \PolyTrans_Logs_Manager::log(
                "[polytrans] Categories not found for post $new_post_id: " . implode(',', $categories_not_found) . "; Found: " . implode(',', $categories_found),
                "info"
            );
        }
    }

    /**
     * Sets up translated tags for the new post.
     * 
     * @param int $new_post_id New translated post ID
     * @param int $original_post_id Original post ID  
     * @param string $target_language Target language code
     */
    private function setup_translated_tags($new_post_id, $original_post_id, $target_language)
    {
        $orig_tags = wp_get_post_tags($original_post_id);
        $translated_tag_ids = [];

        foreach ($orig_tags as $tag) {
            $translated_tag_id = $this->find_translated_tag($tag, $target_language);
            if ($translated_tag_id) {
                $translated_tag_ids[] = $translated_tag_id;
            }
        }

        if ($translated_tag_ids) {
            wp_set_post_tags($new_post_id, $translated_tag_ids);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
            error_log("[polytrans] Set translated tags for post $new_post_id: " . implode(',', $translated_tag_ids));
        }
    }

    /**
     * Finds the translated version of a category in the target language.
     * 
     * @param WP_Term $category Original category
     * @param string $target_language Target language code
     * @return int|null Translated category ID or null if not found
     */
    private function find_translated_category($category, $target_language)
    {
        if (function_exists('pll_get_term_translations')) {
            $translations = pll_get_term_translations($category->term_id);
            return isset($translations[$target_language]) ? $translations[$target_language] : null;
        }

        return $category->term_id; // Fallback: use original category
    }

    /**
     * Finds the translated version of a tag in the target language.
     * 
     * @param WP_Term $tag Original tag
     * @param string $target_language Target language code
     * @return int|null Translated tag ID or null if not found
     */
    private function find_translated_tag($tag, $target_language)
    {
        if (function_exists('pll_get_term_translations')) {
            $translations = pll_get_term_translations($tag->term_id);
            return isset($translations[$target_language]) ? $translations[$target_language] : null;
        }

        return $tag->term_id; // Fallback: use original tag
    }
}

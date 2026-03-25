<?php

namespace PolyTrans\Receiver\Managers;

/**
 * Handles creation of translated WordPress posts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PostCreator
{
    /**
     * Creates a new WordPress post with the translated content.
     *
     * @param array $translated Translated content data
     * @param int $original_post_id Original post ID
     * @return int|WP_Error New post ID or WP_Error on failure
     */
    public function create_post(array $translated, $original_post_id)
    {
        $original_post = get_post($original_post_id);

        // Determine post type and author from original post or use defaults
        if ($original_post) {
            $post_type = $original_post->post_type ?: 'post';
            $post_author = $original_post->post_author ?: get_current_user_id();
        } else {
            // Original post doesn't exist locally (separate database architecture)
            // Use defaults from translated data or system defaults
            $post_type = $translated['post_type'] ?? 'post';
            $post_author = $translated['author_id'] ?? get_current_user_id();

            \PolyTrans_Logs_Manager::log(
                "Original post $original_post_id not found locally - using defaults (type: $post_type, author: $post_author)",
                "info",
                ['source' => 'translation_post_creator', 'original_post_id' => $original_post_id]
            );
        }

        $postarr = [
            'post_title'   => $this->sanitize_title(isset($translated['title']) ? $translated['title'] : ''),
            'post_content' => $this->sanitize_content(isset($translated['content']) ? $translated['content'] : ''),
            'post_excerpt' => $this->sanitize_excerpt(isset($translated['excerpt']) ? $translated['excerpt'] : ''),
            'post_status'  => 'pending', // Will be updated later based on settings
            'post_type'    => $post_type,
            'post_author'  => $post_author,
        ];

        $new_post_id = wp_insert_post($postarr);

        if (is_wp_error($new_post_id)) {
            \PolyTrans\Core\LogsManager::log('[polytrans] Failed to create translated post: ' . $new_post_id->get_error_message(), "error");
            return $new_post_id;
        }

        // Log the author attribution for audit purposes
        $original_author = get_user_by('id', $post_author);
        $author_name = $original_author ? $original_author->display_name : 'Unknown';
        \PolyTrans_Logs_Manager::log("Created translated post {$new_post_id}", "info", [
            'source' => 'translation_post_creator',
            'original_post_id' => $original_post_id,
            'original_exists_locally' => (bool)$original_post,
            'translated_post_id' => $new_post_id,
            'author_id' => $post_author,
            'author_name' => $author_name,
            'post_type' => $post_type
        ]);

        // Ensure initial revision is created with correct author
        $this->create_initial_revision($new_post_id, $post_author);

        return $new_post_id;
    }

    /**
     * Create an initial revision for the post with the correct author
     * 
     * @param int $post_id The post ID
     * @param int $author_id The author ID to use for the revision
     */
    private function create_initial_revision($post_id, $author_id)
    {
        // Only create revision if post type supports revisions
        if (!post_type_supports(get_post_type($post_id), 'revisions')) {
            return;
        }

        // Get the post
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Store original current user
        $original_user_id = get_current_user_id();

        // Temporarily switch to the author for revision creation
        wp_set_current_user($author_id);

        // Force WordPress to create a revision
        // This is better than manually creating revision as it respects all WP revision settings
        $revision_id = wp_save_post_revision($post_id);

        // Restore original user
        wp_set_current_user($original_user_id);

        if ($revision_id && !is_wp_error($revision_id)) {
            \PolyTrans_Logs_Manager::log("Created initial revision {$revision_id} for post {$post_id} with author {$author_id}", "debug", [
                'source' => 'translation_post_creator',
                'post_id' => $post_id,
                'revision_id' => $revision_id,
                'author_id' => $author_id,
                'method' => 'wp_save_post_revision'
            ]);
        } else {
            \PolyTrans_Logs_Manager::log("Failed to create initial revision for post {$post_id} or no revision needed", "debug", [
                'source' => 'translation_post_creator',
                'post_id' => $post_id,
                'author_id' => $author_id,
                'revision_result' => $revision_id
            ]);
        }
    }

    /**
     * Sanitizes the post title.
     * 
     * @param string $title Raw title
     * @return string Sanitized title
     */
    private function sanitize_title($title)
    {
        return sanitize_text_field($title);
    }

    /**
     * Sanitizes the post content.
     * 
     * @param string $content Raw content
     * @return string Sanitized content
     */
    private function sanitize_content($content)
    {
        // WordPress will handle content sanitization during wp_insert_post
        return $content;
    }

    /**
     * Sanitizes the post excerpt.
     * 
     * @param string $excerpt Raw excerpt
     * @return string Sanitized excerpt
     */
    private function sanitize_excerpt($excerpt)
    {
        return sanitize_textarea_field($excerpt);
    }
}

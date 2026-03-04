<?php

namespace PolyTrans\Receiver\Managers;

/**
 * Handles translation status tracking and updates.
 */

if (!defined('ABSPATH')) {
    exit;
}

class StatusManager
{
    /**
     * Check if we can safely update the original post's translation meta.
     *
     * This validates that the post exists in the local database and has
     * an appropriate status for updates (pending translation states).
     *
     * @param int $post_id Original post ID
     * @param string $target_language Target language code
     * @return bool True if safe to update, false otherwise
     */
    private function can_update_original_post($post_id, $target_language)
    {
        if (!$post_id) {
            return false;
        }

        // Check if post exists locally
        $post = get_post($post_id);
        if (!$post) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Cannot update original post $post_id: post does not exist locally (separate database?)");
            return false;
        }

        // Check if post has a pending translation status for this language
        $status_key = '_polytrans_translation_status_' . $target_language;
        $current_status = get_post_meta($post_id, $status_key, true);

        // Allow update if status is pending/processing or if no status yet (first translation)
        $updatable_statuses = ['', 'started', 'translating', 'processing', 'post_processing'];
        if (!in_array($current_status, $updatable_statuses, true)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Cannot update original post $post_id for $target_language: status is '$current_status'");
            return false;
        }

        return true;
    }

    /**
     * Updates the translation status to an intermediate state (e.g., post_processing).
     * Used when post is created but workflows are still running.
     *
     * @param int $original_post_id Original post ID
     * @param string $target_language Target language code
     * @param int $new_post_id New translated post ID
     * @param string $status Intermediate status (default: 'post_processing')
     */
    public function update_status_intermediate($original_post_id, $target_language, $new_post_id, $status = 'post_processing')
    {
        // Verify we can update this post
        if (!$this->can_update_original_post($original_post_id, $target_language)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Skipping intermediate status update for original post $original_post_id - not available locally");
            return;
        }

        $status_key = '_polytrans_translation_status_' . $target_language;
        $log_key = '_polytrans_translation_log_' . $target_language;

        // Update status to intermediate state
        update_post_meta($original_post_id, $status_key, $status);

        // Store the translated post ID reference early (so UI can show preview link)
        update_post_meta($original_post_id, '_polytrans_translation_target_' . $target_language, $new_post_id);
        update_post_meta($original_post_id, '_polytrans_translation_post_id_' . $target_language, $new_post_id);

        // Add log entry
        $log = get_post_meta($original_post_id, $log_key, true);
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'timestamp' => time(),
            /* translators: %d: translated post ID */
            'msg' => sprintf(__('Translation created (Post ID: %d). Running post-processing workflows...', 'polytrans'), $new_post_id)
        ];

        update_post_meta($original_post_id, $log_key, $log);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Set intermediate status '$status' for post $original_post_id -> $new_post_id (language: $target_language)");
    }

    /**
     * Updates the translation status to completed.
     *
     * @param int $original_post_id Original post ID
     * @param string $target_language Target language code
     * @param int $new_post_id New translated post ID
     */
    public function update_status($original_post_id, $target_language, $new_post_id)
    {
        // Verify we can update this post
        if (!$this->can_update_original_post($original_post_id, $target_language)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Skipping status update for original post $original_post_id - not available locally");
            return;
        }

        $status_key = '_polytrans_translation_status_' . $target_language;
        $log_key = '_polytrans_translation_log_' . $target_language;
        $langs_key = '_polytrans_translation_langs';

        // Ensure the language is in the scheduled languages list
        $scheduled_langs = get_post_meta($original_post_id, $langs_key, true);
        if (!is_array($scheduled_langs)) $scheduled_langs = [];
        if (!in_array($target_language, $scheduled_langs, true)) {
            $scheduled_langs[] = $target_language;
            update_post_meta($original_post_id, $langs_key, $scheduled_langs);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Added $target_language to scheduled languages for post $original_post_id");
        }

        // Update status to completed (matches Background Processor expectation)
        update_post_meta($original_post_id, $status_key, 'completed');

        // Set a completion timestamp
        update_post_meta($original_post_id, '_polytrans_translation_completed_' . $target_language, time());

        // Add completion log entry
        $log = get_post_meta($original_post_id, $log_key, true);
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'timestamp' => time(),
            /* translators: %d: translated post ID */
            'msg' => sprintf(__('Translation completed. Post ID: %d', 'polytrans'), $new_post_id)
        ];

        update_post_meta($original_post_id, $log_key, $log);

        // Store the translated post ID reference (both keys for compatibility)
        update_post_meta($original_post_id, '_polytrans_translation_target_' . $target_language, $new_post_id);
        update_post_meta($original_post_id, '_polytrans_translation_post_id_' . $target_language, $new_post_id);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Updated translation status for post $original_post_id -> $new_post_id (language: $target_language)");
    }

    /**
     * Marks a translation as failed.
     *
     * @param int $original_post_id Original post ID
     * @param string $target_language Target language code
     * @param string $error_message Error message
     */
    public function mark_as_failed($original_post_id, $target_language, $error_message)
    {
        // Verify we can update this post
        if (!$this->can_update_original_post($original_post_id, $target_language)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[polytrans] Skipping failure status for original post $original_post_id - not available locally: $error_message");
            return;
        }

        $status_key = '_polytrans_translation_status_' . $target_language;
        $log_key = '_polytrans_translation_log_' . $target_language;

        // Update status to failed
        update_post_meta($original_post_id, $status_key, 'failed');

        // Add failure log entry
        $log = get_post_meta($original_post_id, $log_key, true);
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'timestamp' => time(),
            /* translators: %s: error message */
            'msg' => sprintf(__('Translation failed: %s', 'polytrans'), $error_message)
        ];

        update_post_meta($original_post_id, $log_key, $log);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Marked translation as failed for post $original_post_id (language: $target_language): $error_message");
    }

    /**
     * Gets the current translation status for a post and language.
     * 
     * @param int $post_id Post ID
     * @param string $language Language code
     * @return string Translation status
     */
    public function get_status($post_id, $language)
    {
        $status_key = '_polytrans_translation_status_' . $language;
        return get_post_meta($post_id, $status_key, true) ?: 'not_started';
    }

    /**
     * Gets the translation log for a post and language.
     * 
     * @param int $post_id Post ID
     * @param string $language Language code
     * @return array Translation log entries
     */
    public function get_log($post_id, $language)
    {
        $log_key = '_polytrans_translation_log_' . $language;
        $log = get_post_meta($post_id, $log_key, true);
        return is_array($log) ? $log : [];
    }

    /**
     * Clears translation status and logs for a post and language.
     * 
     * @param int $post_id Post ID
     * @param string $language Language code
     */
    public function clear_status($post_id, $language)
    {
        $status_key = '_polytrans_translation_status_' . $language;
        $log_key = '_polytrans_translation_log_' . $language;
        $target_key = '_polytrans_translation_target_' . $language;
        $post_id_key = '_polytrans_translation_post_id_' . $language;
        $review_key = '_polytrans_translation_needs_review_' . $language;

        delete_post_meta($post_id, $status_key);
        delete_post_meta($post_id, $log_key);
        delete_post_meta($post_id, $target_key);
        delete_post_meta($post_id, $post_id_key);
        delete_post_meta($post_id, $review_key);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[polytrans] Cleared translation status for post $post_id (language: $language)");
    }

    /**
     * Check for stuck translations and mark them as failed.
     * 
     * @param int $timeout_hours Hours after which a translation should be considered stuck (default: 24)
     * @return array Results of the check
     */
    public function check_stuck_translations($timeout_hours = 24)
    {
        global $wpdb;

        $results = [
            'checked' => 0,
            'fixed' => 0,
            'stuck' => []
        ];

        // Non-terminal states - include both local and external workflow status values
        $non_terminal_states = ['started', 'translating', 'processing', 'post_processing'];

        // Get all post meta entries with these statuses
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a trusted WordPress core property
        $stuck_translations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM `{$wpdb->postmeta}`
                WHERE meta_key LIKE %s
                AND meta_value IN ('started', 'translating', 'processing', 'post_processing')",
                '_polytrans_translation_status_%'
            )
        );
        $results['checked'] = count($stuck_translations);

        $timeout_seconds = $timeout_hours * 3600; // Convert hours to seconds
        $now = time();

        foreach ($stuck_translations as $item) {
            // Extract language from meta key
            preg_match('/_polytrans_translation_status_(.+)$/', $item->meta_key, $matches);
            if (empty($matches[1])) continue;

            $post_id = $item->post_id;
            $language = $matches[1];
            $status_key = $item->meta_key;
            $log_key = '_polytrans_translation_log_' . $language;

            // Get the last log entry to determine the start time
            $log = get_post_meta($post_id, $log_key, true);
            $start_time = 0;

            if (is_array($log) && !empty($log)) {
                // Find the most recent "started" or "process started" entry
                foreach (array_reverse($log) as $entry) {
                    $msg = strtolower($entry['msg'] ?? '');
                    // Check for messages from either workflow (local or external)
                    if (strpos($msg, 'started') !== false || strpos($msg, 'process started') !== false) {
                        $start_time = $entry['timestamp'] ?? 0;
                        break;
                    }
                }

                // If we can't find a start entry, use the oldest log entry
                if ($start_time === 0 && isset($log[0]['timestamp'])) {
                    $start_time = $log[0]['timestamp'];
                }
            }

            // If we still don't have a start time, use current time minus timeout (worst case assumption)
            if ($start_time === 0) {
                $start_time = $now - $timeout_seconds - 1; // Ensure it's considered stuck
            }

            $elapsed_seconds = $now - $start_time;

            // If it's been longer than the timeout, mark as failed
            if ($elapsed_seconds > $timeout_seconds) {
                // Add to the results
                $results['stuck'][] = [
                    'post_id' => $post_id,
                    'language' => $language,
                    'status' => $item->meta_value,
                    'elapsed_hours' => round($elapsed_seconds / 3600, 1)
                ];

                // Update the status to failed
                update_post_meta($post_id, $status_key, 'failed');

                // Add a log entry about the timeout
                $log = is_array($log) ? $log : [];
                $log[] = [
                    'timestamp' => $now,
                    'msg' => sprintf(
                        /* translators: %1$s: translation status, %2$s: number of hours */
                        __('Translation marked as failed after being stuck in "%1$s" status for %2$s hours.', 'polytrans'),
                        $item->meta_value,
                        round($elapsed_seconds / 3600, 1)
                    )
                ];
                update_post_meta($post_id, $log_key, $log);

                $results['fixed']++;

                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf(
                    '[polytrans] Fixed stuck translation: Post %1$d, language %2$s was in "%3$s" status for %4$s hours',
                    $post_id,
                    $language,
                    $item->meta_value,
                    round($elapsed_seconds / 3600, 1)
                ));
            }
        }

        return $results;
    }

    /**
     * Get a summary of translation statuses for all posts
     * 
     * @return array Status summary
     */
    public function get_status_summary()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a trusted WordPress core property
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value as status, COUNT(*) as count
                FROM `{$wpdb->postmeta}`
                WHERE meta_key LIKE %s
                GROUP BY meta_value",
                '_polytrans_translation_status_%'
            )
        );

        $summary = [
            'total' => 0,
            'not_started' => 0,
            'started' => 0,
            'translating' => 0,
            'processing' => 0,
            'post_processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];

        if ($results) {
            foreach ($results as $row) {
                $status = $row->status;
                $count = (int)$row->count;
                $summary[$status] = $count;
                $summary['total'] += $count;
            }
        }

        return $summary;
    }
}

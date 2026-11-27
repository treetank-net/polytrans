<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Decides as whom a translation is written when nobody is logged in.
 *
 * Translations are written by the background loopback or the receiver endpoint, where
 * the current user is nobody. WordPress runs every post write through kses for a user
 * without `unfiltered_html`, and for nobody that strips iframes and scripts: video
 * embeds and the script behind social media embeds vanish from the translation, not
 * only on the first insert but on every later wp_update_post() of the post, whichever
 * field it changes.
 *
 * The write is attributed to the person who last saved the original (`_edit_last`),
 * then to its author. Whoever last saved it is the one who put its markup there, so the
 * translation keeps exactly what that person could save themselves and nothing more:
 * if they lack `unfiltered_html`, kses still applies. A logged-in request (an editor
 * running a workflow by hand) keeps its own user.
 *
 * Deciding stays here; the swap itself goes through UserContext::run_as().
 */
class ContentWriter
{
    /**
     * User to write a translation of $original_post_id as, or 0 to stay as the current user.
     *
     * @param int $original_post_id Post the translation was made from.
     * @param int $target_post_id   Post about to be written, when it exists already; the
     *                              writer must be able to edit it.
     * @return int User ID, or 0 when a user is logged in or nobody fits.
     */
    public static function resolve($original_post_id, $target_post_id = 0)
    {
        $original_post_id = (int) $original_post_id;
        $target_post_id = (int) $target_post_id;

        if (get_current_user_id() > 0 || $original_post_id <= 0 || !get_post($original_post_id)) {
            return 0;
        }

        $candidates = [
            (int) get_post_meta($original_post_id, '_edit_last', true),
            (int) get_post_field('post_author', $original_post_id),
        ];

        foreach ($candidates as $user_id) {
            if ($user_id <= 0 || !user_can($user_id, 'edit_post', $original_post_id)) {
                continue;
            }
            if ($target_post_id > 0 && $target_post_id !== $original_post_id && !user_can($user_id, 'edit_post', $target_post_id)) {
                continue;
            }
            return $user_id;
        }

        return 0;
    }
}

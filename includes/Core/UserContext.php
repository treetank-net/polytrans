<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs a single operation with a different current user.
 *
 * `wp_set_current_user()` answers two questions at once: who is credited for a
 * change, and whose capabilities apply while it happens. Only the first is ever
 * the point here, so every swap in this plugin goes through this class: it covers
 * one call and restores the previous identity in `finally`, because an operation
 * that throws must not leave the borrowed identity in place for the rest of the
 * request.
 *
 * Deciding *whether* a user may be borrowed stays with the caller — that check
 * needs the target object, which this class knows nothing about.
 */
class UserContext
{
    /**
     * Execute $callback with $user_id as the current user, then switch back.
     *
     * @param int      $user_id  User to act as. Zero or negative runs unchanged.
     * @param callable $callback The operation to perform.
     * @return mixed Whatever $callback returns.
     */
    public static function run_as($user_id, callable $callback)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0) {
            return $callback();
        }

        $previous_user_id = get_current_user_id();

        if ($previous_user_id === $user_id) {
            return $callback();
        }

        wp_set_current_user($user_id);

        try {
            return $callback();
        } finally {
            wp_set_current_user($previous_user_id);
        }
    }
}

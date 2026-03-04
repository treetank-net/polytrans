<?php

namespace PolyTrans\Core;

/**
 * User Autocomplete Class
 * Handles user search functionality for reviewer assignment
 */

if (!defined('ABSPATH')) {
    exit;
}

class UserAutocomplete
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('wp_ajax_polytrans_search_users', [$this, 'ajax_search_users']);
    }

    /**
     * AJAX handler for user search
     */
    public function ajax_search_users()
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));

        if (strlen($search) < 2) {
            wp_send_json_success(['users' => []]);
        }

        $users = get_users([
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 20,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'user_login' => $user->user_login,
                'label' => $user->display_name . ' (' . $user->user_email . ')',
            ];
        }

        wp_send_json_success(['users' => $results]);
    }
}

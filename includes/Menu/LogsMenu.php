<?php

/**
 * Logs Menu Class
 * Handles logs menu management
 */

namespace PolyTrans\Menu;

if (!defined('ABSPATH')) {
    exit;
}

class LogsMenu
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add logs submenu
     */
    public function add_logs_submenu()
    {
        add_submenu_page(
            'polytrans',
            __('Logs', 'treetank-trans'),
            __('Logs', 'treetank-trans'),
            'manage_options',
            'polytrans-logs',
            [$this, 'render_logs'],
            50
        );
    }

    /**
     * Add scripts for logs page
     */
    public function add_scripts($hook)
    {
        // Load on logs page
        if ($hook === 'polytrans_page_polytrans-logs') {
            // Enqueue logs admin assets
            wp_enqueue_script(
                'polytrans-logs-admin',
                POLYTRANS_PLUGIN_URL . 'assets/js/logs-admin.js',
                ['jquery'],
                POLYTRANS_VERSION,
                true
            );

            wp_enqueue_style(
                'polytrans-logs-admin',
                POLYTRANS_PLUGIN_URL . 'assets/css/logs-admin.css',
                [],
                POLYTRANS_VERSION
            );

            // Localize script
            wp_localize_script('polytrans-logs-admin', 'PolyTransLogsAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_refresh_logs'),
                'strings' => [
                    'showContext' => __('Show Context', 'treetank-trans'),
                    'hideContext' => __('Hide Context', 'treetank-trans'),
                    'refreshing' => __('Refreshing...', 'treetank-trans'),
                    'autoRefreshDisabled' => __('Auto-refresh disabled', 'treetank-trans'),
                    'paused' => __('Paused', 'treetank-trans'),
                    'autoRefreshingEvery' => __('Auto-refreshing every', 'treetank-trans'),
                    'seconds' => __('seconds', 'treetank-trans'),
                    'pause' => __('Pause', 'treetank-trans'),
                    'resume' => __('Resume', 'treetank-trans'),
                ]
            ]);
        }
    }

    /**
     * Render logs page
     */
    public function render_logs()
    {
        \PolyTrans_Logs_Manager::admin_logs_page();
    }
}

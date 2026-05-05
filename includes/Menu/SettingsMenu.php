<?php

/**
 * Settings Menu Class
 * Handles PolyTrans main menu and settings page
 */

namespace PolyTrans\Menu;

use PolyTrans\PromptRefinement\RefinementRunStorage;
use PolyTrans\Templating\TemplateRenderer;
use PolyTrans\Core\LogsManager;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsMenu
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
     * Constructor - register AJAX endpoints early
     */
    public function __construct()
    {
        // Register universal AJAX endpoints early (before page render)
        // This ensures endpoints are available for AJAX requests
        add_action('wp_ajax_polytrans_validate_provider_key', [\PolyTrans\Core\TranslationSettings::class, 'ajax_validate_provider_key_static']);
        add_action('wp_ajax_polytrans_refinement_run_storage_stats', [$this, 'ajax_refinement_run_storage_stats']);
        add_action('wp_ajax_polytrans_cleanup_refinement_runs', [$this, 'ajax_cleanup_refinement_runs']);
        add_action('wp_ajax_polytrans_maintenance_stats', [$this, 'ajax_maintenance_stats']);
        add_action('wp_ajax_polytrans_cleanup_logs', [$this, 'ajax_cleanup_logs']);
    }


    public function add_admin_menu()
    {
        // Main menu - accessible to editors
        add_menu_page(
            __('PolyTrans', 'polytrans'),
            __('PolyTrans', 'polytrans'),
            'edit_posts',
            'polytrans',
            [$this, 'render_overview'],
            'dashicons-translation',
            80
        );

        // Rename first submenu item from "PolyTrans" to "Overview"
        add_submenu_page(
            'polytrans',
            __('Overview', 'polytrans'),
            __('Overview', 'polytrans'),
            'edit_posts',
            'polytrans',
            [$this, 'render_overview']
        );

        // Settings submenu - admin only
        add_submenu_page(
            'polytrans',
            __('Settings', 'polytrans'),
            __('Settings', 'polytrans'),
            'manage_options',
            'polytrans-settings',
            [$this, 'render_settings']
        );
    }

    public function add_scripts($hook)
    {
        // Load scripts for both overview and settings pages
        if ($hook === 'toplevel_page_polytrans' || $hook === 'polytrans_page_polytrans-settings') {
            $plugin_url = POLYTRANS_PLUGIN_URL;
            wp_enqueue_script('polytrans-settings', $plugin_url . 'assets/js/settings/translation-settings-admin.js', ['jquery'], POLYTRANS_VERSION, true);
            wp_enqueue_script('polytrans-provider-settings-universal', $plugin_url . 'assets/js/settings/provider-settings-universal.js', ['jquery'], POLYTRANS_VERSION, true);
            wp_enqueue_script('polytrans-user-autocomplete', $plugin_url . 'assets/js/core/user-autocomplete.js', ['jquery-ui-autocomplete'], POLYTRANS_VERSION, true);

            wp_enqueue_style('polytrans-settings', $plugin_url . 'assets/css/settings/translation-settings-admin.css', [], POLYTRANS_VERSION);
            wp_enqueue_style('jquery-ui-autocomplete');

            // Localize user autocomplete script
            wp_localize_script('polytrans-user-autocomplete', 'PolyTransUserAutocomplete', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_nonce'),
                'i18n' => [
                    'no_results' => esc_html__('No users found.', 'polytrans'),
                    'searching' => esc_html__('Searching users...', 'polytrans'),
                    'clear_selection' => esc_html__('Clear selection', 'polytrans'),
                    'type_to_search' => esc_html__('Type to search users...', 'polytrans'),
                    'min_chars' => esc_html__('Type at least 2 characters to search.', 'polytrans'),
                ]
            ]);

            // Localize script for main settings
            $settings = get_option('polytrans_settings', []);
            $localization_data = [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('polytrans_nonce'),
                'openai_nonce' => wp_create_nonce('polytrans_openai_nonce'),
                'settings' => $settings,
                'translation_receiver_endpoint' => $settings['translation_receiver_endpoint'] ?? '',
                'i18n' => [
                    'loading' => esc_html__('Loading...', 'polytrans'),
                    'saving' => esc_html__('Saving...', 'polytrans'),
                    'saved' => esc_html__('Settings saved successfully!', 'polytrans'),
                    // Universal provider manager i18n
                    'please_enter_api_key' => esc_html__('Please enter an API key', 'polytrans'),
                    'validating' => esc_html__('Validating...', 'polytrans'),
                    'api_key_valid' => esc_html__('API key is valid!', 'polytrans'),
                    'api_key_invalid' => esc_html__('Invalid API key', 'polytrans'),
                    'validation_failed' => esc_html__('Failed to validate API key. Please try again.', 'polytrans'),
                    'refreshing' => esc_html__('Refreshing...', 'polytrans'),
                    'models_refreshed' => esc_html__('Models refreshed', 'polytrans'),
                    'no_models' => esc_html__('No models available', 'polytrans'),
                    'dismiss_notice' => esc_html__('Dismiss this notice', 'polytrans'),
                    'error' => esc_html__('An error occurred. Please try again.', 'polytrans'),
                    'confirm_delete' => esc_html__('Are you sure you want to delete this item?', 'polytrans'),
                    'test_connection' => esc_html__('Testing connection...', 'polytrans'),
                    'connection_success' => esc_html__('Connection successful!', 'polytrans'),
                    'connection_failed' => esc_html__('Connection failed. Please check your settings.', 'polytrans'),
                    'invalid_url' => esc_html__('Please enter a valid URL.', 'polytrans'),
                    'required_field' => esc_html__('This field is required.', 'polytrans'),
                    'all' => esc_html__('All', 'polytrans'),
                    'none_direct' => esc_html__('None (Direct)', 'polytrans'),
                    'remove' => esc_html__('Remove', 'polytrans'),
                ]
            ];
            
            wp_localize_script('polytrans-settings', 'PolyTransAjax', $localization_data);
            wp_localize_script('polytrans-provider-settings-universal', 'PolyTransAjax', $localization_data);
        }
    }

    /**
     * Render overview page
     */
    public function render_overview()
    {
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/settings/overview.twig', [
            'can_manage_options' => current_user_can('manage_options'),
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render settings page
     */
    public function render_settings()
    {
        // Note: polytrans_settings class is autoloaded (aliased to TranslationSettings)
        $settings = new \polytrans_settings();
        $settings->render();
    }

    public function ajax_refinement_run_storage_stats(): void
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'polytrans')], 403);
        }

        wp_send_json_success(['stats' => RefinementRunStorage::stats()]);
    }

    public function ajax_cleanup_refinement_runs(): void
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'polytrans')], 403);
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'expired';
        if ($mode === 'all') {
            $deleted = RefinementRunStorage::deleteAll();
        } else {
            $mode = 'expired';
            $deleted = RefinementRunStorage::cleanupExpiredNow();
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'mode' => $mode,
            'stats' => RefinementRunStorage::stats(),
        ]);
    }

    public function ajax_maintenance_stats(): void
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'polytrans')], 403);
        }

        $retention_days = isset($_POST['logs_retention_days']) ? intval($_POST['logs_retention_days']) : null;

        wp_send_json_success([
            'logs' => LogsManager::get_storage_stats($retention_days),
            'refinement_runs' => RefinementRunStorage::stats(),
        ]);
    }

    public function ajax_cleanup_logs(): void
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'polytrans')], 403);
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'old';
        if ($mode === 'all') {
            $deleted = LogsManager::truncate_logs();
        } else {
            $mode = 'old';
            $retention_days = isset($_POST['logs_retention_days']) ? intval($_POST['logs_retention_days']) : null;
            $deleted = LogsManager::clear_old_logs($retention_days ?? 30);
            update_option('polytrans_logs_cleanup_at', time(), false);
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'mode' => $mode,
            'stats' => LogsManager::get_storage_stats(isset($_POST['logs_retention_days']) ? intval($_POST['logs_retention_days']) : null),
        ]);
    }
}

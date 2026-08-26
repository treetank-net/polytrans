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
        // Main menu - accessible to editors.
        //
        // Position 76 sits in the free slot between Tools (75) and Settings (80).
        // It must not be 80: that slot belongs to core's Settings menu, and
        // add_menu_page() resolves the collision by appending a fraction derived
        // from md5( slug . title ) — PolyTrans landed on 80.36901, below Settings,
        // in a spot nobody chose. ACF (80) and others crowd the same slot, so the
        // resulting order changed with whatever else the site had installed.
        add_menu_page(
            __('TreeTank Translation Workflows', 'treetank-trans'),
            __('TreeTank', 'treetank-trans'),
            'edit_posts',
            'polytrans',
            [$this, 'render_overview'],
            'dashicons-translation',
            76
        );

        // Rename first submenu item from "PolyTrans" to "Overview"
        add_submenu_page(
            'polytrans',
            __('Overview', 'treetank-trans'),
            __('Overview', 'treetank-trans'),
            'edit_posts',
            'polytrans',
            [$this, 'render_overview']
        );

        // Settings submenu - admin only
        add_submenu_page(
            'polytrans',
            __('Settings', 'treetank-trans'),
            __('Settings', 'treetank-trans'),
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
                    'no_results' => esc_html__('No users found.', 'treetank-trans'),
                    'searching' => esc_html__('Searching users...', 'treetank-trans'),
                    'clear_selection' => esc_html__('Clear selection', 'treetank-trans'),
                    'type_to_search' => esc_html__('Type to search users...', 'treetank-trans'),
                    'min_chars' => esc_html__('Type at least 2 characters to search.', 'treetank-trans'),
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
                    'loading' => esc_html__('Loading...', 'treetank-trans'),
                    'saving' => esc_html__('Saving...', 'treetank-trans'),
                    'saved' => esc_html__('Settings saved successfully!', 'treetank-trans'),
                    // Universal provider manager i18n
                    'please_enter_api_key' => esc_html__('Please enter an API key', 'treetank-trans'),
                    'validating' => esc_html__('Validating...', 'treetank-trans'),
                    'api_key_valid' => esc_html__('API key is valid!', 'treetank-trans'),
                    'api_key_invalid' => esc_html__('Invalid API key', 'treetank-trans'),
                    'validation_failed' => esc_html__('Failed to validate API key. Please try again.', 'treetank-trans'),
                    'refreshing' => esc_html__('Refreshing...', 'treetank-trans'),
                    'models_refreshed' => esc_html__('Models refreshed', 'treetank-trans'),
                    'no_models' => esc_html__('No models available', 'treetank-trans'),
                    'none_selected' => esc_html__('None selected', 'treetank-trans'),
                    'effort_provider_default' => esc_html__('Provider default', 'treetank-trans'),
                    'dismiss_notice' => esc_html__('Dismiss this notice', 'treetank-trans'),
                    'error' => esc_html__('An error occurred. Please try again.', 'treetank-trans'),
                    'confirm_delete' => esc_html__('Are you sure you want to delete this item?', 'treetank-trans'),
                    'test_connection' => esc_html__('Testing connection...', 'treetank-trans'),
                    'connection_success' => esc_html__('Connection successful!', 'treetank-trans'),
                    'connection_failed' => esc_html__('Connection failed. Please check your settings.', 'treetank-trans'),
                    'invalid_url' => esc_html__('Please enter a valid URL.', 'treetank-trans'),
                    'required_field' => esc_html__('This field is required.', 'treetank-trans'),
                    'all' => esc_html__('All', 'treetank-trans'),
                    'none_direct' => esc_html__('None (Direct)', 'treetank-trans'),
                    'remove' => esc_html__('Remove', 'treetank-trans'),
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
            wp_send_json_error(['message' => __('Insufficient permissions.', 'treetank-trans')], 403);
        }

        wp_send_json_success(['stats' => RefinementRunStorage::stats()]);
    }

    public function ajax_cleanup_refinement_runs(): void
    {
        check_ajax_referer('polytrans_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'treetank-trans')], 403);
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
            wp_send_json_error(['message' => __('Insufficient permissions.', 'treetank-trans')], 403);
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
            wp_send_json_error(['message' => __('Insufficient permissions.', 'treetank-trans')], 403);
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

<?php

/**
 * Usage Menu Class
 * Cost and token usage dashboard
 */

namespace PolyTrans\Menu;

use PolyTrans\Core\UsageRecorder;
use PolyTrans\Core\UsageReport;
use PolyTrans\Templating\TemplateRenderer;

if (!defined('ABSPATH')) {
    exit;
}

class UsageMenu
{
    const PAGE_SLUG = 'polytrans-usage';

    /**
     * Selectable periods, in days. Zero means everything on record.
     *
     * @var array
     */
    private static $periods = [7, 30, 90, 365, 0];

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
     * Add the usage submenu.
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'polytrans',
            __('AI Costs', 'polytrans'),
            __('AI Costs', 'polytrans'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            45
        );
    }

    /**
     * Enqueue the dashboard stylesheet.
     *
     * @param string $hook Current admin page hook.
     */
    public function add_scripts($hook)
    {
        if ($hook !== 'polytrans_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'polytrans-usage-admin',
            POLYTRANS_PLUGIN_URL . 'assets/css/usage-admin.css',
            [],
            POLYTRANS_VERSION
        );
    }

    /**
     * Render the dashboard.
     */
    public function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'polytrans'));
        }

        // Creates the table on first view, so the page works on an installation that
        // was updated rather than reactivated.
        UsageRecorder::initialize();

        $filters = $this->read_filters();

        $context = [
            'page_slug' => self::PAGE_SLUG,
            'filters' => $filters,
            'periods' => $this->period_choices(),
            'table_missing' => !UsageRecorder::table_exists(),
            'totals' => UsageReport::totals($filters),
            'by_model' => UsageReport::by('model', $filters),
            'by_language' => UsageReport::by('target_language', $filters),
            'by_activity' => UsageReport::by('activity', $filters),
            'by_workflow' => UsageReport::by('workflow_id', $filters),
            'daily' => UsageReport::daily($filters),
            'top_posts' => UsageReport::top_posts($filters, 20),
            'known_models' => UsageReport::known_models(),
            'activities' => $this->activity_choices(),
        ];

        $context['daily_max'] = $this->max_daily_cost($context['daily']);
        $context['daily_max_display'] = UsageReport::format_usd($context['daily_max']);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/usage/page.twig', $context);
    }

    /**
     * Read and sanitise the request filters.
     *
     * @return array
     */
    private function read_filters()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report filters on a GET form.
        $days = isset($_GET['days']) ? (int) $_GET['days'] : 30;
        $model = isset($_GET['model']) ? sanitize_text_field(wp_unslash($_GET['model'])) : '';
        $activity = isset($_GET['activity']) ? sanitize_text_field(wp_unslash($_GET['activity'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!in_array($days, self::$periods, true)) {
            $days = 30;
        }

        return [
            'days' => $days,
            'model' => $model,
            'activity' => $activity,
        ];
    }

    /**
     * @return array Period options as value => label.
     */
    private function period_choices()
    {
        return [
            7 => __('Last 7 days', 'polytrans'),
            30 => __('Last 30 days', 'polytrans'),
            90 => __('Last 90 days', 'polytrans'),
            365 => __('Last 12 months', 'polytrans'),
            0 => __('All time', 'polytrans'),
        ];
    }

    /**
     * @return array Activity options as value => label.
     */
    private function activity_choices()
    {
        return [
            'translation' => __('Translation', 'polytrans'),
            'workflow_step' => __('Workflow step', 'polytrans'),
            'assistant_test' => __('Assistant test', 'polytrans'),
        ];
    }

    /**
     * Largest daily cost, used to scale the trend bars.
     *
     * @param array $daily Daily rows.
     * @return float
     */
    private function max_daily_cost($daily)
    {
        $max = 0.0;

        foreach ($daily as $row) {
            $max = max($max, (float) ($row['total_usd'] ?? 0));
        }

        return $max;
    }
}

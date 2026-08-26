<?php

/**
 * Usage Menu Class
 * Cost and token usage dashboard
 */

namespace PolyTrans\Menu;

use PolyTrans\Core\UsageRecorder;
use PolyTrans\Core\UsageReport;
use PolyTrans\Core\UsageWindow;
use PolyTrans\Core\TranslationRunManager;
use PolyTrans\Templating\TemplateRenderer;

if (!defined('ABSPATH')) {
    exit;
}

class UsageMenu
{
    const PAGE_SLUG = 'polytrans-usage';

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
            __('AI Costs', 'treetank-trans'),
            __('AI Costs', 'treetank-trans'),
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

        wp_enqueue_script(
            'polytrans-usage-admin',
            POLYTRANS_PLUGIN_URL . 'assets/js/usage-admin.js',
            [],
            POLYTRANS_VERSION,
            true
        );
    }

    /**
     * Render the dashboard.
     */
    public function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'treetank-trans'));
        }

        // Creates the table on first view, so the page works on an installation that
        // was updated rather than reactivated.
        UsageRecorder::initialize();
        TranslationRunManager::initialize();

        $request = $this->read_request();
        $window = $this->build_window($request);

        // The window is the authority on the range, so it is merged in last: a stale
        // from/to in the request cannot outvote the preset that was actually chosen.
        $filters = array_merge($request['filters'], $window->args());

        $context = [
            'page_slug' => self::PAGE_SLUG,
            'filters' => $filters,
            'window' => [
                'preset' => $window->preset(),
                'bucket' => $window->bucket(),
                'requested_bucket' => $window->requested_bucket(),
                'bucket_downgraded' => $window->bucket_downgraded(),
                'from' => $window->input_from(),
                'to' => $window->input_to(),
                'range_display' => $window->format_range(),
            ],
            'presets' => UsageWindow::preset_labels(),
            'buckets' => UsageWindow::bucket_labels(),
            'table_missing' => !UsageRecorder::table_exists(),
            'totals' => UsageReport::totals($filters),
            'by_model' => UsageReport::by('model', $filters),
            // The language the request was for, so a relay's intermediate hop counts
            // towards the market that needed it rather than the one it passed through.
            'by_language' => UsageReport::by('final_language', $filters),
            'by_activity' => UsageReport::by('activity', $filters),
            'by_workflow' => UsageReport::by('workflow_id', $filters),
            // The hops themselves, which is where a relay becomes visible: pl>en and
            // en>de appear separately, and the row says how many of its calls were
            // intermediate.
            'by_step' => UsageReport::by('language_pair', $filters),
            'by_path' => UsageReport::by('translation_path', $filters),
            'series' => UsageReport::series($window, $request['filters']),
            'top_posts' => UsageReport::top_posts($filters, 20),
            'translation_run_totals' => UsageReport::translation_run_totals($filters),
            'translation_runs' => UsageReport::translation_runs($filters, 20),
            'known_models' => UsageReport::known_models(),
            'activities' => $this->activity_choices(),
        ];

        $context['series_max'] = $this->max_bucket_cost($context['series']);
        $context['series_max_display'] = UsageReport::format_usd($context['series_max']);
        $context['series_ticks'] = $this->series_ticks($context['series']);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig templates handle escaping
        echo TemplateRenderer::render('admin/usage/page.twig', $context);
    }

    /**
     * Read and sanitise the request.
     *
     * The range half is handed to UsageWindow to interpret, since deciding what
     * '7d' or a half-filled custom range means is its job, not this one's.
     *
     * @return array {
     *     @type array $range   Raw range input for UsageWindow.
     *     @type array $filters Everything else, already sanitised.
     * }
     */
    private function read_request()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report filters on a GET form.
        $get = function ($key) {
            return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        };
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return [
            'range' => [
                'preset' => $get('preset'),
                'from' => $get('from'),
                'to' => $get('to'),
                'bucket' => $get('bucket'),
            ],
            'filters' => [
                'model' => $get('model'),
                'activity' => $get('activity'),
            ],
        ];
    }

    /**
     * Resolve the request's range into a window.
     *
     * @param array $request Sanitised request, see read_request().
     * @return UsageWindow
     */
    private function build_window(array $request)
    {
        // Only 'all time' needs to know when recording started, and it costs an index
        // lookup, so every other preset skips it.
        $earliest = ($request['range']['preset'] ?? '') === UsageWindow::PRESET_ALL
            ? UsageReport::earliest_call()
            : null;

        return UsageWindow::from_request($request['range'], $earliest);
    }

    /**
     * @return array Activity options as value => label.
     */
    private function activity_choices()
    {
        return [
            'translation' => __('Translation', 'treetank-trans'),
            'workflow_step' => __('Workflow step', 'treetank-trans'),
            'assistant_test' => __('Assistant test', 'treetank-trans'),
        ];
    }

    /**
     * Largest cost in the series, used to scale the trend bars.
     *
     * @param array $series Series rows.
     * @return float
     */
    private function max_bucket_cost($series)
    {
        $max = 0.0;

        foreach ($series as $row) {
            $max = max($max, (float) ($row['total_usd'] ?? 0));
        }

        return $max;
    }

    /**
     * Three labels along the series, so a reader can tell where in it they are.
     *
     * Labelling every bucket is unreadable at hourly resolution - 336 of them - and
     * labelling none leaves a chart whose bars mean nothing without hovering each one.
     *
     * @param array $series Series rows.
     * @return array Start, middle and end labels, or an empty array when too short.
     */
    private function series_ticks($series)
    {
        $count = count($series);

        if ($count < 3) {
            return [];
        }

        return [
            $series[0]['label'],
            $series[intdiv($count, 2)]['label'],
            $series[$count - 1]['label'],
        ];
    }
}

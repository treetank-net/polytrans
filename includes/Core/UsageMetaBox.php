<?php

namespace PolyTrans\Core;

use PolyTrans\Templating\TemplateRenderer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usage Meta Box
 *
 * Shows what the AI work on one article cost, read from the summary meta that
 * UsageRecorder maintains. Deliberately does not query polytrans_usage: the point
 * of that denormalised copy is that an edit screen costs no aggregate query.
 */
class UsageMetaBox
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
     * Register the meta box, unless nothing has been recorded for the post.
     *
     * @param string   $post_type Post type.
     * @param \WP_Post $post      Post being edited.
     * @return void
     */
    public function register($post_type, $post)
    {
        if (!$post || !UsageRecorder::get_post_summary($post->ID)) {
            return;
        }

        add_meta_box(
            'polytrans_usage',
            __('AI Cost', 'treetank-trans'),
            [$this, 'render'],
            $post_type,
            'side',
            'default'
        );
    }

    /**
     * Enqueue the shared dashboard stylesheet on edit screens.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function add_scripts($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
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
     * Render the meta box.
     *
     * @param \WP_Post $post Post being edited.
     * @return void
     */
    public function render($post)
    {
        $summary = UsageRecorder::get_post_summary($post->ID);

        if (!$summary) {
            echo '<p>' . esc_html__('No AI calls recorded for this post.', 'treetank-trans') . '</p>';
            return;
        }

        $html = TemplateRenderer::render('admin/usage/metabox.twig', [
            'summary' => $summary,
            'total_display' => UsageReport::format_usd($summary['total_usd'] ?? null),
            'languages' => self::prepare_buckets($summary['by_language'] ?? []),
            'models' => self::prepare_buckets($summary['by_model'] ?? []),
            'activities' => self::prepare_buckets($summary['by_activity'] ?? []),
            'reasoning_share' => self::reasoning_share($summary),
            'dashboard_url' => admin_url('admin.php?page=polytrans-usage&days=0'),
        ]);

        // A phpcs:ignore covers the next line only, so the echo has to be one line —
        // annotating a multi-line statement silences nothing.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup from admin/usage/metabox.twig, where every value is escaped per output with esc_html()/esc_attr().
        echo $html;
    }

    /**
     * Sort buckets by cost and attach display strings.
     *
     * @param array $buckets Buckets keyed by label.
     * @return array
     */
    private static function prepare_buckets($buckets)
    {
        if (!is_array($buckets)) {
            return [];
        }

        $rows = [];

        foreach ($buckets as $label => $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            $rows[] = [
                'label' => (string) $label,
                'calls' => (int) ($bucket['calls'] ?? 0),
                'unpriced_calls' => (int) ($bucket['unpriced_calls'] ?? 0),
                'tokens' => (int) ($bucket['tokens_input'] ?? 0) + (int) ($bucket['tokens_output'] ?? 0),
                'cost_display' => UsageReport::format_usd($bucket['total_usd'] ?? null),
                'cost' => (float) ($bucket['total_usd'] ?? 0),
            ];
        }

        usort($rows, function ($a, $b) {
            return $b['cost'] <=> $a['cost'];
        });

        return $rows;
    }

    /**
     * Share of output tokens spent on reasoning.
     *
     * Shown because it is usually the largest single lever on the number above it.
     *
     * @param array $summary Stored summary.
     * @return int
     */
    private static function reasoning_share($summary)
    {
        $output = (int) ($summary['tokens_output'] ?? 0);
        $reasoning = (int) ($summary['tokens_reasoning'] ?? 0);

        if ($output <= 0) {
            return 0;
        }

        return min(100, (int) round($reasoning / $output * 100));
    }
}

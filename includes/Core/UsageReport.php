<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usage Report
 *
 * Reads the aggregations a cost dashboard needs out of {prefix}polytrans_usage.
 *
 * Every figure is summed in SQL rather than in PHP, since the table grows by one
 * row per AI call and a busy site accumulates them quickly. Costs were frozen when
 * each row was written, so a total here is the sum of what was charged at the time,
 * not a recomputation at today's prices.
 */
class UsageReport
{
    /**
     * Columns that may be grouped on. Anything else is rejected rather than
     * interpolated, since a group column cannot be passed as a prepared value.
     *
     * @var array
     */
    private static $groupable = [
        'model',
        'provider',
        'source_language',
        'target_language',
        'final_language',
        'translation_path',
        'path_step',
        'activity',
        'workflow_id',
        'pricing_source',
    ];

    /**
     * Dimensions that are not columns but expressions over them. Kept here rather
     * than assembled by the caller, for the same reason as $groupable: the grouping
     * term is interpolated, so it must come from this file and nowhere else.
     *
     * @var array
     */
    private static $group_expressions = [
        // The hop itself, e.g. 'pl>en'. Two languages identify it; the path it sat in
        // is a separate dimension, so the same hop shared by several paths groups
        // together here.
        'language_pair' => "CONCAT(COALESCE(source_language, '?'), '>', COALESCE(target_language, '?'))",
    ];

    /**
     * Dimensions whose rows belong to one activity type. Keeping the constraint here,
     * rather than in each dashboard caller, prevents a translation from appearing as
     * "not reported" in the workflow breakdown and a workflow as "?>de" in the hop
     * breakdown.
     *
     * @var array<string, string>
     */
    private static $dimension_activities = [
        'language_pair' => 'translation',
        'translation_path' => 'translation',
        'path_step' => 'translation',
        'workflow_id' => 'workflow_step',
    ];

    /**
     * Totals across the selected period.
     *
     * @param array $args Query arguments, see build_where().
     * @return array
     */
    public static function totals($args = [])
    {
        global $wpdb;

        if (!UsageRecorder::table_exists()) {
            return self::empty_totals();
        }

        [$where, $params] = self::build_where($args);

        $sql = "SELECT
                COUNT(*) AS calls,
                SUM(cost_usd) AS total_usd,
                SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(CASE WHEN final_language IS NOT NULL AND target_language <> final_language THEN 1 ELSE 0 END) AS relay_calls,
                SUM(CASE WHEN final_language IS NOT NULL AND target_language <> final_language THEN cost_usd ELSE 0 END) AS relay_usd,
                SUM(tokens_input) AS tokens_input,
                SUM(tokens_output) AS tokens_output,
                SUM(tokens_cached_read) AS tokens_cached_read,
                SUM(tokens_reasoning) AS tokens_reasoning,
                COUNT(DISTINCT model) AS models,
                MIN(created_at) AS first_call,
                MAX(created_at) AS last_call
            FROM " . self::table() . " {$where}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row(self::prepare($sql, $params), ARRAY_A);

        if (!$row) {
            return self::empty_totals();
        }

        return self::normalize_row($row);
    }

    /**
     * Totals grouped by one column, most expensive first.
     *
     * Ordered by cost with the call count as a tiebreak, so rows that cost nothing
     * measurable still appear in a stable order rather than shuffling between loads.
     *
     * @param string $column Dimension to group by: a name from self::$groupable or
     *                       self::$group_expressions.
     * @param array  $args   Query arguments, see build_where().
     * @param int    $limit  Maximum rows.
     * @return array
     */
    public static function by($column, $args = [], $limit = 50)
    {
        global $wpdb;

        $term = self::group_term($column);

        if ($term === null) {
            return [];
        }

        $args = self::dimension_activity_args($column, $args);

        if ($args === null || !UsageRecorder::table_exists()) {
            return [];
        }

        [$where, $params] = self::build_where($args);
        $limit = max(1, min(500, (int) $limit));

        $sql = "SELECT
                {$term} AS label,
                COUNT(*) AS calls,
                SUM(cost_usd) AS total_usd,
                SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(CASE WHEN final_language IS NOT NULL AND target_language <> final_language THEN 1 ELSE 0 END) AS relay_calls,
                SUM(tokens_input) AS tokens_input,
                SUM(tokens_output) AS tokens_output,
                SUM(tokens_cached_read) AS tokens_cached_read,
                SUM(tokens_reasoning) AS tokens_reasoning
            FROM " . self::table() . " {$where}
            GROUP BY {$term}
            ORDER BY SUM(cost_usd) DESC, COUNT(*) DESC
            LIMIT {$limit}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(self::prepare($sql, $params), ARRAY_A);

        return array_map([self::class, 'normalize_row'], is_array($rows) ? $rows : []);
    }

    /**
     * The SQL term for a requested dimension, or null when it is not one we allow.
     *
     * @param string $column Dimension name.
     * @return string|null
     */
    private static function group_term($column)
    {
        if (in_array($column, self::$groupable, true)) {
            return $column;
        }

        return self::$group_expressions[$column] ?? null;
    }

    /**
     * Restrict activity-specific dimensions to their own kind of call.
     *
     * @param string $column Dimension name.
     * @param array  $args   Query arguments.
     * @return array|null Query arguments, or null when an incompatible activity filter
     *                    makes the dimension empty by definition.
     */
    private static function dimension_activity_args($column, $args)
    {
        if (!isset(self::$dimension_activities[$column])) {
            return $args;
        }

        $activity = self::$dimension_activities[$column];

        if (!empty($args['activity']) && $args['activity'] !== $activity) {
            return null;
        }

        $args['activity'] = $activity;

        return $args;
    }

    /**
     * @return array Dimension names by() accepts, for a caller building a UI.
     */
    public static function dimensions()
    {
        return array_merge(self::$groupable, array_keys(self::$group_expressions));
    }

    /**
     * Daily totals, oldest first, for a trend line.
     *
     * @param array $args Query arguments, see build_where().
     * @return array
     */
    public static function daily($args = [])
    {
        global $wpdb;

        if (!UsageRecorder::table_exists()) {
            return [];
        }

        [$where, $params] = self::build_where($args);

        $sql = "SELECT
                DATE(created_at) AS label,
                COUNT(*) AS calls,
                SUM(cost_usd) AS total_usd,
                SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(tokens_input) AS tokens_input,
                SUM(tokens_output) AS tokens_output,
                SUM(tokens_cached_read) AS tokens_cached_read,
                SUM(tokens_reasoning) AS tokens_reasoning
            FROM " . self::table() . " {$where}
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(self::prepare($sql, $params), ARRAY_A);

        return array_map([self::class, 'normalize_row'], is_array($rows) ? $rows : []);
    }

    /**
     * The posts that cost the most in the selected period.
     *
     * Grouped on the original post, since that is the unit an editor thinks in: one
     * article, translated into several languages.
     *
     * @param array $args  Query arguments, see build_where().
     * @param int   $limit Maximum rows.
     * @return array
     */
    public static function top_posts($args = [], $limit = 20)
    {
        global $wpdb;

        if (!UsageRecorder::table_exists()) {
            return [];
        }

        [$where, $params] = self::build_where($args);
        $where = $where === ''
            ? 'WHERE source_post_id IS NOT NULL'
            : $where . ' AND source_post_id IS NOT NULL';
        $limit = max(1, min(200, (int) $limit));

        $sql = "SELECT
                source_post_id AS post_id,
                COUNT(*) AS calls,
                SUM(cost_usd) AS total_usd,
                SUM(CASE WHEN cost_usd IS NULL THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(tokens_input) AS tokens_input,
                SUM(tokens_output) AS tokens_output,
                SUM(tokens_cached_read) AS tokens_cached_read,
                SUM(tokens_reasoning) AS tokens_reasoning,
                COUNT(DISTINCT target_language) AS languages
            FROM " . self::table() . " {$where}
            GROUP BY source_post_id
            ORDER BY SUM(cost_usd) DESC
            LIMIT {$limit}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(self::prepare($sql, $params), ARRAY_A);

        $rows = is_array($rows) ? $rows : [];

        return array_map(function ($row) {
            $row = self::normalize_row($row);
            $row['post_id'] = (int) ($row['post_id'] ?? 0);
            $row['languages'] = (int) ($row['languages'] ?? 0);
            $row['title'] = get_the_title($row['post_id']);
            $row['edit_link'] = get_edit_post_link($row['post_id'], 'raw');

            return $row;
        }, $rows);
    }

    /**
     * Distinct models seen in the table, for a filter dropdown.
     *
     * @return array
     */
    public static function known_models()
    {
        global $wpdb;

        if (!UsageRecorder::table_exists()) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $models = $wpdb->get_col('SELECT DISTINCT model FROM ' . self::table() . " WHERE model <> '' ORDER BY model ASC");

        return is_array($models) ? $models : [];
    }

    /**
     * Format a dollar amount for display.
     *
     * Individual calls cost fractions of a cent, so a two-decimal format would show
     * most of this table as $0.00; totals in the tens of dollars do not need ten
     * decimals either. The scale follows the number.
     *
     * @param string|float|null $amount Amount in USD.
     * @return string
     */
    public static function format_usd($amount)
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;

        if ($value === 0.0) {
            return '$0';
        }

        if ($value < 0.01) {
            return '$' . rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if ($value < 1) {
            return '$' . number_format($value, 4);
        }

        return '$' . number_format($value, 2);
    }

    /**
     * Format a token count compactly.
     *
     * @param int $tokens Token count.
     * @return string
     */
    public static function format_tokens($tokens)
    {
        $tokens = (int) $tokens;

        if ($tokens >= 1000000) {
            return number_format($tokens / 1000000, 1) . 'M';
        }

        if ($tokens >= 1000) {
            return number_format($tokens / 1000, 1) . 'k';
        }

        return (string) $tokens;
    }

    /**
     * @return string Fully qualified table name.
     */
    private static function table()
    {
        global $wpdb;

        return $wpdb->prefix . UsageRecorder::TABLE;
    }

    /**
     * Build the WHERE clause and its bound parameters.
     *
     * @param array $args {
     *     @type int    $days     Only rows from the last N days.
     *     @type string $model    Restrict to one model.
     *     @type string $activity Restrict to one activity.
     *     @type int    $post_id  Restrict to one original post.
     * }
     * @return array [string $where, array $params]
     */
    private static function build_where($args)
    {
        $clauses = [];
        $params = [];

        $days = isset($args['days']) ? (int) $args['days'] : 0;
        if ($days > 0) {
            $clauses[] = 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[] = $days;
        }

        if (!empty($args['model'])) {
            $clauses[] = 'model = %s';
            $params[] = (string) $args['model'];
        }

        if (!empty($args['activity'])) {
            $clauses[] = 'activity = %s';
            $params[] = (string) $args['activity'];
        }

        // The language a request was for, not the one a given hop produced: filtering
        // a relay by target_language would hide the hop that fed it.
        if (!empty($args['language'])) {
            $clauses[] = 'final_language = %s';
            $params[] = (string) $args['language'];
        }

        if (!empty($args['relay_only'])) {
            $clauses[] = 'final_language IS NOT NULL AND target_language <> final_language';
        }

        if (!empty($args['post_id'])) {
            $clauses[] = '(post_id = %d OR source_post_id = %d)';
            $params[] = (int) $args['post_id'];
            $params[] = (int) $args['post_id'];
        }

        $where = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }

    /**
     * Apply $wpdb->prepare() only when there is something to bind.
     *
     * prepare() with no placeholders is an error in newer WordPress versions, and a
     * filtered query often has none.
     *
     * @param string $sql    Query.
     * @param array  $params Bound values.
     * @return string
     */
    private static function prepare($sql, $params)
    {
        global $wpdb;

        if (empty($params)) {
            return $sql;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->prepare($sql, $params);
    }

    /**
     * Cast a result row and attach display strings.
     *
     * @param array $row Raw row.
     * @return array
     */
    private static function normalize_row($row)
    {
        $row = is_array($row) ? $row : [];

        foreach (['calls', 'unpriced_calls', 'relay_calls', 'tokens_input', 'tokens_output', 'tokens_cached_read', 'tokens_reasoning', 'models'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }

        // SUM() over rows that are all NULL returns NULL, which must stay distinct
        // from a real zero: it means nothing here could be priced.
        $row['total_usd'] = isset($row['total_usd']) && $row['total_usd'] !== null
            ? (string) $row['total_usd']
            : null;
        $row['cost_display'] = self::format_usd($row['total_usd']);

        $output = (int) ($row['tokens_output'] ?? 0);
        $reasoning = (int) ($row['tokens_reasoning'] ?? 0);

        // Share of output tokens spent thinking. Worth surfacing: on high reasoning
        // effort it dominates the bill, and it is the one knob that changes it.
        $row['reasoning_share'] = $output > 0 ? min(100, (int) round($reasoning / $output * 100)) : 0;

        // Only present on queries that ask for it. An intermediate hop is a call whose
        // target is not the language the request was for.
        if (array_key_exists('relay_usd', $row)) {
            $row['relay_usd'] = $row['relay_usd'] !== null ? (string) $row['relay_usd'] : null;
            $row['relay_display'] = self::format_usd($row['relay_usd']);
            $row['relay_share'] = self::share_of($row['relay_usd'], $row['total_usd']);
        }

        if (array_key_exists('label', $row) && ($row['label'] === null || $row['label'] === '')) {
            // Not '(none)': a blank model means the provider named none, and a reader
            // needs to tell that apart from a dimension that genuinely does not apply.
            $row['label'] = __('not reported', 'polytrans');
        }

        return $row;
    }

    /**
     * One amount as a whole-percent share of another.
     *
     * @param string|null $part  Part, as a decimal string.
     * @param string|null $whole Whole, as a decimal string.
     * @return int 0 when either is missing or the whole is zero.
     */
    private static function share_of($part, $whole)
    {
        $part = (float) ($part ?? 0);
        $whole = (float) ($whole ?? 0);

        if ($whole <= 0) {
            return 0;
        }

        return min(100, (int) round($part / $whole * 100));
    }

    /**
     * @return array Zeroed totals, for an empty or missing table.
     */
    private static function empty_totals()
    {
        return [
            'calls' => 0,
            'total_usd' => null,
            'cost_display' => self::format_usd(null),
            'unpriced_calls' => 0,
            'relay_calls' => 0,
            'relay_usd' => null,
            'relay_display' => self::format_usd(null),
            'relay_share' => 0,
            'tokens_input' => 0,
            'tokens_output' => 0,
            'tokens_cached_read' => 0,
            'tokens_reasoning' => 0,
            'reasoning_share' => 0,
            'models' => 0,
            'first_call' => null,
            'last_call' => null,
        ];
    }
}

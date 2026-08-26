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
     * The SQL term that snaps a row to its time bucket, per resolution.
     *
     * Written without DATE_FORMAT on purpose. Its patterns are full of '%', which
     * $wpdb->prepare() reads as placeholders; the resulting query is either mangled or
     * rejected outright, and only on the code paths that bind a parameter. The date
     * arithmetic below says the same thing and survives preparation.
     *
     * Each term must produce exactly what UsageWindow writes for that resolution, or a
     * filled series matches nothing and every bucket reads as empty.
     *
     * @var array
     */
    private static $bucket_terms = [
        UsageWindow::BUCKET_HOUR => 'DATE_ADD(DATE(created_at), INTERVAL HOUR(created_at) HOUR)',
        UsageWindow::BUCKET_DAY => 'DATE(created_at)',
        // WEEKDAY() is Monday-based regardless of the server's week mode, which
        // YEARWEEK() is not.
        UsageWindow::BUCKET_WEEK => 'DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)',
        UsageWindow::BUCKET_MONTH => 'DATE_SUB(DATE(created_at), INTERVAL DAYOFMONTH(created_at) - 1 DAY)',
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
     * A gap-free cost series over the window, oldest first.
     *
     * The window supplies both the range and the resolution, so a caller cannot ask
     * for one that does not match the other. Buckets the database has no rows for are
     * filled in rather than skipped: the query only returns buckets containing calls,
     * and a chart drawn from those alone squeezes a quiet night out of existence and
     * misstates the shape of everything either side of it.
     *
     * @param UsageWindow $window Range and resolution.
     * @param array       $args   Further filters, see build_where(). Any range in here
     *                            is overridden by the window.
     * @return array
     */
    public static function series(UsageWindow $window, $args = [])
    {
        global $wpdb;

        $term = self::$bucket_terms[$window->bucket()] ?? null;

        if ($term === null || !UsageRecorder::table_exists()) {
            return [];
        }

        [$where, $params] = self::build_where(array_merge($args, $window->args()));

        $sql = "SELECT
                {$term} AS bucket,
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
            ORDER BY {$term} ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(self::prepare($sql, $params), ARRAY_A);

        return self::fill_series($window, is_array($rows) ? $rows : []);
    }

    /**
     * Place the rows a query returned onto every bucket of the window.
     *
     * @param UsageWindow $window Range and resolution.
     * @param array       $rows   Raw rows, keyed by bucket in the 'bucket' column.
     * @return array
     */
    private static function fill_series(UsageWindow $window, array $rows)
    {
        $found = [];

        foreach ($rows as $row) {
            $found[(string) ($row['bucket'] ?? '')] = $row;
        }

        $series = [];

        foreach ($window->bucket_starts() as $key) {
            // An absent bucket cost zero, not "unknown". NULL is reserved for a call
            // that ran and could not be priced, and the two must not read alike.
            $row = $found[$key] ?? [
                'bucket' => $key,
                'calls' => 0,
                'total_usd' => '0',
                'unpriced_calls' => 0,
                'relay_calls' => 0,
                'tokens_input' => 0,
                'tokens_output' => 0,
                'tokens_cached_read' => 0,
                'tokens_reasoning' => 0,
            ];

            $row = self::normalize_row($row);
            $row['bucket'] = $key;
            $row['label'] = $window->format_label($key);
            $row['is_empty'] = ((int) $row['calls']) === 0;
            $row['is_partial'] = $window->is_partial_bucket($key);

            $series[] = $row;
        }

        return $series;
    }

    /**
     * The oldest call on record, for a report that means to cover everything.
     *
     * MIN() over an indexed column, so this costs one index lookup rather than a scan.
     *
     * @return \DateTimeImmutable|null
     */
    public static function earliest_call()
    {
        global $wpdb;

        if (!UsageRecorder::table_exists()) {
            return null;
        }

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $earliest = $wpdb->get_var('SELECT MIN(created_at) FROM ' . self::table());

        if (!$earliest) {
            return null;
        }

        // Stored as site-local wall clock, so it is read back as one.
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) $earliest,
            function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone(date_default_timezone_get())
        );

        return $parsed instanceof \DateTimeImmutable ? $parsed : null;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
     * Report one complete translation process per row.
     *
     * The run is the unit the dashboard uses for an article/target pair. Usage
     * rows are joined without filtering them out, so a model/activity filter only
     * selects runs that contain a matching call; the displayed totals still cover
     * the whole process, including relay hops and post-translation workflows.
     *
     * @param array $args  Run/report filters.
     * @param int   $limit Maximum rows.
     * @return array
     */
    public static function translation_runs($args = [], $limit = 20)
    {
        global $wpdb;

        if (!UsageRecorder::table_exists() || !TranslationRunManager::table_exists()) {
            return [];
        }

        [$where, $params] = self::build_run_where($args);
        $runs_table = TranslationRunManager::table_name();
        $usage_table = self::table();
        $limit = max(1, min(200, (int) $limit));

        $sql = "SELECT
                r.run_id,
                r.created_at,
                r.completed_at,
                r.status,
                r.source_post_id,
                r.translated_post_id,
                r.source_language,
                r.target_language,
                r.translation_path,
                r.source_characters,
                r.source_words,
                COUNT(u.id) AS calls,
                SUM(CASE WHEN u.activity = 'translation' THEN 1 ELSE 0 END) AS translation_calls,
                SUM(CASE WHEN u.activity = 'workflow_step' THEN 1 ELSE 0 END) AS workflow_calls,
                SUM(CASE WHEN u.id IS NOT NULL AND u.cost_usd IS NULL THEN 1 ELSE 0 END) AS unpriced_calls,
                SUM(u.cost_usd) AS total_usd,
                SUM(CASE WHEN u.activity = 'translation' THEN u.cost_usd ELSE 0 END) AS translation_usd,
                SUM(CASE WHEN u.activity = 'workflow_step' THEN u.cost_usd ELSE 0 END) AS workflow_usd,
                SUM(u.tokens_input) AS tokens_input,
                SUM(u.tokens_output) AS tokens_output,
                SUM(u.tokens_cached_read) AS tokens_cached_read,
                SUM(u.tokens_reasoning) AS tokens_reasoning
            FROM {$runs_table} r
            LEFT JOIN {$usage_table} u ON u.run_id = r.run_id
            {$where}
            GROUP BY r.id, r.run_id, r.created_at, r.completed_at, r.status,
                r.source_post_id, r.translated_post_id, r.source_language,
                r.target_language, r.translation_path, r.source_characters, r.source_words
            ORDER BY COALESCE(SUM(u.cost_usd), 0) DESC, r.created_at DESC
            LIMIT {$limit}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(self::prepare($sql, $params), ARRAY_A);

        return array_map([self::class, 'normalize_run'], is_array($rows) ? $rows : []);
    }

    /**
     * Aggregate the complete translation-process population for the dashboard.
     *
     * Usage is pre-aggregated by run before it is joined to the parent table. This
     * matters for source characters and words: joining raw usage rows would count
     * the same article once per AI call and inflate the denominators.
     *
     * @param array $args Run/report filters.
     * @return array
     */
    public static function translation_run_totals($args = [])
    {
        global $wpdb;

        if (!UsageRecorder::table_exists() || !TranslationRunManager::table_exists()) {
            return self::normalize_run_totals([]);
        }

        [$where, $params] = self::build_run_where($args);
        $runs_table = TranslationRunManager::table_name();
        $usage_table = self::table();

        $sql = "SELECT
                COUNT(*) AS runs,
                SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_runs,
                SUM(CASE WHEN r.status = 'running' THEN 1 ELSE 0 END) AS running_runs,
                SUM(CASE WHEN r.status = 'failed' THEN 1 ELSE 0 END) AS failed_runs,
                SUM(r.source_characters) AS source_characters,
                SUM(r.source_words) AS source_words,
                SUM(COALESCE(u.calls, 0)) AS calls,
                SUM(COALESCE(u.translation_calls, 0)) AS translation_calls,
                SUM(COALESCE(u.workflow_calls, 0)) AS workflow_calls,
                SUM(COALESCE(u.unpriced_calls, 0)) AS unpriced_calls,
                SUM(u.total_usd) AS total_usd,
                SUM(u.translation_usd) AS translation_usd,
                SUM(u.workflow_usd) AS workflow_usd,
                SUM(COALESCE(u.tokens_input, 0)) AS tokens_input,
                SUM(COALESCE(u.tokens_output, 0)) AS tokens_output,
                SUM(COALESCE(u.tokens_cached_read, 0)) AS tokens_cached_read,
                SUM(COALESCE(u.tokens_reasoning, 0)) AS tokens_reasoning
            FROM {$runs_table} r
            LEFT JOIN (
                SELECT
                    run_id,
                    COUNT(*) AS calls,
                    SUM(activity = 'translation') AS translation_calls,
                    SUM(activity = 'workflow_step') AS workflow_calls,
                    SUM(cost_usd IS NULL) AS unpriced_calls,
                    SUM(cost_usd) AS total_usd,
                    SUM(CASE WHEN activity = 'translation' THEN cost_usd ELSE 0 END) AS translation_usd,
                    SUM(CASE WHEN activity = 'workflow_step' THEN cost_usd ELSE 0 END) AS workflow_usd,
                    SUM(tokens_input) AS tokens_input,
                    SUM(tokens_output) AS tokens_output,
                    SUM(tokens_cached_read) AS tokens_cached_read,
                    SUM(tokens_reasoning) AS tokens_reasoning
                FROM {$usage_table}
                GROUP BY run_id
            ) u ON u.run_id = r.run_id
            {$where}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(self::prepare($sql, $params), ARRAY_A);

        return self::normalize_run_totals(is_array($row) ? $row : []);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
     *     @type string $from     Only rows at or after this instant, site-local.
     *     @type string $to       Only rows strictly before this instant, site-local.
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

        // Bound in PHP rather than with NOW(). Rows are stamped with
        // current_time('mysql'), which is the site's timezone; NOW() is the database
        // server's, commonly UTC. The two differ by the site's offset, which a
        // thirty-day window hides and an hourly one is entirely made of.
        if (!empty($args['from'])) {
            $clauses[] = 'created_at >= %s';
            $params[] = (string) $args['from'];
        }

        // Half-open: a row on the boundary belongs to one bucket and one period, so
        // two adjacent windows neither double-count it nor drop it.
        if (!empty($args['to'])) {
            $clauses[] = 'created_at < %s';
            $params[] = (string) $args['to'];
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
     * Build filters for the run parent while preserving full-run aggregates.
     *
     * @param array $args Report filters.
     * @return array [string $where, array $params]
     */
    private static function build_run_where($args)
    {
        $clauses = [];
        $params = [];
        $usage_table = self::table();

        // A run is placed in the window by when it started, not by when each of its
        // calls ran: the unit here is the process, and one that begins at 13:59 and
        // finishes at 14:20 belongs to the hour that commissioned it.
        if (!empty($args['from'])) {
            $clauses[] = 'r.created_at >= %s';
            $params[] = (string) $args['from'];
        }

        if (!empty($args['to'])) {
            $clauses[] = 'r.created_at < %s';
            $params[] = (string) $args['to'];
        }

        if (!empty($args['language'])) {
            $clauses[] = 'r.target_language = %s';
            $params[] = (string) $args['language'];
        }

        if (!empty($args['post_id'])) {
            $clauses[] = '(r.source_post_id = %d OR r.translated_post_id = %d)';
            $params[] = (int) $args['post_id'];
            $params[] = (int) $args['post_id'];
        }

        // These filters select a run, but deliberately do not constrain the LEFT
        // JOIN. The reader asked for the full process cost, not a partial activity
        // total that happens to match the filter.
        if (!empty($args['model'])) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$usage_table} uf_model WHERE uf_model.run_id = r.run_id AND uf_model.model = %s)";
            $params[] = (string) $args['model'];
        }

        if (!empty($args['activity'])) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$usage_table} uf_activity WHERE uf_activity.run_id = r.run_id AND uf_activity.activity = %s)";
            $params[] = (string) $args['activity'];
        }

        if (!empty($args['relay_only'])) {
            $clauses[] = "EXISTS (SELECT 1 FROM {$usage_table} uf_relay WHERE uf_relay.run_id = r.run_id AND uf_relay.final_language IS NOT NULL AND uf_relay.target_language <> uf_relay.final_language)";
        }

        return [empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
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
            $row['label'] = __('not reported', 'treetank-trans');
        }

        return $row;
    }

    /**
     * Normalize one complete run and derive comparable rates.
     *
     * @param array $row Raw run row.
     * @return array
     */
    private static function normalize_run($row)
    {
        $row = is_array($row) ? $row : [];

        foreach (
            [
                'source_post_id',
                'translated_post_id',
                'source_characters',
                'source_words',
                'calls',
                'translation_calls',
                'workflow_calls',
                'unpriced_calls',
                'tokens_input',
                'tokens_output',
                'tokens_cached_read',
                'tokens_reasoning',
            ] as $key
        ) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['total_usd', 'translation_usd', 'workflow_usd'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (string) $row[$key] : null;
            $row[$key . '_display'] = self::format_usd($row[$key]);
        }

        // Names used by the run dashboard make the scope of each amount explicit.
        $row['total_cost_display'] = $row['total_usd_display'];
        $row['translation_cost_display'] = $row['translation_usd_display'];
        $row['workflow_cost_display'] = $row['workflow_usd_display'];

        $output = (int) ($row['tokens_output'] ?? 0);
        $reasoning = (int) ($row['tokens_reasoning'] ?? 0);
        $row['reasoning_share'] = $output > 0 ? min(100, (int) round($reasoning / $output * 100)) : 0;
        $row['title'] = !empty($row['source_post_id']) ? get_the_title((int) $row['source_post_id']) : '';
        $row['edit_link'] = !empty($row['source_post_id'])
            ? get_edit_post_link((int) $row['source_post_id'], 'raw')
            : '';

        foreach (
            [
                'total_usd' => 'cost_per_1000',
                'translation_usd' => 'translation_cost_per_1000',
                'workflow_usd' => 'workflow_cost_per_1000',
            ] as $cost_key => $rate_key
        ) {
            $row[$rate_key . '_characters'] = self::rate_per_1000(
                $row[$cost_key],
                $row['source_characters'] ?? 0
            );
            $row[$rate_key . '_characters_display'] = self::format_usd($row[$rate_key . '_characters']);
            $row[$rate_key . '_words'] = self::rate_per_1000(
                $row[$cost_key],
                $row['source_words'] ?? 0
            );
            $row[$rate_key . '_words_display'] = self::format_usd($row[$rate_key . '_words']);
        }

        return $row;
    }

    /**
     * Normalize the aggregate used by the process summary cards.
     *
     * @param array $row Raw aggregate row.
     * @return array
     */
    private static function normalize_run_totals($row)
    {
        $row = is_array($row) ? $row : [];

        foreach (
            [
                'runs',
                'completed_runs',
                'running_runs',
                'failed_runs',
                'source_characters',
                'source_words',
                'calls',
                'translation_calls',
                'workflow_calls',
                'unpriced_calls',
                'tokens_input',
                'tokens_output',
                'tokens_cached_read',
                'tokens_reasoning',
            ] as $key
        ) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }

        foreach (['total_usd', 'translation_usd', 'workflow_usd'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (string) $row[$key] : null;
            $row[$key . '_display'] = self::format_usd($row[$key]);
        }

        $row['total_cost_display'] = $row['total_usd_display'];
        $row['translation_cost_display'] = $row['translation_usd_display'];
        $row['workflow_cost_display'] = $row['workflow_usd_display'];
        $row['cost_per_1000_characters'] = self::rate_per_1000($row['total_usd'], $row['source_characters']);
        $row['cost_per_1000_words'] = self::rate_per_1000($row['total_usd'], $row['source_words']);
        $row['cost_per_1000_characters_display'] = self::format_usd($row['cost_per_1000_characters']);
        $row['cost_per_1000_words_display'] = self::format_usd($row['cost_per_1000_words']);
        $row['workflow_share'] = self::share_of($row['workflow_usd'], $row['total_usd']);

        return $row;
    }

    /**
     * @param string|null $cost Cost amount.
     * @param int $units Number of source units.
     * @return string|null Cost per 1,000 units.
     */
    private static function rate_per_1000($cost, $units)
    {
        if ($cost === null || $cost === '' || (int) $units <= 0) {
            return null;
        }

        return number_format(((float) $cost * 1000) / (int) $units, 10, '.', '');
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

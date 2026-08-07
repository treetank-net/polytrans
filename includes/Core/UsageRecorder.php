<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Usage Recorder
 *
 * Records token usage and estimated cost for every AI call the plugin makes.
 *
 * Written in three places on purpose:
 *  - a row in {prefix}polytrans_usage - the source of truth, indexed so a
 *    dashboard can group by model or month in one query;
 *  - post meta on the translated post - what producing that post cost;
 *  - post meta on the original, broken down per target language - so "what did
 *    translating this article into the other markets cost" is one meta read,
 *    with no query against the table.
 *
 * Costs are frozen at write time. Prices change, and recomputing on read would
 * silently rewrite last month's report.
 */
class UsageRecorder
{
    const TABLE = 'polytrans_usage';

    const META_SUMMARY = '_polytrans_usage_summary';
    const META_COST = '_polytrans_cost_usd';

    const SUMMARY_VERSION = 1;

    /**
     * Raise when the table gains a column, so an existing install migrates without
     * being reactivated. An insert naming a column the table lacks is dropped by
     * wpdb with nothing but a database error to show for it.
     */
    const SCHEMA_VERSION = 3;

    const OPTION_SCHEMA = 'polytrans_usage_schema_version';

    /**
     * Whether the table has been verified during this request.
     *
     * @var bool|null
     */
    private static $table_ready = null;

    /**
     * Ensure the table exists, creating it if needed.
     *
     * Called on activation, and lazily before the first write so that updating
     * the plugin without reactivating it does not silently drop records.
     *
     * @return bool
     */
    public static function initialize()
    {
        if (self::$table_ready === true) {
            return true;
        }

        $migrated = (int) get_option(self::OPTION_SCHEMA, 0) >= self::SCHEMA_VERSION;

        if (self::table_exists() && $migrated) {
            self::$table_ready = true;

            return true;
        }

        // dbDelta() adds the columns an older table is missing, so the same call
        // serves creation and migration.
        self::$table_ready = self::create_table();

        if (self::$table_ready) {
            self::backfill_final_language();
            update_option(self::OPTION_SCHEMA, self::SCHEMA_VERSION);
        }

        return self::$table_ready;
    }

    /**
     * Create the usage table.
     *
     * @return bool
     */
    public static function create_table()
    {
        global $wpdb;

        // Guarded rather than required outright: the function is all this needs, and
        // asking for it only when it is absent keeps the call reachable from a context
        // that has no wp-admin loaded.
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        // cost_usd is DECIMAL rather than float: per-token prices run to 1e-8 and
        // float error would accumulate once these rows are summed. NULL means
        // "not priced", which must stay distinguishable from a genuine zero.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) DEFAULT NULL,
            created_at datetime NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            source_post_id bigint(20) unsigned DEFAULT NULL,
            source_language varchar(20) DEFAULT NULL,
            target_language varchar(20) DEFAULT NULL,
            final_language varchar(20) DEFAULT NULL,
            translation_path varchar(191) DEFAULT NULL,
            path_step tinyint(3) unsigned DEFAULT NULL,
            activity varchar(40) NOT NULL DEFAULT 'unknown',
            step varchar(191) DEFAULT NULL,
            workflow_id varchar(191) DEFAULT NULL,
            provider varchar(40) NOT NULL DEFAULT '',
            model varchar(191) NOT NULL DEFAULT '',
            surface varchar(20) DEFAULT NULL,
            effort varchar(20) DEFAULT NULL,
            tokens_input bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_input_uncached bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_output bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_cached_read bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_cached_write bigint(20) unsigned NOT NULL DEFAULT 0,
            tokens_reasoning bigint(20) unsigned NOT NULL DEFAULT 0,
            cost_usd decimal(18,10) DEFAULT NULL,
            pricing_source varchar(20) NOT NULL DEFAULT 'unknown',
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY post_id (post_id),
            KEY source_post_id (source_post_id),
            KEY model (model),
            KEY created_at (created_at),
            KEY activity (activity),
            KEY final_language (final_language)
        ) {$charset_collate};";

        dbDelta($sql);

        return self::table_exists();
    }

    /**
     * Give rows written before final_language existed the only value that can be
     * inferred for them.
     *
     * Without this, every historical row answers "which language was this request
     * for" with NULL, and a per-market report silently omits the whole history. The
     * inference is exact for a direct translation and is the best available for a
     * relayed one, whose path was never recorded.
     *
     * @return void
     */
    private static function backfill_final_language()
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("UPDATE {$table} SET final_language = target_language WHERE final_language IS NULL AND target_language IS NOT NULL");
    }

    /**
     * @return bool Whether the usage table is present.
     */
    public static function table_exists()
    {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Record one AI call.
     *
     * @param array $event {
     *     @type string $provider        Provider ID.
     *     @type string $model           Model ID.
     *     @type mixed  $usage           Raw usage payload from the provider.
     *     @type string $activity        What the call was for, e.g. 'translation', 'workflow_step'.
     *     @type string $step            Optional step ID or label.
     *     @type int    $post_id         Post the call produced or acted on.
     *     @type int    $source_post_id  Original post, when this is a translation.
     *     @type string $source_language Language this call read from.
     *     @type string $target_language Language this call produced.
     *     @type string $final_language  Language the whole request was for. Differs from
     *                                   target_language on an intermediate hop of a
     *                                   relay; defaults to target_language.
     *     @type string $translation_path Full path this call belongs to, e.g. 'pl>en>de'.
     *     @type int    $path_step       1-based position of this call within that path.
     *     @type string $surface         API surface used.
     *     @type string $effort          Reasoning effort used.
     *     @type string $run_id          TranslationRun this call belongs to.
     * }
     * @return array The priced record, as stored.
     */
    public static function record(array $event)
    {
        $provider = (string) ($event['provider'] ?? '');
        $model = (string) ($event['model'] ?? '');

        $priced = ModelPricing::estimate_cost($provider, $model, $event['usage'] ?? []);
        $tokens = $priced['tokens'];

        $record = [
            'created_at' => current_time('mysql'),
            'run_id' => TranslationRunManager::normalize_id($event['run_id'] ?? null),
            'post_id' => !empty($event['post_id']) ? (int) $event['post_id'] : null,
            'source_post_id' => !empty($event['source_post_id']) ? (int) $event['source_post_id'] : null,
            'source_language' => !empty($event['source_language']) ? (string) $event['source_language'] : null,
            'target_language' => !empty($event['target_language']) ? (string) $event['target_language'] : null,
            // Defaulted rather than left null, so a report grouping by the language a
            // request was for covers every row, not only the relayed ones.
            'final_language' => !empty($event['final_language'])
                ? (string) $event['final_language']
                : (!empty($event['target_language']) ? (string) $event['target_language'] : null),
            'translation_path' => !empty($event['translation_path']) ? (string) $event['translation_path'] : null,
            'path_step' => !empty($event['path_step']) ? (int) $event['path_step'] : null,
            'activity' => (string) ($event['activity'] ?? 'unknown'),
            'step' => !empty($event['step']) ? (string) $event['step'] : null,
            'workflow_id' => !empty($event['workflow_id']) ? (string) $event['workflow_id'] : null,
            'provider' => $provider,
            'model' => $model,
            'surface' => !empty($event['surface']) ? (string) $event['surface'] : null,
            'effort' => isset($event['effort']) && $event['effort'] !== '' ? (string) $event['effort'] : null,
            'tokens_input' => (int) $tokens['input'],
            'tokens_input_uncached' => (int) $tokens['input_uncached'],
            'tokens_output' => (int) $tokens['output'],
            'tokens_cached_read' => (int) $tokens['cached_read'],
            'tokens_cached_write' => (int) $tokens['cached_write'],
            'tokens_reasoning' => (int) $tokens['reasoning'],
            'cost_usd' => $priced['cost_usd'],
            'pricing_source' => $priced['source'],
        ];

        // A call with no tokens at all carries no information worth a row.
        if ($record['tokens_input'] === 0 && $record['tokens_output'] === 0) {
            return $record;
        }

        self::insert($record);

        // Test runs cost real money, so the row is always written, but they must not
        // move the totals shown on a live post.
        if (empty($event['skip_post_meta'])) {
            self::update_post_meta_summaries($record);
        }

        self::log($record);

        /**
         * Fires after a usage record has been stored.
         *
         * @param array $record The stored record.
         */
        do_action('polytrans_usage_recorded', $record);

        return $record;
    }

    /**
     * Insert the row, if the table is available.
     *
     * @param array $record Record.
     * @return void
     */
    private static function insert(array $record)
    {
        global $wpdb;

        if (!self::initialize()) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($wpdb->prefix . self::TABLE, $record);
    }

    /**
     * Update the denormalised summaries on the affected posts.
     *
     * @param array $record Record.
     * @return void
     */
    private static function update_post_meta_summaries(array $record)
    {
        if (!empty($record['post_id'])) {
            self::merge_summary((int) $record['post_id'], $record, null);
        }

        // The original is the hub: it holds the status of every target language, so
        // it also holds the cost of every target language.
        //
        // Bucketed by final_language, not by the language the call produced: the
        // pl→en hop of a pl→en→de relay was spent on delivering German, and charging
        // it to English would credit a market nobody ordered while understating the
        // one that was. The per-hop truth stays in the table row.
        $source_id = (int) ($record['source_post_id'] ?? 0);
        if ($source_id > 0 && $source_id !== (int) ($record['post_id'] ?? 0)) {
            self::merge_summary($source_id, $record, self::market_language($record));
        }
    }

    /**
     * The language a record should be charged to on the original.
     *
     * Falls back to target_language for rows written before final_language existed,
     * so a rebuild of an old post keeps working.
     *
     * @param array $record Record.
     * @return string|null
     */
    private static function market_language(array $record)
    {
        $final = $record['final_language'] ?? null;

        if (is_string($final) && $final !== '') {
            return $final;
        }

        $target = $record['target_language'] ?? null;

        return (is_string($target) && $target !== '') ? $target : null;
    }

    /**
     * Merge one record into a post's stored summary.
     *
     * @param int         $post_id  Post to update.
     * @param array       $record   Record.
     * @param string|null $language Bucket the figures under this language, when set.
     * @return void
     */
    private static function merge_summary($post_id, array $record, $language)
    {
        // The translation endpoint can be called by another site, whose post IDs mean
        // nothing here. The ID is still worth keeping in the table row, but writing
        // meta against it would attach the cost to an unrelated local post.
        if (!get_post($post_id)) {
            return;
        }

        $summary = get_post_meta($post_id, self::META_SUMMARY, true);

        if (!is_array($summary) || ($summary['version'] ?? null) !== self::SUMMARY_VERSION) {
            $summary = [
                'version' => self::SUMMARY_VERSION,
                'total_usd' => '0',
                'unpriced_calls' => 0,
                'by_model' => [],
                'by_activity' => [],
                'by_language' => [],
            ];
        }

        $summary = self::add_to_bucket($summary, $record);

        $model_key = $record['model'] !== '' ? $record['model'] : 'unknown';
        $summary['by_model'][$model_key] = self::add_to_bucket(
            $summary['by_model'][$model_key] ?? [],
            $record
        );

        $activity_key = $record['activity'];
        $summary['by_activity'][$activity_key] = self::add_to_bucket(
            $summary['by_activity'][$activity_key] ?? [],
            $record
        );

        if ($language !== null && $language !== '') {
            $bucket = $summary['by_language'][$language] ?? [];
            $bucket = self::add_to_bucket($bucket, $record);
            $bucket['by_model'][$model_key] = self::add_to_bucket(
                $bucket['by_model'][$model_key] ?? [],
                $record
            );
            $summary['by_language'][$language] = $bucket;
        }

        $summary['updated_at'] = $record['created_at'];

        update_post_meta($post_id, self::META_SUMMARY, $summary);

        // A single numeric key so costs can be summed and sorted in SQL without
        // parsing the JSON above.
        update_post_meta($post_id, self::META_COST, $summary['total_usd']);
    }

    /**
     * Add a record's figures into one accumulator bucket.
     *
     * @param array $bucket Existing bucket.
     * @param array $record Record.
     * @return array
     */
    private static function add_to_bucket($bucket, array $record)
    {
        $bucket = is_array($bucket) ? $bucket : [];

        foreach (['input', 'output', 'cached_read', 'cached_write', 'reasoning'] as $kind) {
            $key = 'tokens_' . $kind;
            $bucket[$key] = (int) ($bucket[$key] ?? 0) + (int) $record[$key];
        }

        $bucket['calls'] = (int) ($bucket['calls'] ?? 0) + 1;

        if ($record['cost_usd'] === null) {
            // Tracked separately so a total is never quietly short.
            $bucket['unpriced_calls'] = (int) ($bucket['unpriced_calls'] ?? 0) + 1;
        } else {
            $bucket['total_usd'] = self::add_money($bucket['total_usd'] ?? '0', $record['cost_usd']);
        }

        $bucket['total_usd'] = $bucket['total_usd'] ?? '0';
        $bucket['unpriced_calls'] = (int) ($bucket['unpriced_calls'] ?? 0);

        return $bucket;
    }

    /**
     * Add two decimal strings, keeping ten decimal places.
     *
     * Uses bcmath when available, since repeated float addition of 1e-8 magnitudes
     * drifts; falls back to float arithmetic, which is still far below the noise
     * of the price estimate itself.
     *
     * @param string $a First value.
     * @param string $b Second value.
     * @return string
     */
    private static function add_money($a, $b)
    {
        if (function_exists('bcadd')) {
            return bcadd((string) $a, (string) $b, 10);
        }

        return number_format((float) $a + (float) $b, 10, '.', '');
    }

    /**
     * Write the record to the plugin log, where the activity context already lives.
     *
     * @param array $record Record.
     * @return void
     */
    private static function log(array $record)
    {
        $cost = $record['cost_usd'] === null ? 'unpriced' : $record['cost_usd'] . ' USD';

        LogsManager::log(
            sprintf(
                'AI usage: %s %s (%s) - in %d (cached %d), out %d (reasoning %d), cost %s',
                $record['provider'],
                $record['model'],
                $record['activity'],
                $record['tokens_input'],
                $record['tokens_cached_read'],
                $record['tokens_output'],
                $record['tokens_reasoning'],
                $cost
            ),
            'info',
            array_merge($record, ['source' => 'usage_recorder'])
        );
    }

    /**
     * Rebuild a post's summary meta from the table.
     *
     * The table is the source of truth; the meta is a denormalised copy that can be
     * discarded and recomputed. Use this when the meta is known to be wrong - it was
     * copied from another post, say - rather than deleting it, which would throw away
     * figures that are genuinely this post's.
     *
     * Replays each row through the same accumulation the live path uses, so a rebuilt
     * summary is identical to one that grew normally.
     *
     * @param int $post_id Post to rebuild.
     * @return array|null The rebuilt summary, or null if the post has no rows.
     */
    public static function rebuild_post_summary($post_id)
    {
        global $wpdb;

        $post_id = (int) $post_id;

        if ($post_id <= 0 || !self::table_exists()) {
            return null;
        }

        delete_post_meta($post_id, self::META_SUMMARY);
        delete_post_meta($post_id, self::META_COST);

        $table = $wpdb->prefix . self::TABLE;

        // Rows produced for this post, then rows where it was the original - the same
        // two contributions record() makes, in insertion order.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $own = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d ORDER BY id ASC", $post_id), ARRAY_A);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $as_source = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE source_post_id = %d AND (post_id IS NULL OR post_id <> %d) ORDER BY id ASC",
            $post_id,
            $post_id
        ), ARRAY_A);

        $own = is_array($own) ? $own : [];
        $as_source = is_array($as_source) ? $as_source : [];

        if (empty($own) && empty($as_source)) {
            return null;
        }

        foreach ($own as $row) {
            self::merge_summary($post_id, self::normalize_stored_row($row), null);
        }

        foreach ($as_source as $row) {
            $row = self::normalize_stored_row($row);
            self::merge_summary($post_id, $row, self::market_language($row));
        }

        return self::get_post_summary($post_id);
    }

    /**
     * Cast a row read back from the database into the shape merge_summary() expects.
     *
     * @param array $row Stored row.
     * @return array
     */
    private static function normalize_stored_row(array $row)
    {
        foreach (['tokens_input', 'tokens_input_uncached', 'tokens_output', 'tokens_cached_read', 'tokens_cached_write', 'tokens_reasoning'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }

        $row['activity'] = (string) ($row['activity'] ?? 'unknown');
        $row['model'] = (string) ($row['model'] ?? '');
        // NULL must survive the round trip: it means unpriced, not free.
        $row['cost_usd'] = isset($row['cost_usd']) && $row['cost_usd'] !== null ? (string) $row['cost_usd'] : null;
        $row['created_at'] = (string) ($row['created_at'] ?? '');

        return $row;
    }

    /**
     * Read a post's stored summary.
     *
     * @param int $post_id Post ID.
     * @return array|null
     */
    public static function get_post_summary($post_id)
    {
        $summary = get_post_meta((int) $post_id, self::META_SUMMARY, true);

        return is_array($summary) ? $summary : null;
    }
}

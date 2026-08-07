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

        self::$table_ready = self::table_exists() ? true : self::create_table();

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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        // cost_usd is DECIMAL rather than float: per-token prices run to 1e-8 and
        // float error would accumulate once these rows are summed. NULL means
        // "not priced", which must stay distinguishable from a genuine zero.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            source_post_id bigint(20) unsigned DEFAULT NULL,
            target_language varchar(20) DEFAULT NULL,
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
            KEY post_id (post_id),
            KEY source_post_id (source_post_id),
            KEY model (model),
            KEY created_at (created_at),
            KEY activity (activity)
        ) {$charset_collate};";

        dbDelta($sql);

        return self::table_exists();
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
     *     @type string $target_language Target language, when this is a translation.
     *     @type string $surface         API surface used.
     *     @type string $effort          Reasoning effort used.
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
            'post_id' => !empty($event['post_id']) ? (int) $event['post_id'] : null,
            'source_post_id' => !empty($event['source_post_id']) ? (int) $event['source_post_id'] : null,
            'target_language' => !empty($event['target_language']) ? (string) $event['target_language'] : null,
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
        $source_id = (int) ($record['source_post_id'] ?? 0);
        if ($source_id > 0 && $source_id !== (int) ($record['post_id'] ?? 0)) {
            self::merge_summary($source_id, $record, $record['target_language'] ?? null);
        }
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

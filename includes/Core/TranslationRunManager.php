<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the parent record for one complete translation process.
 *
 * A run is deliberately separate from the usage ledger. The ledger remains one
 * row per billed AI call, while this table gives those calls a stable unit that
 * also includes post-translation workflows and source-text metrics.
 */
class TranslationRunManager
{
    const TABLE = 'polytrans_translation_runs';
    const SCHEMA_VERSION = 1;
    const OPTION_SCHEMA = 'polytrans_translation_runs_schema_version';

    /**
     * Whether the table has been checked during this request.
     *
     * @var bool|null
     */
    private static $table_ready = null;

    /**
     * Ensure the parent table exists.
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

        self::$table_ready = self::create_table();

        if (self::$table_ready) {
            update_option(self::OPTION_SCHEMA, self::SCHEMA_VERSION);
        }

        return self::$table_ready;
    }

    /**
     * Create or migrate the translation run table.
     *
     * @return bool
     */
    public static function create_table()
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) NOT NULL,
            created_at datetime NOT NULL,
            completed_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'running',
            source_post_id bigint(20) unsigned DEFAULT NULL,
            translated_post_id bigint(20) unsigned DEFAULT NULL,
            source_language varchar(20) DEFAULT NULL,
            target_language varchar(20) DEFAULT NULL,
            translation_path varchar(191) DEFAULT NULL,
            source_characters bigint(20) unsigned NOT NULL DEFAULT 0,
            source_words bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY run_id (run_id),
            KEY source_post_id (source_post_id),
            KEY translated_post_id (translated_post_id),
            KEY target_language (target_language),
            KEY created_at (created_at),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta($sql);

        return self::table_exists();
    }

    /**
     * Start a new run and return its stable identifier.
     *
     * @param array $context Run identity and language context.
     * @param array $metrics Source text metrics.
     * @return string UUID even when the database is temporarily unavailable.
     */
    public static function start(array $context = [], array $metrics = [])
    {
        $run_id = self::new_id();
        self::ensure($run_id, $context, $metrics);

        return $run_id;
    }

    /**
     * Ensure a run exists, which makes an ID received from another translation
     * server safe to use locally and keeps retries idempotent.
     *
     * @param mixed $run_id Run UUID.
     * @param array $context Run identity and language context.
     * @param array $metrics Source text metrics.
     * @return string|null Normalized UUID, or null for an invalid external ID.
     */
    public static function ensure($run_id, array $context = [], array $metrics = [])
    {
        $run_id = self::normalize_id($run_id);

        if ($run_id === null) {
            return null;
        }

        if (!self::initialize()) {
            return $run_id;
        }

        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE run_id = %s", $run_id));

        if ($existing) {
            return $run_id;
        }

        $metrics = self::normalize_metrics($metrics);
        $data = [
            'run_id' => $run_id,
            'created_at' => current_time('mysql'),
            'status' => 'running',
            'source_post_id' => !empty($context['source_post_id']) ? (int) $context['source_post_id'] : null,
            'translated_post_id' => !empty($context['translated_post_id']) ? (int) $context['translated_post_id'] : null,
            'source_language' => !empty($context['source_language']) ? (string) $context['source_language'] : null,
            'target_language' => !empty($context['target_language']) ? (string) $context['target_language'] : null,
            'translation_path' => !empty($context['translation_path']) ? (string) $context['translation_path'] : null,
            'source_characters' => $metrics['source_characters'],
            'source_words' => $metrics['source_words'],
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($table, $data);

        return $run_id;
    }

    /**
     * Store the resolved full path after path rules have been evaluated.
     *
     * @param mixed $run_id Run UUID.
     * @param string $path Path such as pl>en>de.
     * @return void
     */
    public static function update_path($run_id, $path)
    {
        self::update($run_id, ['translation_path' => (string) $path]);
    }

    /**
     * Attach the post created by the translation.
     *
     * @param mixed $run_id Run UUID.
     * @param int $post_id Translated post ID.
     * @return void
     */
    public static function attach_post($run_id, $post_id)
    {
        self::update($run_id, ['translated_post_id' => (int) $post_id]);
    }

    /**
     * Mark a run as finished.
     *
     * @param mixed $run_id Run UUID.
     * @param string $status Final status.
     * @return void
     */
    public static function complete($run_id, $status = 'completed')
    {
        self::update($run_id, [
            'status' => in_array($status, ['completed', 'failed'], true) ? $status : 'completed',
            'completed_at' => current_time('mysql'),
        ]);
    }

    /**
     * Mark a run as failed and close it.
     *
     * @param mixed $run_id Run UUID.
     * @return void
     */
    public static function fail($run_id)
    {
        self::complete($run_id, 'failed');
    }

    /**
     * Check whether the table is present.
     *
     * @return bool
     */
    public static function table_exists()
    {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * @return string Fully qualified table name.
     */
    public static function table_name()
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Normalize a possibly external identifier.
     *
     * @param mixed $run_id Candidate identifier.
     * @return string|null
     */
    public static function normalize_id($run_id)
    {
        $run_id = is_string($run_id) ? strtolower(trim($run_id)) : '';

        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $run_id)
            ? $run_id
            : null;
    }

    /**
     * @param array $metrics Candidate metrics.
     * @return array{source_characters:int, source_words:int}
     */
    private static function normalize_metrics(array $metrics)
    {
        return [
            'source_characters' => max(0, (int) ($metrics['source_characters'] ?? 0)),
            'source_words' => max(0, (int) ($metrics['source_words'] ?? 0)),
        ];
    }

    /**
     * Update one existing run.
     *
     * @param mixed $run_id Run UUID.
     * @param array $data Trusted update fields.
     * @return void
     */
    private static function update($run_id, array $data)
    {
        $run_id = self::normalize_id($run_id);

        if ($run_id === null || !self::initialize()) {
            return;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(self::table_name(), $data, ['run_id' => $run_id]);
    }

    /**
     * @return string UUID v4.
     */
    private static function new_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return strtolower(wp_generate_uuid4());
        }

        $bytes = function_exists('random_bytes') ? random_bytes(16) : md5(uniqid('', true), true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

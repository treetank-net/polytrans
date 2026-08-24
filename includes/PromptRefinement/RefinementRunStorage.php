<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

use PolyTrans\Core\LogsManager;
use PolyTrans\Core\Diagnostics;

if (!defined('ABSPATH')) {
    exit;
}

final class RefinementRunStorage
{
    private const TABLE_NAME = 'polytrans_refinement_runs';
    private static bool $tableInitialized = false;

    public static function initialize(): void
    {
        self::createTable();
        self::cleanupExpired();
    }

    public static function store(string $runId, string $runType, array $payload, int $ttl): bool
    {
        global $wpdb;

        self::createTable();

        $table_name = self::tableName();
        $now = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(60, $ttl));
        $serialized = maybe_serialize($payload);
        $payload_size = strlen($serialized);

        $data = [
            'run_id' => $runId,
            'run_type' => $runType,
            'payload' => $serialized,
            'payload_size' => $payload_size,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expires_at,
        ];
        $formats = ['%s', '%s', '%s', '%d', '%s', '%s', '%s'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime storage for prompt refinement run payloads.
        $updated = $wpdb->update(
            $table_name,
            [
                'payload' => $serialized,
                'payload_size' => $payload_size,
                'updated_at' => $now,
                'expires_at' => $expires_at,
            ],
            ['run_id' => $runId],
            ['%s', '%d', '%s', '%s'],
            ['%s']
        );

        if ($updated !== false && $updated > 0) {
            return true;
        }
        if ($updated === 0 && self::exists($runId)) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Runtime storage for prompt refinement run payloads.
        $inserted = $wpdb->insert($table_name, $data, $formats);
        if ($inserted !== false) {
            return true;
        }

        self::logFailure('Failed to store prompt refinement run payload', [
            'run_id' => $runId,
            'run_type' => $runType,
            'payload_size_bytes' => $payload_size,
            'storage' => 'db',
            'db_error' => $wpdb->last_error,
        ]);

        return false;
    }

    public static function get(string $runId): ?array
    {
        global $wpdb;

        self::createTable();

        $table_name = self::tableName();

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is produced by tableName() from $wpdb->prefix; the run ID is prepared below.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the run ID is prepared.
            $wpdb->prepare("SELECT payload, expires_at FROM {$table_name} WHERE run_id = %s", $runId),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $expires_at = strtotime((string) ($row['expires_at'] ?? ''));
        if ($expires_at !== false && $expires_at <= time()) {
            self::delete($runId);
            return null;
        }

        $payload = maybe_unserialize((string) ($row['payload'] ?? ''));
        return is_array($payload) ? $payload : null;
    }

    public static function delete(string $runId): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime cleanup for plugin-owned table.
        $wpdb->delete(self::tableName(), ['run_id' => $runId], ['%s']);
    }

    /**
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        global $wpdb;

        self::createTable();

        $table_name = self::tableName();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Runtime maintenance stats for plugin-owned table.
        $row = $wpdb->get_row("SELECT COUNT(*) AS total_runs, COALESCE(SUM(payload_size), 0) AS total_payload_size, MIN(expires_at) AS oldest_expires_at, MAX(expires_at) AS newest_expires_at FROM {$table_name}", ARRAY_A);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Runtime maintenance stats for plugin-owned table.
        $expired_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE expires_at < %s", current_time('mysql', true)));

        return [
            'total_runs' => (int) ($row['total_runs'] ?? 0),
            'expired_runs' => $expired_count,
            'total_payload_size' => (int) ($row['total_payload_size'] ?? 0),
            'oldest_expires_at' => (string) ($row['oldest_expires_at'] ?? ''),
            'newest_expires_at' => (string) ($row['newest_expires_at'] ?? ''),
            'last_cleanup_at' => (int) get_option('polytrans_refinement_runs_cleanup_at', 0),
            'cleanup_interval_seconds' => HOUR_IN_SECONDS,
            'default_ttl_seconds' => 2 * HOUR_IN_SECONDS,
        ];
    }

    public static function cleanupExpiredNow(): int
    {
        return self::cleanupExpired(true);
    }

    public static function deleteAll(): int
    {
        global $wpdb;

        self::createTable();
        $stats = self::stats();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Manual maintenance for plugin-owned runtime table; the only interpolated value is the table name, built from $wpdb->prefix, and a table name cannot be a prepared value.
        $truncated = $wpdb->query("TRUNCATE TABLE " . self::tableName());
        if ($truncated !== false) {
            return (int) ($stats['total_runs'] ?? 0);
        }

        self::logFailure('Failed to truncate prompt refinement run storage table', [
            'storage' => 'db',
            'db_error' => $wpdb->last_error,
        ]);

        return 0;
    }

    private static function createTable(): void
    {
        if (self::$tableInitialized) {
            return;
        }

        global $wpdb;

        $table_name = self::tableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(80) NOT NULL,
            run_type varchar(40) NOT NULL,
            payload longtext NOT NULL,
            payload_size bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_id (run_id),
            KEY run_type (run_type),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        self::$tableInitialized = true;
    }

    private static function cleanupExpired(bool $force = false): int
    {
        global $wpdb;

        $last_cleanup = (int) get_option('polytrans_refinement_runs_cleanup_at', 0);
        if (!$force && $last_cleanup > 0 && (time() - $last_cleanup) < HOUR_IN_SECONDS) {
            return 0;
        }

        update_option('polytrans_refinement_runs_cleanup_at', time(), false);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Periodic cleanup for plugin-owned runtime table; the table name comes from $wpdb->prefix and the value is prepared.
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM " . self::tableName() . " WHERE expires_at < %s", current_time('mysql', true)));

        return is_int($deleted) ? $deleted : 0;
    }

    private static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_NAME;
    }

    private static function exists(string $runId): bool
    {
        global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Runtime lookup by run ID in plugin-owned table; the table name comes from $wpdb->prefix and the run ID is prepared.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::tableName() . " WHERE run_id = %s", $runId));
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function logFailure(string $message, array $context): void
    {
        if (class_exists(LogsManager::class)) {
            LogsManager::log($message, 'error', $context);
            return;
        }

        Diagnostics::log($message . ': ' . wp_json_encode($context));
    }
}

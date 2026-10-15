<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records every change to the plugin's settings in the PolyTrans log: which keys
 * changed, from what to what, and who made the change.
 *
 * It listens on the option hooks rather than on the save handlers, so a change
 * made from any path — the settings page, an AJAX handler, WP-CLI, another plugin
 * calling update_option() — is recorded the same way. WordPress fires neither hook
 * when the new value equals the old one, so a save without changes logs nothing.
 *
 * Secrets (API keys, tokens, passwords) are never written to the log, only the
 * fact that they changed.
 */
class SettingsAuditLog
{
    /** Options that hold settings a user edits. */
    const AUDITED_OPTIONS = [
        'polytrans_settings',
        'polytrans_postprocessing_settings',
    ];

    /** Longer values (prompts, e-mail templates) are shortened in the message. */
    const MAX_VALUE_LENGTH = 200;

    const REDACTED = '[redacted]';

    public static function register(): void
    {
        foreach (self::AUDITED_OPTIONS as $option) {
            add_action("update_option_{$option}", [self::class, 'on_update'], 10, 3);
            add_action("add_option_{$option}", [self::class, 'on_add'], 10, 2);
        }
    }

    /**
     * @param mixed  $old_value
     * @param mixed  $new_value
     * @param string $option
     */
    public static function on_update($old_value, $new_value, $option): void
    {
        self::record((string) $option, $old_value, $new_value);
    }

    /**
     * @param string $option
     * @param mixed  $value
     */
    public static function on_add($option, $value): void
    {
        self::record((string) $option, [], $value);
    }

    /**
     * @param mixed $old_value
     * @param mixed $new_value
     */
    private static function record(string $option, $old_value, $new_value): void
    {
        $changes = self::diff($old_value, $new_value);
        if (empty($changes)) {
            return;
        }

        $actor = self::describe_actor();
        $lines = array_map(
            static fn(array $c) => sprintf('%s: %s → %s', $c['key'], $c['old'], $c['new']),
            $changes
        );

        \PolyTrans_Logs_Manager::log(
            sprintf(
                'Settings "%s" changed by %s (%d change(s)): %s',
                $option,
                $actor['label'],
                count($changes),
                implode('; ', $lines)
            ),
            'info',
            [
                'source' => 'settings',
                'option' => $option,
                'changed_by' => $actor,
                'changes' => $changes,
            ]
        );
    }

    /**
     * Flattens both values to dotted keys and returns the keys whose value differs.
     * Each change carries display-ready `old`/`new` strings, secrets redacted.
     *
     * @param mixed $old_value
     * @param mixed $new_value
     * @return array<int, array{key: string, old: string, new: string}>
     */
    public static function diff($old_value, $new_value): array
    {
        $old = self::flatten(is_array($old_value) ? $old_value : ['' => $old_value]);
        $new = self::flatten(is_array($new_value) ? $new_value : ['' => $new_value]);

        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $had = array_key_exists($key, $old);
            $has = array_key_exists($key, $new);
            if ($had && $has && $old[$key] === $new[$key]) {
                continue;
            }

            $key = (string) $key;
            $secret = self::is_secret_key($key);
            $changes[] = [
                'key' => $key === '' ? '(value)' : $key,
                'old' => $had ? self::format($old[$key], $secret) : '(unset)',
                'new' => $has ? self::format($new[$key], $secret) : '(unset)',
            ];
        }

        return $changes;
    }

    /**
     * Who made the change, as the log should name them.
     *
     * @return array{user_id: int, login: string, label: string}
     */
    public static function describe_actor(): array
    {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id > 0 && function_exists('get_userdata')) {
            $user = get_userdata($user_id);
            if ($user) {
                $login = (string) $user->user_login;
                return [
                    'user_id' => $user_id,
                    'login' => $login,
                    'label' => sprintf('%s (#%d)', $login, $user_id),
                ];
            }
        }

        $label = (defined('WP_CLI') && WP_CLI) ? 'WP-CLI' : 'system (no logged-in user)';

        return ['user_id' => $user_id, 'login' => '', 'label' => $label];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            // A list is one setting (e.g. allowed domains); compare it as a whole.
            // Not array_is_list: Plugin Check ties it to WordPress 6.5, the plugin supports 6.0.
            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private static function is_secret_key(string $key): bool
    {
        return (bool) preg_match('/(api_?key|secret|token|password|passwd|credential)/i', $key);
    }

    /**
     * @param mixed $value
     */
    private static function format($value, bool $secret): string
    {
        if ($secret) {
            return ($value === '' || $value === null) ? '(empty)' : self::REDACTED;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            $text = (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $text = '"' . (string) $value . '"';
        }

        if (function_exists('mb_strlen') && mb_strlen($text) > self::MAX_VALUE_LENGTH) {
            return mb_substr($text, 0, self::MAX_VALUE_LENGTH) . '…';
        }

        return $text;
    }
}

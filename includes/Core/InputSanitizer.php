<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitizers for the two shapes of input this plugin actually receives.
 *
 * Most admin input here is prompt text and workflow configuration, and the usual
 * WordPress helpers destroy it: sanitize_text_field() collapses the newlines that
 * separate instructions in a prompt, and wp_strip_all_tags() eats the `<schema>`
 * markers and JSON examples that a prompt has to carry to work at all. That is why
 * these call sites used to be annotated as "trusted admin input" instead of
 * sanitized — an argument the reviewer, correctly, does not accept: capability and
 * nonce checks decide *who* may post, not *what* arrives.
 *
 * So sanitize what is genuinely dangerous regardless of who sent it — invalid UTF-8,
 * null bytes, unbounded length — and leave the payload's meaning intact. Escaping on
 * output stays the caller's job; these values are rendered through Twig or serialized
 * by wp_send_json_*, never concatenated into HTML here.
 */
class InputSanitizer
{
    /**
     * Longest accepted prompt or template, in characters.
     *
     * Well above any real prompt (the longest shipped default is a few thousand
     * characters) and far below what would exhaust memory when a few dozen of them
     * arrive in one nested workflow payload.
     */
    const MAX_TEMPLATE_LENGTH = 200000;

    /** Longest accepted array key. */
    const MAX_KEY_LENGTH = 255;

    /** Deepest accepted nesting in a posted array. */
    const MAX_DEPTH = 20;

    /**
     * Sanitize prompt or template text, preserving what makes it a template.
     *
     * Newlines, `{{ variables }}`, JSON braces and `<schema>` markers all survive.
     *
     * @param mixed $value Raw value, already unslashed.
     * @return string
     */
    public static function prompt_template($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        $value = (string) $value;

        // Invalid UTF-8 is what makes a string behave differently in the database,
        // in json_encode() and in the browser than it does in the check above it.
        $value = wp_check_invalid_utf8($value, true);

        // Null bytes truncate strings in anything that reaches C, including some
        // filesystem and database paths.
        $value = str_replace(chr(0), '', $value);

        // Normalize line endings so a template edited on Windows and one edited on
        // Linux hash and compare identically.
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (mb_strlen($value) > self::MAX_TEMPLATE_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_TEMPLATE_LENGTH);
        }

        return $value;
    }

    /**
     * Sanitize a nested posted structure: workflow definitions, step configuration,
     * schema mappings.
     *
     * Scalars keep their type — a step's `enabled` boolean must not come back as the
     * string "1", because the code that reads it compares identity. Strings go through
     * prompt_template(), because at any depth a string here may be a prompt. Keys are
     * cleaned but keep their case: half of them are camelCase field names and
     * sanitize_key() would silently rename every one of them.
     *
     * @param mixed $value Raw value, already unslashed.
     * @param int   $depth Current recursion depth.
     * @return mixed
     */
    public static function deep($value, $depth = 0)
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $key => $item) {
                $clean[self::key($key)] = self::deep($item, $depth + 1);
            }

            return $clean;
        }

        if (is_object($value)) {
            return self::deep((array) $value, $depth);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || is_null($value)) {
            return $value;
        }

        return self::prompt_template($value);
    }

    /**
     * Sanitize an array key from posted data.
     *
     * @param string|int $key
     * @return string|int
     */
    private static function key($key)
    {
        if (is_int($key)) {
            return $key;
        }

        $key = str_replace(chr(0), '', (string) $key);
        $key = wp_check_invalid_utf8($key, true);
        $key = wp_strip_all_tags($key);
        $key = trim($key);

        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            $key = mb_substr($key, 0, self::MAX_KEY_LENGTH);
        }

        return $key;
    }
}

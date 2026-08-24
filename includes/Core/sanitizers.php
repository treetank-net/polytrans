<?php

/**
 * Namespaced sanitizer functions.
 *
 * The logic lives in PolyTrans\Core\InputSanitizer; these are the callable form of it.
 * They exist as functions rather than only as static methods because PHPCS cannot
 * recognise a static call as sanitisation: WPCS's is_in_function_call() rejects any
 * candidate preceded by `::` (see ContextHelper::has_object_operator_before), so a
 * `customSanitizingFunctions` entry naming a method never matches and every call site
 * would still need a `phpcs:ignore` — which is exactly what the WordPress.org review
 * objected to. Imported with `use function`, these are plain T_STRING calls that the
 * sniff resolves, and the annotations disappear for real rather than being reworded.
 *
 * @package PolyTrans
 */

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitize prompt or template text, preserving newlines, {{ variables }} and markup
 * markers that the template needs in order to work.
 *
 * @param mixed $value Raw value, already unslashed.
 * @return string
 */
function sanitize_prompt_template($value)
{
    return InputSanitizer::prompt_template($value);
}

/**
 * Sanitize a nested posted structure — workflow definitions, step configuration,
 * schema mappings — keeping scalar types and key casing intact.
 *
 * @param mixed $value Raw value, already unslashed.
 * @return mixed
 */
function sanitize_input_deep($value)
{
    return InputSanitizer::deep($value);
}

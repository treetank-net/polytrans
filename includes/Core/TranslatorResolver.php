<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Picks the route and the translator for one translation from the
 * Language Paths settings.
 *
 * A path rule can name a translator ('assistant'). It covers every step of the
 * translations it matches, so one rule "All → All, direct, translator X" makes X the
 * default and the Provider/Assistant Mapping can stay empty. An explicit mapping for
 * a step's language pair still wins over any rule.
 *
 * Rule specificity: exact pair (3) > one side "all" (2) > "all → all" (1); a tie goes
 * to the rule further down the list. The route and the translator are chosen
 * independently: the most specific rule decides the route, the most specific rule
 * *that names a translator* decides the translator, so a narrow routing rule without
 * a translator does not hide a broader default.
 */
class TranslatorResolver
{
    /**
     * @param array $rules Path rules: [['source', 'target', 'intermediate', 'assistant'?], ...]
     * @return array Language codes, e.g. ['pl', 'en', 'de'] or ['pl', 'de'].
     */
    public static function resolve_path($source_lang, $target_lang, $rules)
    {
        $rule = self::best_rule($source_lang, $target_lang, $rules, false);
        if ($rule === null) {
            return [$source_lang, $target_lang];
        }

        $intermediate = isset($rule['intermediate']) ? trim((string) $rule['intermediate']) : '';
        if ($intermediate === '' || strtolower($intermediate) === 'none'
            || $intermediate === $source_lang || $intermediate === $target_lang) {
            return [$source_lang, $target_lang];
        }

        return [$source_lang, $intermediate, $target_lang];
    }

    /**
     * Translator named by the most specific rule matching the whole translation.
     *
     * @return string Assistant/provider id, or '' when no matching rule names one.
     */
    public static function rule_translator($source_lang, $target_lang, $rules)
    {
        $rule = self::best_rule($source_lang, $target_lang, $rules, true);
        return $rule === null ? '' : trim((string) $rule['assistant']);
    }

    /**
     * Translator for one step: the step's own mapping entry, else the rule's.
     *
     * @param string $fallback Result of rule_translator() for the whole translation.
     * @return string Assistant/provider id, or '' when nothing is configured.
     */
    public static function step_translator($step_source, $step_target, $mapping, $fallback = '')
    {
        $key = $step_source . '_to_' . $step_target;
        $mapped = is_array($mapping) && isset($mapping[$key]) ? trim((string) $mapping[$key]) : '';
        return $mapped !== '' ? $mapped : (string) $fallback;
    }

    private static function best_rule($source_lang, $target_lang, $rules, $needs_translator)
    {
        if (!is_array($rules)) {
            return null;
        }

        $best = null;
        $best_score = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ($needs_translator && trim((string) ($rule['assistant'] ?? '')) === '') {
                continue;
            }
            $source = $rule['source'] ?? '';
            $target = $rule['target'] ?? '';

            $score = 0;
            if ($source === $source_lang && $target === $target_lang) {
                $score = 3;
            } elseif (($source === $source_lang && $target === 'all') || ($source === 'all' && $target === $target_lang)) {
                $score = 2;
            } elseif ($source === 'all' && $target === 'all') {
                $score = 1;
            }

            // >= : a tie goes to the later rule.
            if ($score > 0 && $score >= $best_score) {
                $best = $rule;
                $best_score = $score;
            }
        }

        return $best;
    }
}

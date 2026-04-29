<?php

declare(strict_types=1);

namespace PolyTrans\PromptRefinement;

if (!defined('ABSPATH')) {
    exit;
}

final class EvaluationScoreExtractor
{
    public static function extract(string $text): ?float
    {
        $score_patterns = [
            '/(?:score|rating|ocena)\s*[:=]\s*(100(?:\.\d+)?|[0-9]{1,2}(?:\.\d+)?)/i',
            '/\b(100(?:\.\d+)?|[0-9]{1,2}(?:\.\d+)?)\b/',
        ];

        foreach ($score_patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $score = (float) $matches[1];
                if ($score >= 0 && $score <= 100) {
                    return $score;
                }
            }
        }

        return null;
    }
}

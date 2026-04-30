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
        $json_score = self::extractFromJson($text);
        if ($json_score !== null) {
            return $json_score;
        }

        $normalized = self::normalizeText($text);
        $score_patterns = [
            '/\b(?:score|rating|ocena|wynik)\s*(?:[:=:\-]|is|wynosi)?\s*["\']?(100(?:[\.,]\d+)?|[0-9]{1,2}(?:[\.,]\d+)?)["\']?(?:\s*(?:\/|out of|na)\s*100)?\b/i',
            '/\b(100(?:[\.,]\d+)?|[0-9]{1,2}(?:[\.,]\d+)?)\s*(?:\/|out of|na)\s*100\b/i',
            '/^\s*(100(?:[\.,]\d+)?|[1-9][0-9](?:[\.,]\d+)?|0(?:[\.,]\d+)?)(?=\s*(?:$|[.;,\-]))/m',
        ];

        foreach ($score_patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $score = self::normalizeScore($matches[1]);
                if ($score !== null) {
                    return $score;
                }
            }
        }

        return null;
    }

    private static function normalizeText(string $text): string
    {
        $text = str_replace(["\xc2\xa0", "\r\n", "\r"], [' ', "\n", "\n"], $text);
        // Remove markdown emphasis/code markers so forms like **Score:** work.
        $text = preg_replace('/[*_`]+/', '', $text) ?? $text;

        return trim($text);
    }

    private static function normalizeScore($value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            return null;
        }

        $score = (float) $value;
        return ($score >= 0 && $score <= 100) ? $score : null;
    }

    private static function extractFromJson(string $text): ?float
    {
        foreach (self::jsonCandidates($text) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }

            $score = self::findScoreInArray($decoded);
            if ($score !== null) {
                return $score;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private static function jsonCandidates(string $text): array
    {
        $candidates = [trim($text)];

        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $candidates[] = trim($match);
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = trim(substr($text, $start, $end - $start + 1));
        }

        return array_values(array_unique(array_filter($candidates, static fn ($item) => $item !== '')));
    }

    /**
     * @param array<string|int,mixed> $data
     */
    private static function findScoreInArray(array $data): ?float
    {
        foreach (['score', 'rating', 'ocena', 'wynik', 'overall_score'] as $key) {
            if (array_key_exists($key, $data)) {
                $score = self::normalizeScore($data[$key]);
                if ($score !== null) {
                    return $score;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $score = self::findScoreInArray($value);
                if ($score !== null) {
                    return $score;
                }
            }
        }

        return null;
    }
}

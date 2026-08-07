<?php

declare(strict_types=1);

use PolyTrans\Core\TextMetrics;

describe('source text metrics', function () {
    it('counts visible Unicode article text and words', function () {
        $metrics = TextMetrics::from_payload([
            'title' => 'Żółw &amp; lis',
            'content' => '<p>To jest <strong>tekst</strong>.</p>',
            'excerpt' => 'Krótko',
            'meta' => ['seo_description' => 'not article text'],
        ]);

        expect($metrics['source_words'])->toBe(6);
        expect($metrics['source_characters'])->toBe(mb_strlen("Żółw & lis\nTo jest tekst.\nKrótko", 'UTF-8'));
    });

    it('does not treat HTML markup or metadata as billable article text', function () {
        $metrics = TextMetrics::from_payload([
            'content' => '<h1>Jedno</h1><p>drugie</p>',
            'meta' => ['description' => str_repeat('szum ', 100)],
        ]);

        expect($metrics)->toBe([
            'source_characters' => mb_strlen('Jedno drugie', 'UTF-8'),
            'source_words' => 2,
        ]);
    });
});

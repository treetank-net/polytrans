<?php

declare(strict_types=1);

use PolyTrans\Core\InputSanitizer;

/**
 * The point of these sanitizers is that they clean input without destroying it.
 * Half of these tests therefore assert what SURVIVES: a prompt that loses its
 * newlines, its {{ variables }} or its schema markers is a broken prompt, and
 * that is precisely why the call sites used to skip sanitisation altogether.
 */

test('a prompt keeps everything that makes it a prompt', function () {
    $prompt = "You are a translator.\n\nTranslate {{ post.title }} into {{ target_language }}.\n"
        . "Return JSON: {\"title\": \"...\", \"content\": \"...\"}\n"
        . "<schema>{\"type\": \"object\"}</schema>\n"
        . "Keep <strong>inline markup</strong> intact. Use \"quotes\" & ampersands.";

    expect(InputSanitizer::prompt_template($prompt))->toBe($prompt);
});

test('null bytes are removed', function () {
    expect(InputSanitizer::prompt_template("before\0after"))->toBe('beforeafter');
});

test('line endings are normalized so identical templates compare equal', function () {
    expect(InputSanitizer::prompt_template("a\r\nb\rc"))->toBe("a\nb\nc");
});

test('length is capped', function () {
    $long = str_repeat('x', InputSanitizer::MAX_TEMPLATE_LENGTH + 500);

    expect(mb_strlen(InputSanitizer::prompt_template($long)))->toBe(InputSanitizer::MAX_TEMPLATE_LENGTH);
});

test('a prompt field that arrives as an array yields an empty string, not an array', function () {
    expect(InputSanitizer::prompt_template(['unexpected']))->toBe('');
});

test('deep sanitisation keeps scalar types', function () {
    $input = [
        'enabled' => true,
        'disabled' => false,
        'retries' => 3,
        'threshold' => 0.75,
        'missing' => null,
    ];

    expect(InputSanitizer::deep($input))->toBe($input);
});

test('deep sanitisation keeps key casing, because half the field names are camelCase', function () {
    $clean = InputSanitizer::deep(['expectedOutputSchema' => 'x', 'output_actions' => 'y']);

    expect(array_keys($clean))->toBe(['expectedOutputSchema', 'output_actions']);
});

test('deep sanitisation reaches nested prompts and cleans them', function () {
    $clean = InputSanitizer::deep([
        'steps' => [
            ['system_prompt' => "line\r\none\0"],
        ],
    ]);

    expect($clean['steps'][0]['system_prompt'])->toBe("line\none");
});

test('nesting is bounded', function () {
    $deep = 'leaf';
    for ($i = 0; $i < InputSanitizer::MAX_DEPTH + 5; $i++) {
        $deep = ['level' => $deep];
    }

    $clean = InputSanitizer::deep($deep);

    // Walk down until the sanitizer cut the structure off.
    $depth = 0;
    $node = $clean;
    while (is_array($node)) {
        $node = $node['level'];
        $depth++;
    }

    expect($depth)->toBeLessThanOrEqual(InputSanitizer::MAX_DEPTH + 1);
    expect($node)->toBeNull();
});

test('markup in a key is stripped but the key survives', function () {
    $clean = InputSanitizer::deep(['<b>name</b>' => 'value']);

    expect(array_keys($clean))->toBe(['name']);
});

test('numeric keys stay numeric so posted lists stay lists', function () {
    $clean = InputSanitizer::deep(['a', 'b', 'c']);

    expect($clean)->toBe(['a', 'b', 'c']);
});

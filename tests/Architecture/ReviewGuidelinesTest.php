<?php

declare(strict_types=1);

/**
 * Architecture Tests: what the WordPress.org reviewer rejects regardless of annotations.
 *
 * The reviewer reads the shipped code, not our phpcs:ignore comments. Each rule here is a
 * finding from a review round that a suppression had hidden from our own gates — the
 * 2026-10-16 round found a cURL fallback, forwarded $_COOKIE and twelve
 * `echo TemplateRenderer::render()` calls, all annotated and all "passing".
 */

/**
 * PHP files that ship: includes/ and the main file, minus what .distignore removes.
 *
 * @return array<string, string> relative path => source without comments
 */
function review_guidelines_shipped_sources(): array
{
    static $sources = null;
    if (null !== $sources) {
        return $sources;
    }

    $root = dirname(__DIR__, 2);
    $excluded = [];
    foreach (file($root . '/.distignore', FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, '/includes/')) {
            $excluded[] = ltrim($line, '/');
        }
    }

    $files = [$root . '/treetank-trans.php'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $sources = [];
    foreach ($files as $file) {
        $relative = str_replace($root . '/', '', $file);
        foreach ($excluded as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                continue 2;
            }
        }
        $sources[$relative] = file_get_contents($file);
    }

    return $sources;
}

/**
 * Lines of $source matching $pattern after comments are blanked (line numbers kept).
 *
 * @return list<string> "path:line"
 */
function review_guidelines_matches(string $pattern, bool $strip_comments = true): array
{
    $hits = [];
    foreach (review_guidelines_shipped_sources() as $path => $source) {
        if ($strip_comments) {
            $source = preg_replace_callback(
                '!//[^\n]*|/\*.*?\*/!s',
                static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]),
                $source
            );
        }
        foreach (explode("\n", $source) as $index => $line) {
            if (preg_match($pattern, $line)) {
                $hits[] = $path . ':' . ($index + 1);
            }
        }
    }

    return $hits;
}

test('HTTP goes through the WordPress HTTP API, never cURL or PHP streams', function () {
    expect(review_guidelines_matches('/(?<![\w$>:])curl_[a-z_]+\s*\(/'))->toBe([]);
    expect(review_guidelines_matches('/(?<![\w$>:])(fsockopen|stream_socket_client)\s*\(/'))->toBe([]);
    // file_get_contents()/fopen() on a URL — local files are fine, remote ones are not.
    expect(review_guidelines_matches('/(?<![\w$>:])(file_get_contents|fopen)\s*\(\s*(\$url|[\'"]https?:)/'))->toBe([]);
    expect(review_guidelines_matches('/(?<![\w$>:])stream_context_create\s*\(/'))->toBe([]);
});

test('$_COOKIE is never read or forwarded', function () {
    expect(review_guidelines_matches('/\$_COOKIE\b/'))->toBe([]);
});

test('templates reach the screen only through TemplateRenderer::display()', function () {
    // display() runs the markup through wp_kses(); an echo of render() is unescaped output.
    expect(review_guidelines_matches('/\becho\s+[^;]*TemplateRenderer::render\s*\(/'))->toBe([]);
});

/**
 * "Escape late" means at the echo itself. A bare `echo $html;` is what the reviewer's
 * heuristic flags, even when the variable was escaped three lines earlier.
 */
test('no bare variable is echoed', function () {
    expect(review_guidelines_matches('/\becho\s+\$[A-Za-z_][\w\->\[\]\'"]*\s*;/'))->toBe([]);
});

/**
 * Suppressions the reviewer does not accept. The annotation silences our gate and
 * nothing else; the code still gets flagged in the review email.
 */
test('rejected findings are fixed, not suppressed', function () {
    $forbidden = [
        'WordPress.WP.AlternativeFunctions.curl_',
        'RestrictedVariables.cache_constraints___COOKIE',
        'WordPress.Security.EscapeOutput.OutputNotEscaped',
    ];

    $hits = [];
    foreach ($forbidden as $sniff) {
        foreach (review_guidelines_matches('/phpcs:(ignore|disable)[^\n]*' . preg_quote($sniff, '/') . '/', false) as $hit) {
            $hits[] = $hit . ' ' . $sniff;
        }
    }

    expect($hits)->toBe([]);
});

test('inline CSS is not built in PHP', function () {
    // Styles belong in assets/css/, enqueued on the screen that needs them.
    expect(review_guidelines_matches('/(?<![\w$>:])wp_add_inline_style\s*\(/'))->toBe([]);
});

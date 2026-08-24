<?php

declare(strict_types=1);

/**
 * Architecture test: every value a Twig template prints is escaped.
 *
 * Both Twig environments run with `autoescape => false`, so escaping is explicit —
 * done in the template with the WordPress escaping filters this plugin registers
 * (`|esc_html`, `|esc_attr`, `|esc_url`, `|esc_textarea`). That is a deliberate
 * choice, not an oversight: switching autoescape on would double-escape all ~360
 * places that already escape, so it is a migration, not a flag flip.
 *
 * What it costs is a gate. The March review round fixed escaping by hand, and the
 * fixes silently regressed in templates added afterwards, because nothing was
 * checking. This is the check: it fails on a `{{ }}` that prints anything other
 * than an escaped value, a literal, or a call that escapes on its own.
 */

test('every Twig output is escaped, a literal, or a self-escaping call', function () {
    $root = dirname(__DIR__, 2) . '/templates';

    // Filters that either escape, or produce a value that cannot carry markup.
    $escaping_filters = '(e|escape|raw|esc_html|esc_attr|esc_url|esc_textarea|esc_js'
        . '|wp_kses_post|number_format|length|json_encode|wp_json_encode)';

    // Functions that escape their own output, or return a fixed attribute string.
    $safe_functions = '(checked|selected|disabled|__|_e|esc_html__|esc_html_e|esc_attr__'
        . '|esc_attr_e|_n|_x|_ex|_nx)';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $unescaped = [];

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'twig') {
            continue;
        }

        $lines = file($file->getPathname());

        foreach ($lines as $number => $line) {
            if (!preg_match_all('/\{\{(.+?)\}\}/', $line, $matches)) {
                continue;
            }

            foreach ($matches[1] as $expression) {
                $expression = trim($expression);

                // Escaped somewhere in the filter chain.
                if (preg_match('/\|\s*' . $escaping_filters . '\b/', $expression)) {
                    continue;
                }

                // A call that handles its own escaping.
                if (preg_match('/^' . $safe_functions . '\s*\(/', $expression)) {
                    continue;
                }

                // A string literal, or a ternary that can only produce literals.
                if (preg_match("/^'/", $expression)
                    || preg_match("/^[^?]*\?\s*'[^']*'\s*:\s*'[^']*'$/", $expression)) {
                    continue;
                }

                $relative = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
                $unescaped[] = $relative . ':' . ($number + 1) . ' -- {{ ' . $expression . ' }}';
            }
        }
    }

    expect($unescaped)->toBe([]);
});

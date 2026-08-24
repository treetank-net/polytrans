<?php

declare(strict_types=1);

/**
 * Architecture Tests: Naming Conventions
 *
 * Enforces consistent naming across the codebase (basic structure validation)
 */

arch('no dangerous global functions are used')
    ->expect('PolyTrans')
    ->not()->toUse([
        'eval',
        'create_function',
    ]);

test('admin menus delegate prompt refinement execution', function () {
    $menu_files = [
        dirname(__DIR__, 2) . '/includes/Menu/AssistantsMenu.php',
        dirname(__DIR__, 2) . '/includes/Menu/PostprocessingMenu.php',
    ];

    foreach ($menu_files as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('PolyTrans\\Core\\ChatClientFactory');
        expect($source)->not->toContain('PolyTrans\\Templating\\TwigEngine');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\EvaluationScoreExtractor');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptChatRunner');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptPackNormalizer');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptPackParser');
        expect($source)->not->toContain('PolyTrans\\PromptRefinement\\PromptTemplateRenderer');
        expect($source)->not->toContain('ChatClientFactory::create');
        expect($source)->not->toContain('TwigEngine::render');
        expect($source)->not->toContain('PromptChatRunner::execute');
        expect($source)->not->toContain('PromptPackParser::parse');
        expect($source)->not->toContain('PromptTemplateRenderer::render');
        expect($source)->not->toContain('EvaluationScoreExtractor::extract');
    }
});

/**
 * The reviewer's automated scan flags every `wp_set_current_user()` as "creating /
 * logging in users". Ours is deliberate — a revision and a workflow write have to be
 * credited to a chosen author — but it only stays defensible while it is a single,
 * audited implementation that restores the previous identity in `finally`.
 */
test('identity swaps go through Core\\UserContext', function () {
    $root = dirname(__DIR__, 2);
    $files = [$root . '/polytrans.php'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $callers = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        // Skip comments mentioning the function; only real calls matter.
        if (preg_match('/(?<![\w$>:])wp_set_current_user\s*\(/', preg_replace('!//[^\n]*|/\*.*?\*/!s', '', $source))) {
            $callers[] = str_replace($root . '/', '', $file);
        }
    }

    expect($callers)->toBe(['includes/Core/UserContext.php']);

    $context = file_get_contents($root . '/includes/Core/UserContext.php');
    expect($context)->toContain('finally');
});

/**
 * The PHP error log belongs to the site owner, not to us. Everything a site owner
 * needs to read goes to LogsManager; developer breadcrumbs go through
 * Core\\Diagnostics, which stays silent unless debug logging is on. Bootstrap is the
 * one exception — it reports a missing Composer autoloader, so no autoloaded class
 * is available to it yet.
 */
test('only Core\\Diagnostics writes to the PHP error log', function () {
    $root = dirname(__DIR__, 2);
    $files = [$root . '/polytrans.php'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $allowed = [
        'includes/Core/Diagnostics.php',
        'includes/Bootstrap.php',
    ];

    $callers = [];

    foreach ($files as $file) {
        $source = preg_replace('!//[^\n]*|/\*.*?\*/!s', '', file_get_contents($file));

        if (preg_match('/(?<![\w$>:])error_log\s*\(/', $source)) {
            $relative = str_replace($root . '/', '', $file);

            if (!in_array($relative, $allowed, true)) {
                $callers[] = $relative;
            }
        }
    }

    expect($callers)->toBe([]);

    $diagnostics = file_get_contents($root . '/includes/Core/Diagnostics.php');
    expect($diagnostics)->toContain('WP_DEBUG');
});

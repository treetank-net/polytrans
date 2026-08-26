<?php

declare(strict_types=1);

/**
 * Architecture tests for AJAX ownership.
 *
 * Literal AJAX hooks must have one owner. Compatibility wrappers may remain
 * callable for integrations, but they must not register a second callback for
 * the same WordPress action.
 */

test('literal AJAX actions have one registration and canonical owners', function () {
    $root = dirname(__DIR__, 2);
    $files = [$root . '/treetank-trans.php'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/includes', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $registrations = [];
    $pattern = '/add_action\s*\(\s*([\'\"])(wp_ajax(?:_nopriv)?_[^\'\"]+)\1/';

    foreach ($files as $file) {
        $source = file_get_contents($file);
        preg_match_all($pattern, $source, $matches);

        foreach ($matches[2] as $hook) {
            $registrations[$hook][] = $file;
        }
    }

    $duplicates = array_filter(
        $registrations,
        static fn (array $files): bool => count($files) > 1
    );

    expect($duplicates)->toBeEmpty();

    $canonical_owners = [
        'wp_ajax_polytrans_search_users' => 'includes/Core/UserAutocomplete.php',
        'wp_ajax_polytrans_search_posts' => 'includes/Core/PostAutocomplete.php',
        'wp_ajax_polytrans_test_workflow' => 'includes/PostProcessing/WorkflowManager.php',
        'wp_ajax_polytrans_validate_provider_key' => 'includes/Menu/SettingsMenu.php',
    ];

    foreach ($canonical_owners as $hook => $owner) {
        expect($registrations[$hook] ?? [])->toHaveCount(1);
        expect($registrations[$hook][0])->toEndWith($owner);
    }
});

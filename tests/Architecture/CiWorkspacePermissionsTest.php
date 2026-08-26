<?php

declare(strict_types=1);

/**
 * The shell runner reuses its checkout between jobs. Docker containers run as
 * root by default, so a writable bind mount can leave files that the runner
 * cannot remove during the next checkout. The only intentional writable mount
 * is the narrowly-scoped cleanup helper for an already-poisoned workspace.
 */

test('Docker CI jobs do not write through the GitLab checkout mount', function () {
    $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');

    preg_match_all(
        '/-v\s+"\$\{CI_PROJECT_DIR\}:[^"\r\n]+"/',
        $ci,
        $mounts
    );

    expect($mounts[0])->not->toBeEmpty();

    $cleanup_mount = '-v "${CI_PROJECT_DIR}:/cleanup"';
    $writable_mounts = array_values(array_filter(
        $mounts[0],
        static fn (string $mount): bool => !str_ends_with($mount, ':ro"')
    ));

    expect($writable_mounts)->toBe([$cleanup_mount]);

    foreach ($mounts[0] as $mount) {
        if ($mount === $cleanup_mount) {
            continue;
        }

        expect($mount)->toEndWith(':ro"');
    }

    expect($ci)->toContain('GIT_CLEAN_FLAGS: "none"');
    expect($ci)->toContain('rm -rf /cleanup/vendor /cleanup/pcp /cleanup/composer.phar /cleanup/pcp-report.csv');

    $phpcs = substr($ci, strpos($ci, 'phpcs:'));
    $phpcs = substr($phpcs, 0, strpos($phpcs, '# The scan WordPress.org'));
    expect($phpcs)->toContain('-v "${CI_PROJECT_DIR}:/src:ro"');
    expect($phpcs)->toContain('cp -r /src /work');
    expect($phpcs)->not->toContain('-w /src');

    $plugin_check = substr($ci, strpos($ci, 'plugin-check:'));
    $plugin_check = substr($plugin_check, 0, strpos($plugin_check, "# ==============================================================================\n# Build Stage"));
    expect($plugin_check)->toContain('PCP_ROOT="/tmp/treetank-trans-pcp-${CI_JOB_ID}"');
    expect($plugin_check)->not->toContain('${CI_PROJECT_DIR}/pcp');
    expect($plugin_check)->toContain('--user root --entrypoint sh');
});

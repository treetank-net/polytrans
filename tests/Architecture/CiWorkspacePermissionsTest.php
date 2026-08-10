<?php

declare(strict_types=1);

/**
 * The shell runner reuses its checkout between jobs. Docker containers run as
 * root by default, so a writable bind mount can leave files that the runner
 * cannot remove during the next checkout.
 */

test('Docker CI jobs do not write through the GitLab checkout mount', function () {
    $ci = file_get_contents(dirname(__DIR__, 2) . '/.gitlab-ci.yml');

    preg_match_all(
        '/-v\s+"\$\{CI_PROJECT_DIR\}:[^"\r\n]+"/',
        $ci,
        $mounts
    );

    expect($mounts[0])->not->toBeEmpty();

    foreach ($mounts[0] as $mount) {
        expect($mount)->toEndWith(':ro"');
    }

    $phpcs = substr($ci, strpos($ci, 'phpcs:'));
    $phpcs = substr($phpcs, 0, strpos($phpcs, '# The scan WordPress.org'));
    expect($phpcs)->toContain('-v "${CI_PROJECT_DIR}:/src:ro"');
    expect($phpcs)->toContain('cp -r /src /work');
    expect($phpcs)->not->toContain('-w /src');

    $plugin_check = substr($ci, strpos($ci, 'plugin-check:'));
    $plugin_check = substr($plugin_check, 0, strpos($plugin_check, "# ==============================================================================\n# Build Stage"));
    expect($plugin_check)->toContain('PCP_ROOT="/tmp/polytrans-pcp-${CI_JOB_ID}"');
    expect($plugin_check)->not->toContain('${CI_PROJECT_DIR}/pcp');
    expect($plugin_check)->toContain('--user root --entrypoint sh');
});

<?php

declare(strict_types=1);

use PolyTrans\Core\AsyncJobRunner;

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('POLYTRANS_DISABLE_ASYNC_PROCESS_SPAWN')) {
    define('POLYTRANS_DISABLE_ASYNC_PROCESS_SPAWN', true);
}

$GLOBALS['polytrans_async_job_store'] = [];
$GLOBALS['polytrans_async_last_remote_post'] = null;
$GLOBALS['polytrans_async_remote_posts'] = [];
$GLOBALS['polytrans_async_remote_post_response'] = ['response' => ['code' => 200]];

if (!class_exists('PolyTrans_Test_WpDie_Exception')) {
    class PolyTrans_Test_WpDie_Exception extends RuntimeException {}
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return 'job-test-uuid';
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration)
    {
        $GLOBALS['polytrans_async_job_store'][$key] = [
            'value' => $value,
            'expiration' => (int) $expiration,
        ];
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return $GLOBALS['polytrans_async_job_store'][$key]['value'] ?? false;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'https://example.test/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url)
    {
        return rtrim((string) $url, '?') . '?' . http_build_query($args);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'nonce-for-' . (string) $action;
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth')
    {
        return hash('sha256', (string) $data . '|' . (string) $scheme);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['polytrans_async_last_remote_post'] = [
            'url' => $url,
            'args' => $args,
        ];
        $GLOBALS['polytrans_async_remote_posts'][] = $GLOBALS['polytrans_async_last_remote_post'];

        return $GLOBALS['polytrans_async_remote_post_response'];
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim((string) $str);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = [])
    {
        throw new PolyTrans_Test_WpDie_Exception((string) $message);
    }
}

beforeEach(function () {
    $GLOBALS['polytrans_async_job_store'] = [];
    $GLOBALS['polytrans_async_last_remote_post'] = null;
    $GLOBALS['polytrans_async_remote_posts'] = [];
    $GLOBALS['polytrans_async_remote_post_response'] = ['response' => ['code' => 200]];
    $_COOKIE = [];
    $_POST = [];
});

it('dispatches async jobs into transient storage and starts loopback worker', function () {
    $jobId = AsyncJobRunner::dispatch('workflow_run', ['selected_post_id' => 42]);

    expect($jobId)->toBe('job-test-uuid');

    $stored = get_transient('polytrans_async_job_' . $jobId);
    expect($stored)->toBeArray();
    expect($stored['status'])->toBe('pending');
    expect($stored['type'])->toBe('workflow_run');
    expect($stored['params'])->toBe(['selected_post_id' => 42]);

    $loopbacks = $GLOBALS['polytrans_async_remote_posts'];
    expect($loopbacks)->toHaveCount(2);

    expect($loopbacks[0]['url'])->toContain('admin-ajax.php');
    expect($loopbacks[0]['args']['blocking'])->toBeFalse();
    expect((float) $loopbacks[0]['args']['timeout'])->toBe(0.1);
    expect($loopbacks[0]['args']['body']['action'])->toBe('polytrans_async_worker');
    expect($loopbacks[0]['args']['body']['job_id'])->toBe($jobId);

    expect($loopbacks[1]['url'])->toContain('polytrans_bg=1');
    expect($loopbacks[1]['url'])->toContain('action=async_job');
    expect($loopbacks[1]['args']['blocking'])->toBeFalse();
    expect((float) $loopbacks[1]['args']['timeout'])->toBe(0.1);

    $backgroundJobs = array_filter(
        $GLOBALS['polytrans_async_job_store'],
        static fn ($entry, $key) => str_starts_with((string) $key, 'polytrans_bg_'),
        ARRAY_FILTER_USE_BOTH
    );
    expect($backgroundJobs)->toHaveCount(1);
    $backgroundJob = array_values($backgroundJobs)[0]['value'];
    expect($backgroundJob['action'])->toBe('async_job');
    expect($backgroundJob['args']['job_id'])->toBe($jobId);
});

it('returns null when polling missing async jobs', function () {
    expect(AsyncJobRunner::poll('missing-job'))->toBeNull();
});

it('marks stale pending async jobs failed when the worker never starts', function () {
    set_transient('polytrans_async_job_stale-job', [
        'status' => 'pending',
        'type' => 'workflow_run',
        'params' => [],
        'result' => null,
        'created_at' => time() - 120,
    ], 600);

    $job = AsyncJobRunner::poll('stale-job');

    expect($job)->toBeArray();
    expect($job['status'])->toBe('failed');
    expect($job['result']['success'])->toBeFalse();
    expect($job['result']['data']['message'])->toContain('Async worker did not start');
});

it('marks stale running async jobs failed when the worker disappears', function () {
    set_transient('polytrans_async_job_stale-running', [
        'status' => 'running',
        'type' => 'workflow_run',
        'params' => [],
        'result' => null,
        'created_at' => time() - 420,
        'worker_started_at' => time() - 420,
    ], 600);

    $job = AsyncJobRunner::poll('stale-running');

    expect($job)->toBeArray();
    expect($job['status'])->toBe('failed');
    expect($job['result']['success'])->toBeFalse();
    expect($job['result']['data']['message'])->toContain('Async worker stopped before completion');
});

it('marks async jobs failed when loopback dispatch returns a WordPress error', function () {
    $GLOBALS['polytrans_async_remote_post_response'] = new WP_Error('http_request_failed', 'Loopback blocked');

    $jobId = AsyncJobRunner::dispatch('workflow_run', ['selected_post_id' => 42]);
    $stored = get_transient('polytrans_async_job_' . $jobId);

    expect($stored)->toBeArray();
    expect($stored['status'])->toBe('failed');
    expect($stored['result']['success'])->toBeFalse();
    expect($stored['result']['data']['message'])->toContain('Loopback blocked');
});

it('marks worker jobs completed and persists final status', function () {
    $jobId = AsyncJobRunner::dispatch('workflow_adjust', ['assistant_id' => 7]);
    $token = wp_hash('polytrans_async_' . $jobId);

    $_POST['job_id'] = $jobId;
    $_POST['worker_token'] = $token;

    try {
        AsyncJobRunner::executeWorker();
    } catch (PolyTrans_Test_WpDie_Exception $e) {
        // wp_die() terminates requests in production. In tests we convert it to an exception.
    }

    $stored = get_transient('polytrans_async_job_' . $jobId);
    expect($stored)->toBeArray();
    expect($stored['status'])->toBe('completed');
    expect($stored['result'])->toBeNull();
});

it('runs jobs from the background processor endpoint', function () {
    $jobId = AsyncJobRunner::dispatch('workflow_run', ['selected_post_id' => 42]);
    $token = wp_hash('polytrans_async_' . $jobId);

    AsyncJobRunner::executeBackgroundJob([
        'job_id' => $jobId,
        'worker_token' => $token,
    ]);

    $stored = get_transient('polytrans_async_job_' . $jobId);
    expect($stored)->toBeArray();
    expect($stored['status'])->toBe('completed');
    expect($stored['result'])->toBeNull();
});

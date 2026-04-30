<?php

namespace PolyTrans\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic async job system using WordPress transients + loopback HTTP requests.
 *
 * Dispatches slow work to a non-blocking loopback so the original AJAX request
 * can return a job_id immediately.  The caller then polls for completion.
 */
final class AsyncJobRunner
{
    private static bool $workerCompleted = false;

    /**
     * Dispatch an async job. Stores params in transient, fires a non-blocking loopback
     * to the worker endpoint, returns job_id immediately.
     *
     * @param string $job_type Identifier like 'workflow_run', 'workflow_evaluate', 'workflow_adjust', 'workflow_test'
     * @param array  $params   Serializable params for the job
     * @return string job_id
     */
    public static function dispatch(string $job_type, array $params): string
    {
        $job_id = wp_generate_uuid4();
        $job = [
            'status'     => 'pending',
            'type'       => $job_type,
            'params'     => $params,
            'result'     => null,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        set_transient('polytrans_async_job_' . $job_id, $job, self::jobTtl());

        // Fire non-blocking loopback to worker
        $response = wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout'   => 0.1,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => [
                'action'       => 'polytrans_async_worker',
                'job_id'       => $job_id,
                'worker_token' => self::workerToken($job_id),
            ],
            'cookies' => $_COOKIE ?? [], // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- Forward auth cookies when available; token also permits nopriv workers.
        ]);

        if (is_wp_error($response)) {
            self::failJob($job_id, $job, 'Async worker dispatch failed: ' . $response->get_error_message(), [
                'job_id' => $job_id,
                'job_type' => $job_type,
                'error' => $response->get_error_message(),
            ]);
        } else {
            self::log('Async job dispatched', 'info', [
                'job_id' => $job_id,
                'job_type' => $job_type,
            ]);
        }

        return $job_id;
    }

    /**
     * Get job status/result from transient.
     *
     * @return array|null Job data with 'status' (pending|running|completed|failed), 'result', etc.
     */
    public static function poll(string $job_id): ?array
    {
        $job = get_transient('polytrans_async_job_' . $job_id);
        if (!is_array($job)) {
            return null;
        }

        if (($job['status'] ?? '') === 'pending') {
            $created_at = (int) ($job['created_at'] ?? 0);
            if ($created_at > 0 && (time() - $created_at) >= self::pendingStartTimeout()) {
                $job = self::failJob(
                    $job_id,
                    $job,
                    'Async worker did not start. The loopback request may be blocked, unauthenticated, or failing before the worker can mark the job as running.',
                    [
                        'job_id' => $job_id,
                        'job_type' => $job['type'] ?? '',
                        'age_seconds' => time() - $created_at,
                    ]
                );
            }
        }

        return $job;
    }

    /**
     * Called by the loopback worker endpoint. Executes the job.
     */
    public static function executeWorker(): void
    {
        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $token  = isset($_POST['worker_token']) ? sanitize_text_field(wp_unslash($_POST['worker_token'])) : '';

        if ($job_id === '' || !hash_equals(self::workerToken($job_id), $token)) {
            wp_die('', '', ['response' => 403]);
        }

        $job = get_transient('polytrans_async_job_' . $job_id);
        if (!is_array($job) || $job['status'] !== 'pending') {
            wp_die();
        }

        self::$workerCompleted = false;
        register_shutdown_function([self::class, 'handleWorkerShutdown'], $job_id);

        $job['status'] = 'running';
        $job['worker_started_at'] = time();
        $job['updated_at'] = time();
        set_transient('polytrans_async_job_' . $job_id, $job, self::jobTtl());

        self::log('Async job worker started', 'info', [
            'job_id' => $job_id,
            'job_type' => $job['type'] ?? '',
        ]);

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.set_time_limit_set_time_limit -- Best-effort for long-running async jobs; disabled on some hosts.
            @set_time_limit(300);
        }

        try {
            $result = apply_filters('polytrans_async_job_execute', null, $job['type'], $job['params']);
            $job['status'] = 'completed';
            $job['result'] = $result;
            $job['completed_at'] = time();
            $job['updated_at'] = time();
            self::$workerCompleted = true;
        } catch (\Throwable $e) {
            $job['status'] = 'failed';
            $job['result'] = ['success' => false, 'data' => ['message' => $e->getMessage()]];
            $job['failed_at'] = time();
            $job['updated_at'] = time();
            self::$workerCompleted = true;
            self::log('Async job worker failed: ' . $e->getMessage(), 'error', [
                'job_id' => $job_id,
                'job_type' => $job['type'] ?? '',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        set_transient('polytrans_async_job_' . $job_id, $job, self::jobTtl());
        wp_die();
    }

    /**
     * Shutdown handler catches fatal errors after the worker has accepted a job.
     */
    public static function handleWorkerShutdown(string $job_id): void
    {
        if (self::$workerCompleted) {
            return;
        }

        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
            return;
        }

        $job = get_transient('polytrans_async_job_' . $job_id);
        if (!is_array($job) || in_array($job['status'] ?? '', ['completed', 'failed'], true)) {
            return;
        }

        self::failJob($job_id, $job, 'Async worker fatal error: ' . ($error['message'] ?? 'unknown fatal error'), [
            'job_id' => $job_id,
            'job_type' => $job['type'] ?? '',
            'error' => $error,
        ]);
    }

    /**
     * Generate an HMAC token for worker authentication.
     */
    private static function workerToken(string $job_id): string
    {
        return wp_hash('polytrans_async_' . $job_id);
    }

    private static function failJob(string $job_id, array $job, string $message, array $context = []): array
    {
        $job['status'] = 'failed';
        $job['result'] = ['success' => false, 'data' => ['message' => $message]];
        $job['failed_at'] = time();
        $job['updated_at'] = time();
        set_transient('polytrans_async_job_' . $job_id, $job, self::jobTtl());

        self::log($message, 'error', $context);

        return $job;
    }

    private static function pendingStartTimeout(): int
    {
        $timeout = 60;
        if (function_exists('apply_filters')) {
            $timeout = (int) apply_filters('polytrans_async_job_pending_start_timeout', $timeout);
        }

        return max(5, $timeout);
    }

    private static function jobTtl(): int
    {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        return 30 * $minute;
    }

    private static function log(string $message, string $level = 'info', array $context = []): void
    {
        $context['source'] = $context['source'] ?? 'async_job_runner';

        try {
            LogsManager::log($message, $level, $context);
        } catch (\Throwable $e) {
            $context_string = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[polytrans] [' . $level . '] ' . $message . ' | Context: ' . $context_string);
        }
    }
}

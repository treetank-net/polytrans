<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

declare(strict_types=1);

namespace PolyTrans\Workflows;

use PolyTrans\Workflows\Context\VirtualWorkflowContext;
use PolyTrans\Workflows\Context\DatabaseWorkflowContext;
use PolyTrans\Core\LogsManager;

/**
 * Bridge between new Workflow system and legacy PostProcessing system.
 *
 * Provides integration points for:
 * - Virtual workflow execution (Translation Only mode)
 * - Database workflow execution (normal mode)
 * - Compatibility with legacy WorkflowManager
 *
 * @since 1.8.0
 */
class WorkflowBridge
{
    /**
     * @var self|null Singleton instance
     */
    private static ?self $instance = null;

    /**
     * @var WorkflowRunner Runner instance
     */
    private WorkflowRunner $runner;

    /**
     * @var bool Whether to use new workflow system
     */
    private bool $enabled = true;

    /**
     * Private constructor (singleton).
     */
    private function __construct()
    {
        $this->runner = new WorkflowRunner();
        $this->register_hooks();
    }

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register WordPress hooks for integration.
     */
    private function register_hooks(): void
    {
        // Hook for virtual workflow execution before dispatch
        add_filter('polytrans_before_dispatch_payload', [$this, 'process_virtual_workflows'], 10, 3);

        // Action for processing workflows on payload
        add_action('polytrans_process_virtual_workflows', [$this, 'do_process_virtual_workflows'], 10, 4);
    }

    /**
     * Process virtual workflows on translation payload before dispatch.
     *
     * Called via filter 'polytrans_before_dispatch_payload'.
     *
     * @param array $payload Translation payload
     * @param string $source_lang Source language
     * @param string $target_lang Target language
     * @return array Modified payload
     */
    public function process_virtual_workflows(array $payload, string $source_lang, string $target_lang): array
    {
        if (!$this->enabled) {
            return $payload;
        }

        $settings = get_option('polytrans_settings', []);

        // Check if virtual workflows are enabled for this mode
        $enable_virtual_workflows = $settings['enable_virtual_workflows'] ?? false;
        if (!$enable_virtual_workflows) {
            return $payload;
        }

        // Get workflows for target language
        $workflows = $this->get_workflows_for_language($target_lang);
        if (empty($workflows)) {
            return $payload;
        }

        // Check virtual compatibility and filter
        $compatible_workflows = $this->filter_virtual_compatible($workflows);
        if (empty($compatible_workflows)) {
            LogsManager::log("No virtual-compatible workflows for {$target_lang}", "info");
            return $payload;
        }

        // Execute each compatible workflow on the payload
        foreach ($compatible_workflows as $workflow) {
            $result = $this->runner->run_virtual($payload, $workflow);

            if ($result['success']) {
                // Update payload with modified data
                $payload = $result['payload'];

                LogsManager::log("Virtual workflow '{$workflow['name']}' executed successfully", "info", [
                    'workflow_id' => $workflow['id'] ?? 'unknown',
                    'executed' => $result['execution']['stats']['executed'] ?? 0,
                    'skipped' => $result['execution']['stats']['skipped'] ?? 0,
                ]);
            } else {
                LogsManager::log("Virtual workflow '{$workflow['name']}' failed", "warning", [
                    'workflow_id' => $workflow['id'] ?? 'unknown',
                    'errors' => $result['execution']['errors'] ?? [],
                ]);
            }
        }

        // Mark workflows as executed in payload
        $payload['workflows_executed'] = true;
        $payload['workflows_executed_virtual'] = true;

        return $payload;
    }

    /**
     * Execute virtual workflows on a payload (action callback).
     *
     * @param array &$payload Translation payload (by reference)
     * @param string $source_lang Source language
     * @param string $target_lang Target language
     * @param array $options Additional options
     */
    public function do_process_virtual_workflows(
        array &$payload,
        string $source_lang,
        string $target_lang,
        array $options = []
    ): void {
        $payload = $this->process_virtual_workflows($payload, $source_lang, $target_lang);
    }

    /**
     * Execute workflows on a translation payload (virtual context).
     *
     * Call this directly when you need virtual workflow execution.
     *
     * @param array $payload Translation payload
     * @param string $target_lang Target language
     * @param array $options Options (workflow_ids to limit which workflows run)
     * @return array Result with modified payload
     */
    public function execute_virtual(array $payload, string $target_lang, array $options = []): array
    {
        $source_lang = $payload['source_language'] ?? $payload['source_lang'] ?? 'en';

        // Get workflows (optionally filtered by IDs)
        if (!empty($options['workflow_ids'])) {
            $workflows = $this->get_workflows_by_ids($options['workflow_ids']);
        } else {
            $workflows = $this->get_workflows_for_language($target_lang);
        }

        // Filter for virtual compatibility
        $compatible = $this->filter_virtual_compatible($workflows);

        if (empty($compatible)) {
            return [
                'success' => true,
                'payload' => $payload,
                'workflows_run' => 0,
                'message' => 'No compatible workflows to run',
            ];
        }

        $total_executed = 0;
        $total_skipped = 0;
        $errors = [];

        foreach ($compatible as $workflow) {
            $result = $this->runner->run_virtual($payload, $workflow);

            if ($result['success']) {
                $payload = $result['payload'];
                $total_executed += $result['execution']['stats']['executed'] ?? 0;
                $total_skipped += $result['execution']['stats']['skipped'] ?? 0;
            } else {
                $errors[] = [
                    'workflow' => $workflow['name'] ?? $workflow['id'],
                    'errors' => $result['execution']['errors'] ?? [],
                ];
            }
        }

        return [
            'success' => empty($errors),
            'payload' => $payload,
            'workflows_run' => count($compatible),
            'steps_executed' => $total_executed,
            'steps_skipped' => $total_skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Execute workflows on a WordPress post (database context).
     *
     * Alternative to legacy WorkflowManager for post-based workflows.
     *
     * @param int $post_id Translated post ID
     * @param string $target_lang Target language
     * @param array $options Options (auto_commit, workflow_ids)
     * @return array Execution result
     */
    public function execute_on_post(int $post_id, string $target_lang, array $options = []): array
    {
        // Get workflows
        if (!empty($options['workflow_ids'])) {
            $workflows = $this->get_workflows_by_ids($options['workflow_ids']);
        } else {
            $workflows = $this->get_workflows_for_language($target_lang);
        }

        if (empty($workflows)) {
            return [
                'success' => true,
                'workflows_run' => 0,
                'message' => 'No workflows configured for this language',
            ];
        }

        $auto_commit = $options['auto_commit'] ?? true;
        $source_lang = $options['source_language'] ?? '';

        $total_executed = 0;
        $total_skipped = 0;
        $errors = [];

        foreach ($workflows as $workflow) {
            $result = $this->runner->run_on_post($post_id, $workflow, [
                'auto_commit' => $auto_commit,
                'source_language' => $source_lang,
                'target_language' => $target_lang,
            ]);

            if ($result['success']) {
                $total_executed += $result['execution']['stats']['executed'] ?? 0;
                $total_skipped += $result['execution']['stats']['skipped'] ?? 0;
            } else {
                $errors[] = [
                    'workflow' => $workflow['name'] ?? $workflow['id'],
                    'errors' => $result['execution']['errors'] ?? [],
                ];
            }
        }

        return [
            'success' => empty($errors),
            'workflows_run' => count($workflows),
            'steps_executed' => $total_executed,
            'steps_skipped' => $total_skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Check if a workflow is compatible with virtual context.
     *
     * @param array $workflow Workflow definition
     * @return array Compatibility info
     */
    public function check_workflow_compatibility(array $workflow): array
    {
        return $this->runner->check_virtual_compatibility($workflow);
    }

    /**
     * Get available steps info for UI.
     *
     * @param bool $external_only Only external-compatible steps
     * @return array Steps metadata
     */
    public function get_available_steps(bool $external_only = false): array
    {
        return $this->runner->get_available_steps($external_only);
    }

    /**
     * Enable or disable the new workflow system.
     *
     * @param bool $enabled Whether to enable
     */
    public function set_enabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if new workflow system is enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get workflows for a specific language.
     *
     * @param string $language Language code
     * @return array Workflows
     */
    private function get_workflows_for_language(string $language): array
    {
        // Use legacy storage manager if available
        if (class_exists(\PolyTrans\PostProcessing\Managers\WorkflowStorageManager::class)) {
            $storage = new \PolyTrans\PostProcessing\Managers\WorkflowStorageManager();
            return $storage->get_workflows_for_language($language);
        }

        return [];
    }

    /**
     * Get workflows by IDs.
     *
     * @param array $ids Workflow IDs
     * @return array Workflows
     */
    private function get_workflows_by_ids(array $ids): array
    {
        if (class_exists(\PolyTrans\PostProcessing\Managers\WorkflowStorageManager::class)) {
            $storage = new \PolyTrans\PostProcessing\Managers\WorkflowStorageManager();
            $workflows = [];

            foreach ($ids as $id) {
                $workflow = $storage->get_workflow($id);
                if ($workflow) {
                    $workflows[] = $workflow;
                }
            }

            return $workflows;
        }

        return [];
    }

    /**
     * Filter workflows to only virtual-compatible ones.
     *
     * @param array $workflows Workflows to filter
     * @return array Compatible workflows
     */
    private function filter_virtual_compatible(array $workflows): array
    {
        $compatible = [];

        foreach ($workflows as $workflow) {
            // Check if workflow is enabled
            if (!($workflow['enabled'] ?? true)) {
                continue;
            }

            // Check workflow compatibility
            $check = $this->runner->check_virtual_compatibility($workflow);

            if ($check['compatible']) {
                $compatible[] = $workflow;
            } else {
                LogsManager::log("Workflow '{$workflow['name']}' not virtual-compatible", "debug", [
                    'workflow_id' => $workflow['id'] ?? 'unknown',
                    'incompatible_steps' => $check['incompatible_steps'],
                ]);
            }
        }

        return $compatible;
    }

    /**
     * Reset singleton (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

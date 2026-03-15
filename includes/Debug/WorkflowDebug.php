<?php

/**
 * Debug script for PolyTrans Workflow Triggering
 * 
 * This script helps debug why workflows are not triggering after translation completion.
 * Run this from WordPress admin or CLI to check workflow conditions.
 */

namespace PolyTrans\Debug;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WorkflowDebug
{
    /**
     * Debug workflow triggering for a specific post and language
     * 
     * @param int $original_post_id The original post ID
     * @param int $translated_post_id The translated post ID
     * @param string $target_language The target language
     */
    public static function debug_workflow_triggering($original_post_id, $translated_post_id, $target_language)
    {
        echo "<h2>PolyTrans Workflow Debug Report</h2>\n";

        echo "<p><strong>Original Post ID:</strong> " . esc_html($original_post_id) . "</p>\n";

        echo "<p><strong>Translated Post ID:</strong> " . esc_html($translated_post_id) . "</p>\n";

        echo "<p><strong>Target Language:</strong> " . esc_html($target_language) . "</p>\n";

        // Check if workflow manager exists
        if (!class_exists('\PolyTrans_Workflow_Manager')) {
            echo "<div style='color: red;'><strong>ERROR:</strong> PolyTrans_Workflow_Manager class not found. Make sure the workflow system is properly loaded.</div>\n";
            return;
        }

        // Get workflow manager instance
        global $polytrans_workflow_manager;
        if (!$polytrans_workflow_manager) {
            echo "<div style='color: red;'><strong>ERROR:</strong> Workflow manager not initialized.</div>\n";
            return;
        }

        // Get all workflows
        $workflows = get_option('polytrans_workflows', []);
        echo "<h3>Installed Workflows</h3>\n";

        echo "<p>Total workflows: " . count($workflows) . "</p>\n";

        if (empty($workflows)) {
            echo "<div style='color: orange;'><strong>WARNING:</strong> No workflows found. Create a workflow first.</div>\n";
            return;
        }

        // Create context for debugging
        $context = [
            'original_post_id' => $original_post_id,
            'translated_post_id' => $translated_post_id,
            'target_language' => $target_language,
            'trigger' => 'translation_completed'
        ];

        // Check each workflow
        foreach ($workflows as $workflow_id => $workflow) {
            self::debug_single_workflow($workflow, $context, $workflow_id);
        }

        // Check post meta
        self::debug_post_meta($original_post_id, $target_language);

        // Check hooks
        self::debug_hooks();
    }

    /**
     * Debug a single workflow
     */
    private static function debug_single_workflow($workflow, $context, $workflow_id)
    {
        $workflow_name = $workflow['name'] ?? 'Unknown';

        echo "<h4>Workflow: " . esc_html($workflow_name) . " (ID: " . esc_html($workflow_id) . ")</h4>\n";

        // Check if enabled
        $enabled = isset($workflow['enabled']) && $workflow['enabled'];

        echo "<p><strong>Enabled:</strong> " . ($enabled ? "✅ Yes" : "❌ No") . "</p>\n";

        if (!$enabled) {
            echo "<div style='color: orange;'>Workflow is disabled. Enable it in the workflow settings.</div>\n";
            return;
        }

        // Check target languages
        $target_languages = $workflow['target_languages'] ?? [];

        echo "<p><strong>Target Languages:</strong> " . esc_html(implode(', ', $target_languages)) . "</p>\n";

        $language_match = empty($target_languages) || in_array($context['target_language'], $target_languages);

        echo "<p><strong>Language Match:</strong> " . ($language_match ? "✅ Yes" : "❌ No") . "</p>\n";

        if (!$language_match) {
    
            echo "<div style='color: orange;'>Target language '" . esc_html($context['target_language']) . "' is not in the workflow's target languages.</div>\n";
        }

        // Check triggers
        $triggers = $workflow['triggers'] ?? [];
        echo "<p><strong>Triggers:</strong></p>\n";
        echo "<ul>\n";

        echo "<li>on_translation_complete: " . (isset($triggers['on_translation_complete']) && $triggers['on_translation_complete'] ? "✅ Enabled" : "❌ Disabled") . "</li>\n";

        echo "<li>manual_only: " . (isset($triggers['manual_only']) && $triggers['manual_only'] ? "❌ Manual Only" : "✅ Auto Execute") . "</li>\n";
        echo "</ul>\n";

        // Check trigger conditions
        $on_translation_complete = isset($triggers['on_translation_complete']) && $triggers['on_translation_complete'];
        $manual_only = isset($triggers['manual_only']) && $triggers['manual_only'];

        if (!$on_translation_complete) {
            echo "<div style='color: orange;'>Workflow does not have 'on_translation_complete' trigger enabled.</div>\n";
        }

        if ($manual_only) {
            echo "<div style='color: orange;'>Workflow is set to 'manual_only' - it will not auto-execute.</div>\n";
        }

        // Check conditions
        if (isset($triggers['conditions']) && !empty($triggers['conditions'])) {
            echo "<p><strong>Additional Conditions:</strong></p>\n";
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug output
            echo "<pre>" . esc_html(print_r($triggers['conditions'], true)) . "</pre>\n";

            global $polytrans_workflow_manager;
            $reflection = new \ReflectionClass($polytrans_workflow_manager);
            $method = $reflection->getMethod('evaluate_workflow_conditions');
            $method->setAccessible(true);

            try {
                $conditions_met = $method->invoke($polytrans_workflow_manager, $triggers['conditions'], $context);
        
                echo "<p><strong>Conditions Met:</strong> " . ($conditions_met ? "✅ Yes" : "❌ No") . "</p>\n";
            } catch (\Exception $e) {
        
                echo "<div style='color: red;'>Error evaluating conditions: " . esc_html($e->getMessage()) . "</div>\n";
            }
        } else {
            echo "<p><strong>Additional Conditions:</strong> None</p>\n";
        }

        // Overall assessment
        $would_execute = $enabled && $language_match && $on_translation_complete && !$manual_only;

        echo "<p><strong>Would Execute:</strong> " . ($would_execute ? "✅ Yes" : "❌ No") . "</p>\n";

        if (!$would_execute) {
            echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7;'>";
            echo "<strong>Issues preventing execution:</strong><ul>";
            if (!$enabled) echo "<li>Workflow is disabled</li>";
            if (!$language_match) echo "<li>Target language doesn't match</li>";
            if (!$on_translation_complete) echo "<li>'on_translation_complete' trigger is disabled</li>";
            if ($manual_only) echo "<li>Workflow is set to manual execution only</li>";
            echo "</ul></div>\n";
        }

        echo "<hr>\n";
    }

    /**
     * Debug post meta
     */
    private static function debug_post_meta($post_id, $target_language)
    {
        echo "<h3>Post Meta Debug</h3>\n";

        $status_key = '_polytrans_translation_status_' . $target_language;
        $completion_key = '_polytrans_translation_completed_' . $target_language;
        $post_id_key = '_polytrans_translation_post_id_' . $target_language;

        $status = get_post_meta($post_id, $status_key, true);
        $completion_time = get_post_meta($post_id, $completion_key, true);
        $translated_post_id = get_post_meta($post_id, $post_id_key, true);


        echo "<p><strong>Translation Status:</strong> " . esc_html($status ?: 'Not set') . "</p>\n";

        echo "<p><strong>Completion Time:</strong> " . esc_html($completion_time ? gmdate('Y-m-d H:i:s', $completion_time) : 'Not set') . "</p>\n";

        echo "<p><strong>Translated Post ID:</strong> " . esc_html($translated_post_id ?: 'Not set') . "</p>\n";

        if ($status !== 'completed') {
            echo "<div style='color: orange;'><strong>WARNING:</strong> Translation status is not 'completed'. This may indicate the translation process hasn't finished properly.</div>\n";
        }
    }

    /**
     * Debug WordPress hooks
     */
    private static function debug_hooks()
    {
        echo "<h3>Hook Debug</h3>\n";

        // Check if the hook is registered
        global $wp_filter;

        if (isset($wp_filter['polytrans_translation_completed'])) {
            $callbacks = $wp_filter['polytrans_translation_completed']->callbacks;
            echo "<p><strong>polytrans_translation_completed hook:</strong> ✅ Registered</p>\n";
    
            echo "<p><strong>Callbacks:</strong> " . esc_html(count($callbacks)) . "</p>\n";

            foreach ($callbacks as $priority => $functions) {
                echo "<ul>";
                foreach ($functions as $function_data) {
                    $callback = $function_data['function'];
                    if (is_array($callback)) {
                        $callback_name = is_object($callback[0]) ? get_class($callback[0]) . '::' . $callback[1] : $callback[0] . '::' . $callback[1];
                    } else {
                        $callback_name = $callback;
                    }
            
                    echo "<li>Priority " . esc_html($priority) . ": " . esc_html($callback_name) . "</li>\n";
                }
                echo "</ul>";
            }
        } else {
            echo "<p><strong>polytrans_translation_completed hook:</strong> ❌ Not registered</p>\n";
            echo "<div style='color: red;'><strong>ERROR:</strong> The workflow manager is not listening for translation completion events.</div>\n";
        }
    }

    /**
     * Simulate a workflow trigger for testing
     */
    public static function simulate_workflow_trigger($original_post_id, $translated_post_id, $target_language)
    {
        echo "<h3>Simulating Workflow Trigger</h3>\n";

        // Log before triggering
        \PolyTrans_Logs_Manager::log("Simulating workflow trigger", 'info', [
            'source' => 'debug',
            'original_post_id' => $original_post_id,
            'translated_post_id' => $translated_post_id,
            'target_language' => $target_language
        ]);

        echo "<p>Firing polytrans_translation_completed action...</p>\n";

        // Fire the action
        do_action('polytrans_translation_completed', $original_post_id, $translated_post_id, $target_language);

        echo "<p>✅ Action fired. Check the logs for workflow execution details.</p>\n";
    }
}

// Usage example (uncomment to test):
/*
// Debug specific translation
PolyTrans_Workflow_Debug::debug_workflow_triggering(123, 456, 'en');

// Simulate workflow trigger
PolyTrans_Workflow_Debug::simulate_workflow_trigger(123, 456, 'en');
*/

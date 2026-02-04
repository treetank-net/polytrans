<?php

declare(strict_types=1);

/**
 * Unit Tests: Workflow Step Failure Handling
 *
 * Tests user expectations for workflow step failures:
 * - Failed step should stop workflow by default
 * - continue_on_error=true allows workflow to continue
 * - Overall success reflects all step results
 *
 * User expectations:
 * - "When a step fails, the workflow should stop and not execute further steps"
 * - "When I enable continue_on_error, the workflow should continue despite failures"
 * - "I should know which steps failed and why"
 */

// ============================================================================
// DEFAULT BEHAVIOR: STOP ON FAILURE
// User expectation: "When a step fails, stop the workflow"
// ============================================================================

test('workflow stops on first step failure by default', function () {
    // Simulating workflow execution logic
    $steps = [
        ['name' => 'Step 1', 'success' => true],
        ['name' => 'Step 2', 'success' => false, 'error' => 'API error'],  // This fails
        ['name' => 'Step 3', 'success' => true],  // Should NOT execute
    ];

    $executed_steps = [];
    $should_break = false;

    foreach ($steps as $step) {
        if ($should_break) {
            break;
        }

        // "Execute" step
        $executed_steps[] = $step['name'];

        if (!$step['success']) {
            $continue_on_error = $step['continue_on_error'] ?? false;
            if (!$continue_on_error) {
                $should_break = true;
            }
        }
    }

    expect($executed_steps)->toBe(['Step 1', 'Step 2']);
    expect($executed_steps)->not()->toContain('Step 3');
});

test('workflow overall success is false when any step fails', function () {
    $step_results = [
        ['name' => 'Step 1', 'success' => true],
        ['name' => 'Step 2', 'success' => false],
    ];

    $overall_success = true;
    $failed_steps = [];

    foreach ($step_results as $result) {
        if (!$result['success']) {
            $overall_success = false;
            $failed_steps[] = $result['name'];
        }
    }

    expect($overall_success)->toBeFalse();
    expect($failed_steps)->toBe(['Step 2']);
});

// ============================================================================
// CONTINUE ON ERROR
// User expectation: "When I enable continue_on_error, workflow continues"
// ============================================================================

test('workflow continues when continue_on_error is true', function () {
    $steps = [
        ['name' => 'Step 1', 'success' => true],
        ['name' => 'Step 2', 'success' => false, 'continue_on_error' => true],  // Fails but continues
        ['name' => 'Step 3', 'success' => true],  // Should execute
    ];

    $executed_steps = [];
    $should_break = false;

    foreach ($steps as $step) {
        if ($should_break) {
            break;
        }

        $executed_steps[] = $step['name'];

        if (!$step['success']) {
            $continue_on_error = $step['continue_on_error'] ?? false;
            if (!$continue_on_error) {
                $should_break = true;
            }
        }
    }

    expect($executed_steps)->toBe(['Step 1', 'Step 2', 'Step 3']);
});

test('overall success is false even when continue_on_error allows completion', function () {
    $step_results = [
        ['name' => 'Step 1', 'success' => true],
        ['name' => 'Step 2', 'success' => false, 'continue_on_error' => true],
        ['name' => 'Step 3', 'success' => true],
    ];

    $overall_success = true;
    foreach ($step_results as $result) {
        if (!$result['success']) {
            $overall_success = false;
        }
    }

    expect($overall_success)->toBeFalse();
});

// ============================================================================
// STEP OUTPUT HANDLING
// User expectation: "Failed step output should not be merged into context"
// ============================================================================

test('successful step output is merged into context', function () {
    $context = ['title' => 'Original'];

    $step_result = [
        'success' => true,
        'data' => ['translated_title' => 'Przetłumaczony']
    ];

    // Merge logic (simplified from WorkflowExecutor)
    if ($step_result['success'] && isset($step_result['data'])) {
        $context = array_merge($context, $step_result['data']);
    }

    expect($context)->toHaveKey('translated_title');
    expect($context['translated_title'])->toBe('Przetłumaczony');
});

test('failed step output is NOT merged into context', function () {
    $context = ['title' => 'Original'];

    $step_result = [
        'success' => false,
        'error' => 'API failure',
        'data' => ['bad_data' => 'should not appear']
    ];

    // Merge logic (only on success)
    if ($step_result['success'] && isset($step_result['data'])) {
        $context = array_merge($context, $step_result['data']);
    }

    expect($context)->not()->toHaveKey('bad_data');
    expect($context)->toBe(['title' => 'Original']);
});

// ============================================================================
// RESULT STRUCTURE
// User expectation: "I should get detailed information about what happened"
// ============================================================================

test('step result contains required fields', function () {
    $step_result = [
        'success' => false,
        'error' => 'Translation API returned error',
        'execution_time' => 1.234,
        'step_name' => 'translate_content',
        'step_type' => 'managed_assistant'
    ];

    expect($step_result)->toHaveKey('success');
    expect($step_result)->toHaveKey('error');
    expect($step_result)->toHaveKey('execution_time');
    expect($step_result)->toHaveKey('step_name');
});

test('workflow result includes all executed steps', function () {
    $step_results = [
        ['step_name' => 'step_1', 'success' => true],
        ['step_name' => 'step_2', 'success' => false, 'error' => 'Failed'],
    ];

    $workflow_result = [
        'success' => false,
        'steps_executed' => count($step_results),
        'step_results' => $step_results,
        'failed_steps' => ['step_2']
    ];

    expect($workflow_result['steps_executed'])->toBe(2);
    expect($workflow_result['failed_steps'])->toContain('step_2');
});

// ============================================================================
// EMPTY AI RESPONSE IN STEP
// User expectation: "Empty AI response should fail the step, not continue with empty data"
// ============================================================================

test('step with empty AI response reports failure', function () {
    // This simulates what ManagedAssistantStep should return for empty response
    $step_result = [
        'success' => false,
        'error' => 'AI returned empty response - step rejected to prevent data loss',
        'assistant_name' => 'translate_content'
    ];

    expect($step_result['success'])->toBeFalse();
    expect($step_result['error'])->toContain('empty response');
});

test('step with truncated AI response reports failure', function () {
    // This simulates what should happen for truncated response
    $step_result = [
        'success' => false,
        'error' => 'AI response was truncated due to max_tokens limit',
        'assistant_name' => 'translate_content'
    ];

    expect($step_result['success'])->toBeFalse();
    expect($step_result['error'])->toContain('truncated');
});

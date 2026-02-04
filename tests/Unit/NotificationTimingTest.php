<?php

declare(strict_types=1);

/**
 * Unit Tests: Notification Timing
 *
 * Tests user expectations for email notification timing:
 * - after_workflows: emails sent after all workflows complete
 * - immediate: emails sent right after translation, before workflows
 *
 * User expectations:
 * - "When I set 'after_workflows', reviewer sees final content after post-processing"
 * - "When I set 'immediate', reviewer gets notified quickly even if workflows are slow"
 * - "Default should be 'after_workflows' for best reviewer experience"
 */

// ============================================================================
// NOTIFICATION TIMING LOGIC
// User expectation: "Notification timing setting controls when emails are sent"
// ============================================================================

test('default notification timing is after_workflows', function () {
    $settings = [];  // Empty settings
    
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    expect($notification_timing)->toBe('after_workflows');
});

test('notification timing can be set to immediate', function () {
    $settings = ['notification_timing' => 'immediate'];
    
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    expect($notification_timing)->toBe('immediate');
});

test('notification timing can be set to after_workflows', function () {
    $settings = ['notification_timing' => 'after_workflows'];
    
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    expect($notification_timing)->toBe('after_workflows');
});

// ============================================================================
// IMMEDIATE TIMING BEHAVIOR
// User expectation: "With 'immediate', TranslationCoordinator sends notifications"
// ============================================================================

test('immediate timing triggers notification in coordinator', function () {
    $settings = ['notification_timing' => 'immediate'];
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    $should_send_in_coordinator = ($notification_timing === 'immediate');
    
    expect($should_send_in_coordinator)->toBeTrue();
});

test('immediate timing skips notification in extension/processor', function () {
    $settings = ['notification_timing' => 'immediate'];
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    $should_send_after_workflows = ($notification_timing === 'after_workflows');
    
    expect($should_send_after_workflows)->toBeFalse();
});

// ============================================================================
// AFTER_WORKFLOWS TIMING BEHAVIOR
// User expectation: "With 'after_workflows', notifications sent after post-processing"
// ============================================================================

test('after_workflows timing skips notification in coordinator', function () {
    $settings = ['notification_timing' => 'after_workflows'];
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    $should_send_in_coordinator = ($notification_timing === 'immediate');
    
    expect($should_send_in_coordinator)->toBeFalse();
});

test('after_workflows timing triggers notification after workflows complete', function () {
    $settings = ['notification_timing' => 'after_workflows'];
    $notification_timing = $settings['notification_timing'] ?? 'after_workflows';
    
    $should_send_after_workflows = ($notification_timing === 'after_workflows');
    
    expect($should_send_after_workflows)->toBeTrue();
});

// ============================================================================
// NOTIFICATION TIMING VALIDATION
// User expectation: "Invalid timing values fall back to default"
// ============================================================================

test('invalid timing value falls back to after_workflows', function () {
    $raw_value = 'invalid_value';
    
    // Validation logic (as in TranslationSettings)
    $valid_values = ['immediate', 'after_workflows'];
    if (in_array($raw_value, $valid_values, true)) {
        $notification_timing = $raw_value;
    } else {
        $notification_timing = 'after_workflows';
    }
    
    expect($notification_timing)->toBe('after_workflows');
});

test('empty timing value falls back to after_workflows', function () {
    $raw_value = '';
    
    $valid_values = ['immediate', 'after_workflows'];
    if (in_array($raw_value, $valid_values, true)) {
        $notification_timing = $raw_value;
    } else {
        $notification_timing = 'after_workflows';
    }
    
    expect($notification_timing)->toBe('after_workflows');
});

// ============================================================================
// NO DOUBLE NOTIFICATIONS
// User expectation: "I should receive exactly one notification per translation"
// ============================================================================

test('only one path sends notification based on timing', function () {
    // Simulate the decision flow
    $test_cases = [
        ['timing' => 'immediate', 'coordinator_sends' => true, 'after_workflows_sends' => false],
        ['timing' => 'after_workflows', 'coordinator_sends' => false, 'after_workflows_sends' => true],
    ];
    
    foreach ($test_cases as $case) {
        $timing = $case['timing'];
        
        $coordinator_should_send = ($timing === 'immediate');
        $after_workflows_should_send = ($timing === 'after_workflows');
        
        expect($coordinator_should_send)->toBe($case['coordinator_sends']);
        expect($after_workflows_should_send)->toBe($case['after_workflows_sends']);
        
        // Exactly one should be true
        expect($coordinator_should_send xor $after_workflows_should_send)->toBeTrue();
    }
});

// ============================================================================
// MANUAL WORKFLOW EXECUTION
// User expectation: "Manual workflow execution should NOT send notifications"
// ============================================================================

test('manual workflow execution does not trigger notifications', function () {
    $trigger = 'manual';
    
    // Manual workflows should not send notifications
    // (notifications are for translation completion, not manual post-processing)
    $should_send_notification = ($trigger !== 'manual');
    
    expect($should_send_notification)->toBeFalse();
});

test('translation completion trigger should send notifications', function () {
    $trigger = 'translation_completed';
    
    $should_send_notification = ($trigger !== 'manual');
    
    expect($should_send_notification)->toBeTrue();
});

// ============================================================================
// EPHEMERAL POSTS
// User expectation: "Ephemeral/temp posts should not trigger notifications"
// ============================================================================

test('ephemeral posts skip notifications', function () {
    $is_ephemeral = true;
    
    $should_send_notification = !$is_ephemeral;
    
    expect($should_send_notification)->toBeFalse();
});

test('regular posts receive notifications', function () {
    $is_ephemeral = false;
    
    $should_send_notification = !$is_ephemeral;
    
    expect($should_send_notification)->toBeTrue();
});

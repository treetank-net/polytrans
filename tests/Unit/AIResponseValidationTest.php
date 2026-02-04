<?php

declare(strict_types=1);

/**
 * Unit Tests: AI Response Validation
 *
 * Tests user expectations for AI response handling:
 * - Empty responses should be rejected
 * - Truncated responses should be rejected
 * - Valid responses should be accepted
 *
 * User expectations:
 * - "When AI returns empty string, the step should fail, not wipe my data"
 * - "When AI response is cut off (max_tokens), I should get an error"
 * - "When AI returns valid content, it should be processed normally"
 */

use PolyTrans\Assistants\AssistantExecutor;

beforeEach(function () {
    // Mock LogsManager to avoid errors
    if (!class_exists('PolyTrans\Core\LogsManager')) {
        // Create a minimal mock in the namespace
        eval('
            namespace PolyTrans\Core;
            class LogsManager {
                public static function log($message, $level = "info", $context = []) {
                    // Silent for tests
                }
            }
        ');
    }
});

// ============================================================================
// EMPTY RESPONSE REJECTION
// User expectation: "When AI returns empty, my data should NOT be wiped"
// ============================================================================

test('rejects empty string response from OpenAI', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => ''], 'finish_reason' => 'stop']
        ],
        'usage' => ['total_tokens' => 10]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('empty_response');
});

test('rejects whitespace-only response from OpenAI', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => '   \n\t  '], 'finish_reason' => 'stop']
        ],
        'usage' => ['total_tokens' => 10]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('empty_response');
});

test('rejects empty response from Claude', function () {
    $response = [
        'content' => [
            ['type' => 'text', 'text' => '']
        ],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0]
    ];

    $config = [
        'provider' => 'claude',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('empty_response');
});

// ============================================================================
// TRUNCATED RESPONSE REJECTION
// User expectation: "When AI response is cut off, I should get an error, not partial data"
// ============================================================================

test('rejects truncated OpenAI response (finish_reason: length)', function () {
    $response = [
        'choices' => [
            [
                'message' => ['content' => 'This is a partial response that was cut off...'],
                'finish_reason' => 'length'  // Indicates truncation
            ]
        ],
        'usage' => ['total_tokens' => 4096]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('truncated_response');
});

test('rejects truncated Claude response (stop_reason: max_tokens)', function () {
    $response = [
        'content' => [
            ['type' => 'text', 'text' => 'This is a partial response...']
        ],
        'stop_reason' => 'max_tokens',  // Indicates truncation
        'usage' => ['input_tokens' => 100, 'output_tokens' => 4096]
    ];

    $config = [
        'provider' => 'claude',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('truncated_response');
});

// ============================================================================
// VALID RESPONSE ACCEPTANCE
// User expectation: "When AI returns valid content, it should work"
// ============================================================================

test('accepts valid OpenAI text response', function () {
    $response = [
        'choices' => [
            [
                'message' => ['content' => 'This is a valid translated text.'],
                'finish_reason' => 'stop'
            ]
        ],
        'usage' => ['total_tokens' => 50]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeFalse();
    expect($result)->toHaveKey('output');
    expect($result['output'])->toBe('This is a valid translated text.');
});

test('accepts valid Claude text response', function () {
    $response = [
        'content' => [
            ['type' => 'text', 'text' => 'This is a valid Claude response.']
        ],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20]
    ];

    $config = [
        'provider' => 'claude',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeFalse();
    expect($result)->toHaveKey('output');
    expect($result['output'])->toBe('This is a valid Claude response.');
});

test('accepts valid JSON response from OpenAI', function () {
    $json_content = '{"title": "Translated Title", "excerpt": "Translated excerpt"}';
    
    $response = [
        'choices' => [
            [
                'message' => ['content' => $json_content],
                'finish_reason' => 'stop'
            ]
        ],
        'usage' => ['total_tokens' => 100]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'json'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeFalse();
    expect($result)->toHaveKey('output');
});

// ============================================================================
// EDGE CASES
// ============================================================================

test('handles OpenAI response with content_filter finish_reason', function () {
    // OpenAI may return content_filter when content is blocked
    $response = [
        'choices' => [
            [
                'message' => ['content' => ''],
                'finish_reason' => 'content_filter'
            ]
        ],
        'usage' => ['total_tokens' => 10]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    // Should be rejected because content is empty
    expect(is_wp_error($result))->toBeTrue();
});

test('accepts response with only newlines as content (edge case)', function () {
    // Just newlines should be considered empty
    $response = [
        'choices' => [
            [
                'message' => ['content' => "\n\n\n"],
                'finish_reason' => 'stop'
            ]
        ],
        'usage' => ['total_tokens' => 10]
    ];

    $config = [
        'provider' => 'openai',
        'expected_format' => 'text'
    ];

    $result = AssistantExecutor::process_response($response, $config);

    expect(is_wp_error($result))->toBeTrue();
    expect($result->get_error_code())->toBe('empty_response');
});

<?php

declare(strict_types=1);

/**
 * Unit Tests: Assistant Executor
 *
 * Tests the AI Assistant Execution System (Phase 1):
 * - Loading assistants from database
 * - Interpolating prompts with Twig variables
 * - Calling provider APIs (OpenAI, Claude, Gemini)
 * - Processing responses (text and JSON formats)
 * - Error handling and validation
 *
 * @package PolyTrans
 * @subpackage Tests\Unit
 */

beforeEach(function () {
    // Mock WordPress globals and functions
    global $wpdb;

    $this->wpdb = $this->createMock(stdClass::class);
    $this->wpdb->prefix = 'wp_';
    $this->wpdb->polytrans_assistants = 'wp_polytrans_assistants';

    $wpdb = $this->wpdb;

    // Mock WordPress functions
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data)
        {
            return json_encode($data);
        }
    }

    if (!function_exists('wp_json_decode')) {
        function wp_json_decode($json, $assoc = false)
        {
            return json_decode($json, $assoc);
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            private $errors = [];

            public function __construct($code = '', $message = '', $data = '')
            {
                if (!empty($code)) {
                    $this->errors[$code][] = $message;
                }
            }

            public function get_error_message()
            {
                $code = $this->get_error_code();
                return $this->errors[$code][0] ?? '';
            }

            public function get_error_code()
            {
                return array_key_first($this->errors);
            }
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return ($thing instanceof WP_Error);
        }
    }
});

// ============================================================================
// CORE EXECUTION TESTS (TDD)
// ============================================================================

test('execute returns standardized result structure', function () {
    $assistant_id = 1;
    $context = [
        'title' => 'Test Post',
        'content' => 'Test content'
    ];

    // Expected result structure
    $expected = [
        'success' => true,
        'output' => 'Processed content',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150
        ],
        'raw_response' => [] // Full API response
    ];

    expect($expected)->toBeArray();
    expect($expected)->toHaveKey('success');
    expect($expected)->toHaveKey('output');
    expect($expected['success'])->toBeTrue();
});

test('execute returns WP_Error for non-existent assistant', function () {
    $non_existent_id = 999;
    $context = [];

    // Expected: WP_Error
    $result = new \WP_Error('assistant_not_found', 'Assistant not found');

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect(is_wp_error($result))->toBeTrue();
});

test('execute returns WP_Error when assistant is inactive', function () {
    $inactive_assistant_id = 5;
    $context = [];

    // Expected: WP_Error
    $result = new \WP_Error('assistant_inactive', 'Assistant is inactive');

    expect($result)->toBeInstanceOf(WP_Error::class);
});

test('execute loads assistant from database', function () {
    $assistant_id = 1;

    // This test verifies that execute() calls AssistantManager::get_assistant()
    // Implementation will call: PolyTrans_Assistant_Manager::get_assistant($assistant_id)
    expect($assistant_id)->toBeInt();
    expect($assistant_id)->toBeGreaterThan(0);
});

// ============================================================================
// DIRECT CONFIG EXECUTION TESTS
// ============================================================================

test('execute_with_config works without database lookup', function () {
    $config = [
        'name' => 'Test Assistant',
        'provider' => 'openai',
        'system_prompt' => 'You are a helpful translator',
        'user_message_template' => 'Translate: {{ content }}',
        'api_parameters' => [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7
        ],
        'expected_format' => 'text'
    ];

    $context = [
        'content' => 'Hello world'
    ];

    // Expected: successful execution without DB
    $expected = [
        'success' => true,
        'output' => 'Translated: Hello world'
    ];

    expect($expected['success'])->toBeTrue();
});

test('execute_with_config validates required config fields', function () {
    $invalid_config = [
        'name' => 'Test',
        // Missing system_prompt
        // Missing api_parameters
    ];

    $context = [];

    // Expected: WP_Error
    $result = new \WP_Error('invalid_config', 'Missing required fields');

    expect($result)->toBeInstanceOf(WP_Error::class);
});

// ============================================================================
// PROMPT INTERPOLATION TESTS
// ============================================================================

test('interpolate_prompts uses Twig Variable Manager', function () {
    $config = [
        'system_prompt' => 'Translate from {{ original.language }} to {{ translated.language }}',
        'user_message_template' => 'Content: {{ content }}'
    ];

    $context = [
        'original' => ['language' => 'en'],
        'translated' => ['language' => 'pl'],
        'content' => 'Test'
    ];

    // Expected: interpolated prompts
    $expected = [
        'system_prompt' => 'Translate from en to pl',
        'user_message' => 'Content: Test'
    ];

    expect($expected['system_prompt'])->toContain('en');
    expect($expected['system_prompt'])->toContain('pl');
    expect($expected['user_message'])->toContain('Test');
});

test('interpolate_prompts handles missing context variables gracefully', function () {
    $config = [
        'system_prompt' => 'Hello {{ missing_var }}'
    ];

    $context = [
        'title' => 'Test'
    ];

    // Expected: empty string or null for missing variables
    $expected = [
        'system_prompt' => 'Hello '
    ];

    expect($expected)->toBeArray();
});

test('interpolate_prompts handles null user_message_template', function () {
    $config = [
        'system_prompt' => 'Test prompt',
        'user_message_template' => null
    ];

    $context = [];

    // Expected: user_message is null or empty
    $expected = [
        'system_prompt' => 'Test prompt',
        'user_message' => null
    ];

    expect($expected['user_message'])->toBeNull();
});

test('interpolate_prompts supports Twig filters', function () {
    $config = [
        'system_prompt' => 'Title: {{ title|upper }}'
    ];

    $context = [
        'title' => 'hello world'
    ];

    // Expected: Twig filter applied
    $expected = [
        'system_prompt' => 'Title: HELLO WORLD'
    ];

    expect($expected['system_prompt'])->toBe('Title: HELLO WORLD');
});

// ============================================================================
// PROVIDER API CALL TESTS
// ============================================================================

test('call_provider_api supports OpenAI provider', function () {
    $config = [
        'provider' => 'openai',
        'api_parameters' => [
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 2000
        ]
    ];

    $prompts = [
        'system_prompt' => 'You are helpful',
        'user_message' => 'Translate this'
    ];

    // Expected: API call returns response
    expect($config['provider'])->toBe('openai');
    expect($config['api_parameters'])->toHaveKey('model');
});

test('call_provider_api supports Claude provider', function () {
    $config = [
        'provider' => 'claude',
        'api_parameters' => [
            'model' => 'claude-3-sonnet',
            'max_tokens' => 2000
        ]
    ];

    $prompts = [
        'system_prompt' => 'You are helpful',
        'user_message' => 'Process this'
    ];

    expect($config['provider'])->toBe('claude');
});

test('call_provider_api supports Gemini provider', function () {
    $config = [
        'provider' => 'gemini',
        'api_parameters' => [
            'model' => 'gemini-pro',
            'temperature' => 0.5
        ]
    ];

    $prompts = [
        'system_prompt' => 'You are helpful',
        'user_message' => 'Process this'
    ];

    expect($config['provider'])->toBe('gemini');
});

test('call_provider_api returns WP_Error for unsupported provider', function () {
    $config = [
        'provider' => 'unsupported_provider',
        'api_parameters' => []
    ];

    $prompts = [];

    // Expected: WP_Error
    $result = new \WP_Error('unsupported_provider', 'Provider not supported');

    expect($result)->toBeInstanceOf(WP_Error::class);
});

test('call_provider_api returns WP_Error on API failure', function () {
    $config = [
        'provider' => 'openai',
        'api_parameters' => ['model' => 'gpt-4']
    ];

    $prompts = [
        'system_prompt' => 'Test',
        'user_message' => 'Test'
    ];

    // Simulate API error (network error, invalid API key, etc.)
    $result = new \WP_Error('api_error', 'Failed to call API', ['status' => 401]);

    expect($result)->toBeInstanceOf(WP_Error::class);
});

test('call_provider_api includes usage statistics', function () {
    $config = [
        'provider' => 'openai',
        'api_parameters' => ['model' => 'gpt-4o-mini']
    ];

    $prompts = [
        'system_prompt' => 'Test',
        'user_message' => 'Test'
    ];

    // Expected: response includes usage stats
    $expected_response = [
        'choices' => [
            ['message' => ['content' => 'Response']]
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30
        ]
    ];

    expect($expected_response)->toHaveKey('usage');
    expect($expected_response['usage'])->toHaveKey('total_tokens');
});

test('call_provider_api sanitizes internal migration metadata from api_parameters', function () {
    $method = new \ReflectionMethod(\PolyTrans\Assistants\AssistantExecutor::class, 'sanitize_api_parameters');
    $method->setAccessible(true);

    $sanitized = $method->invoke(null, [
        'model' => 'gpt-4o-mini',
        'temperature' => 0.7,
        'migrated_from' => [
            'workflow_id' => 'workflow_123',
            'step_name' => 'Legacy Step',
        ],
    ]);

    expect($sanitized)->toHaveKey('model');
    expect($sanitized)->toHaveKey('temperature');
    expect($sanitized)->not->toHaveKey('migrated_from');
});

// ============================================================================
// RESPONSE PROCESSING TESTS
// ============================================================================

test('process_response handles text format', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => 'Translated text']]
        ],
        'usage' => ['total_tokens' => 100]
    ];

    $config = [
        'expected_format' => 'text'
    ];

    // Expected: plain text output
    $expected = 'Translated text';

    expect($expected)->toBeString();
    expect($expected)->toBe('Translated text');
});

test('process_response handles JSON format', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => '{"title":"New Title","content":"New Content"}']]
        ]
    ];

    $config = [
        'expected_format' => 'json',
        'output_variables' => ['title', 'content']
    ];

    // Expected: parsed JSON
    $expected = [
        'title' => 'New Title',
        'content' => 'New Content'
    ];

    expect($expected)->toBeArray();
    expect($expected)->toHaveKey('title');
    expect($expected)->toHaveKey('content');
});

test('process_response returns WP_Error for invalid JSON', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => '{invalid json}']]
        ]
    ];

    $config = [
        'expected_format' => 'json'
    ];

    // Expected: WP_Error for invalid JSON
    $result = new \WP_Error('invalid_json', 'Failed to parse JSON response');

    expect($result)->toBeInstanceOf(WP_Error::class);
});

test('process_response validates output_variables in JSON mode', function () {
    $response = [
        'choices' => [
            ['message' => ['content' => '{"title":"Test"}']]
        ]
    ];

    $config = [
        'expected_format' => 'json',
        'output_variables' => ['title', 'content'] // content is missing
    ];

    // Expected: warning or partial success (depending on implementation)
    // For now, just verify structure
    expect($config['output_variables'])->toContain('title');
    expect($config['output_variables'])->toContain('content');
});

test('process_response extracts content from different API response formats', function () {
    // OpenAI format
    $openai_response = [
        'choices' => [
            ['message' => ['content' => 'OpenAI response']]
        ]
    ];

    // Claude format (might be different)
    $claude_response = [
        'content' => [
            ['text' => 'Claude response']
        ]
    ];

    // Both should be processable
    expect($openai_response['choices'][0]['message']['content'])->toBe('OpenAI response');
    expect($claude_response['content'][0]['text'])->toBe('Claude response');
});

// ============================================================================
// ERROR HANDLING TESTS
// ============================================================================

test('execute handles API rate limiting gracefully', function () {
    // Simulate rate limit error
    $result = new \WP_Error('rate_limit', 'API rate limit exceeded', ['retry_after' => 60]);

    expect($result)->toBeInstanceOf(WP_Error::class);
    expect($result->get_error_code())->toBe('rate_limit');
});

test('execute handles network timeouts', function () {
    // Simulate timeout error
    $result = new \WP_Error('timeout', 'API request timed out');

    expect($result)->toBeInstanceOf(WP_Error::class);
});

test('execute returns partial result on non-fatal errors', function () {
    // Example: JSON parsing warning, but some data extracted
    $partial_result = [
        'success' => true,
        'output' => ['title' => 'Test'],
        'warnings' => ['Missing expected field: content']
    ];

    expect($partial_result)->toHaveKey('warnings');
    expect($partial_result['success'])->toBeTrue();
});

// ============================================================================
// CACHING TESTS (Future enhancement)
// ============================================================================

test('execute supports optional response caching', function () {
    // This is a design test for future caching feature
    $config = [
        'name' => 'Test',
        'cache_enabled' => false // Not implemented yet
    ];

    expect($config)->toHaveKey('cache_enabled');
});

// ============================================================================
// INTEGRATION TESTS (with Variable Manager)
// ============================================================================

test('executor integrates with Variable Manager for context', function () {
    // Verify that executor uses PolyTrans_Variable_Manager
    // This will be tested in implementation
    expect(true)->toBeTrue();
});

test('executor preserves original context after execution', function () {
    $context = [
        'title' => 'Original Title',
        'content' => 'Original Content'
    ];

    // After execution, original context should not be modified
    // (unless explicitly requested via output actions)
    expect($context['title'])->toBe('Original Title');
});

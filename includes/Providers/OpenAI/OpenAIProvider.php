<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Providers\TranslationProviderInterface;
use PolyTrans\PostProcessing\JsonResponseParser;
use PolyTrans\Assistants\AssistantManager;
use PolyTrans\Assistants\AssistantExecutor;
use PolyTrans\Core\LogsManager;
use PolyTrans\Providers\ProviderRegistry;
use PolyTrans\Core\Diagnostics;

/**
 * OpenAI Provider
 * Implements OpenAI translation integration following the provider interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAIProvider implements TranslationProviderInterface
{
    /**
     * Get the provider identifier
     */
    public function get_id()
    {
        return 'openai';
    }

    /**
     * Get the provider display name
     */
    public function get_name()
    {
        return __('OpenAI', 'treetank-trans');
    }

    /**
     * Get the provider description
     */
    public function get_description()
    {
        return __('AI-powered translation with custom assistants. Requires OpenAI API key and configured assistants.', 'treetank-trans');
    }

    /**
     * Check if the provider is properly configured
     */
    public function is_configured(array $settings)
    {
        $api_key = $settings['openai_api_key'] ?? '';
        $assistants = $settings['openai_assistants'] ?? [];

        // Check if we have an API key and at least one assistant configured
        if (empty($api_key)) {
            return false;
        }

        // Check if we have at least one non-empty assistant (language pair)
        $has_assistant = false;
        foreach ($assistants as $language_pair => $assistant_id) {
            if (!empty($assistant_id)) {
                $has_assistant = true;
                break;
            }
        }

        return $has_assistant;
    }

    /**
     * Resolve translation path using openai_path_rules.
     * Returns an array of language codes representing the path, e.g. [source, intermediate, target] or [source, target].
     */
    private function resolve_translation_path($source_lang, $target_lang, $openai_path_rules)
    {
        // Find the most specific rule, breaking ties by last-in-list
        $best_rule = null;
        $best_score = 0;
        $best_index = -1;
        foreach ($openai_path_rules as $idx => $rule) {
            $score = 0;
            if ($rule['source'] === $source_lang && $rule['target'] === $target_lang) {
                $score = 3; // exact match
            } elseif (
                ($rule['source'] === $source_lang && $rule['target'] === 'all') ||
                ($rule['source'] === 'all' && $rule['target'] === $target_lang)
            ) {
                $score = 2; // semi-wildcard
            } elseif ($rule['source'] === 'all' && $rule['target'] === 'all') {
                $score = 1; // full wildcard
            }
            if ($score > 0 && ($score > $best_score || ($score === $best_score && $idx > $best_index))) {
                $best_rule = $rule;
                $best_score = $score;
                $best_index = $idx;
            }
        }
        if ($best_rule) {
            $intermediate = isset($best_rule['intermediate']) ? trim($best_rule['intermediate']) : '';
            LogsManager::log(
                "resolve_translation_path: selected rule (score $best_score, index $best_index): " . json_encode($best_rule) . ", intermediate: '" . $intermediate . "'",
                "debug"
            );
            if ($intermediate === '' || strtolower($intermediate) === 'none' || $intermediate === $source_lang || $intermediate === $target_lang) {
                return [$source_lang, $target_lang];
            } else {
                return [$source_lang, $intermediate, $target_lang];
            }
        }
        LogsManager::log("resolve_translation_path: no rule found for $source_lang -> $target_lang, using direct path", "debug");
        return [$source_lang, $target_lang];
    }

    /**
     * Translate content using OpenAI
     */
    public function translate(array $content, string $source_lang, string $target_lang, array $settings)
    {
        LogsManager::log("OpenAI: translating from $source_lang to $target_lang", "info");

        $openai_api_key = $settings['openai_api_key'] ?? '';
        $openai_assistants = $settings['openai_assistants'] ?? [];
        $openai_path_rules = $settings['openai_path_rules'] ?? [];

        if (!$openai_api_key) {
            return [
                'success' => false,
                'translated_content' => null,
                'error' => 'OpenAI API key not configured'
            ];
        }

        try {
            LogsManager::log("OpenAI: openai_path_rules: " . json_encode($openai_path_rules), "debug");
            $path = $this->resolve_translation_path($source_lang, $target_lang, $openai_path_rules);
            LogsManager::log("OpenAI: translation path resolved: " . implode(' -> ', $path), "info");

            $content_to_translate = $content;
            for ($i = 0; $i < count($path) - 1; $i++) {
                $step_source = $path[$i];
                $step_target = $path[$i + 1];
                $assistant_key = $step_source . '_to_' . $step_target;
                $assistant_id = $openai_assistants[$assistant_key] ?? null;
                LogsManager::log("OpenAI: step $i: $step_source -> $step_target, assistant_key: $assistant_key, assistant_id: " . ($assistant_id ?: 'none'), "debug");
                if (!$assistant_id || empty($assistant_id)) {
                    // Fallback: any available assistant
                    $available_assistants = array_filter($openai_assistants, function ($assistant) {
                        return !empty($assistant);
                    });
                    if (!empty($available_assistants)) {
                        $assistant_id = reset($available_assistants);
                        $used_direction = array_search($assistant_id, $openai_assistants);
                        LogsManager::log("No specific assistant for $assistant_key, using fallback: $used_direction ($assistant_id)", "info");
                    } else {
                        LogsManager::log("No OpenAI assistant configured for translation step ($step_source -> $step_target). Please configure an assistant for '$assistant_key' or any other direction.", "error");
                        return [
                            'success' => false,
                            'translated_content' => null,
                            'error' => "No OpenAI assistant configured for translation step ($step_source -> $step_target). Please configure an assistant for '$assistant_key' or any other direction."
                        ];
                    }
                }
                LogsManager::log("OpenAI: translating step $step_source -> $step_target with assistant $assistant_id", "info");

                // Detect assistant/provider type and route accordingly
                if (strpos($assistant_id, 'provider_') === 0) {
                    // Translation Provider (e.g., provider_google)
                    $provider_id = str_replace('provider_', '', $assistant_id);
                    $result = $this->translate_with_provider($content_to_translate, $step_source, $step_target, $provider_id, $settings);
                } elseif (strpos($assistant_id, 'managed_') === 0) {
                    // Managed Assistant (from local database)
                    $result = $this->translate_with_managed_assistant($content_to_translate, $step_source, $step_target, $assistant_id);
                } else {
                    // OpenAI API Assistant (asst_xxx format)
                    $result = $this->translate_with_openai($content_to_translate, $step_source, $step_target, $assistant_id, $openai_api_key);
                }
                if (!$result['success']) {
                    // Build detailed error message with error code if available
                    $error_msg = $result['error'];
                    $error_code = $result['error_code'] ?? null;

                    if ($error_code) {
                        $log_msg = "OpenAI: step $i failed ($step_source -> $step_target) [code: {$error_code}]: {$error_msg}";
                    } else {
                        $log_msg = "OpenAI: step $i failed ($step_source -> $step_target): {$error_msg}";
                    }

                    LogsManager::log($log_msg, "error", [
                        'step' => $i,
                        'source_lang' => $step_source,
                        'target_lang' => $step_target,
                        'error_code' => $error_code,
                        'error_details' => $result['error_details'] ?? null
                    ]);

                    return [
                        'success' => false,
                        'translated_content' => null,
                        'error' => "Translation step failed ($step_source -> $step_target): " . $error_msg,
                        'error_code' => $error_code
                    ];
                }
                LogsManager::log("OpenAI: step $i completed ($step_source -> $step_target)", "debug");
                $content_to_translate = $result['translated_content'];
            }
            LogsManager::log("OpenAI: all steps completed, returning final translation", "info");
            return [
                'success' => true,
                'translated_content' => $content_to_translate,
                'error' => null
            ];
        } catch (\Exception $e) {
            Diagnostics::log("OpenAI translation error: " . $e->getMessage());
            LogsManager::log("OpenAI: exception: " . $e->getMessage(), "error");
            return [
                'success' => false,
                'translated_content' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get supported languages
     */
    public function get_supported_languages()
    {
        // OpenAI supports most languages, return empty array to indicate all languages supported
        return [];
    }

    /**
     * Get the settings provider class name for this translation provider
     */
    public function get_settings_provider_class()
    {
        return OpenAISettingsProvider::class;
    }

    /**
     * Translate text using OpenAI API
     */
    private function translate_with_openai($content, $source_lang, $target_lang, $assistant_id, $api_key)
    {
        LogsManager::log("OpenAI run status: started ($source_lang -> $target_lang)", "info");

        // Create OpenAI client
        $client = new OpenAIClient($api_key);

        // Prepare the content for translation as JSON
        $content_to_translate = [
            'title' => $content['title'] ?? '',
            'content' => $content['content'] ?? '',
            'excerpt' => $content['excerpt'] ?? '',
            'meta' => $content['meta'] ?? [],
            'featured_image' => $content['featured_image'] ?? null
        ];

        $prompt = "Please translate the following JSON content from $source_lang to $target_lang. Return only a JSON object with the same structure but translated content:\n\n" . json_encode($content_to_translate, JSON_PRETTY_PRINT);

        // Create a thread
        $thread_result = $client->create_thread();
        if (!$thread_result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create thread: ' . $thread_result['error']
            ];
        }

        $thread_id = $thread_result['thread_id'];

        // Add message to thread
        $message_result = $client->add_message($thread_id, 'user', $prompt);
        if (!$message_result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to add message: ' . $message_result['error']
            ];
        }

        // Run the assistant
        $run_result = $client->run_assistant($thread_id, $assistant_id);
        if (!$run_result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to run assistant: ' . $run_result['error']
            ];
        }

        $run_id = $run_result['run_id'];

        // Wait for completion using the client's built-in method
        // This will poll up to 30 times with 1 second between checks (30 seconds total)
        sleep(10); // Initial wait to allow processing to start

        $completion_result = $client->wait_for_run_completion($thread_id, $run_id, 120, 1);

        if (!$completion_result['success']) {
            // Extract detailed error information
            $status = $completion_result['status'] ?? 'failed';
            $error_code = $completion_result['error_code'] ?? null;
            $error_msg = $completion_result['error'];

            // Build detailed log message
            if ($error_code) {
                $log_msg = "OpenAI run status: {$status} [code: {$error_code}] ($source_lang -> $target_lang): {$error_msg}";
            } else {
                $log_msg = "OpenAI run status: {$status} ($source_lang -> $target_lang): {$error_msg}";
            }

            LogsManager::log($log_msg, "error", [
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'status' => $status,
                'error_code' => $error_code,
                'error_details' => $completion_result['error_details'] ?? null,
                'thread_id' => $thread_id,
                'run_id' => $run_id
            ]);

            return [
                'success' => false,
                'error' => $error_msg,
                'error_code' => $error_code,
                'status' => $status
            ];
        }

        LogsManager::log("OpenAI run status: completed ($source_lang -> $target_lang)", "info");

        // Get the assistant's response
        $message_result = $client->get_latest_assistant_message($thread_id);
        if (!$message_result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to get messages: ' . $message_result['error']
            ];
        }

        $response_text = $message_result['content'];

        // Log raw response before parsing
        LogsManager::log(
            "Raw response received from OpenAI",
            "info",
            [
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'response_length' => strlen($response_text),
                'response_preview' => substr($response_text, 0, 1000) // First 1000 chars
            ]
        );

        // Use JSON Response Parser for robust extraction and validation
        $parser = new JsonResponseParser();

        // Define expected schema for translation response
        // Note: In PHP, JSON objects are arrays, so we use 'array' for both meta and featured_image
        $schema = [
            'title' => 'string',
            'content' => 'string',
            'excerpt' => 'string',
            'meta' => 'array', // JSON object becomes PHP array
            'featured_image' => 'array' // Featured image data as object/array (id, alt, title, caption, description, filename)
        ];

        $parse_result = $parser->parse_with_schema($response_text, $schema);

        if (!$parse_result['success']) {
            LogsManager::log(
                "Failed to parse translation response: " . $parse_result['error'],
                "error",
                ['raw_response' => substr($response_text, 0, 500)]
            );
            return [
                'success' => false,
                'error' => 'Failed to parse OpenAI response: ' . $parse_result['error']
            ];
        }

        // Log warnings if any (missing fields, type coercion, etc.)
        if (!empty($parse_result['warnings'])) {
            LogsManager::log(
                "Translation response parsing warnings: " . implode(', ', $parse_result['warnings']),
                "warning",
                ['source_lang' => $source_lang, 'target_lang' => $target_lang]
            );
        }

        // Log what we received from translator
        $translated_data = $parse_result['data'];
        LogsManager::log(
            "Translation response received from OpenAI",
            "info",
            [
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'has_title' => !empty($translated_data['title']),
                'has_content' => !empty($translated_data['content']),
                'has_excerpt' => !empty($translated_data['excerpt']),
                'has_meta' => !empty($translated_data['meta']),
                'meta_type' => gettype($translated_data['meta'] ?? null),
                'meta_keys' => is_array($translated_data['meta'] ?? null) ? array_keys($translated_data['meta']) : null,
                'meta_sample' => is_array($translated_data['meta'] ?? null) ? array_slice($translated_data['meta'], 0, 3, true) : null,
                'has_featured_image' => !empty($translated_data['featured_image']),
                'featured_image_type' => gettype($translated_data['featured_image'] ?? null),
                'featured_image_data' => $translated_data['featured_image'] ?? null
            ]
        );

        return [
            'success' => true,
            'translated_content' => $translated_data
        ];
    }

    /**
     * Translate using a translation provider
     *
     * @param array $content Content to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param string $provider_id Provider ID (e.g., 'google')
     * @param array $settings Translation settings
     * @return array Translation result
     */
    private function translate_with_provider($content, $source_lang, $target_lang, $provider_id, $settings)
    {
        LogsManager::log("Using translation provider: $provider_id for step $source_lang -> $target_lang", "info");
        
        $registry = ProviderRegistry::get_instance();
        $provider = $registry->get_provider($provider_id);
        
        if (!$provider) {
            return [
                'success' => false,
                'error' => "Translation provider '$provider_id' not found",
                'error_code' => 'provider_not_found'
            ];
        }
        
        // Check if provider is configured
        if (!$provider->is_configured($settings)) {
            return [
                'success' => false,
                'error' => "Translation provider '$provider_id' is not properly configured",
                'error_code' => 'provider_not_configured'
            ];
        }
        
        // Use the provider's translate method
        return $provider->translate($content, $source_lang, $target_lang, $settings);
    }

    /**
     * Translate content using a Managed Assistant
     * 
     * @param array $content Content to translate
     * @param string $source_lang Source language code
     * @param string $target_lang Target language code
     * @param string $assistant_id Managed assistant ID (format: managed_123)
     * @return array Translation result
     */
    private function translate_with_managed_assistant($content, $source_lang, $target_lang, $assistant_id)
    {
        try {
            // Extract numeric ID from managed_123 format
            $numeric_id = (int) str_replace('managed_', '', $assistant_id);

            // Get the assistant configuration
            $assistant = AssistantManager::get_assistant($numeric_id);

            if (!$assistant) {
                return [
                    'success' => false,
                    'error' => "Managed Assistant not found (ID: {$numeric_id})",
                    'error_code' => 'assistant_not_found'
                ];
            }

            // Prepare context variables for Twig interpolation
            $context = [
                'source_language' => $source_lang,
                'target_language' => $target_lang,
                'translated' => $content, // Current content state
                'original' => $content,   // Original content (same at this point)
            ];

            // Execute the assistant
            $executor = new AssistantExecutor();
            $result = $executor->execute($numeric_id, $context);

            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'error_code' => $result->get_error_code()
                ];
            }

            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown error from Managed Assistant',
                    'error_code' => 'managed_assistant_execution_failed'
                ];
            }

            $ai_output = $result['output'] ?? '';

            // Assistant_Executor already parses JSON and returns array
            // If we have expected_output_schema, the output is already parsed by executor
            if (!empty($assistant['expected_output_schema']) && $assistant['expected_format'] === 'json') {
                // Output is already parsed array from Assistant_Executor
                if (is_array($ai_output)) {
                    return [
                        'success' => true,
                        'translated_content' => $ai_output
                    ];
                }

                // Fallback: if output is string, parse it (shouldn't happen)
                $parser = new JsonResponseParser();
                $parse_result = $parser->parse_with_schema($ai_output, $assistant['expected_output_schema']);

                if (!$parse_result['success']) {
                    // Log parsing error (without full response to avoid DB/memory issues)
                    LogsManager::log(
                        "Managed Assistant response parsing failed: " . $parse_result['error'],
                        'error',
                        [
                            'assistant_id' => $numeric_id,
                            'response_length' => strlen($ai_output),
                            'response_preview' => substr($ai_output, 0, 500), // First 500 chars only
                            'response_end' => substr($ai_output, -200), // Last 200 chars to see if truncated
                            'parse_error' => $parse_result['error']
                        ]
                    );
                    return [
                        'success' => false,
                        'error' => 'Failed to parse Managed Assistant response: ' . $parse_result['error'],
                        'error_code' => 'parsing_failed'
                    ];
                }

                // Log warnings if any
                if (!empty($parse_result['warnings'])) {
                    LogsManager::log(
                        "Managed Assistant response parsing warnings: " . implode(', ', $parse_result['warnings']),
                        'warning',
                        ['assistant_id' => $numeric_id, 'source_lang' => $source_lang, 'target_lang' => $target_lang]
                    );
                }

                return [
                    'success' => true,
                    'translated_content' => $parse_result['data']
                ];
            }

            // If no schema or not JSON format, return raw output (shouldn't happen for translations)

            return [
                'success' => true,
                'translated_content' => [
                    'title' => $content['title'] ?? '',
                    'content' => $ai_output, // Put raw output in content
                    'excerpt' => $content['excerpt'] ?? '',
                    'meta' => $content['meta'] ?? []
                ]
            ];
        } catch (\Throwable $e) {
            // Catch any unexpected errors (\Exception, Error, TypeError, etc.)
            LogsManager::log(
                "Managed Assistant translation failed with exception: " . $e->getMessage(),
                'error',
                [
                    'assistant_id' => $numeric_id ?? null,
                    'exception_class' => get_class($e),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'memory_at_error' => memory_get_usage(true),
                ]
            );

            return [
                'success' => false,
                'error' => 'Managed Assistant execution failed: ' . $e->getMessage(),
                'error_code' => 'exception',
                'error_details' => [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ];
        }
    }
}

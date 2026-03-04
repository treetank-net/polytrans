<?php

namespace PolyTrans\Providers\OpenAI;

use PolyTrans\Core\Http\HttpClient;
use PolyTrans\Core\Http\HttpResponse;

/**
 * OpenAI API Client
 * 
 * Centralized client for all OpenAI API interactions.
 * Handles authentication, pagination, and common API operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAIClient
{
    private $api_key;
    private $base_url;
    private $default_timeout;
    private $http_client;

    /**
     * Constructor
     * 
     * @param string $api_key OpenAI API key
     * @param string $base_url Base URL for OpenAI API (default: https://api.openai.com/v1)
     * @param int|null $default_timeout Default timeout for requests in seconds (null = use setting)
     */
    public function __construct($api_key, $base_url = 'https://api.openai.com/v1', $default_timeout = null)
    {
        $this->api_key = $api_key;
        $this->base_url = rtrim($base_url, '/');
        
        // Get API timeout from settings if not provided
        if ($default_timeout === null) {
            $settings = get_option('polytrans_settings', []);
            $default_timeout = absint($settings['api_timeout'] ?? 180);
            $default_timeout = max(30, min(600, $default_timeout)); // Clamp between 30-600 seconds
        }
        
        $this->default_timeout = $default_timeout;
        
        // Initialize HTTP client with configurable timeout
        $this->http_client = new HttpClient($this->base_url, $default_timeout);
        $this->http_client
            ->set_auth('bearer', $api_key)
            ->set_header('Content-Type', 'application/json')
            ->set_header('OpenAI-Beta', 'assistants=v2')
            ->set_header('User-Agent', 'PolyTrans/1.0');
    }

    /**
     * Get all assistants with automatic pagination
     * 
     * @param int $limit Results per page (default: 100, max: 100)
     * @param string $order Sort order: 'asc' or 'desc' (default: 'desc')
     * @return array Array of assistants or empty array on error
     */
    public function get_all_assistants($limit = 100, $order = 'desc')
    {
        $all_assistants = [];
        $after = null;

        do {
            $query_params = [
                'limit' => min($limit, 100), // API max is 100
                'order' => $order
            ];

            if ($after) {
                $query_params['after'] = $after;
            }

            $url = add_query_arg($query_params, $this->base_url . '/assistants');

            $response = $this->make_request('GET', $url, null, 15);

            if (!$response['success']) {
                // If first page fails, return empty array
                // If subsequent page fails, return what we have so far
                return $all_assistants;
            }

            $data = $response['data'];

            if (!isset($data['data']) || !is_array($data['data'])) {
                break;
            }

            // Add assistants from this page to the collection
            $all_assistants = array_merge($all_assistants, $data['data']);

            // Check if there are more pages
            $has_more = $data['has_more'] ?? false;

            // Get the last assistant ID for pagination
            if ($has_more && !empty($data['data'])) {
                $last_assistant = end($data['data']);
                $after = $last_assistant['id'];
            } else {
                $after = null;
            }
        } while ($after !== null);

        return $all_assistants;
    }

    /**
     * Get all available models
     * 
     * @return array Array of models or empty array on error
     */
    public function get_models()
    {
        $url = '/models';
        $response = $this->make_request('GET', $url);

        if (!$response['success']) {
            return [];
        }

        return $response['data']['data'] ?? [];
    }

    /**
     * Create a thread for assistant conversations
     * 
     * @param array $messages Array of messages to initialize the thread
     * @return array Response with 'success' and 'thread_id' or 'error'
     */
    public function create_thread($messages = [])
    {
        $url = '/threads';
        $body = ['messages' => $messages];

        $response = $this->make_request('POST', $url, $body, 120);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        return [
            'success' => true,
            'thread_id' => $response['data']['id']
        ];
    }

    /**
     * Add a message to a thread
     * 
     * @param string $thread_id Thread ID
     * @param string $role Message role ('user' or 'assistant')
     * @param string $content Message content
     * @return array Response with 'success' and 'message_id' or 'error'
     */
    public function add_message($thread_id, $role, $content)
    {
        $url = "/threads/{$thread_id}/messages";
        $body = [
            'role' => $role,
            'content' => $content
        ];

        $response = $this->make_request('POST', $url, $body, 120);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        return [
            'success' => true,
            'message_id' => $response['data']['id']
        ];
    }

    /**
     * Run an assistant on a thread
     * 
     * @param string $thread_id Thread ID
     * @param string $assistant_id Assistant ID
     * @param array $additional_params Additional parameters for the run
     * @return array Response with 'success' and 'run_id' or 'error'
     */
    public function run_assistant($thread_id, $assistant_id, $additional_params = [])
    {
        $url = "/threads/{$thread_id}/runs";
        $body = array_merge(['assistant_id' => $assistant_id], $additional_params);

        $response = $this->make_request('POST', $url, $body, 120);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        return [
            'success' => true,
            'run_id' => $response['data']['id'],
            'status' => $response['data']['status']
        ];
    }

    /**
     * Get the status of a run
     * 
     * @param string $thread_id Thread ID
     * @param string $run_id Run ID
     * @return array Response with 'success', 'status' and full data or 'error'
     */
    public function get_run_status($thread_id, $run_id)
    {
        $url = "/threads/{$thread_id}/runs/{$run_id}";
        $response = $this->make_request('GET', $url, null, 120);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        return [
            'success' => true,
            'status' => $response['data']['status'],
            'data' => $response['data']
        ];
    }

    /**
     * Wait for a run to complete
     * 
     * @param string $thread_id Thread ID
     * @param string $run_id Run ID
     * @param int $max_attempts Maximum number of polling attempts (default: 30)
     * @param int $sleep_seconds Sleep between attempts in seconds (default: 1)
     * @return array Response with 'success', 'status' and 'data' or 'error'
     */
    public function wait_for_run_completion($thread_id, $run_id, $max_attempts = 30, $sleep_seconds = 1)
    {
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $status_response = $this->get_run_status($thread_id, $run_id);

            if (!$status_response['success']) {
                return $status_response;
            }

            $status = $status_response['status'];

            if ($status === 'completed') {
                return [
                    'success' => true,
                    'status' => 'completed',
                    'data' => $status_response['data']
                ];
            } elseif (in_array($status, ['failed', 'cancelled', 'expired'])) {
                // Extract detailed error information from last_error field
                $error_details = $this->extract_run_error_details($status_response['data']);

                return [
                    'success' => false,
                    'error' => "Run ended with status: {$status}" . ($error_details['message'] ? " - {$error_details['message']}" : ''),
                    'status' => $status,
                    'error_code' => $error_details['code'],
                    'error_details' => $error_details
                ];
            }

            sleep($sleep_seconds);
            $attempt++;
        }

        return [
            'success' => false,
            'error' => 'Run timed out after ' . ($max_attempts * $sleep_seconds) . ' seconds',
            'status' => 'timeout'
        ];
    }

    /**
     * Get messages from a thread
     * 
     * @param string $thread_id Thread ID
     * @param int $limit Number of messages to retrieve (default: 20)
     * @param string $order Sort order: 'asc' or 'desc' (default: 'desc')
     * @return array Response with 'success' and 'messages' or 'error'
     */
    public function get_messages($thread_id, $limit = 20, $order = 'desc')
    {
        $query_params = [
            'limit' => $limit,
            'order' => $order
        ];

        $url = "/threads/{$thread_id}/messages";
        if (!empty($query_params)) {
            $url = add_query_arg($query_params, $url);
        }
        $response = $this->make_request('GET', $url, null, 30);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        return [
            'success' => true,
            'messages' => $response['data']['data'] ?? []
        ];
    }

    /**
     * Get the latest assistant message from a thread
     * 
     * @param string $thread_id Thread ID
     * @return array Response with 'success' and 'content' or 'error'
     */
    public function get_latest_assistant_message($thread_id)
    {
        $messages_response = $this->get_messages($thread_id, 20, 'desc');

        if (!$messages_response['success']) {
            return $messages_response;
        }

        // Find the latest assistant message
        foreach ($messages_response['messages'] as $message) {
            if ($message['role'] === 'assistant') {
                $content = '';
                foreach ($message['content'] as $content_block) {
                    if ($content_block['type'] === 'text') {
                        $content .= $content_block['text']['value'];
                    }
                }

                return [
                    'success' => true,
                    'content' => $content,
                    'message' => $message
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'No assistant message found'
        ];
    }

    /**
     * Make an HTTP request to the OpenAI API
     * 
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $url Full URL or path (relative to base_url)
     * @param array|null $body Request body (for POST/PUT requests)
     * @param int|null $timeout Timeout in seconds (uses default if not specified)
     * @return array Response with 'success', 'data' or 'error'
     */
    private function make_request($method, $url, $body = null, $timeout = null)
    {
        $options = [];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        // Try up to 2 times (initial attempt + 1 retry)
        $max_attempts = 2;
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = $this->http_client->request($method, $url, $body, $options);

            if ($response->is_error()) {
                $error_message = $response->get_error_message();
                
                // If this was a timeout and we have attempts left, retry
                if ($attempt < $max_attempts && strpos($error_message, 'timeout') !== false) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                    error_log("PolyTrans OpenAI: Request timeout on attempt {$attempt}, retrying...");
                    continue;
                }

                return [
                    'success' => false,
                    'error' => $error_message,
                    'status_code' => $response->get_status_code()
                ];
            }

            // Success - get JSON data
            $data = $response->get_json(true);
            
            if ($data === null) {
                return [
                    'success' => false,
                    'error' => 'Invalid JSON response',
                    'status_code' => $response->get_status_code()
                ];
            }

            return [
                'success' => true,
                'data' => $data,
                'status_code' => $response->get_status_code()
            ];
        }

        // This should not be reached, but just in case
        return [
            'success' => false,
            'error' => $last_error ?? 'Request failed after retries'
        ];
    }

    /**
     * Extract detailed error information from OpenAI run response
     *
     * When a run fails, OpenAI API returns a last_error object with:
     * - code: Error code (e.g., 'rate_limit_exceeded', 'server_error', 'insufficient_quota')
     * - message: Human-readable error message
     *
     * @param array $run_data Full run response data from OpenAI API
     * @return array Array with 'code' and 'message' keys
     */
    private function extract_run_error_details($run_data)
    {
        $error_details = [
            'code' => null,
            'message' => null,
            'raw' => null
        ];

        // Check if last_error exists in response
        if (isset($run_data['last_error']) && is_array($run_data['last_error'])) {
            $last_error = $run_data['last_error'];

            // Extract error code (e.g., 'rate_limit_exceeded', 'insufficient_quota')
            if (isset($last_error['code'])) {
                $error_details['code'] = $last_error['code'];
            }

            // Extract human-readable message
            if (isset($last_error['message'])) {
                $error_details['message'] = $last_error['message'];
            }

            // Store raw error for debugging
            $error_details['raw'] = $last_error;
        }

        // If no last_error, try to extract from incomplete_details (for incomplete runs)
        if (empty($error_details['code']) && isset($run_data['incomplete_details'])) {
            $incomplete = $run_data['incomplete_details'];

            if (isset($incomplete['reason'])) {
                $error_details['code'] = 'incomplete_' . $incomplete['reason'];
                $error_details['message'] = "Run incomplete: " . $incomplete['reason'];
                $error_details['raw'] = $incomplete;
            }
        }

        // Fallback: check for general error field (some endpoints use this)
        if (empty($error_details['code']) && isset($run_data['error'])) {
            if (is_array($run_data['error'])) {
                $error_details['code'] = $run_data['error']['code'] ?? 'unknown_error';
                $error_details['message'] = $run_data['error']['message'] ?? 'Unknown error occurred';
                $error_details['raw'] = $run_data['error'];
            } else {
                $error_details['message'] = $run_data['error'];
            }
        }

        return $error_details;
    }

    /**
     * Create a client instance from plugin settings
     *
     * @return PolyTrans_OpenAI_Client|null Client instance or null if not configured
     */
    public static function from_settings()
    {
        $settings = get_option('polytrans_settings', []);
        $api_key = $settings['openai_api_key'] ?? '';

        if (empty($api_key)) {
            return null;
        }

        $base_url = $settings['openai_base_url'] ?? 'https://api.openai.com/v1';

        return new self($api_key, $base_url);
    }
}

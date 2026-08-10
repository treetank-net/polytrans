<?php

namespace PolyTrans\Core\Http;

/**
 * HTTP Response Wrapper
 * 
 * Wraps WordPress wp_remote_* response with convenient methods
 * for parsing and error handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HttpResponse
{
    private $response;
    private $body;
    private $status_code;
    private $headers;
    private $error;

    /**
     * Constructor
     * 
     * @param array|\WP_Error $response WordPress HTTP response or WP_Error
     */
    public function __construct($response)
    {
        if (is_wp_error($response)) {
            $this->error = $response;
            $this->status_code = 0;
            $this->body = '';
            $this->headers = [];
        } else {
            $this->response = $response;
            $this->status_code = wp_remote_retrieve_response_code($response);
            $this->body = wp_remote_retrieve_body($response);
            $this->headers = wp_remote_retrieve_headers($response);
        }
    }

    /**
     * Check if request was successful
     * 
     * @return bool True if status code is 2xx
     */
    public function is_success(): bool
    {
        if ($this->error) {
            return false;
        }
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    /**
     * Check if request failed
     * 
     * @return bool True if error occurred or status code is not 2xx
     */
    public function is_error(): bool
    {
        return !$this->is_success();
    }

    /**
     * Get status code
     * 
     * @return int HTTP status code (0 if WP_Error)
     */
    public function get_status_code(): int
    {
        return $this->status_code;
    }

    /**
     * Get response body as string
     * 
     * @return string Response body
     */
    public function get_body(): string
    {
        return $this->body;
    }

    /**
     * Get response body as JSON
     * 
     * @param bool $assoc Decode as associative array (default: true)
     * @return array|object|null Decoded JSON or null on error
     */
    public function get_json($assoc = true)
    {
        $data = json_decode($this->body, $assoc);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $data;
    }

    /**
     * Get response headers
     * 
     * @return array Response headers
     */
    public function get_headers(): array
    {
        return $this->headers;
    }

    /**
     * Get specific header value
     * 
     * @param string $name Header name (case-insensitive)
     * @return string|null Header value or null if not found
     */
    public function get_header($name): ?string
    {
        if (!$this->headers) {
            return null;
        }

        // WordPress headers are case-insensitive
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get error message
     * 
     * @return string Error message or empty string if no error
     */
    public function get_error_message(): string
    {
        if ($this->error) {
            return $this->error->get_error_message();
        }

        if (!$this->is_success()) {
            // Try to extract error message from JSON response
            $json = $this->get_json(true);
            if ($json && isset($json['error'])) {
                if (is_array($json['error'])) {
                    return $json['error']['message'] ?? $json['error']['status'] ?? "HTTP {$this->status_code}";
                }
                return (string) $json['error'];
            }
            return "HTTP {$this->status_code}";
        }

        return '';
    }

    /**
     * Get error code
     * 
     * @return string Error code or empty string if no error
     */
    public function get_error_code(): string
    {
        if ($this->error) {
            return $this->error->get_error_code();
        }

        if (!$this->is_success()) {
            // Try to extract error code from JSON response
            $json = $this->get_json(true);
            if ($json && isset($json['error']['code'])) {
                return (string) $json['error']['code'];
            }
            return "http_{$this->status_code}";
        }

        return '';
    }

    /**
     * Get raw WordPress response
     * 
     * @return array|\WP_Error Raw response
     */
    public function get_raw_response()
    {
        return $this->response ?? $this->error;
    }

    /**
     * Check if response has specific header
     * 
     * @param string $name Header name (case-insensitive)
     * @return bool True if header exists
     */
    public function has_header($name): bool
    {
        return $this->get_header($name) !== null;
    }
}

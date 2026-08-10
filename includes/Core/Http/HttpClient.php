<?php

namespace PolyTrans\Core\Http;

/**
 * HTTP Client Wrapper
 * 
 * Simplified wrapper for WordPress wp_remote_* functions
 * with consistent error handling and response parsing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HttpClient
{
    private $base_url;
    private $default_headers;
    private $default_timeout;
    private $default_sslverify;

    /**
     * Constructor
     * 
     * @param string $base_url Base URL for all requests (optional)
     * @param int $default_timeout Default timeout in seconds (default: 30)
     * @param bool $default_sslverify Default SSL verification (default: true, false in dev)
     */
    public function __construct($base_url = '', $default_timeout = 30, $default_sslverify = null)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->default_headers = [];
        $this->default_timeout = $default_timeout;
        
        // Auto-detect SSL verification based on environment
        if ($default_sslverify === null) {
            $this->default_sslverify = (getenv('WP_ENV') === 'prod' || getenv('WP_ENV') === 'production');
        } else {
            $this->default_sslverify = $default_sslverify;
        }
    }

    /**
     * Set default header for all requests
     * 
     * @param string $name Header name
     * @param string $value Header value
     * @return self For method chaining
     */
    public function set_header($name, $value): self
    {
        $this->default_headers[$name] = $value;
        return $this;
    }

    /**
     * Set authentication header
     * 
     * @param string $type Auth type: 'bearer', 'basic', or 'custom'
     * @param string $token_or_value Token or value
     * @param string|null $custom_header_name Custom header name (if type is 'custom')
     * @return self For method chaining
     */
    public function set_auth($type, $token_or_value, $custom_header_name = null): self
    {
        switch ($type) {
            case 'bearer':
                $this->set_header('Authorization', 'Bearer ' . $token_or_value);
                break;
            case 'basic':
                $this->set_header('Authorization', 'Basic ' . base64_encode($token_or_value));
                break;
            case 'custom':
                if ($custom_header_name) {
                    $this->set_header($custom_header_name, $token_or_value);
                }
                break;
        }
        return $this;
    }

    /**
     * Set API key header (common pattern)
     * 
     * @param string $api_key API key
     * @param string $header_name Header name (default: 'x-api-key')
     * @return self For method chaining
     */
    public function set_api_key($api_key, $header_name = 'x-api-key'): self
    {
        return $this->set_header($header_name, $api_key);
    }

    /**
     * Make HTTP request with automatic retry on timeout
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url Full URL or path (if base_url is set)
     * @param array|null $data Request body data (will be JSON encoded)
     * @param array $options Additional options (timeout, headers, sslverify, retry_on_timeout, etc.)
     * @return HttpResponse Response wrapper
     */
    public function request($method, $url, $data = null, array $options = []): HttpResponse
    {
        // Build full URL
        $full_url = $this->build_url($url);

        // Merge headers
        $headers = array_merge($this->default_headers, $options['headers'] ?? []);

        // Get timeout (from options, or default)
        $timeout = $options['timeout'] ?? $this->default_timeout;
        
        // Check if retry on timeout is enabled (default: true)
        $retry_on_timeout = $options['retry_on_timeout'] ?? true;
        $max_attempts = $retry_on_timeout ? 2 : 1; // Initial attempt + 1 retry

        // Build request args
        $args = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'timeout' => $timeout,
            'sslverify' => $options['sslverify'] ?? $this->default_sslverify,
        ];

        // Add body for POST/PUT/PATCH
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH']) && $data !== null) {
            if (is_string($data)) {
                $args['body'] = $data;
            } else {
                $args['body'] = wp_json_encode($data);
                // Ensure Content-Type is set for JSON
                if (!isset($args['headers']['Content-Type'])) {
                    $args['headers']['Content-Type'] = 'application/json';
                }
            }
        }

        // Add query params for GET/DELETE
        if (in_array(strtoupper($method), ['GET', 'DELETE']) && $data !== null && is_array($data)) {
            $full_url = add_query_arg($data, $full_url);
        }

        // Merge any additional options
        if (isset($options['blocking'])) {
            $args['blocking'] = $options['blocking'];
        }
        if (isset($options['cookies'])) {
            $args['cookies'] = $options['cookies'];
        }
        if (isset($options['redirection'])) {
            $args['redirection'] = $options['redirection'];
        }

        // Make request with retry logic
        $last_response = null;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $response = wp_remote_request($full_url, $args);
            $http_response = new HttpResponse($response);
            
            // Check if this was a timeout error
            if ($http_response->is_error()) {
                $error_message = $http_response->get_error_message();
                
                // If timeout and we have attempts left, retry
                if ($attempt < $max_attempts &&
                    (strpos(strtolower($error_message), 'timeout') !== false ||
                     strpos(strtolower($error_message), 'timed out') !== false)) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging
                    error_log(sprintf(
                        '[PolyTrans HttpClient] Request timeout on attempt %d/%d, retrying... URL: %s',
                        $attempt,
                        $max_attempts,
                        $full_url
                    ));
                    $last_response = $http_response;
                    continue; // Retry
                }
            }
            
            // Success or non-timeout error - return immediately
            return $http_response;
        }
        
        // All attempts exhausted - return last response
        return $last_response ?? new HttpResponse(new \WP_Error('unknown', 'Request failed'));
    }

    /**
     * Make GET request
     * 
     * @param string $url Full URL or path
     * @param array|null $query_params Query parameters
     * @param array $options Additional options
     * @return HttpResponse Response wrapper
     */
    public function get($url, $query_params = null, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $query_params, $options);
    }

    /**
     * Make POST request
     * 
     * @param string $url Full URL or path
     * @param array|string|null $data Request body
     * @param array $options Additional options
     * @return HttpResponse Response wrapper
     */
    public function post($url, $data = null, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $options);
    }

    /**
     * Make PUT request
     * 
     * @param string $url Full URL or path
     * @param array|string|null $data Request body
     * @param array $options Additional options
     * @return HttpResponse Response wrapper
     */
    public function put($url, $data = null, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $data, $options);
    }

    /**
     * Make DELETE request
     * 
     * @param string $url Full URL or path
     * @param array|null $query_params Query parameters
     * @param array $options Additional options
     * @return HttpResponse Response wrapper
     */
    public function delete($url, $query_params = null, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $query_params, $options);
    }

    /**
     * Build full URL from path or URL
     * 
     * @param string $url Path or full URL
     * @return string Full URL
     */
    private function build_url($url): string
    {
        // If it's already a full URL, return as-is
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }

        // If base_url is set, prepend it
        if ($this->base_url) {
            return $this->base_url . '/' . ltrim($url, '/');
        }

        return $url;
    }

    /**
     * Reset default headers
     * 
     * @return self For method chaining
     */
    public function reset_headers(): self
    {
        $this->default_headers = [];
        return $this;
    }

    /**
     * Get base URL
     * 
     * @return string Base URL
     */
    public function get_base_url(): string
    {
        return $this->base_url;
    }
}

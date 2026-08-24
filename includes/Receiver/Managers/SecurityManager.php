<?php

namespace PolyTrans\Receiver\Managers;

use PolyTrans\Core\EndpointAuth;
use PolyTrans\Core\Diagnostics;

/**
 * Handles security validation for translation receiver endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SecurityManager
{
    /**
     * Validates permission for translation receiver endpoints.
     * 
     * @param WP_REST_Request $request The REST API request
     * @return bool True if authorized, false otherwise
     */
    public function validate_permission($request)
    {
        $settings = get_option('polytrans_settings', []);
        $secret = isset($settings['translation_receiver_secret']) ? $settings['translation_receiver_secret'] : '';
        $method = isset($settings['translation_receiver_secret_method']) ? $settings['translation_receiver_secret_method'] : 'header_bearer';
        $custom_header_name = isset($settings['translation_receiver_secret_custom_header']) ? $settings['translation_receiver_secret_custom_header'] : 'x-polytrans-secret';

        // "none" alone is not consent. It opens this endpoint only together with
        // POLYTRANS_ALLOW_UNAUTHENTICATED_ENDPOINTS in wp-config.php, because this
        // endpoint creates posts and triggers workflows. See EndpointAuth.
        if (EndpointAuth::is_unauthenticated_method($method)) {
            if (EndpointAuth::allows_unauthenticated()) {
                return true;
            }

            \PolyTrans_Logs_Manager::log(EndpointAuth::refusal_message(), 'warning');

            return false;
        }

        if (!$secret) {
            \PolyTrans_Logs_Manager::log(
                'Translation receiver request rejected: no receiver secret is configured. '
                    . 'Set one under PolyTrans → Settings → Advanced.',
                'warning'
            );
            return false;
        }

        $provided_secret = '';

        switch ($method) {
            case 'get_param':
                $provided_secret = $request->get_param('secret') ?: '';
                break;
            case 'header_bearer':
                $auth_header = $request->get_header('authorization');
                if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
                    $provided_secret = substr($auth_header, 7);
                }
                break;
            case 'header_custom':
                $provided_secret = $request->get_header($custom_header_name) ?: '';
                break;
            case 'post_param':
                $body = $request->get_json_params();
                $provided_secret = isset($body['secret']) ? $body['secret'] : '';
                break;
        }

        // Cast: get_header() returns null for an absent header, and hash_equals() takes strings.
        $is_valid = hash_equals($secret, is_string($provided_secret) ? $provided_secret : '');

        if (!$is_valid) {
            \PolyTrans_Logs_Manager::log("Translation receiver authentication failed", "info");
        }

        return $is_valid;
    }

    /**
     * Validates IP address restrictions if configured.
     * 
     * @param WP_REST_Request $request The REST API request
     * @return bool True if IP is allowed, false otherwise
     */
    public function validate_ip_address($request)
    {
        $settings = get_option('polytrans_settings', []);
        $allowed_ips = isset($settings['allowed_ips']) ? $settings['allowed_ips'] : [];

        // If no IP restrictions configured, allow all
        if (empty($allowed_ips)) {
            return true;
        }

        $client_ip = $this->get_client_ip($request);

        foreach ($allowed_ips as $allowed_ip) {
            if ($this->ip_matches($client_ip, trim($allowed_ip))) {
                return true;
            }
        }

        Diagnostics::log("Translation receiver IP restriction failed for IP: $client_ip");
        return false;
    }

    /**
     * Gets the client IP address from the request.
     * 
     * @param WP_REST_Request $request The REST API request
     * @return string Client IP address
     */
    private function get_client_ip($request)
    {
        // Check for shared internet/proxy
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        }
        // Check for IP passed from proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        }
        // Check for IP from remote address
        elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return '0.0.0.0';
    }

    /**
     * Checks if client IP matches allowed IP (supports CIDR notation).
     * 
     * @param string $client_ip Client IP address
     * @param string $allowed_ip Allowed IP address or CIDR range
     * @return bool True if IP matches
     */
    private function ip_matches($client_ip, $allowed_ip)
    {
        // Exact match
        if ($client_ip === $allowed_ip) {
            return true;
        }

        // CIDR range check
        if (strpos($allowed_ip, '/') !== false) {
            list($subnet, $mask) = explode('/', $allowed_ip);
            $subnet_long = ip2long($subnet);
            $client_long = ip2long($client_ip);
            $mask_long = ~((1 << (32 - $mask)) - 1);

            return ($client_long & $mask_long) === ($subnet_long & $mask_long);
        }

        return false;
    }
}

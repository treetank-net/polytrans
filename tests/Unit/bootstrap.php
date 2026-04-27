<?php
/**
 * Bootstrap for Unit tests - no WordPress dependency
 */

// Composer autoloader
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!defined('POLYTRANS_PLUGIN_DIR')) {
    define('POLYTRANS_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
}

$GLOBALS['polytrans_test_options'] = [];

// Mock WordPress functions for unit tests
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['polytrans_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        $GLOBALS['polytrans_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        unset($GLOBALS['polytrans_test_options'][$option]);
        return true;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return is_array($response) && isset($response['body']) ? (string) $response['body'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        return is_array($response) && isset($response['headers']) ? $response['headers'] : [];
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => '',
            'headers' => [],
        ];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $errors = [];
        protected $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code() {
            return array_key_first($this->errors) ?? '';
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public $ID;
        public $user_email;
        public $roles = [];

        public function __construct($id = 0, $email = '', $roles = [])
        {
            $this->ID = $id;
            $this->user_email = $email;
            $this->roles = $roles;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('PolyTrans_Requests_CaseInsensitiveDictionary')) {
    class PolyTrans_Requests_CaseInsensitiveDictionary extends \ArrayObject {}
}

if (!class_exists('\WpOrg\Requests\Utility\CaseInsensitiveDictionary')) {
    class_alias('PolyTrans_Requests_CaseInsensitiveDictionary', 'WpOrg\Requests\Utility\CaseInsensitiveDictionary');
}

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
$GLOBALS['polytrans_test_filters'] = [];

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

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) {
        echo esc_html($text);
    }
}

if (!function_exists('selected')) {
    function selected($selected, $current = true, $display = true) {
        $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
        if ($display) {
            echo $result;
        }
        return $result;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// Date handling is pinned to a fixed zone so that a window built in a test covers the
// same instants wherever the suite runs.
if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone($GLOBALS['polytrans_test_timezone'] ?? 'Europe/Warsaw');
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return wp_timezone()->getName();
    }
}

if (!function_exists('current_datetime')) {
    function current_datetime() {
        return $GLOBALS['polytrans_test_now'] ?? new DateTimeImmutable('now', wp_timezone());
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) {
        $now = current_datetime();

        return $type === 'timestamp' ? $now->getTimestamp() : $now->format('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        $timestamp = $timestamp ?? current_datetime()->getTimestamp();
        $when = new DateTimeImmutable('@' . $timestamp);

        return $when->setTimezone($timezone ?: wp_timezone())->format($format);
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

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) {
        return strip_tags((string) $text);
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
    /**
     * Minimal apply_filters() stub.
     *
     * Tests may register a callback in $GLOBALS['polytrans_test_filters'][$hook_name]
     * to exercise filterable code paths.
     */
    function apply_filters($hook_name, $value, ...$args) {
        $filters = $GLOBALS['polytrans_test_filters'] ?? [];

        if (isset($filters[$hook_name]) && is_callable($filters[$hook_name])) {
            return call_user_func($filters[$hook_name], $value, ...$args);
        }

        return $value;
    }
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// $wpdb result formats.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// Transient stubs are intentionally NOT defined here. Individual test files
// declare their own, each with its own backing store, so a shared definition
// here would win the function_exists() race and break them. Seed and read
// transients through set_transient()/get_transient() rather than a global array.

if (!function_exists('wp_remote_get')) {
    /**
     * Offline by default: tests must seed a transient or a filter rather than
     * reaching the network. Set $GLOBALS['polytrans_test_http_get'] to override.
     */
    function wp_remote_get($url, $args = []) {
        $handler = $GLOBALS['polytrans_test_http_get'] ?? null;

        if (is_callable($handler)) {
            return call_user_func($handler, $url, $args);
        }

        return new WP_Error('http_request_failed', 'Network disabled in unit tests: ' . $url);
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

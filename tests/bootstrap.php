<?php
/**
 * PHPUnit bootstrap file for PolyTrans plugin tests
 */

// Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// WordPress test environment
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Check if WordPress test environment exists
// If not, use lightweight Unit bootstrap (for CI/isolated unit tests)
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    require_once __DIR__ . '/Unit/bootstrap.php';
    return;
}

// Load WordPress test environment
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
    require dirname(__DIR__) . '/treetank-trans.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';

// All classes are now autoloaded via PSR-4 and LegacyAutoloader!
// No manual require_once needed.

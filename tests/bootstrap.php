<?php
/**
 * PHPUnit bootstrap for the WordPress integration tests.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find $_tests_dir/includes/functions.php. Run bin/install-wp-tests.sh first." . PHP_EOL;
    exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $_tests_dir . '/includes/functions.php';

// Load the plugin the same way WordPress does, so $GLOBALS['cgv'] is set at plugin load time.
tests_add_filter(
    'muplugins_loaded',
    function () {
        global $custom_global_variables;
        require dirname( __DIR__ ) . '/custom-global-variables.php';
    }
);

require $_tests_dir . '/includes/bootstrap.php';

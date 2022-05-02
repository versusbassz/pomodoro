<?php

// composer
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// own helpers
require_once __DIR__ . '/helpers.php';

// Activate the plugin
if ( ! isset( $GLOBALS['wp_tests_options'] ) ) {
	$GLOBALS['wp_tests_options'] = [];
}

global $wp_tests_options;

$wp_tests_options = [
	'active_plugins' => [
//		'my_plugin/my_plugin.php',
	],
];

// wp tests environment
const WP_TESTS_CONFIG_FILE_PATH = __DIR__ . '/wp-tests-config.php';
require_once dirname( __DIR__, 2 ) . '/custom/wp-tests-lib/includes/bootstrap.php'; // uses WP_TESTS_CONFIG_FILE_PATH

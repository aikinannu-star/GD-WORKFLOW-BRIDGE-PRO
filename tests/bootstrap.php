<?php
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "Could not find WP_TESTS_DIR. Set the WP_TESTS_DIR environment variable.\n");
    exit(1);
}
require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    require dirname(__DIR__) . '/gd-workflow-bridge-pro.php';
}

function _manually_create_tables() {
    if (class_exists('GDWB\\Core\\Activator')) {
        $activator = new \GDWB\Core\Activator();
        $activator->activate();
    }
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');
tests_add_filter('init', '_manually_create_tables', 999);

require $_tests_dir . '/bootstrap.php';

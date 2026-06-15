<?php
class Tests_Plugin_Setup extends WP_UnitTestCase {

    public function test_plugin_setup_status() {
        if (class_exists('GDWB\\Core\\PluginSetup')) {
            $status = \GDWB\Core\PluginSetup::verify_setup();
            
            $this->assertTrue($status['php_version'], 'PHP 8.0+ is required');
            $this->assertTrue($status['modules_loaded'], 'Core modules must be loaded');
            $this->assertTrue($status['db_tables_ok'], 'Custom DB tables must exist');
        }
    }

}

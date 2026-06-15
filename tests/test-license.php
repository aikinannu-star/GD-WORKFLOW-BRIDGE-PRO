<?php
class Tests_License_Manager extends WP_UnitTestCase {

    public function test_license_manager_loads() {
        $this->assertTrue(class_exists('GDWB\\Admin\\License_Manager'));
    }

    public function test_license_info_returns_masked_key() {
        $license_manager = new \GDWB\Admin\License_Manager();
        update_option(\GDWB\Admin\License_Manager::LICENSE_KEY_OPTION, 'TEST-LICENSE-KEY-12345-ABCDE');
        update_option(\GDWB\Admin\License_Manager::LICENSE_STATUS_OPTION, 'active');

        $info = $license_manager->get_license_info();
        $this->assertEquals('active', $info['status']);
        $this->assertStringContainsString('***', $info['key']);
    }

    public function test_license_format_validation_accepts_valid_keys() {
        $license_manager = new \GDWB\Admin\License_Manager();
        $valid = $license_manager->validate_license_format('GDWB-PRO-2026-TEST-VALID-00001');

        $this->assertTrue($valid);
    }

    public function test_license_expiry_transitions_to_expired() {
        $license_manager = new \GDWB\Admin\License_Manager();
        update_option(\GDWB\Admin\License_Manager::LICENSE_STATUS_OPTION, 'active');
        update_option(\GDWB\Admin\License_Manager::LICENSE_EXPIRY_OPTION, strtotime('-1 day'));
        update_option(\GDWB\Admin\License_Manager::LICENSE_KEY_OPTION, 'GDWB-PRO-2026-TEST-VALID-00001');

        $license_manager->check_license();

        $this->assertEquals('expired', get_option(\GDWB\Admin\License_Manager::LICENSE_STATUS_OPTION));
    }
}

<?php
class Tests_Capabilities extends WP_UnitTestCase {

    public function test_capabilities_registered() {
        $admin_role = get_role('administrator');
        $this->assertTrue($admin_role->has_cap('manage_gdwb_projects'));
        $this->assertTrue($admin_role->has_cap('edit_gdwb_project'));
    }

}

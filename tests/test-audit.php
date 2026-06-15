<?php
class Tests_Audit_Logger extends WP_UnitTestCase {

    public function test_audit_logger_loads() {
        $this->assertTrue(class_exists('GDWB\\Admin\\Audit_Logger'));
    }

    public function test_audit_entries_created() {
        global $wpdb;

        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $admin_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Audit Test',
            'post_status' => 'publish'
        ]);

        $table = $wpdb->prefix . 'gdwb_audit_log';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE project_id = %d", $project_id));

        $this->assertGreaterThan(0, (int)$count, 'Audit log entry should be created');
    }

}

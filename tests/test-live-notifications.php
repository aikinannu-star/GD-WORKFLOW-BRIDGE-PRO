<?php
class Tests_Live_Notifications extends WP_UnitTestCase {

    public function test_notifications_module_loads() {
        $this->assertTrue(class_exists('GDWB\\Notifications\\Live_Notifications'));
    }

    public function test_notifications_created() {
        global $wpdb;
        
        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $customer_id = $this->factory->user->create(['role' => 'customer']);
        $admin_id = $this->factory->user->create(['role' => 'administrator']);

        $table = $wpdb->prefix . 'gdwb_notifications';
        $wpdb->insert($table, [
            'user_id' => $customer_id,
            'type' => 'project_created',
            'payload' => maybe_serialize(['project_title' => 'Test Project']),
            'created_at' => current_time('mysql'),
        ]);

        $notifications = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d", $customer_id));
        $this->assertCount(1, $notifications);
        $this->assertEquals('project_created', $notifications[0]->type);
    }
}

<?php
class Tests_Timeline extends WP_UnitTestCase {

    public function test_timeline_manager_loads() {
        $this->assertTrue(class_exists('GDWB\\Projects\\Timeline_Manager'));
    }

    public function test_timeline_entry_logged_on_project_created() {
        global $wpdb;

        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Timeline Test',
            'post_status' => 'publish'
        ]);

        do_action('gdwb_project_created', $project_id);

        $table = $wpdb->prefix . 'gdwb_timeline';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE project_id = %d", $project_id));

        $this->assertGreaterThan(0, (int)$count, 'Timeline entry should be created');
    }

}

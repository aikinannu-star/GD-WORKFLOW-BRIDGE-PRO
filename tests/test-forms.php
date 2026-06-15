<?php
class Tests_Forms_Manager extends WP_UnitTestCase {

    public function test_forms_manager_loads() {
        $this->assertTrue(class_exists('GDWB\\Projects\\Forms_Manager'));
    }

    public function test_revision_request_stored() {
        global $wpdb;
        
        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $user_id = $this->factory->user->create(['role' => 'customer']);
        wp_set_current_user($user_id);

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Form Test',
        ]);

        $table = $wpdb->prefix . 'gdwb_timeline';
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'event_type' => 'revision_request',
            'message' => maybe_serialize([
                'title' => 'Color Change',
                'description' => 'Change to blue',
                'priority' => 'high',
            ]),
            'user_id' => $user_id,
            'created_at' => current_time('mysql'),
        ]);

        $entries = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE project_id = %d AND event_type = %s", $project_id, 'revision_request'));
        $this->assertCount(1, $entries);
    }
}

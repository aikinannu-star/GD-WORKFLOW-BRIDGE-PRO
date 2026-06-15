<?php
class Tests_Chat_Manager extends WP_UnitTestCase {

    public function test_chat_manager_loads() {
        $this->assertTrue(class_exists('GDWB\\Projects\\Chat_Manager'));
    }

    public function test_messages_stored_in_database() {
        global $wpdb;
        
        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $user_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Chat Test Project',
            'post_author' => $user_id,
        ]);

        $table = $wpdb->prefix . 'gdwb_chat';
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'user_id' => $user_id,
            'message' => 'Test message',
            'is_private' => 0,
            'created_at' => current_time('mysql'),
        ]);

        $messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE project_id = %d", $project_id));
        $this->assertCount(1, $messages);
        $this->assertEquals('Test message', $messages[0]->message);
    }
}

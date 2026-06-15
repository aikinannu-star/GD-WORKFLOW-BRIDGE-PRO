<?php
class Tests_Files_Vault extends WP_UnitTestCase {

    public function test_files_vault_loads() {
        $this->assertTrue(class_exists('GDWB\\Integrations\\Files_Vault'));
    }

    public function test_files_stored_in_vault_table() {
        global $wpdb;
        
        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $user_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Vault Test',
            'post_author' => $user_id,
        ]);

        $table = $wpdb->prefix . 'gdwb_files';
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'file_name' => 'test.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $user_id,
            'created_at' => current_time('mysql'),
        ]);

        $files = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE project_id = %d", $project_id));
        $this->assertCount(1, $files);
        $this->assertEquals('test.pdf', $files[0]->file_name);
    }
}

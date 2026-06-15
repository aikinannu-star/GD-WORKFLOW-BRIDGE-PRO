<?php
class Tests_DB_Projects extends WP_UnitTestCase {

    public function test_db_row_created_on_project_save() {
        global $wpdb;

        // Ensure custom table exists by invoking activator
        if (class_exists('GDWB\\Core\\Activator')) {
            $activator = new \GDWB\Core\Activator();
            $activator->activate();
        }

        $post_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'DB Test Project',
            'post_status' => 'publish'
        ]);

        $table = $wpdb->prefix . 'gdwb_projects';
        $cnt = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id));
        $this->assertEquals(1, (int)$cnt, 'Project row should be created in custom table');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d", $post_id), ARRAY_A);
        $this->assertNotEmpty($row);
        $this->assertEquals($post_id, (int)$row['post_id']);
    }

}

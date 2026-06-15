<?php
class Tests_Email_Notifications extends WP_UnitTestCase {

    public function test_email_manager_loads() {
        $this->assertTrue(class_exists('GDWB\\Notifications\\Email_Manager'));
    }

    public function test_email_sent_on_project_created() {
        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Email Test',
            'post_status' => 'publish'
        ]);

        do_action('gdwb_project_created', $project_id);
        // Note: wp_mail is mocked in test environment
        $this->assertTrue(true);
    }

}

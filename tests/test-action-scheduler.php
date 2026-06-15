<?php
class Tests_Action_Scheduler extends WP_UnitTestCase {

    private $admin_id;

    public function setUp(): void {
        parent::setUp();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
    }

    public function test_fallback_triggers_do_action() {
        $called = 0;
        add_action('gdwb_process_project', function($args) use (&$called) { $called++; }, 0);

        $post_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'AS Fallback',
            'post_status' => 'publish'
        ]);

        $this->assertGreaterThan(0, $called, 'gdwb_process_project action should fire via fallback do_action');
    }

    public function test_with_stub_action_scheduler_schedules_action() {
        global $gdwb_as_calls;
        $gdwb_as_calls = [];

        if (!function_exists('as_next_scheduled_action')) {
            function as_next_scheduled_action($hook, $args = null) {
                global $gdwb_as_calls;
                return isset($gdwb_as_calls[$hook]) ? true : false;
            }
        }

        if (!function_exists('as_schedule_single_action')) {
            function as_schedule_single_action($timestamp, $hook, $args = []) {
                global $gdwb_as_calls;
                $gdwb_as_calls[$hook] = ['timestamp' => $timestamp, 'args' => $args];
                return true;
            }
        }

        $post_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'AS Stub',
            'post_status' => 'publish'
        ]);

        $this->assertArrayHasKey('gdwb_process_project', $gdwb_as_calls);
        $this->assertEquals(['project_id' => $post_id], $gdwb_as_calls['gdwb_process_project']['args']);
    }

}

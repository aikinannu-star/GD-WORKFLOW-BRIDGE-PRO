<?php
class Tests_REST_API extends WP_UnitTestCase {

    private $admin_id;
    private $project_id;

    public function setUp(): void {
        parent::setUp();
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        $this->project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Test Project',
            'post_status' => 'publish'
        ]);
    }

    public function test_get_projects_requires_auth() {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET','/gdwb/v1/projects');
        $response = rest_get_server()->dispatch($request);
        $this->assertInstanceOf('WP_Error', $response);
        $this->assertEquals(403, $response->get_error_data()['status'] ?? 403);
    }

    public function test_get_projects_success() {
        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request('GET','/gdwb/v1/projects');
        $response = rest_get_server()->dispatch($request);
        $this->assertNotInstanceOf('WP_Error', $response);
        $data = $response->get_data();
        $this->assertIsArray($data);
        $found = false;
        foreach ($data as $p) {
            if ((int)$p['id'] === (int)$this->project_id) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Created project must be returned by the API');
    }

    public function test_create_project_permission() {
        $subscriber = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $request = new WP_REST_Request('POST','/gdwb/v1/projects');
        $request->set_body_params(['title'=>'New Project']);
        $response = rest_get_server()->dispatch($request);
        $this->assertInstanceOf('WP_Error', $response);
        $this->assertEquals(403, $response->get_error_data()['status'] ?? 403);

        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request('POST','/gdwb/v1/projects');
        $request->set_body_params(['title'=>'New Project']);
        $response = rest_get_server()->dispatch($request);
        $this->assertNotInstanceOf('WP_Error', $response);
        $data = $response->get_data();
        $this->assertArrayHasKey('id', $data);
        $this->assertGreaterThan(0, $data['id']);
    }

}

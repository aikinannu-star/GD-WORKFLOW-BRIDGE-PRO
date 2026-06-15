<?php
class Tests_Stats_API extends WP_UnitTestCase {

    public function test_stats_endpoint_registered_and_returns_keys() {
        // Ensure plugin initialized
        \GDWB\Core\Plugin::instance();

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));

        $request = new WP_REST_Request('GET', '/gdwb/v1/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('total_projects', $data);
        $this->assertArrayHasKey('total_revenue', $data);
    }
}
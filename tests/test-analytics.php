<?php
class Tests_Analytics extends WP_UnitTestCase {

    public function test_analytics_loads() {
        $this->assertTrue(class_exists('GDWB\\Integrations\\Analytics'));
    }

    public function test_metrics_recorded() {
        global $wpdb;

        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $project_id = $this->factory->post->create([
            'post_type' => 'gdwb_project',
            'post_title' => 'Analytics Test',
            'post_status' => 'publish'
        ]);

        do_action('gdwb_project_created', $project_id);

        $table = $wpdb->prefix . 'gdwb_analytics';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE metric_name = %s", 'projects_created'));

        $this->assertGreaterThan(0, (int)$count, 'Metric should be recorded');
    }

}

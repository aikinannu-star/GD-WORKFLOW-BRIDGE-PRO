<?php
class Tests_Services_Category extends WP_UnitTestCase {

    public function test_service_category_created_on_activation() {
        $activator = new \GDWB\Core\Activator();
        $activator->activate();

        $term_id = get_option('gdwb_service_category_id');
        $this->assertNotEmpty($term_id, 'Service category should be created and option saved');

        $term = get_term($term_id, 'product_cat');
        $this->assertNotWPError($term);
        $this->assertEquals('Services', $term->name);
    }
}

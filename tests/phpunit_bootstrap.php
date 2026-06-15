<?php
// Minimal bootstrap for license-server phpunit tests
date_default_timezone_set('UTC');
// Ensure license-server helpers are loadable via relative paths
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . '/../license-server');

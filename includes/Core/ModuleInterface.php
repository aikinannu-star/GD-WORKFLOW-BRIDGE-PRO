<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

interface ModuleInterface {
    public function init(ServiceContainer $container): void;
}

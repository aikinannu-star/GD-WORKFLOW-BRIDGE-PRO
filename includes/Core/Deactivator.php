<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class Deactivator {
    public function deactivate(): void {
        // Intentionally left blank. Do not drop tables on deactivation.
    }
}

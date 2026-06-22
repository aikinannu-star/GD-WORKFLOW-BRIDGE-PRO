<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class Logger {
    public function log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[gdwb] ' . $message);
        }
    }
}

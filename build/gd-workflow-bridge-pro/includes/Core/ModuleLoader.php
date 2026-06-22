<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class ModuleLoader {
    protected ServiceContainer $container;
    protected array $modules = [];

    public function __construct(ServiceContainer $container) {
        $this->container = $container;
    }

    public function registerModule(string $className): void {
        if (!in_array($className, $this->modules, true)) {
            $this->modules[] = $className;
        }
    }

    public function init(): void {
        foreach ($this->modules as $moduleClass) {
            if (!class_exists($moduleClass)) {
                $relative = str_replace('GDWB\\', '', $moduleClass);
                $path = GDWB_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }

            if (class_exists($moduleClass)) {
                $module = new $moduleClass();
                if ($module instanceof ModuleInterface) {
                    $module->init($this->container);
                } elseif (method_exists($module, 'init')) {
                    $module->init($this->container);
                }
            }
        }
    }
}

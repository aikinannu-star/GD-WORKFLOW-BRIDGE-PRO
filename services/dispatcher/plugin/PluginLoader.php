<?php

/**
 * Discovers and loads plugins from a directory structure
 * 
 * Plugin directory structure:
 * plugins/
 *   my-plugin/
 *     plugin.json
 *     MyPlugin.php
 *   another-plugin/
 *     plugin.json
 *     AnotherPlugin.php
 */
class PluginLoader
{
    private string $pluginsDir;
    private array $discoveredPlugins = [];

    public function __construct(string $pluginsDir)
    {
        $normalizedPath = $this->normalizePath($pluginsDir);
        if (!is_dir($normalizedPath)) {
            throw new \RuntimeException("Plugins directory not found: {$normalizedPath}");
        }
        $this->pluginsDir = rtrim($normalizedPath, DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path);
        return realpath($normalized) ?: $normalized;
    }

    /**
     * Discover all plugins in the plugins directory
     * Returns array of [pluginId => PluginManifest]
     */
    public function discover(): array
    {
        $this->discoveredPlugins = [];

        $items = scandir($this->pluginsDir);
        if ($items === false) {
            throw new \RuntimeException("Cannot read plugins directory: {$this->pluginsDir}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $pluginDir = $this->pluginsDir . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($pluginDir)) {
                continue;
            }

            $manifestPath = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (file_exists($manifestPath)) {
                try {
                    $manifest = PluginManifest::fromFile($manifestPath);
                    $this->discoveredPlugins[$manifest->getId()] = [
                        'manifest' => $manifest,
                        'directory' => $pluginDir,
                    ];
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException("Failed to load plugin manifest from {$manifestPath}: {$e->getMessage()}");
                }
            }
        }

        return $this->discoveredPlugins;
    }

    /**
     * Get discovered plugin by ID
     */
    public function getPlugin(string $pluginId): ?array
    {
        return $this->discoveredPlugins[$pluginId] ?? null;
    }

    /**
     * Load and instantiate a plugin class
     * 
     * The plugin.json must specify an 'entry' field pointing to the PHP class file
     * relative to the plugin directory
     */
    public function loadPluginClass(string $pluginId): ?PluginInterface
    {
        $plugin = $this->getPlugin($pluginId);
        if (!$plugin) {
            throw new \RuntimeException("Plugin not discovered: {$pluginId}");
        }

        $manifest = $plugin['manifest'];
        if (empty($manifest->getEntry())) {
            throw new \RuntimeException("Plugin {$pluginId} does not specify an entry point");
        }

        $entryPath = $plugin['directory'] . DIRECTORY_SEPARATOR . $manifest->getEntry();
        if (!file_exists($entryPath)) {
            throw new \RuntimeException("Plugin entry file not found: {$entryPath}");
        }

        require_once $entryPath;

        // Try to infer class name from filename (e.g., EmailPlugin.php -> EmailPlugin)
        $fileName = basename($manifest->getEntry(), '.php');
        $className = $fileName;

        if (!class_exists($className)) {
            throw new \RuntimeException("Class not found: {$className} in {$entryPath}");
        }

        $instance = new $className();
        if (!$instance instanceof PluginInterface) {
            throw new \RuntimeException("Plugin class {$className} must implement PluginInterface");
        }

        return $instance;
    }

    /**
     * Get all discovered manifests
     */
    public function getManifests(): array
    {
        return array_map(fn($p) => $p['manifest'], $this->discoveredPlugins);
    }

    /**
     * Get plugin directory
     */
    public function getPluginDirectory(string $pluginId): ?string
    {
        $plugin = $this->getPlugin($pluginId);
        return $plugin ? $plugin['directory'] : null;
    }
}

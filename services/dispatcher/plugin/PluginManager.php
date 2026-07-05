<?php
require_once __DIR__ . '/PluginInterface.php';
require_once __DIR__ . '/PluginManifest.php';
require_once __DIR__ . '/PluginLoader.php';
require_once __DIR__ . '/SemanticVersionComparator.php';
require_once __DIR__ . '/PluginDependencyResolver.php';
require_once __DIR__ . '/CapabilityRegistry.php';
require_once __DIR__ . '/PluginHealthService.php';

/**
 * Manages plugin lifecycle: registration, discovery, loading, version validation
 */
class PluginManager
{
    private $plugins = [];
    private $manifests = [];
    private $loaded = [];
    private $runtimeVersion = '1.0.0';
    private $loader;
    private $dependencyResolver;
    private $capabilityRegistry;
    private $healthService;

    public function __construct(string $runtimeVersion = '1.0.0')
    {
        $this->runtimeVersion = $runtimeVersion;
    }

    public function setDependencyResolver(PluginDependencyResolver $resolver): void
    {
        $this->dependencyResolver = $resolver;
    }

    public function setCapabilityRegistry(CapabilityRegistry $registry): void
    {
        $this->capabilityRegistry = $registry;
    }

    public function setHealthService(PluginHealthService $healthService): void
    {
        $this->healthService = $healthService;
    }

    /**
     * Register a plugin instance directly (for in-code plugins)
     */
    public function register(PluginInterface $plugin): void
    {
        $name = $plugin->getName();
        $this->plugins[$name] = $plugin;
    }

    /**
     * Set the PluginLoader for manifest-based discovery
     */
    public function setLoader(PluginLoader $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * Discover plugins from manifest files
     * Must call this before loading manifest-based plugins
     */
    public function discoverFromManifests(): array
    {
        if (!$this->loader) {
            throw new Exception('PluginLoader not set. Call setLoader() first.');
        }

        $discovered = $this->loader->discover();
        foreach ($discovered as $pluginId => $plugin) {
            $this->manifests[$pluginId] = $plugin['manifest'];
        }

        return array_keys($discovered);
    }

    public function getAllManifests(): array
    {
        return $this->manifests;
    }

    public function resolveLoadOrder(): array
    {
        if ($this->dependencyResolver && !empty($this->manifests)) {
            return $this->dependencyResolver->resolve($this->manifests);
        }

        return array_merge(array_keys($this->plugins), array_keys($this->manifests));
    }

    /**
     * Load a plugin by name and register its extensions
     */
    public function load(string $name, RuntimeRegistrar $registrar): void
    {
        if (isset($this->loaded[$name])) {
            return; // Already loaded
        }

        // If it has a manifest, validate version compatibility first
        if (isset($this->manifests[$name])) {
            $manifest = $this->manifests[$name];
            $this->validateManifestCompatibility($manifest);
        }

        // If plugin not yet instantiated but has manifest, load it from manifest
        if (!isset($this->plugins[$name]) && isset($this->manifests[$name])) {
            if (!$this->loader) {
                throw new Exception('PluginLoader not set. Cannot load manifest-based plugin: ' . $name);
            }
            $this->plugins[$name] = $this->loader->loadPluginClass($name);
        }

        if (!isset($this->plugins[$name])) {
            throw new Exception('plugin_not_found: ' . $name);
        }

        $pluginManifest = $this->manifests[$name] ?? null;
        if ($pluginManifest instanceof PluginManifest) {
            $registrar->setActivePlugin($pluginManifest->getId(), $pluginManifest->getPermissions());
        }

        try {
            $this->plugins[$name]->register($registrar);
        } finally {
            $registrar->clearActivePlugin();
        }

        if ($this->capabilityRegistry !== null && isset($this->manifests[$name])) {
            $manifest = $this->manifests[$name];
            foreach ($manifest->getActions() as $actionName) {
                $this->capabilityRegistry->register($name, 'actions', $actionName, ['plugin' => $name]);
            }
            foreach ($manifest->getMiddleware() as $middlewareName) {
                $this->capabilityRegistry->register($name, 'middleware', $middlewareName, ['plugin' => $name]);
            }
            foreach ($manifest->getWorkers() as $workerName) {
                $this->capabilityRegistry->register($name, 'workers', $workerName, ['plugin' => $name]);
            }
        }

        if ($this->healthService !== null) {
            $this->healthService->markEnabled($name);
        }

        $this->loaded[$name] = true;
    }

    /**
     * Load all registered and discovered plugins
     */
    public function loadAll(RuntimeRegistrar $registrar): void
    {
        $order = $this->resolveLoadOrder();

        foreach ($order as $name) {
            $this->load($name, $registrar);
        }
    }

    /**
     * Get plugin instance by name
     */
    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Get plugin manifest by name
     */
    public function getManifest(string $name): ?PluginManifest
    {
        return $this->manifests[$name] ?? null;
    }

    /**
     * List all plugins with their metadata
     */
    public function listPlugins(): array
    {
        $list = [];

        foreach ($this->plugins as $name => $plugin) {
            $manifest = $this->manifests[$name] ?? null;
            $list[] = [
                'id' => $manifest ? $manifest->getId() : $name,
                'name' => $manifest ? $manifest->getName() : $name,
                'version' => $manifest ? $manifest->getVersion() : $plugin->getVersion(),
                'loaded' => isset($this->loaded[$name]),
                'from' => $manifest ? 'manifest' : 'code',
            ];
        }

        // Include manifest-only plugins (not yet instantiated)
        foreach ($this->manifests as $id => $manifest) {
            if (!isset($this->plugins[$id])) {
                $list[] = [
                    'id' => $id,
                    'name' => $manifest->getName(),
                    'version' => $manifest->getVersion(),
                    'loaded' => isset($this->loaded[$id]),
                    'from' => 'manifest',
                ];
            }
        }

        return $list;
    }

    /**
     * Validate that a manifest is compatible with the current runtime
     */
    public function validateManifestCompatibility(PluginManifest $manifest): void
    {
        if (!SemanticVersionComparator::isCompatible($this->runtimeVersion, $manifest->getMinimumRuntimeVersion())) {
            throw new Exception(
                'incompatible_plugin_version: Plugin ' . $manifest->getId() . ' v' . $manifest->getVersion() .
                ' requires runtime >= ' . $manifest->getMinimumRuntimeVersion() .
                ' but runtime is ' . $this->runtimeVersion
            );
        }
    }

    /**
     * Get runtime version
     */
    public function getRuntimeVersion(): string
    {
        return $this->runtimeVersion;
    }
}

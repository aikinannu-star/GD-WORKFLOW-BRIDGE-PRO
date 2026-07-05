<?php
require_once __DIR__ . '/PluginManager.php';
require_once __DIR__ . '/PluginLoader.php';
require_once __DIR__ . '/PluginDependencyResolver.php';
require_once __DIR__ . '/RuntimeRegistrar.php';

class PluginLifecycleManager
{
    private PluginManager $pluginManager;
    private PluginLoader $pluginLoader;
    private PluginDependencyResolver $resolver;
    private array $states = [];

    public function __construct(PluginManager $pluginManager, PluginLoader $pluginLoader, PluginDependencyResolver $resolver)
    {
        $this->pluginManager = $pluginManager;
        $this->pluginLoader = $pluginLoader;
        $this->resolver = $resolver;
    }

    public function install(string $pluginId): void
    {
        $this->pluginLoader->discover();
        if (!$this->pluginLoader->getPlugin($pluginId)) {
            throw new RuntimeException("Plugin {$pluginId} not found for installation");
        }

        $this->states[$pluginId] = 'installed';
    }

    public function validate(string $pluginId): void
    {
        $manifest = $this->pluginLoader->getPlugin($pluginId)['manifest'] ?? null;
        if (!$manifest) {
            throw new RuntimeException("Plugin {$pluginId} manifest not found for validation");
        }

        $this->pluginManager->setLoader($this->pluginLoader);
        $this->pluginManager->discoverFromManifests();
        $this->pluginManager->validateManifestCompatibility($manifest);
        $this->states[$pluginId] = 'validated';
    }

    public function resolve(): array
    {
        $this->pluginManager->setLoader($this->pluginLoader);
        $this->pluginManager->discoverFromManifests();
        $manifests = $this->pluginManager->getAllManifests();
        $order = $this->resolver->resolve($manifests);
        $this->states = array_merge($this->states, array_fill_keys($order, 'resolved'));
        return $order;
    }

    public function enable(RuntimeRegistrar $registrar): void
    {
        $this->pluginManager->setLoader($this->pluginLoader);
        $this->pluginManager->discoverFromManifests();
        $order = $this->resolve();

        foreach ($order as $pluginId) {
            $this->pluginManager->load($pluginId, $registrar);
            $this->states[$pluginId] = 'enabled';
        }
    }

    public function disable(string $pluginId): void
    {
        if (!isset($this->states[$pluginId]) || $this->states[$pluginId] !== 'enabled') {
            return;
        }

        $this->states[$pluginId] = 'disabled';
    }

    public function uninstall(string $pluginId): void
    {
        $this->disable($pluginId);
        unset($this->states[$pluginId]);
    }

    public function getState(string $pluginId): ?string
    {
        return $this->states[$pluginId] ?? null;
    }
}

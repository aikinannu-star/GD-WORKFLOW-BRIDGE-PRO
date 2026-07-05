<?php
require_once __DIR__ . '/PluginManifest.php';
require_once __DIR__ . '/PluginDependencyException.php';
require_once __DIR__ . '/SemanticVersionComparator.php';

class PluginDependencyResolver
{
    /**
     * Resolve activation order for plugins with dependency and version validation.
     *
     * @param PluginManifest[] $manifests keyed by plugin id
     * @return string[] ordered plugin ids
     * @throws PluginDependencyException
     */
    public function resolve(array $manifests): array
    {
        $nodes = array_keys($manifests);
        $inDegree = array_fill_keys($nodes, 0);
        $adjacency = array_fill_keys($nodes, []);

        foreach ($manifests as $pluginId => $manifest) {
            $dependencies = $this->normalizeDependencies($manifest->getDependencies());
            $optionalDependencies = $this->normalizeDependencies($manifest->getOptionalDependencies());

            foreach ($dependencies as $dependency) {
                $this->validateDependency($dependency, $manifests, false);
                $adjacency[$dependency['id']][] = $pluginId;
                $inDegree[$pluginId]++;
            }

            foreach ($optionalDependencies as $dependency) {
                if (!isset($manifests[$dependency['id']])) {
                    continue;
                }
                $this->validateDependency($dependency, $manifests, true);
                $adjacency[$dependency['id']][] = $pluginId;
                $inDegree[$pluginId]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $pluginId => $degree) {
            if ($degree === 0) {
                $queue[] = $pluginId;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($adjacency[$current] as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        if (count($sorted) !== count($nodes)) {
            throw new CircularDependencyException('Circular plugin dependency detected');
        }

        return $sorted;
    }

    private function normalizeDependencies(array $dependencies): array
    {
        return array_map(function ($dependency) {
            if (is_string($dependency)) {
                return ['id' => $dependency, 'version' => null, 'optional' => false];
            }

            if (!is_array($dependency) || !isset($dependency['id'])) {
                throw new PluginDependencyException('Invalid dependency declaration');
            }

            return [
                'id' => $dependency['id'],
                'version' => $dependency['version'] ?? null,
                'optional' => $dependency['optional'] ?? false,
            ];
        }, $dependencies);
    }

    private function validateDependency(array $dependency, array $manifests, bool $optional): void
    {
        $pluginId = $dependency['id'];
        if (!isset($manifests[$pluginId])) {
            if ($optional) {
                return;
            }
            throw new MissingPluginException("Missing required plugin dependency: {$pluginId}");
        }

        if ($dependency['version'] !== null) {
            $actualVersion = $manifests[$pluginId]->getVersion();
            if (!SemanticVersionComparator::satisfies($actualVersion, $dependency['version'])) {
                throw new VersionConflictException(
                    "Plugin {$manifests[$pluginId]->getId()} version {$actualVersion} does not satisfy dependency constraint {$dependency['version']}"
                );
            }
        }
    }
}

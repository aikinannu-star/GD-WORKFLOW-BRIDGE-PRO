<?php

/**
 * Represents a plugin's metadata and configuration from plugin.json
 * 
 * Plugin manifests describe:
 * - Identity (id, name, version, author)
 * - Runtime requirements (minimum version, dependencies)
 * - Capabilities and permissions (what the plugin is allowed to do)
 * - Extension points (actions, middleware, workers, listeners)
 */
class PluginManifest
{
    private string $id;
    private string $name;
    private string $version;
    private string $author;
    private string $description;
    private string $minimumRuntimeVersion;
    private array $dependencies;
    private array $optionalDependencies;
    private array $permissions;
    private string $entry;
    private array $actions;
    private array $middleware;
    private array $workers;
    private array $eventListeners;
    private array $metadata;
    private ?string $manifestPath;

    public function __construct(array $data, ?string $manifestPath = null)
    {
        $this->validateManifest($data);

        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->version = $data['version'];
        $this->author = $data['author'];
        $this->description = $data['description'] ?? '';
        $this->minimumRuntimeVersion = $data['minimumRuntimeVersion'] ?? '1.0.0';
        $this->dependencies = $data['dependencies'] ?? [];
        $this->optionalDependencies = $data['optionalDependencies'] ?? [];
        $this->permissions = $data['permissions'] ?? [];
        $this->entry = $data['entry'] ?? '';
        $this->actions = $data['actions'] ?? [];
        $this->middleware = $data['middleware'] ?? [];
        $this->workers = $data['workers'] ?? [];
        $this->eventListeners = $data['eventListeners'] ?? [];
        $this->metadata = $data['metadata'] ?? [];
        $this->manifestPath = $manifestPath;
    }

    /**
     * Load manifest from JSON file
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Manifest not found: {$path}");
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in manifest {$path}: " . json_last_error_msg());
        }

        return new self($data, $path);
    }

    /**
     * Load manifest from JSON string
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in manifest: " . json_last_error_msg());
        }

        return new self($data);
    }

    private function validateManifest(array $data): void
    {
        $required = ['id', 'name', 'version', 'author'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \RuntimeException("Manifest missing required field: {$field}");
            }
        }

        // Validate manifest structure
        if (!preg_match('/^[a-z0-9\-]+$/', $data['id'])) {
            throw new \RuntimeException("Plugin ID must be lowercase alphanumeric with hyphens");
        }

        // Validate semantic version format
        if (!$this->isValidSemanticVersion($data['version'])) {
            throw new \RuntimeException("Invalid semantic version: {$data['version']}");
        }

        if (isset($data['minimumRuntimeVersion']) && !$this->isValidSemanticVersion($data['minimumRuntimeVersion'])) {
            throw new \RuntimeException("Invalid semantic version in minimumRuntimeVersion: {$data['minimumRuntimeVersion']}");
        }

        // Validate arrays
        foreach (['dependencies', 'optionalDependencies', 'permissions', 'actions', 'middleware', 'workers', 'eventListeners', 'metadata'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                throw new \RuntimeException("Field {$field} must be an array");
            }
        }

        $this->validateDependencyDeclarations($data['dependencies'] ?? []);
        $this->validateDependencyDeclarations($data['optionalDependencies'] ?? []);

        // Validate permissions are from allowed set
        $validPermissions = ['network', 'filesystem', 'database', 'tenant_data', 'scheduler', 'background_workers'];
        foreach ($data['permissions'] ?? [] as $perm) {
            if (!in_array($perm, $validPermissions)) {
                throw new \RuntimeException("Unknown permission: {$perm}. Allowed: " . implode(', ', $validPermissions));
            }
        }
    }

    private function validateDependencyDeclarations(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if (is_string($dependency)) {
                continue;
            }

            if (!is_array($dependency) || !isset($dependency['id'])) {
                throw new \RuntimeException('Dependency entries must be strings or objects containing an id');
            }

            if (isset($dependency['version']) && !$this->isValidVersionConstraint($dependency['version'])) {
                throw new \RuntimeException('Invalid dependency version constraint: ' . $dependency['version']);
            }
        }
    }

    private function isValidVersionConstraint(string $version): bool
    {
        if ($this->isValidSemanticVersion($version)) {
            return true;
        }

        return (bool)preg_match('/^(>=|<=|>|<|\^|~)?\d+\.\d+\.\d+$/', $version);
    }

    private function isValidSemanticVersion(string $version): bool
    {
        return (bool)preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?(\+[a-zA-Z0-9]+)?$/', $version);
    }

    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getVersion(): string { return $this->version; }
    public function getAuthor(): string { return $this->author; }
    public function getDescription(): string { return $this->description; }
    public function getMinimumRuntimeVersion(): string { return $this->minimumRuntimeVersion; }
    public function getDependencies(): array { return $this->dependencies; }
    public function getOptionalDependencies(): array { return $this->optionalDependencies; }
    public function getPermissions(): array { return $this->permissions; }
    public function getEntry(): string { return $this->entry; }
    public function getActions(): array { return $this->actions; }
    public function getMiddleware(): array { return $this->middleware; }
    public function getWorkers(): array { return $this->workers; }
    public function getEventListeners(): array { return $this->eventListeners; }
    public function getMetadata(): array { return $this->metadata; }
    public function getManifestPath(): ?string { return $this->manifestPath; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'minimumRuntimeVersion' => $this->minimumRuntimeVersion,
            'dependencies' => $this->dependencies,
            'permissions' => $this->permissions,
            'entry' => $this->entry,
            'actions' => $this->actions,
            'middleware' => $this->middleware,
            'workers' => $this->workers,
            'eventListeners' => $this->eventListeners,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if this manifest requires a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    /**
     * Check if this manifest has a dependency
     */
    public function hasDependency(string $pluginId): bool
    {
        return in_array($pluginId, $this->dependencies);
    }
}

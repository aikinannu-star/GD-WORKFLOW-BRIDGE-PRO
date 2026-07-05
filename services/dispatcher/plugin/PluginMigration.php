<?php

class PluginMigration
{
    private string $version;
    private $up;
    private $down;

    public function __construct(string $version, callable $up, callable $down = null)
    {
        $this->version = $version;
        $this->up = $up;
        $this->down = $down;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function up(): void
    {
        ($this->up)();
    }

    public function down(): void
    {
        if ($this->down !== null) {
            ($this->down)();
        }
    }
}

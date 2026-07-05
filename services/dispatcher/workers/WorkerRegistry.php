<?php
class WorkerRegistry
{
    private $workers = [];

    public function register(string $name, $worker): void
    {
        $this->workers[$name] = $worker;
    }

    public function has(string $name): bool
    {
        return isset($this->workers[$name]);
    }

    public function get(string $name)
    {
        return $this->workers[$name] ?? null;
    }

    public function all(): array
    {
        return $this->workers;
    }
}

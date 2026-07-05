<?php
class RuntimeContainer
{
    private $services = [];
    private $singletons = [];

    public function set(string $key, $value, bool $singleton = true): void
    {
        $this->services[$key] = $value;
        if ($singleton) {
            $this->singletons[$key] = true;
        }
    }

    public function get(string $key)
    {
        if (!isset($this->services[$key])) {
            throw new Exception('service_not_found: ' . $key);
        }

        $service = $this->services[$key];
        if (is_callable($service)) {
            return $service($this);
        }

        return $service;
    }

    public function has(string $key): bool
    {
        return isset($this->services[$key]);
    }
}

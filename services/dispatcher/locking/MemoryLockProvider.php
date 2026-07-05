<?php
require_once __DIR__ . '/LockProviderInterface.php';

class MemoryLockProvider implements LockProviderInterface
{
    private $locks = [];

    public function acquire(string $key): bool
    {
        if (isset($this->locks[$key])) {
            return false;
        }
        $this->locks[$key] = true;
        return true;
    }

    public function release(string $key): void
    {
        unset($this->locks[$key]);
    }
}

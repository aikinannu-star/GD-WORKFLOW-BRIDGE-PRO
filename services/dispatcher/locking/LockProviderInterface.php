<?php
interface LockProviderInterface
{
    public function acquire(string $key): bool;
    public function release(string $key): void;
}

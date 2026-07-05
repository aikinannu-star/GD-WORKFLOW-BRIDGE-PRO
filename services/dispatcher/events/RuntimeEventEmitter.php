<?php
class RuntimeEventEmitter
{
    private $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }
}

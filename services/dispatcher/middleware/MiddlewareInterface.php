<?php
interface MiddlewareInterface
{
    public function handle(array $context, callable $next): array;
}

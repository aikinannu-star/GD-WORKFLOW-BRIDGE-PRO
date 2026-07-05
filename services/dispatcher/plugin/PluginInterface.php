<?php
interface PluginInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function register(RuntimeRegistrar $registrar): void;
}

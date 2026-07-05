<?php

interface ToolInterface
{
    /**
     * Unique tool id
     */
    public function id(): string;

    /**
     * Human friendly name
     */
    public function name(): string;

    /**
     * Short description
     */
    public function description(): string;

    /**
     * JSON schema or array describing input shape (optional)
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with provided arguments
     * Returns ['success' => bool, 'result' => mixed, 'error' => null|string]
     */
    public function execute(array $args): array;
}

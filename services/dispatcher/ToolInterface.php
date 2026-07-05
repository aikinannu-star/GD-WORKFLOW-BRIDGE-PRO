<?php

interface ToolInterface
{
    /**
     * Whether this tool supports the given task type.
     * @param string $taskType
     * @return bool
     */
    public function supports(string $taskType): bool;

    /**
     * Execute the tool for the given payload.
     * Returns ['status' => int, 'result' => mixed]
     * @param array $payload
     * @return array
     */
    public function execute(array $payload): array;
}

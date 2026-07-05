<?php

interface ExecutionReportRepositoryInterface
{
    public function save(array $report): bool;
    public function savePartial(string $executionId, array $partial): bool;
}

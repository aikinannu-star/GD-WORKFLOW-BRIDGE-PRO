<?php

interface MemoryLifecycleStageInterface
{
    public function process(MemoryRecord $record, array $context = []): MemoryRecord;
}

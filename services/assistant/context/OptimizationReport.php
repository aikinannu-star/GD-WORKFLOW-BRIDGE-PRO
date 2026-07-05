<?php

require_once __DIR__ . '/PipelineReport.php';

class OptimizationReport extends PipelineReport
{
    public function __construct()
    {
        parent::__construct('promptOptimization');
        $this->start();
    }

    public function addAppliedStage(string $name, array $details = []): self
    {
        $this->addStageExecuted($name, $details);
        return $this;
    }

    public function addSkippedStage(string $name, array $details = []): self
    {
        $this->addStageSkipped($name, $details);
        return $this;
    }

    public function addMessage(string $level, string $message): self
    {
        parent::addMessage($level, $message);
        return $this;
    }
}

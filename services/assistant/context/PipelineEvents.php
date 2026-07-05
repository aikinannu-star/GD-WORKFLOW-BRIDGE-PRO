<?php

class PipelineEvents
{
    public const PROMPT_OPTIMIZATION_PIPELINE_STARTED = 'promptOptimization.pipeline.started';
    public const PROMPT_OPTIMIZATION_PIPELINE_COMPLETED = 'promptOptimization.pipeline.completed';
    public const PROMPT_OPTIMIZATION_PIPELINE_STAGE_SKIPPED = 'promptOptimization.pipeline.stageSkipped';
    public const PROMPT_OPTIMIZATION_STAGE_STARTED = 'promptOptimization.stage.started';
    public const PROMPT_OPTIMIZATION_STAGE_COMPLETED = 'promptOptimization.stage.completed';
    public const PROMPT_OPTIMIZATION_VALIDATION_STARTED = 'promptOptimization.validation.started';
    public const PROMPT_OPTIMIZATION_VALIDATION_COMPLETED = 'promptOptimization.validation.completed';
    public const PROMPT_OPTIMIZATION_PIPELINE_FAILED = 'promptOptimization.pipeline.failed';
}

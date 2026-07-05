<?php

interface PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult;
    public function supports(ModelProviderInterface $provider): bool;
}

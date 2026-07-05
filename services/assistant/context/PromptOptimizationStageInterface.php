<?php

interface PromptOptimizationStageInterface
{
    public function supports(AssistantContext $context, ModelProviderInterface $provider, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): bool;
    public function optimize(PromptContext $prompt): PromptContext;
    public function priority(): int;
}

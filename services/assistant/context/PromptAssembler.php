<?php

require_once __DIR__ . '/PromptTemplate.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/PromptOptimizationPipeline.php';

class PromptAssembler
{
    private PromptTemplate $template;
    private ?PromptOptimizationPipeline $optimizer;

    public function __construct(?PromptTemplate $template = null, ?PromptOptimizationPipeline $optimizer = null)
    {
        $this->template = $template ?? new PromptTemplate();
        $this->optimizer = $optimizer;
    }

    public function assemble(AssistantContext $context, string $message, array $contextSections = [], ?array $toolResult = null, ?ModelProviderInterface $provider = null): string
    {
        $data = [
            'instructions' => 'Assistant: process a user message using available tools.',
            'assistantId' => $context->assistantId,
            'sessionId' => $context->sessionId,
            'userId' => $context->userId,
            'message' => $message,
            'sections' => $contextSections,
            'toolResult' => $toolResult,
        ];

        if ($provider !== null && $this->optimizer !== null) {
            return $this->optimizer->optimize($data, $provider)->getPrompt();
        }

        return $this->template->render($data);
    }
}

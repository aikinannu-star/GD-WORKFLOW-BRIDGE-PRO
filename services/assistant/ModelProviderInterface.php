<?php

interface ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array;
    public function stream(string $prompt, array $options = []): iterable;
    public function embeddings(string $input, array $options = []): array;
    public function health(): array;
    public function capabilities(): array;
}

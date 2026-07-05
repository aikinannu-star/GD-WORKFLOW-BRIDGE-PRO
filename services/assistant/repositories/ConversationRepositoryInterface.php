<?php

interface ConversationRepositoryInterface
{
    public function create(string $id, array $metadata = [], ?string $tenantId = 'default', ?string $userId = null): array;

    public function get(string $id): ?array;

    public function save(array $conversation): array;

    public function appendMessage(string $id, array $message): array;

    public function getHistory(string $id): array;

    public function addMetadata(string $id, array $metadata): array;
}

<?php

require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/ProviderInfo.php';
require_once __DIR__ . '/ModelProfile.php';

class PromptContext
{
    private array $data;
    private string $content;
    private array $metadata;
    private array $auditEntries;
    private ?ProviderInfo $providerInfo;
    private ?ModelProfile $modelProfile;
    private ?ModelProviderInterface $provider;

    public function __construct(array $data = [], ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null, ?ModelProviderInterface $provider = null, string $content = '')
    {
        $this->data = $data;
        $this->metadata = [];
        $this->auditEntries = [];
        $this->providerInfo = $providerInfo;
        $this->modelProfile = $modelProfile;
        $this->provider = $provider;
        $this->content = $content !== '' ? $content : (string)($data['content'] ?? $data['prompt'] ?? '');
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDataValue(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function setDataValue(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function appendContent(string $content): self
    {
        if ($this->content === '') {
            $this->content = $content;
            return $this;
        }

        $this->content .= $content;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getAuditEntries(): array
    {
        return $this->auditEntries;
    }

    public function recordChange(string $stageName, string $message): self
    {
        $this->auditEntries[] = [
            'stage' => $stageName,
            'message' => $message,
        ];
        return $this;
    }

    public function getProviderInfo(): ?ProviderInfo
    {
        return $this->providerInfo;
    }

    public function setProviderInfo(?ProviderInfo $providerInfo): self
    {
        $this->providerInfo = $providerInfo;
        return $this;
    }

    public function getModelProfile(): ?ModelProfile
    {
        return $this->modelProfile;
    }

    public function setModelProfile(?ModelProfile $modelProfile): self
    {
        $this->modelProfile = $modelProfile;
        return $this;
    }

    public function getProvider(): ?ModelProviderInterface
    {
        return $this->provider;
    }

    public function setProvider(?ModelProviderInterface $provider): self
    {
        $this->provider = $provider;
        return $this;
    }
}

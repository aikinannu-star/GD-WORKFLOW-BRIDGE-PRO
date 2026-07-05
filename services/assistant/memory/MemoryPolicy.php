<?php

class MemoryPolicy
{
    public bool $rememberUserPreferences;
    public bool $rememberFacts;
    public bool $rememberProjects;
    public bool $rememberContacts;
    public bool $rememberTemporaryInformation;

    public function __construct(
        bool $rememberUserPreferences = true,
        bool $rememberFacts = true,
        bool $rememberProjects = true,
        bool $rememberContacts = true,
        bool $rememberTemporaryInformation = false
    ) {
        $this->rememberUserPreferences = $rememberUserPreferences;
        $this->rememberFacts = $rememberFacts;
        $this->rememberProjects = $rememberProjects;
        $this->rememberContacts = $rememberContacts;
        $this->rememberTemporaryInformation = $rememberTemporaryInformation;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (bool)($data['remember_user_preferences'] ?? $data['rememberUserPreferences'] ?? true),
            (bool)($data['remember_facts'] ?? $data['rememberFacts'] ?? true),
            (bool)($data['remember_projects'] ?? $data['rememberProjects'] ?? true),
            (bool)($data['remember_contacts'] ?? $data['rememberContacts'] ?? true),
            (bool)($data['remember_temporary_information'] ?? $data['rememberTemporaryInformation'] ?? false)
        );
    }

    public function toArray(): array
    {
        return [
            'remember_user_preferences' => $this->rememberUserPreferences,
            'remember_facts' => $this->rememberFacts,
            'remember_projects' => $this->rememberProjects,
            'remember_contacts' => $this->rememberContacts,
            'remember_temporary_information' => $this->rememberTemporaryInformation,
        ];
    }
}

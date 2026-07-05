<?php
declare(strict_types=1);

class Violation
{
    private string $id;
    private string $rule;
    private string $severity;
    private string $message;
    private ?string $remediation;
    private array $location;

    public function __construct(string $id, string $rule, string $severity, string $message, ?string $remediation = null, array $location = [])
    {
        $this->id = $id;
        $this->rule = $rule;
        $this->severity = $severity;
        $this->message = $message;
        $this->remediation = $remediation;
        $this->location = $location;
    }

    public function getId(): string { return $this->id; }
    public function getRule(): string { return $this->rule; }
    public function getSeverity(): string { return $this->severity; }
    public function getMessage(): string { return $this->message; }
    public function getRemediation(): ?string { return $this->remediation; }
    public function getLocation(): array { return $this->location; }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule,
            'severity' => $this->severity,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'location' => $this->location,
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->toArray());
    }
}

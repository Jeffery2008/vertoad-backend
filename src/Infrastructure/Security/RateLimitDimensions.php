<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

final readonly class RateLimitDimensions
{
    public function __construct(
        public ?string $ip = null,
        public ?int $userId = null,
        public ?int $organizationId = null,
        public ?string $clientId = null,
        public ?string $endpoint = null,
    ) {
    }

    public function key(): string
    {
        $parts = [
            'ip=' . ($this->ip === null || trim($this->ip) === '' ? 'unknown' : trim($this->ip)),
            'user=' . ($this->userId === null ? 'anonymous' : (string) $this->userId),
            'org=' . ($this->organizationId === null ? 'none' : (string) $this->organizationId),
            'client=' . ($this->clientId === null || trim($this->clientId) === '' ? 'none' : trim($this->clientId)),
            'endpoint=' . ($this->endpoint === null || trim($this->endpoint) === '' ? 'unknown' : trim($this->endpoint)),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'client_id' => $this->clientId,
            'endpoint' => $this->endpoint,
            'has_ip' => $this->ip !== null && trim($this->ip) !== '',
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
        ];
    }
}

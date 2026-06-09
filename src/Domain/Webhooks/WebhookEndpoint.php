<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class WebhookEndpoint
{
    public ?int $id;
    public string $endpointId;
    public int $organizationId;
    public int $createdByUserId;
    public string $name;
    public string $endpointUrl;
    public string $status;
    /** @var list<string> */
    public array $events;
    public string $encryptedSigningSecret;
    public string $secretPreview;
    public DateTimeImmutable $secretRotatedAt;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $events
     */
    public function __construct(
        ?int $id,
        string $endpointId,
        int $organizationId,
        int $createdByUserId,
        string $name,
        string $endpointUrl,
        string $status,
        array $events,
        string $encryptedSigningSecret,
        string $secretPreview,
        DateTimeImmutable $secretRotatedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        $endpointId = trim($endpointId);
        $name = trim($name);
        $endpointUrl = trim($endpointUrl);
        $status = trim($status);
        $encryptedSigningSecret = trim($encryptedSigningSecret);
        $secretPreview = trim($secretPreview);
        $events = self::normalizeEvents($events);

        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('Webhook endpoint internal ID must be positive when provided.');
        }

        if ($endpointId === '') {
            throw new InvalidArgumentException('Webhook endpoint ID is required.');
        }

        if ($organizationId <= 0) {
            throw new InvalidArgumentException('Webhook endpoint organization ID must be positive.');
        }

        if ($createdByUserId <= 0) {
            throw new InvalidArgumentException('Webhook endpoint creator user ID must be positive.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Webhook endpoint name is required.');
        }

        self::validateEndpointUrl($endpointUrl);

        if (!in_array($status, ['active', 'paused'], true)) {
            throw new InvalidArgumentException('Webhook endpoint status is not supported.');
        }

        if ($events === []) {
            throw new InvalidArgumentException('Webhook endpoint requires at least one event.');
        }

        if ($encryptedSigningSecret === '') {
            throw new InvalidArgumentException('Webhook endpoint encrypted signing secret is required.');
        }

        if ($secretPreview === '') {
            throw new InvalidArgumentException('Webhook endpoint secret preview is required.');
        }

        $this->id = $id;
        $this->endpointId = $endpointId;
        $this->organizationId = $organizationId;
        $this->createdByUserId = $createdByUserId;
        $this->name = $name;
        $this->endpointUrl = $endpointUrl;
        $this->status = $status;
        $this->events = $events;
        $this->encryptedSigningSecret = $encryptedSigningSecret;
        $this->secretPreview = $secretPreview;
        $this->secretRotatedAt = $secretRotatedAt;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function enabled(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param list<string>|null $events
     */
    public function withChanges(
        ?string $name = null,
        ?string $endpointUrl = null,
        ?string $status = null,
        ?array $events = null,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            id: $this->id,
            endpointId: $this->endpointId,
            organizationId: $this->organizationId,
            createdByUserId: $this->createdByUserId,
            name: $name ?? $this->name,
            endpointUrl: $endpointUrl ?? $this->endpointUrl,
            status: $status ?? $this->status,
            events: $events ?? $this->events,
            encryptedSigningSecret: $this->encryptedSigningSecret,
            secretPreview: $this->secretPreview,
            secretRotatedAt: $this->secretRotatedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? new DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            endpointId: $this->endpointId,
            organizationId: $this->organizationId,
            createdByUserId: $this->createdByUserId,
            name: $this->name,
            endpointUrl: $this->endpointUrl,
            status: $this->status,
            events: $this->events,
            encryptedSigningSecret: $this->encryptedSigningSecret,
            secretPreview: $this->secretPreview,
            secretRotatedAt: $this->secretRotatedAt,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * @param list<string> $events
     * @return list<string>
     */
    public static function normalizeEvents(array $events): array
    {
        $normalized = [];
        foreach ($events as $event) {
            $event = trim($event);
            if ($event === '') {
                continue;
            }

            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $event) !== 1) {
                throw new InvalidArgumentException('Webhook endpoint event type format is invalid.');
            }

            $normalized[] = $event;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    public static function validateEndpointUrl(string $endpointUrl): void
    {
        if ($endpointUrl === '' || filter_var($endpointUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Webhook endpoint URL must be a valid HTTPS URL.');
        }

        $parts = parse_url($endpointUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));
        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Webhook endpoint URL must use HTTPS.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('Webhook endpoint URL host must not be localhost.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($publicIp === false) {
                throw new InvalidArgumentException('Webhook endpoint URL host must not be private, reserved, or link-local.');
            }

            return;
        }

        if (preg_match('/^\d+(?:\.\d+){3}$/', $host) === 1) {
            throw new InvalidArgumentException('Webhook endpoint URL host is invalid.');
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Webhook endpoint URL host is invalid.');
        }
    }
}

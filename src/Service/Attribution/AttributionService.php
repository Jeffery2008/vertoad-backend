<?php

declare(strict_types=1);

namespace VertoAD\Service\Attribution;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Attribution\ConversionAttributionResult;
use VertoAD\Repository\Attribution\AttributionEventRepositoryInterface;

final readonly class AttributionService
{
    public function __construct(
        private AttributionEventRepositoryInterface $events,
        private int $defaultWindowSeconds,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordServerApiConversion(
        array $payload,
        int $organizationId,
        ?int $oauthClientId,
        ?int $recordedByUserId,
    ): ConversionAttributionResult
    {
        return $this->recordConversion($payload, 'server_api', $organizationId, $oauthClientId, $recordedByUserId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordBrowserPixelConversion(array $payload): ConversionAttributionResult
    {
        return $this->recordConversion($payload, 'browser_pixel', null, null, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordConversion(
        array $payload,
        string $source,
        ?int $organizationId,
        ?int $oauthClientId,
        ?int $recordedByUserId,
    ): ConversionAttributionResult
    {
        $eventId = $this->requiredString($payload, 'event_id');
        $scopedEventId = $this->scopedEventId($source, $organizationId, $eventId);
        $stored = $this->events->findConversion($scopedEventId);
        if ($stored !== null) {
            return new ConversionAttributionResult(
                conversionId: $stored->conversionId,
                organizationId: $stored->organizationId,
                oauthClientId: $stored->oauthClientId,
                recordedByUserId: $stored->recordedByUserId,
                attributed: $stored->attributed,
                duplicate: true,
                clickEventId: $stored->clickEventId,
                decisionId: $stored->decisionId,
                campaignId: $stored->campaignId,
                windowSeconds: $stored->windowSeconds,
                source: $stored->source,
                conversionName: $stored->conversionName,
                valuePoints: $stored->valuePoints,
                occurredAt: $stored->occurredAt,
            );
        }

        $viewerId = $this->requiredString($payload, 'viewer_id');
        $conversionName = $this->requiredString($payload, 'conversion_name');
        $valuePoints = $this->intValue($payload, 'value_points', 0);
        $occurredAt = $this->occurredAt($payload);
        $windowSeconds = $this->intValue($payload, 'window_seconds', $this->defaultWindowSeconds);
        $click = $this->events->findLastClick($viewerId, $occurredAt, $windowSeconds);
        if ($click !== null
            && $organizationId !== null
            && $click['decision']->advertiserOrganizationId !== $organizationId
        ) {
            $click = null;
        }

        return $this->events->recordConversion($scopedEventId, new ConversionAttributionResult(
            conversionId: 'conversion_' . sha1($scopedEventId),
            organizationId: $organizationId,
            oauthClientId: $oauthClientId,
            recordedByUserId: $recordedByUserId,
            attributed: $click !== null,
            duplicate: false,
            clickEventId: $click['click_event_id'] ?? null,
            decisionId: $click['decision']->decisionId ?? null,
            campaignId: $click['decision']->campaignId ?? null,
            windowSeconds: $windowSeconds,
            source: $source,
            conversionName: $conversionName,
            valuePoints: $valuePoints,
            occurredAt: $occurredAt,
        ));
    }

    private function scopedEventId(string $source, ?int $organizationId, string $eventId): string
    {
        return $source . ':' . ($organizationId === null ? 'public' : (string) $organizationId) . ':' . $eventId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        if (!isset($payload[$field]) || !is_scalar($payload[$field]) || trim((string) $payload[$field]) === '') {
            throw new InvalidArgumentException($field . ' must be a non-empty string.');
        }

        return trim((string) $payload[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intValue(array $payload, string $field, int $default): int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === '' || $payload[$field] === null) {
            return $default;
        }

        if (!is_scalar($payload[$field]) || filter_var((string) $payload[$field], FILTER_VALIDATE_INT) === false || (int) $payload[$field] < 0) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer.');
        }

        return (int) $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function occurredAt(array $payload): DateTimeImmutable
    {
        if (!array_key_exists('occurred_at', $payload) || $payload['occurred_at'] === '' || $payload['occurred_at'] === null) {
            return new DateTimeImmutable();
        }

        if (!is_scalar($payload['occurred_at'])) {
            throw new InvalidArgumentException('occurred_at must be an ISO-8601 date-time string.');
        }

        try {
            return new DateTimeImmutable((string) $payload['occurred_at']);
        } catch (DateMalformedStringException $exception) {
            throw new InvalidArgumentException('occurred_at must be an ISO-8601 date-time string.', previous: $exception);
        }
    }
}

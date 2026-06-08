<?php

declare(strict_types=1);

namespace VertoAD\Repository\Attribution;

use DateTimeImmutable;
use VertoAD\Domain\Attribution\ConversionAttributionResult;
use VertoAD\Domain\Serving\AdDecision;

interface AttributionEventRepositoryInterface
{
    public function recordClick(AdDecision $decision, string $clickEventId, DateTimeImmutable $occurredAt): void;

    /**
     * @return array{decision:AdDecision,click_event_id:string,occurred_at:DateTimeImmutable}|null
     */
    public function findLastClick(string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): ?array;

    public function findConversion(string $eventId): ?ConversionAttributionResult;

    public function recordConversion(string $eventId, ConversionAttributionResult $result): ConversionAttributionResult;
}

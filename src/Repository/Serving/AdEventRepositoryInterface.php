<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEvent;

interface AdEventRepositoryInterface
{
    public function hasEvent(string $eventType, string $eventId): bool;

    public function findEvent(string $eventType, string $eventId): ?AdEvent;

    public function hasValidImpression(string $decisionId, string $viewerId): bool;

    public function hasRecentValidClick(string $decisionId, string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): bool;

    public function recordImpression(AdDecision $decision, string $eventId, float $visibleRatio, int $visibleMs, DateTimeImmutable $occurredAt): void;

    public function recordClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt): void;

    public function recordInvalidClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, string $reason): void;
}

<?php

declare(strict_types=1);

namespace VertoAD\Repository\Attribution;

use DateTimeImmutable;
use VertoAD\Domain\Attribution\ConversionAttributionResult;
use VertoAD\Domain\Serving\AdDecision;

final class InMemoryAttributionEventRepository implements AttributionEventRepositoryInterface
{
    /** @var list<array{decision:AdDecision,click_event_id:string,occurred_at:DateTimeImmutable}> */
    private array $clicks = [];

    /** @var array<string, ConversionAttributionResult> */
    private array $conversions = [];

    public function recordClick(AdDecision $decision, string $clickEventId, DateTimeImmutable $occurredAt): void
    {
        $this->clicks[] = [
            'decision' => $decision,
            'click_event_id' => $clickEventId,
            'occurred_at' => $occurredAt,
        ];
    }

    public function findLastClick(string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): ?array
    {
        $matched = null;
        $windowStart = $occurredAt->getTimestamp() - $windowSeconds;

        foreach ($this->clicks as $click) {
            $clickTime = $click['occurred_at']->getTimestamp();
            if ($click['decision']->viewerId !== $viewerId || $clickTime < $windowStart || $clickTime > $occurredAt->getTimestamp()) {
                continue;
            }

            if ($matched === null || $clickTime >= $matched['occurred_at']->getTimestamp()) {
                $matched = $click;
            }
        }

        return $matched;
    }

    public function findConversion(string $eventId): ?ConversionAttributionResult
    {
        return $this->conversions[$eventId] ?? null;
    }

    public function recordConversion(string $eventId, ConversionAttributionResult $result): ConversionAttributionResult
    {
        $this->conversions[$eventId] = $result;

        return $result;
    }
}

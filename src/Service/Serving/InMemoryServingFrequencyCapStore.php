<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use DateTimeZone;

final class InMemoryServingFrequencyCapStore implements ServingFrequencyCapStoreInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function servedCount(
        int $campaignId,
        int $slotId,
        string $viewerId,
        string $window,
        DateTimeImmutable $at,
    ): int {
        return $this->count('serve', $campaignId, $slotId, $viewerId, $window, $at);
    }

    public function clickCount(
        int $campaignId,
        int $slotId,
        string $viewerId,
        string $window,
        DateTimeImmutable $at,
    ): int {
        return $this->count('click', $campaignId, $slotId, $viewerId, $window, $at);
    }

    public function recordServe(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $at,
    ): void {
        $this->record('serve', $campaignId, $slotId, $viewerId, $at);
    }

    public function recordClick(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $at,
    ): void {
        $this->record('click', $campaignId, $slotId, $viewerId, $at);
    }

    private function count(string $type, int $campaignId, int $slotId, string $viewerId, string $window, DateTimeImmutable $at): int
    {
        return $this->counts[$this->key($type, $campaignId, $slotId, $viewerId, $window, $at)] ?? 0;
    }

    private function record(string $type, int $campaignId, int $slotId, string $viewerId, DateTimeImmutable $at): void
    {
        foreach (['hour', 'day'] as $window) {
            $key = $this->key($type, $campaignId, $slotId, $viewerId, $window, $at);
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
    }

    private function key(string $type, int $campaignId, int $slotId, string $viewerId, string $window, DateTimeImmutable $at): string
    {
        $utc = $at->setTimezone(new DateTimeZone('UTC'));
        $bucket = match ($window) {
            'hour' => $utc->format('YmdH'),
            'day' => $utc->format('Ymd'),
            default => throw new \InvalidArgumentException('Serving frequency cap window is not supported.'),
        };

        return implode(':', [$type, $campaignId, $slotId, $viewerId, $window, $bucket]);
    }
}

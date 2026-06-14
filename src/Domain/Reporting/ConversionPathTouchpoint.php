<?php

declare(strict_types=1);

namespace VertoAD\Domain\Reporting;

use DateTimeImmutable;

final readonly class ConversionPathTouchpoint
{
    public function __construct(
        public int $position,
        public string $eventType,
        public string $eventId,
        public string $decisionId,
        public DateTimeImmutable $occurredAt,
        public ?int $campaignId,
        public int $siteId,
        public int $slotId,
    ) {
        if ($position <= 0) {
            throw new \InvalidArgumentException('touchpoint position must be positive.');
        }

        if (!in_array($eventType, ['impression', 'click'], true)) {
            throw new \InvalidArgumentException('touchpoint event_type must be impression or click.');
        }

        foreach ([
            'event_id' => $eventId,
            'decision_id' => $decisionId,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException($name . ' is required.');
            }
        }

        foreach ([
            'campaign_id' => $campaignId,
            'site_id' => $siteId,
            'slot_id' => $slotId,
        ] as $name => $value) {
            if ($value !== null && $value <= 0) {
                throw new \InvalidArgumentException($name . ' must be positive when present.');
            }
        }
    }
}

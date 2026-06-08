<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

final readonly class CampaignTargeting
{
    /**
     * @param list<string> $devices
     * @param list<string> $geos
     * @param list<int> $siteIds
     * @param list<int> $slotIds
     * @param list<array{day_of_week: int, start: string, end: string}> $timeWindows
     */
    public function __construct(
        public array $devices = [],
        public array $geos = [],
        public array $siteIds = [],
        public array $slotIds = [],
        public array $timeWindows = [],
    ) {
    }

    /**
     * @return array{devices: list<string>, geos: list<string>, site_ids: list<int>, slot_ids: list<int>, time_windows: list<array{day_of_week: int, start: string, end: string}>}
     */
    public function toArray(): array
    {
        return [
            'devices' => $this->devices,
            'geos' => $this->geos,
            'site_ids' => $this->siteIds,
            'slot_ids' => $this->slotIds,
            'time_windows' => $this->timeWindows,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            devices: self::stringList($value['devices'] ?? []),
            geos: self::stringList($value['geos'] ?? []),
            siteIds: self::intList($value['site_ids'] ?? []),
            slotIds: self::intList($value['slot_ids'] ?? []),
            timeWindows: self::timeWindows($value['time_windows'] ?? []),
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item))) : [];
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter(array_map(static fn (mixed $item): int => (int) $item, $value), static fn (int $item): bool => $item > 0)) : [];
    }

    /** @return list<array{day_of_week: int, start: string, end: string}> */
    private static function timeWindows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $windows = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $dayOfWeek = (int) ($item['day_of_week'] ?? -1);
            $start = (string) ($item['start'] ?? '');
            $end = (string) ($item['end'] ?? '');
            if ($dayOfWeek >= 0 && $dayOfWeek <= 6 && $start !== '' && $end !== '') {
                $windows[] = ['day_of_week' => $dayOfWeek, 'start' => $start, 'end' => $end];
            }
        }

        return $windows;
    }
}

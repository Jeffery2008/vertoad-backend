<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

use InvalidArgumentException;

final readonly class CampaignTargeting
{
    public const DEVICE_TYPES = ['desktop', 'mobile', 'tablet'];

    /** @var list<string> */
    public array $devices;

    /** @var list<string> */
    public array $geos;

    /** @var list<int> */
    public array $siteIds;

    /** @var list<int> */
    public array $slotIds;

    /** @var list<CampaignTimeWindow> */
    public array $timeWindows;

    /**
     * @param array<mixed> $devices
     * @param array<mixed> $geos
     * @param array<mixed> $siteIds
     * @param array<mixed> $slotIds
     * @param array<mixed> $timeWindows
     */
    public function __construct(
        array $devices = [],
        array $geos = [],
        array $siteIds = [],
        array $slotIds = [],
        array $timeWindows = [],
    ) {
        $this->devices = self::devices($devices);
        $this->geos = self::geos($geos);
        $this->siteIds = self::positiveIds($siteIds);
        $this->slotIds = self::positiveIds($slotIds);
        $this->timeWindows = self::timeWindows($timeWindows);
    }

    /**
     * @return array{
     *     devices: list<string>,
     *     geos: list<string>,
     *     site_ids: list<int>,
     *     slot_ids: list<int>,
     *     time_windows: list<array{day_of_week: int, start: string, end: string, timezone: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'devices' => $this->devices,
            'geos' => $this->geos,
            'site_ids' => $this->siteIds,
            'slot_ids' => $this->slotIds,
            'time_windows' => array_map(
                static fn (CampaignTimeWindow $window): array => $window->toArray(),
                $this->timeWindows,
            ),
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, ['devices', 'geos', 'site_ids', 'slot_ids', 'time_windows'], true)) {
                throw new InvalidArgumentException('Campaign targeting contains an unknown field.');
            }
        }

        foreach (['devices', 'geos', 'site_ids', 'slot_ids', 'time_windows'] as $field) {
            if (array_key_exists($field, $value) && !is_array($value[$field])) {
                throw new InvalidArgumentException('Campaign targeting list fields must be arrays.');
            }
        }

        return new self(
            devices: $value['devices'] ?? [],
            geos: $value['geos'] ?? [],
            siteIds: $value['site_ids'] ?? [],
            slotIds: $value['slot_ids'] ?? [],
            timeWindows: $value['time_windows'] ?? [],
        );
    }

    public static function fromJson(string $json): self
    {
        $object = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new InvalidArgumentException('Stored campaign targeting must be a JSON object.');
        }

        /** @var array<string, mixed> $value */
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($value);
    }

    /** @param array<mixed> $value @return list<string> */
    private static function devices(array $value): array
    {
        $selected = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Campaign devices must contain strings.');
            }

            if (!in_array($item, self::DEVICE_TYPES, true)) {
                throw new InvalidArgumentException('Campaign device type is invalid.');
            }

            $selected[$item] = true;
        }

        return array_values(array_filter(
            self::DEVICE_TYPES,
            static fn (string $device): bool => isset($selected[$device]),
        ));
    }

    /** @param array<mixed> $value @return list<string> */
    private static function geos(array $value): array
    {
        $geos = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException('Campaign geos must contain non-empty strings.');
            }

            $geos[] = strtoupper(trim($item));
        }

        $geos = array_values(array_unique($geos));
        sort($geos, SORT_STRING);

        return $geos;
    }

    /** @param array<mixed> $value @return list<int> */
    private static function positiveIds(array $value): array
    {
        $ids = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item <= 0) {
                throw new InvalidArgumentException('Campaign targeting IDs must be positive integers.');
            }

            $ids[] = $item;
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param array<mixed> $value @return list<CampaignTimeWindow> */
    private static function timeWindows(array $value): array
    {
        $windows = [];
        foreach ($value as $item) {
            if ($item instanceof CampaignTimeWindow) {
                $window = $item;
            } elseif (is_array($item)) {
                $window = CampaignTimeWindow::fromArray($item);
            } else {
                throw new InvalidArgumentException('Campaign time windows must contain objects.');
            }

            $key = implode('|', [$window->timezone, (string) $window->dayOfWeek, $window->start, $window->end]);
            $windows[$key] = $window;
        }

        ksort($windows, SORT_STRING);

        return array_values($windows);
    }
}

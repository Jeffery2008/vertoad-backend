<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CampaignTimeWindow
{
    private int $startMinute;
    private int $endMinute;

    public string $start;
    public string $end;
    public string $timezone;

    public function __construct(
        public int $dayOfWeek,
        string $start,
        string $end,
        string $timezone,
    ) {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('day_of_week must be between 0 and 6.');
        }

        $this->startMinute = self::clockMinute($start, false);
        $this->endMinute = self::clockMinute($end, true);
        if ($this->startMinute === $this->endMinute) {
            throw new InvalidArgumentException('Time window start and end must differ.');
        }

        $this->start = $start;
        $this->end = $end;
        $this->timezone = self::timezone($timezone);
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, ['day_of_week', 'start', 'end', 'timezone'], true)) {
                throw new InvalidArgumentException('Time window contains an unknown field.');
            }
        }

        $dayOfWeek = $value['day_of_week'] ?? null;
        $start = $value['start'] ?? null;
        $end = $value['end'] ?? null;
        $timezone = $value['timezone'] ?? null;
        if (!is_int($dayOfWeek) || !is_string($start) || !is_string($end) || !is_string($timezone)) {
            throw new InvalidArgumentException('Time window fields are invalid.');
        }

        return new self($dayOfWeek, $start, $end, $timezone);
    }

    /** @return array{day_of_week: int, start: string, end: string, timezone: string} */
    public function toArray(): array
    {
        return [
            'day_of_week' => $this->dayOfWeek,
            'start' => $this->start,
            'end' => $this->end,
            'timezone' => $this->timezone,
        ];
    }

    public function matches(DateTimeImmutable $instant): bool
    {
        $local = $instant->setTimezone(new DateTimeZone($this->timezone));
        $localDay = (int) $local->format('w');
        $localMinute = ((int) $local->format('H') * 60) + (int) $local->format('i');

        if ($this->startMinute < $this->endMinute) {
            return $localDay === $this->dayOfWeek
                && $localMinute >= $this->startMinute
                && $localMinute < $this->endMinute;
        }

        return ($localDay === $this->dayOfWeek && $localMinute >= $this->startMinute)
            || ($localDay === (($this->dayOfWeek + 1) % 7) && $localMinute < $this->endMinute);
    }

    private static function clockMinute(string $value, bool $allowEndOfDay): int
    {
        if ($allowEndOfDay && $value === '24:00') {
            return 24 * 60;
        }

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException('Time window clocks must use HH:MM in 24-hour time.');
        }

        return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
    }

    private static function timezone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Time window timezone is required.');
        }

        /** @var array<string, string>|null $identifiers */
        static $identifiers = null;
        if ($identifiers === null) {
            $identifiers = [];
            foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $identifier) {
                $identifiers[strtolower($identifier)] = $identifier;
            }
        }

        return $identifiers[strtolower($value)]
            ?? throw new InvalidArgumentException('Time window timezone must be a valid IANA identifier.');
    }
}

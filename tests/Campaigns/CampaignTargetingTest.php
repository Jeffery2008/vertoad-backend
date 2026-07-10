<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Campaign\CampaignTargeting;
use VertoAD\Domain\Campaign\CampaignTimeWindow;

final class CampaignTargetingTest extends TestCase
{
    public function testTargetingNormalizesAndSerializesCanonicalValues(): void
    {
        $utcWindow = new CampaignTimeWindow(1, '09:00', '17:00', 'UTC');
        $targeting = CampaignTargeting::fromArray([
            'devices' => ['mobile', 'desktop', 'mobile', 'tablet'],
            'geos' => ['cn-sh', 'CN-BJ', 'cn-sh'],
            'site_ids' => [20, 10, 20],
            'slot_ids' => [40, 30, 40],
            'time_windows' => [
                ['day_of_week' => 1, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'utc'],
                ['day_of_week' => 0, 'start' => '22:00', 'end' => '02:00', 'timezone' => 'asia/shanghai'],
            ],
        ]);
        $withObject = new CampaignTargeting(timeWindows: [$utcWindow, $utcWindow]);

        self::assertSame(['desktop', 'mobile', 'tablet'], $targeting->devices);
        self::assertSame(['CN-BJ', 'CN-SH'], $targeting->geos);
        self::assertSame([10, 20], $targeting->siteIds);
        self::assertSame([30, 40], $targeting->slotIds);
        self::assertSame([
            [
                'day_of_week' => 0,
                'start' => '22:00',
                'end' => '02:00',
                'timezone' => 'Asia/Shanghai',
            ],
            [
                'day_of_week' => 1,
                'start' => '09:00',
                'end' => '17:00',
                'timezone' => 'UTC',
            ],
        ], $targeting->toArray()['time_windows']);
        self::assertCount(1, $withObject->timeWindows);
        self::assertSame($targeting->toArray(), CampaignTargeting::fromJson(
            json_encode($targeting->toArray(), JSON_THROW_ON_ERROR),
        )->toArray());
    }

    public function testTargetingRejectsUnknownFieldsAndInvalidLists(): void
    {
        $invalid = [
            ['unexpected' => true],
            ['devices' => 'desktop'],
            ['devices' => [12]],
            ['devices' => ['watch']],
            ['devices' => ['MOBILE']],
            ['geos' => ['']],
            ['geos' => [12]],
            ['site_ids' => ['10']],
            ['slot_ids' => [0]],
            ['time_windows' => ['weekday']],
        ];

        foreach ($invalid as $value) {
            $this->assertInvalid(static fn (): CampaignTargeting => CampaignTargeting::fromArray($value));
        }

        foreach (['null', '[]'] as $json) {
            $this->assertInvalid(static fn (): CampaignTargeting => CampaignTargeting::fromJson($json));
        }
    }

    public function testTimeWindowRejectsMalformedRules(): void
    {
        $valid = ['day_of_week' => 1, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'];
        $invalid = [
            $valid + ['unexpected' => true],
            ['day_of_week' => '1', 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'],
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '17:00'],
            ['day_of_week' => 7, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'],
            ['day_of_week' => 1, 'start' => '24:00', 'end' => '01:00', 'timezone' => 'UTC'],
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '24:01', 'timezone' => 'UTC'],
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '09:00', 'timezone' => 'UTC'],
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '17:00', 'timezone' => ' '],
            ['day_of_week' => 1, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'Mars/Olympus'],
        ];

        foreach ($invalid as $value) {
            $this->assertInvalid(static fn (): CampaignTimeWindow => CampaignTimeWindow::fromArray($value));
        }
    }

    public function testTimeWindowsMatchHalfOpenLocalIntervalsAndCrossMidnight(): void
    {
        $businessHours = new CampaignTimeWindow(1, '09:00', '18:00', 'UTC');
        self::assertFalse($businessHours->matches(new DateTimeImmutable('2026-06-08T08:59:00Z')));
        self::assertTrue($businessHours->matches(new DateTimeImmutable('2026-06-08T09:00:00Z')));
        self::assertTrue($businessHours->matches(new DateTimeImmutable('2026-06-08T17:59:59Z')));
        self::assertFalse($businessHours->matches(new DateTimeImmutable('2026-06-08T18:00:00Z')));
        self::assertFalse($businessHours->matches(new DateTimeImmutable('2026-06-09T10:00:00Z')));

        $overnight = new CampaignTimeWindow(1, '22:00', '02:00', 'Asia/Shanghai');
        self::assertFalse($overnight->matches(new DateTimeImmutable('2026-06-08 21:59:00 Asia/Shanghai')));
        self::assertTrue($overnight->matches(new DateTimeImmutable('2026-06-08 22:00:00 Asia/Shanghai')));
        self::assertTrue($overnight->matches(new DateTimeImmutable('2026-06-09 01:59:00 Asia/Shanghai')));
        self::assertFalse($overnight->matches(new DateTimeImmutable('2026-06-09 02:00:00 Asia/Shanghai')));
        self::assertFalse($overnight->matches(new DateTimeImmutable('2026-06-10 01:00:00 Asia/Shanghai')));

        $sundayToMonday = new CampaignTimeWindow(0, '23:00', '01:00', 'UTC');
        self::assertTrue($sundayToMonday->matches(new DateTimeImmutable('2026-06-08T00:30:00Z')));
    }

    public function testTimeWindowsHonorEndOfDayTimezoneAndDstBoundaries(): void
    {
        $fullSunday = new CampaignTimeWindow(0, '00:00', '24:00', 'UTC');
        self::assertTrue($fullSunday->matches(new DateTimeImmutable('2026-06-07T23:59:59Z')));
        self::assertFalse($fullSunday->matches(new DateTimeImmutable('2026-06-08T00:00:00Z')));

        $shanghaiMonday = new CampaignTimeWindow(1, '00:00', '01:00', 'Asia/Shanghai');
        self::assertTrue($shanghaiMonday->matches(new DateTimeImmutable('2026-06-07T16:30:00Z')));

        $newYorkDst = new CampaignTimeWindow(0, '01:30', '03:30', 'America/New_York');
        self::assertTrue($newYorkDst->matches(new DateTimeImmutable('2026-03-08T07:00:00Z')));
        self::assertFalse($newYorkDst->matches(new DateTimeImmutable('2026-03-08T07:30:00Z')));
    }

    /** @param callable(): mixed $callback */
    private function assertInvalid(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected invalid campaign targeting to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}

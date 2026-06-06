<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;

final class AdSlotSetupServiceTest extends TestCase
{
    public function testCreatesPresetSlotForVerifiedSite(): void
    {
        $siteRepository = new FakePublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
        ]);
        $slotRepository = new FakeAdSlotRepository();
        $service = new AdSlotSetupService($siteRepository, $slotRepository);

        $slot = $service->createPresetSlot(
            siteId: 12,
            name: 'Leaderboard',
            slotKey: ' home-top ',
            presetKey: 'leaderboard',
            responsive: false,
            responsiveRules: null,
        );

        self::assertSame(1, $slot->id);
        self::assertSame(12, $slot->siteId);
        self::assertSame('home-top', $slot->slotKey);
        self::assertSame(728, $slot->size->width);
        self::assertSame(90, $slot->size->height);
        self::assertFalse($slot->responsive);
        self::assertSame([], $slot->responsiveRules);
    }

    public function testCreatesCustomResponsiveSlotWithValidatedRules(): void
    {
        $siteRepository = new FakePublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
        ]);
        $slotRepository = new FakeAdSlotRepository();
        $service = new AdSlotSetupService($siteRepository, $slotRepository);

        $slot = $service->createCustomSlot(
            siteId: 12,
            name: 'Article Inline',
            slotKey: 'article-inline',
            size: new AdSlotSize(640, 320),
            responsive: true,
            responsiveRules: [
                ['min_width' => 0, 'width' => 320, 'height' => 160],
                ['min_width' => 768, 'width' => 640, 'height' => 320],
            ],
        );

        self::assertTrue($slot->responsive);
        self::assertSame(
            [
                ['min_width' => 0, 'width' => 320, 'height' => 160],
                ['min_width' => 768, 'width' => 640, 'height' => 320],
            ],
            $slot->responsiveRules,
        );
    }

    public function testRejectsSlotCreationUntilSiteIsVerified(): void
    {
        $siteRepository = new FakePublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site must be verified before ad slots can be created.');

        $service->createPresetSlot(12, 'Leaderboard', 'home-top', 'leaderboard', false, null);
    }

    public function testRejectsUnknownPresetAndInvalidCustomSize(): void
    {
        $siteRepository = new FakePublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        try {
            $service->createPresetSlot(12, 'Bad', 'bad', 'unknown', false, null);
            self::fail('Expected unknown preset to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown ad slot preset size.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ad slot width must be between 1 and 4096 pixels.');

        $service->createCustomSlot(12, 'Bad', 'bad-custom', new AdSlotSize(0, 250), false, null);
    }

    public function testResponsiveSlotsRequireAtLeastOneValidRule(): void
    {
        $siteRepository = new FakePublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Responsive ad slots require at least one responsive rule.');

        $service->createCustomSlot(12, 'Responsive', 'responsive', new AdSlotSize(300, 250), true, []);
    }
}

final class FakeAdSlotRepository implements AdSlotRepositoryInterface
{
    /** @var list<AdSlot> */
    public array $slots = [];

    public function store(AdSlot $slot): AdSlot
    {
        $stored = $slot->id === null ? $slot->withId(count($this->slots) + 1) : $slot;
        $this->slots[] = $stored;

        return $stored;
    }
}

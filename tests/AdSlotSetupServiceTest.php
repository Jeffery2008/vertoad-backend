<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;

final class AdSlotSetupServiceTest extends TestCase
{
    public function testCreatesPresetSlotForVerifiedSite(): void
    {
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
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
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
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
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site must be verified before ad slots can be created.');

        $service->createPresetSlot(12, 'Leaderboard', 'home-top', 'leaderboard', false, null);
    }

    public function testRejectsUnknownPresetAndInvalidCustomSize(): void
    {
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
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
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Responsive ad slots require at least one responsive rule.');

        $service->createCustomSlot(12, 'Responsive', 'responsive', new AdSlotSize(300, 250), true, []);
    }

    public function testPresetSizesReturnsAllSupportedSizesAsValueObjects(): void
    {
        $service = new AdSlotSetupService(new FakeAdSlotSetupPublisherSiteRepository(), new FakeAdSlotRepository());

        $sizes = $service->presetSizes();

        self::assertSame(
            [
                'leaderboard',
                'medium_rectangle',
                'mobile_banner',
                'wide_skyscraper',
                'half_page',
                'billboard',
            ],
            array_keys($sizes),
        );
        self::assertContainsOnlyInstancesOf(AdSlotSize::class, $sizes);
        self::assertSame(728, $sizes['leaderboard']->width);
        self::assertSame(90, $sizes['leaderboard']->height);
        self::assertSame(300, $sizes['medium_rectangle']->width);
        self::assertSame(250, $sizes['medium_rectangle']->height);
        self::assertSame(320, $sizes['mobile_banner']->width);
        self::assertSame(50, $sizes['mobile_banner']->height);
        self::assertSame(160, $sizes['wide_skyscraper']->width);
        self::assertSame(600, $sizes['wide_skyscraper']->height);
        self::assertSame(300, $sizes['half_page']->width);
        self::assertSame(600, $sizes['half_page']->height);
        self::assertSame(970, $sizes['billboard']->width);
        self::assertSame(250, $sizes['billboard']->height);
        self::assertNotSame($sizes['leaderboard'], $service->presetSizes()['leaderboard']);
    }

    public function testRejectsInvalidSiteId(): void
    {
        $service = new AdSlotSetupService(new FakeAdSlotSetupPublisherSiteRepository(), new FakeAdSlotRepository());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Publisher site ID must be positive.');

        $service->createPresetSlot(0, 'Leaderboard', 'home-top', 'leaderboard', false, null);
    }

    public function testRejectsMissingSite(): void
    {
        $service = new AdSlotSetupService(new FakeAdSlotSetupPublisherSiteRepository(), new FakeAdSlotRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site was not found.');

        $service->createPresetSlot(12, 'Leaderboard', 'home-top', 'leaderboard', false, null);
    }

    public function testRejectsSiteWithoutVerificationTimestamp(): void
    {
        $siteRepository = new FakeAdSlotSetupPublisherSiteRepository([
            new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', null),
        ]);
        $service = new AdSlotSetupService($siteRepository, new FakeAdSlotRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site must be verified before ad slots can be created.');

        $service->createPresetSlot(12, 'Leaderboard', 'home-top', 'leaderboard', false, null);
    }

    /**
     * @return iterable<string, array{AdSlotSize, string}>
     */
    public static function invalidCustomSizes(): iterable
    {
        yield 'zero width' => [new AdSlotSize(0, 250), 'Ad slot width must be between 1 and 4096 pixels.'];
        yield 'oversized width' => [new AdSlotSize(4097, 250), 'Ad slot width must be between 1 and 4096 pixels.'];
        yield 'zero height' => [new AdSlotSize(300, 0), 'Ad slot height must be between 1 and 4096 pixels.'];
        yield 'oversized height' => [new AdSlotSize(300, 4097), 'Ad slot height must be between 1 and 4096 pixels.'];
    }

    #[DataProvider('invalidCustomSizes')]
    public function testRejectsInvalidCustomDimensions(AdSlotSize $size, string $message): void
    {
        $service = $this->serviceForVerifiedSite();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->createCustomSlot(12, 'Bad Size', 'bad-size', $size, false, null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'blank' => ['   ', 'Ad slot name is required.'];
        yield 'too long' => [str_repeat('a', 161), 'Ad slot name must be 160 characters or fewer.'];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $name, string $message): void
    {
        $service = $this->serviceForVerifiedSite();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->createCustomSlot(12, $name, 'valid-key', new AdSlotSize(300, 250), false, null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSlotKeys(): iterable
    {
        yield 'blank' => ['   ', 'Ad slot key is required.'];
        yield 'uppercase' => ['Home-Top', 'Ad slot key must use lowercase letters, numbers, underscores, or hyphens.'];
        yield 'leading underscore' => ['_home_top', 'Ad slot key must use lowercase letters, numbers, underscores, or hyphens.'];
        yield 'too long' => [str_repeat('a', 121), 'Ad slot key must use lowercase letters, numbers, underscores, or hyphens.'];
    }

    #[DataProvider('invalidSlotKeys')]
    public function testRejectsInvalidSlotKeys(string $slotKey, string $message): void
    {
        $service = $this->serviceForVerifiedSite();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->createCustomSlot(12, 'Article Inline', $slotKey, new AdSlotSize(300, 250), false, null);
    }

    /**
     * @return iterable<string, array{array<int, array<string, mixed>>|null, string}>
     */
    public static function invalidResponsiveRules(): iterable
    {
        yield 'missing rules' => [null, 'Responsive ad slots require at least one responsive rule.'];
        yield 'missing width' => [
            [['min_width' => 0, 'height' => 250]],
            'Responsive ad slot rules require integer min_width, width, and height.',
        ];
        yield 'non-integer min width' => [
            [['min_width' => '0', 'width' => 300, 'height' => 250]],
            'Responsive ad slot rules require integer min_width, width, and height.',
        ];
        yield 'negative min width' => [
            [['min_width' => -1, 'width' => 300, 'height' => 250]],
            'Responsive ad slot rule min_width must be between 0 and 4096 pixels.',
        ];
        yield 'oversized min width' => [
            [['min_width' => 4097, 'width' => 300, 'height' => 250]],
            'Responsive ad slot rule min_width must be between 0 and 4096 pixels.',
        ];
        yield 'unordered min width' => [
            [
                ['min_width' => 768, 'width' => 640, 'height' => 320],
                ['min_width' => 320, 'width' => 300, 'height' => 250],
            ],
            'Responsive ad slot rules must be ordered by increasing min_width.',
        ];
        yield 'duplicate min width' => [
            [
                ['min_width' => 320, 'width' => 300, 'height' => 250],
                ['min_width' => 320, 'width' => 320, 'height' => 50],
            ],
            'Responsive ad slot rules must be ordered by increasing min_width.',
        ];
        yield 'zero rule width' => [
            [['min_width' => 0, 'width' => 0, 'height' => 250]],
            'Ad slot width must be between 1 and 4096 pixels.',
        ];
        yield 'oversized rule height' => [
            [['min_width' => 0, 'width' => 300, 'height' => 4097]],
            'Ad slot height must be between 1 and 4096 pixels.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|null $rules
     *
     */
    #[DataProvider('invalidResponsiveRules')]
    public function testRejectsInvalidResponsiveRules(?array $rules, string $message): void
    {
        $service = $this->serviceForVerifiedSite();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $service->createCustomSlot(12, 'Responsive', 'responsive', new AdSlotSize(300, 250), true, $rules);
    }

    public function testIgnoresResponsiveRulesWhenSlotIsNotResponsive(): void
    {
        $service = $this->serviceForVerifiedSite();

        $slot = $service->createCustomSlot(
            12,
            'Static Slot',
            'static-slot',
            new AdSlotSize(300, 250),
            false,
            [
                ['min_width' => 'invalid', 'width' => 0],
            ],
        );

        self::assertFalse($slot->responsive);
        self::assertSame([], $slot->responsiveRules);
    }

    private function serviceForVerifiedSite(): AdSlotSetupService
    {
        return new AdSlotSetupService(
            new FakeAdSlotSetupPublisherSiteRepository([
                new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', new \DateTimeImmutable()),
            ]),
            new FakeAdSlotRepository(),
        );
    }
}

final class FakeAdSlotSetupPublisherSiteRepository implements PublisherSiteRepositoryInterface
{
    /** @var array<int, PublisherSite> */
    private array $sitesById = [];

    /**
     * @param list<PublisherSite> $sites
     */
    public function __construct(array $sites = [])
    {
        foreach ($sites as $site) {
            $this->sitesById[$site->id] = $site;
        }
    }

    public function findById(int $id): ?PublisherSite
    {
        return $this->sitesById[$id] ?? null;
    }

    public function create(int $organizationId, string $name, string $domain, string $verificationToken): PublisherSite
    {
        $site = new PublisherSite(count($this->sitesById) + 1, $organizationId, $domain, PublisherSiteStatus::Pending, $verificationToken, null, $name);
        $this->sitesById[$site->id] = $site;

        return $site;
    }

    public function listForOrganization(int $organizationId): array
    {
        return array_values(array_filter(
            $this->sitesById,
            static fn (PublisherSite $site): bool => $site->organizationId === $organizationId,
        ));
    }

    public function markVerified(PublisherSite $site, \DateTimeImmutable $verifiedAt): PublisherSite
    {
        $verified = $site->withVerification(PublisherSiteStatus::Verified, $verifiedAt);
        $this->sitesById[$site->id] = $verified;

        return $verified;
    }

    public function markVerificationFailed(PublisherSite $site): PublisherSite
    {
        $failed = $site->withVerification(PublisherSiteStatus::Failed, null);
        $this->sitesById[$site->id] = $failed;

        return $failed;
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

    public function listForSite(int $siteId): array
    {
        return array_values(array_filter(
            $this->slots,
            static fn (AdSlot $slot): bool => $slot->siteId === $siteId,
        ));
    }

    public function findForSiteInOrganization(int $siteId, int $slotId, int $organizationId): ?AdSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->siteId === $siteId && $slot->id === $slotId) {
                return $slot;
            }
        }

        return null;
    }
}

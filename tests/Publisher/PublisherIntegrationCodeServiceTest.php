<?php

declare(strict_types=1);

namespace VertoAD\Tests\Publisher;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use VertoAD\AppFactory;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\Publisher\PublisherIntegrationCodeService;

final class PublisherIntegrationCodeServiceTest extends TestCase
{
    public function testBuildsSafeIntegrationCodeForOwnedPublisherSlot(): void
    {
        $service = new PublisherIntegrationCodeService(
            new FakePublisherIntegrationSiteRepository([
                12 => new PublisherSite(
                    id: 12,
                    organizationId: 99,
                    domain: 'publisher.example',
                    status: PublisherSiteStatus::Verified,
                    verificationToken: 'secret-verification-token',
                    verifiedAt: new \DateTimeImmutable('2026-06-18 08:00:00+00:00'),
                    name: 'Publisher',
                ),
            ]),
            new FakePublisherIntegrationSlotRepository([
                '99:12:34' => new AdSlot(
                    id: 34,
                    siteId: 12,
                    name: 'Article Inline',
                    slotKey: 'article-inline',
                    size: new AdSlotSize(728, 90),
                    responsive: true,
                    responsiveRules: [
                        ['min_width' => 0, 'width' => 320, 'height' => 50],
                        ['min_width' => 768, 'width' => 728, 'height' => 90],
                    ],
                    presetKey: 'leaderboard',
                ),
            ]),
            'https://sdk.example.test/',
            'https://ads.example.test/root/',
        );

        $code = $service->forSlot(organizationId: 99, siteId: 12, slotId: 34);

        self::assertNotNull($code);
        self::assertSame(12, $code['site_id']);
        self::assertSame(34, $code['slot_id']);
        self::assertSame(99, $code['organization_id']);
        self::assertSame('article-inline', $code['slot_key']);
        self::assertSame('publisher.example', $code['site_domain']);
        self::assertSame('first_party_stable_id', $code['viewer_id_strategy']);
        self::assertSame(['width' => 728, 'height' => 90], $code['slot_size']);
        self::assertTrue($code['responsive']);
        self::assertSame([
            ['min_width' => 0, 'width' => 320, 'height' => 50],
            ['min_width' => 768, 'width' => 728, 'height' => 90],
        ], $code['responsive_rules']);
        self::assertSame('https://sdk.example.test', $code['sdk_public_base_url']);
        self::assertSame('https://ads.example.test/root', $code['ads_public_base_url']);
        self::assertStringStartsWith('https://ads.example.test/root/api/v1/ads/serve?', $code['iframe_url_template']);
        self::assertStringContainsString('viewer_id={viewer_id}', $code['iframe_url_template']);
        self::assertStringContainsString('width=728', $code['iframe_url_template']);
        self::assertStringContainsString('height=90', $code['iframe_url_template']);
        self::assertStringContainsString('src="https://sdk.example.test/vertoad-sdk.js"', $code['hosted_script_snippet']);
        self::assertStringContainsString('<div id="vertoad-slot-34"></div>', $code['hosted_script_snippet']);
        self::assertStringContainsString('window.VertoAD = window.VertoAD || [];', $code['hosted_script_snippet']);
        self::assertStringContainsString('window.VertoAD.push({', $code['hosted_script_snippet']);
        self::assertStringContainsString('"siteId": 12', $code['hosted_script_snippet']);
        self::assertStringContainsString('"slotId": 34', $code['hosted_script_snippet']);
        self::assertStringContainsString('"adsBaseUrl": "https://ads.example.test/root"', $code['hosted_script_snippet']);
        self::assertStringContainsString('"responsive": true', $code['hosted_script_snippet']);
        self::assertSame('npm install @vertoad/sdk', $code['npm_install_command']);
        self::assertStringContainsString("import { createVertoAdSlot } from '@vertoad/sdk';", $code['npm_usage_snippet']);
        self::assertStringContainsString('createVertoAdSlot({', $code['npm_usage_snippet']);
        self::assertStringContainsString('"container": "#vertoad-slot-34"', $code['npm_usage_snippet']);
        self::assertStringContainsString('"adsBaseUrl": "https://ads.example.test/root"', $code['npm_usage_snippet']);
        self::assertStringNotContainsString('slotKey', $code['npm_usage_snippet']);
        self::assertStringNotContainsString('sdkBaseUrl', $code['npm_usage_snippet']);

        $encoded = json_encode($code, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-verification-token', $encoded);
        self::assertStringNotContainsString('verification_token', $encoded);
    }

    public function testReturnsNullForMissingOrCrossOrganizationSlot(): void
    {
        $service = new PublisherIntegrationCodeService(
            new FakePublisherIntegrationSiteRepository([
                12 => new PublisherSite(
                    id: 12,
                    organizationId: 100,
                    domain: 'other.example',
                    status: PublisherSiteStatus::Verified,
                    verificationToken: 'other-secret',
                    verifiedAt: new \DateTimeImmutable('2026-06-18 08:00:00+00:00'),
                    name: 'Other',
                ),
            ]),
            new FakePublisherIntegrationSlotRepository([]),
            'https://sdk.example.test',
            'https://ads.example.test',
        );

        self::assertNull($service->forSlot(organizationId: 99, siteId: 12, slotId: 34));
        self::assertNull($service->forSlot(organizationId: 99, siteId: 404, slotId: 34));
        self::assertNull($service->forSlot(organizationId: 0, siteId: 12, slotId: 34));
    }

    public function testBuildsNonResponsiveSnippetsWithLocalPublicBaseUrls(): void
    {
        $service = new PublisherIntegrationCodeService(
            new FakePublisherIntegrationSiteRepository([
                12 => new PublisherSite(
                    id: 12,
                    organizationId: 99,
                    domain: 'publisher.example',
                    status: PublisherSiteStatus::Verified,
                    verificationToken: 'verification-token',
                    verifiedAt: new \DateTimeImmutable('2026-06-18 08:00:00+00:00'),
                    name: 'Publisher',
                ),
            ]),
            new FakePublisherIntegrationSlotRepository([
                '99:12:35' => new AdSlot(
                    id: 35,
                    siteId: 12,
                    name: 'Sidebar',
                    slotKey: 'sidebar',
                    size: new AdSlotSize(300, 250),
                    responsive: false,
                    responsiveRules: [],
                    presetKey: 'medium_rectangle',
                ),
            ]),
            'http://localhost:5173',
            'http://localhost:8080',
        );

        $code = $service->forSlot(organizationId: 99, siteId: 12, slotId: 35);

        self::assertNotNull($code);
        self::assertSame('http://localhost:5173', $code['sdk_public_base_url']);
        self::assertSame('http://localhost:8080', $code['ads_public_base_url']);
        self::assertStringContainsString('src="http://localhost:5173/vertoad-sdk.js"', $code['hosted_script_snippet']);
        self::assertStringContainsString('"size": {', $code['hosted_script_snippet']);
        self::assertStringContainsString('"width": 300', $code['hosted_script_snippet']);
        self::assertStringContainsString('"height": 250', $code['hosted_script_snippet']);
        self::assertStringContainsString('"width": 300', $code['npm_usage_snippet']);
        self::assertStringContainsString('"height": 250', $code['npm_usage_snippet']);
        self::assertStringNotContainsString('"responsive": true', $code['hosted_script_snippet']);
    }

    public function testAppFactoryPublicBaseUrlUsesExplicitBaseUrls(): void
    {
        $previousSdkUrl = getenv('SDK_PUBLIC_BASE_URL');
        $previousAdsUrl = getenv('ADS_PUBLIC_BASE_URL');
        $method = new ReflectionMethod(AppFactory::class, 'integrationPublicBaseUrl');
        $settings = ['app' => ['env' => 'prod']];

        try {
            putenv('SDK_PUBLIC_BASE_URL=https://sdk.explicit.test/base/');
            putenv('ADS_PUBLIC_BASE_URL=https://ads.explicit.test/root/');
            self::assertSame('https://sdk.explicit.test/base', $method->invoke(null, 'SDK_PUBLIC_BASE_URL', $settings));
            self::assertSame('https://ads.explicit.test/root', $method->invoke(null, 'ADS_PUBLIC_BASE_URL', $settings));
        } finally {
            self::restoreEnvironment('SDK_PUBLIC_BASE_URL', $previousSdkUrl);
            self::restoreEnvironment('ADS_PUBLIC_BASE_URL', $previousAdsUrl);
        }
    }

    public function testAppFactoryUsesDistinctLocalDefaultsWithoutAppUrlFallback(): void
    {
        $previousSdkUrl = getenv('SDK_PUBLIC_BASE_URL');
        $previousAdsUrl = getenv('ADS_PUBLIC_BASE_URL');
        $previousAppUrl = getenv('APP_URL');
        $method = new ReflectionMethod(AppFactory::class, 'integrationPublicBaseUrl');

        try {
            putenv('SDK_PUBLIC_BASE_URL');
            putenv('ADS_PUBLIC_BASE_URL');
            putenv('APP_URL=https://spa.must-not-be-used.test');

            foreach (['local', 'test', 'testing'] as $environment) {
                $settings = ['app' => ['env' => $environment]];
                self::assertSame('http://localhost:5173', $method->invoke(null, 'SDK_PUBLIC_BASE_URL', $settings));
                self::assertSame('http://localhost:8080', $method->invoke(null, 'ADS_PUBLIC_BASE_URL', $settings));
            }
        } finally {
            self::restoreEnvironment('SDK_PUBLIC_BASE_URL', $previousSdkUrl);
            self::restoreEnvironment('ADS_PUBLIC_BASE_URL', $previousAdsUrl);
            self::restoreEnvironment('APP_URL', $previousAppUrl);
        }
    }

    public function testAppFactoryRequiresBothPublicBaseUrlsOutsideLocalTesting(): void
    {
        $previousSdkUrl = getenv('SDK_PUBLIC_BASE_URL');
        $previousAdsUrl = getenv('ADS_PUBLIC_BASE_URL');
        $previousAppUrl = getenv('APP_URL');
        $method = new ReflectionMethod(AppFactory::class, 'integrationPublicBaseUrl');

        try {
            putenv('SDK_PUBLIC_BASE_URL');
            putenv('ADS_PUBLIC_BASE_URL');
            putenv('APP_URL=https://spa.must-not-be-used.test');

            foreach (['staging', 'prod'] as $environment) {
                $settings = ['app' => ['env' => $environment]];
                foreach (['SDK_PUBLIC_BASE_URL', 'ADS_PUBLIC_BASE_URL'] as $variable) {
                    try {
                        $method->invoke(null, $variable, $settings);
                        self::fail($variable . ' should be required in ' . $environment . '.');
                    } catch (\RuntimeException $exception) {
                        self::assertSame($variable . ' is required outside local/testing.', $exception->getMessage());
                    }
                }
            }
        } finally {
            self::restoreEnvironment('SDK_PUBLIC_BASE_URL', $previousSdkUrl);
            self::restoreEnvironment('ADS_PUBLIC_BASE_URL', $previousAdsUrl);
            self::restoreEnvironment('APP_URL', $previousAppUrl);
        }
    }

    public function testAppFactoryRejectsScriptFilenameAsSdkPublicBaseUrl(): void
    {
        $previousSdkUrl = getenv('SDK_PUBLIC_BASE_URL');
        $method = new ReflectionMethod(AppFactory::class, 'integrationPublicBaseUrl');

        try {
            putenv('SDK_PUBLIC_BASE_URL=https://sdk.example.test/vertoad-sdk.js');

            $method->invoke(null, 'SDK_PUBLIC_BASE_URL', ['app' => ['env' => 'prod']]);
            self::fail('SDK_PUBLIC_BASE_URL containing a script filename should be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('SDK_PUBLIC_BASE_URL must not include a script filename.', $exception->getMessage());
        } finally {
            self::restoreEnvironment('SDK_PUBLIC_BASE_URL', $previousSdkUrl);
        }
    }

    public function testAppFactoryRejectsInvalidAndInsecurePublicBaseUrls(): void
    {
        $previousSdkUrl = getenv('SDK_PUBLIC_BASE_URL');
        $previousAdsUrl = getenv('ADS_PUBLIC_BASE_URL');
        $method = new ReflectionMethod(AppFactory::class, 'integrationPublicBaseUrl');
        $settings = ['app' => ['env' => 'prod']];

        try {
            foreach (
                [
                    ['SDK_PUBLIC_BASE_URL', 'not-an-absolute-url'],
                    ['SDK_PUBLIC_BASE_URL', 'https://user:secret@sdk.example.test'],
                    ['ADS_PUBLIC_BASE_URL', 'https://ads.example.test/base?tenant=prod'],
                ] as [$variable, $value]
            ) {
                putenv($variable . '=' . $value);

                try {
                    $method->invoke(null, $variable, $settings);
                    self::fail($variable . ' should reject ' . $value . '.');
                } catch (\RuntimeException $exception) {
                    self::assertSame(
                        $variable . ' must be an HTTP(S) origin/base URL without credentials, query, or fragment.',
                        $exception->getMessage(),
                    );
                }
            }

            foreach (['SDK_PUBLIC_BASE_URL', 'ADS_PUBLIC_BASE_URL'] as $variable) {
                putenv($variable . '=http://public.example.test');

                try {
                    $method->invoke(null, $variable, $settings);
                    self::fail($variable . ' should require HTTPS in production.');
                } catch (\RuntimeException $exception) {
                    self::assertSame($variable . ' must use HTTPS outside local/testing.', $exception->getMessage());
                }
            }

            try {
                $method->invoke(null, 'UNSUPPORTED_PUBLIC_BASE_URL', $settings);
                self::fail('Unsupported public base variables should be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'Unsupported integration public base URL variable: UNSUPPORTED_PUBLIC_BASE_URL',
                    $exception->getMessage(),
                );
            }
        } finally {
            self::restoreEnvironment('SDK_PUBLIC_BASE_URL', $previousSdkUrl);
            self::restoreEnvironment('ADS_PUBLIC_BASE_URL', $previousAdsUrl);
        }
    }

    public function testIntegrationServiceRejectsAnEmptyPublicBaseUrl(): void
    {
        $service = new PublisherIntegrationCodeService(
            new FakePublisherIntegrationSiteRepository([]),
            new FakePublisherIntegrationSlotRepository([]),
            'https://sdk.example.test',
            'https://ads.example.test',
        );
        $method = new ReflectionMethod(PublisherIntegrationCodeService::class, 'baseUrl');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Publisher integration public base URL must not be empty.');

        $method->invoke($service, ' ');
    }

    private static function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}

final class FakePublisherIntegrationSiteRepository implements PublisherSiteRepositoryInterface
{
    /** @param array<int, PublisherSite> $sites */
    public function __construct(private readonly array $sites)
    {
    }

    public function create(int $organizationId, string $name, string $domain, string $verificationToken): PublisherSite
    {
        throw new \LogicException('Not used by this test.');
    }

    public function listForOrganization(int $organizationId): array
    {
        throw new \LogicException('Not used by this test.');
    }

    public function findById(int $id): ?PublisherSite
    {
        return $this->sites[$id] ?? null;
    }

    public function markVerified(PublisherSite $site, \DateTimeImmutable $verifiedAt): PublisherSite
    {
        throw new \LogicException('Not used by this test.');
    }

    public function markVerificationFailed(PublisherSite $site): PublisherSite
    {
        throw new \LogicException('Not used by this test.');
    }
}

final class FakePublisherIntegrationSlotRepository implements AdSlotRepositoryInterface
{
    /** @param array<string, AdSlot> $slots */
    public function __construct(private readonly array $slots)
    {
    }

    public function store(AdSlot $slot): AdSlot
    {
        throw new \LogicException('Not used by this test.');
    }

    public function listForSite(int $siteId): array
    {
        throw new \LogicException('Not used by this test.');
    }

    public function findForSiteInOrganization(int $siteId, int $slotId, int $organizationId): ?AdSlot
    {
        return $this->slots[$organizationId . ':' . $siteId . ':' . $slotId] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttempt;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttemptStatus;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\PublisherSiteVerificationService;

final class PublisherSiteVerificationServiceTest extends TestCase
{
    public function testExpectationsAreDeterministicForEveryVerificationMethod(): void
    {
        $site = new PublisherSite(12, 7, 'Example.COM', PublisherSiteStatus::Pending, 'site-secret', null);
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([$site]));

        $html = $service->expectedChallenge(12, PublisherSiteVerificationMethod::HtmlMeta);
        $dns = $service->expectedChallenge(12, PublisherSiteVerificationMethod::DnsTxt);
        $file = $service->expectedChallenge(12, PublisherSiteVerificationMethod::VerificationFile);

        $expectedToken = 'va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        self::assertSame($expectedToken, $html->token);
        self::assertSame('vertoad-site-verification', $html->name);
        self::assertSame('meta', $html->placement);
        self::assertSame($expectedToken, $html->expectedValue);

        self::assertSame('_vertoad.example.com', $dns->name);
        self::assertSame('dns_txt', $dns->placement);
        self::assertSame('vertoad-site-verification=' . $expectedToken, $dns->expectedValue);

        self::assertSame('/.well-known/vertoad-site-verification.txt', $file->name);
        self::assertSame('file', $file->placement);
        self::assertSame($expectedToken, $file->expectedValue);
    }

    public function testServerSideHtmlMetaProbeMarksSiteVerifiedAndRecordsAttempt(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $token = 'va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => '<!doctype html><html><head><meta content="' . $token . '" name="vertoad-site-verification"></head></html>',
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: self::publicHttpHostResolver(...),
        );

        $verified = $service->verify(
            siteId: 12,
            method: PublisherSiteVerificationMethod::HtmlMeta,
            checkedAt: new \DateTimeImmutable('2026-06-07 08:00:00+00:00'),
        );

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame('2026-06-07T08:00:00+00:00', $verified->verifiedAt?->format(DATE_ATOM));
        self::assertSame($verified, $repository->findById(12));
        self::assertCount(1, $attempts->attempts);
        self::assertSame(PublisherSiteVerificationAttemptStatus::Success, $attempts->attempts[0]->status);
        self::assertSame(PublisherSiteVerificationMethod::HtmlMeta, $attempts->attempts[0]->method);
        self::assertSame($token, $attempts->attempts[0]->expectedValue);
        self::assertStringContainsString('sha256:', $attempts->attempts[0]->observedSummary ?? '');
        self::assertStringNotContainsString($token, $attempts->attempts[0]->observedSummary ?? '');
    }

    public function testHttpProbeUsesResolvedPublicIpWhenFetching(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $token = 'va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        $requests = [];
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: function (string $url, ?string $connectIp, string $host) use (&$requests, $token): ?string {
                $requests[] = ['url' => $url, 'connect_ip' => $connectIp, 'host' => $host];

                return '<meta name="vertoad-site-verification" content="' . $token . '">';
            },
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: static fn (string $host): array => ['93.184.216.34'],
        );

        $verified = $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame([
            ['url' => 'https://example.com/', 'connect_ip' => '93.184.216.34', 'host' => 'example.com'],
        ], $requests);
    }

    public function testHttpProbeAllowsLiteralPublicIpWithoutDnsResolution(): void
    {
        $site = new PublisherSite(12, 7, '8.8.8.8', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $token = 'va-' . substr(hash('sha256', '12|8.8.8.8|site-secret'), 0, 40);
        $requests = [];
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: function (string $url, ?string $connectIp, string $host) use (&$requests, $token): ?string {
                $requests[] = ['url' => $url, 'connect_ip' => $connectIp, 'host' => $host];

                return '<meta name="vertoad-site-verification" content="' . $token . '">';
            },
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: static function (): array {
                self::fail('Literal public IP verification should not perform DNS resolution.');
            },
        );

        $verified = $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame([
            ['url' => 'https://8.8.8.8/', 'connect_ip' => '8.8.8.8', 'host' => '8.8.8.8'],
        ], $requests);
    }

    public function testServerSideDnsTxtProbeAcceptsAnyMatchingRecordAndRecordsAttempt(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $expectedValue = 'vertoad-site-verification=va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => null,
            dnsTxtResolver: static fn (string $host): array => ['unrelated-record', $expectedValue],
        );

        $verified = $service->verify(12, PublisherSiteVerificationMethod::DnsTxt, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame(PublisherSiteVerificationAttemptStatus::Success, $attempts->attempts[0]->status);
        self::assertSame($expectedValue, $attempts->attempts[0]->expectedValue);
    }

    public function testServerSideDnsTxtProbeAcceptsStructuredRecords(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $expectedValue = 'vertoad-site-verification=va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => null,
            dnsTxtResolver: static fn (string $host): array => [
                ['txt' => 'unrelated-record'],
                ['value' => '"' . $expectedValue . '"'],
            ],
        );

        $verified = $service->verify(12, PublisherSiteVerificationMethod::DnsTxt, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame(PublisherSiteVerificationAttemptStatus::Success, $attempts->attempts[0]->status);
    }

    public function testServerSideVerificationFileProbeUsesWellKnownFileAndRecordsAttempt(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $token = 'va-' . substr(hash('sha256', '12|example.com|site-secret'), 0, 40);
        $requestedUrls = [];
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: function (string $url) use (&$requestedUrls, $token): ?string {
                $requestedUrls[] = $url;

                return "  {$token}\n";
            },
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: self::publicHttpHostResolver(...),
        );

        $verified = $service->verify(12, PublisherSiteVerificationMethod::VerificationFile, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame(['https://example.com/.well-known/vertoad-site-verification.txt'], $requestedUrls);
        self::assertSame(PublisherSiteVerificationAttemptStatus::Success, $attempts->attempts[0]->status);
        self::assertSame($token, $attempts->attempts[0]->expectedValue);
    }

    public function testServerSideProbeFailureRecordsFailedAttemptWithoutLeakingEvidence(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => '<meta name="vertoad-site-verification" content="attacker-token">',
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: self::publicHttpHostResolver(...),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publisher_site_verification_failed');

        try {
            $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
        } finally {
            self::assertSame(PublisherSiteStatus::Failed, $repository->findById(12)?->status);
            self::assertCount(1, $attempts->attempts);
            self::assertSame(PublisherSiteVerificationAttemptStatus::Failed, $attempts->attempts[0]->status);
            self::assertSame('expected_value_not_found', $attempts->attempts[0]->failureReason);
            self::assertStringContainsString('sha256:', $attempts->attempts[0]->observedSummary ?? '');
            self::assertStringNotContainsString('attacker-token', $attempts->attempts[0]->observedSummary ?? '');
        }
    }

    public function testServerSideProbeUnavailableRecordsFailedAttemptWithoutEvidence(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => throw new RuntimeException('network unavailable'),
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: self::publicHttpHostResolver(...),
        );

        try {
            $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
            self::fail('Expected unavailable probes to fail verification.');
        } catch (RuntimeException $exception) {
            self::assertSame('publisher_site_verification_failed', $exception->getMessage());
        }

        self::assertSame(PublisherSiteStatus::Failed, $repository->findById(12)?->status);
        self::assertSame(PublisherSiteVerificationAttemptStatus::Failed, $attempts->attempts[0]->status);
        self::assertSame('probe_unavailable', $attempts->attempts[0]->failureReason);
        self::assertNull($attempts->attempts[0]->observedSummary);
    }

    public function testHttpProbeFailuresCoverEmptyResponsesAndMalformedMeta(): void
    {
        foreach (
            [
                'empty-body' => static fn (string $url): ?string => null,
                'no-meta-tags' => static fn (string $url): ?string => '<html><head><title>VertoAD</title></head><body>missing verification</body></html>',
                'malformed-meta' => static fn (string $url): ?string => '<html><head><meta property="og:title" content="ignored"><meta name="vertoad-site-verification"></head></html>',
            ] as $case => $fetcher
        ) {
            $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
            $repository = new FakePublisherSiteRepository([$site]);
            $attempts = new FakePublisherSiteVerificationAttemptRepository();
            $service = new PublisherSiteVerificationService(
                $repository,
                $attempts,
                httpFetcher: $fetcher,
                dnsTxtResolver: static fn (string $host): array => [],
                httpHostResolver: self::publicHttpHostResolver(...),
            );

            try {
                $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
                self::fail('Expected invalid HTML evidence to fail verification.');
            } catch (RuntimeException $exception) {
                self::assertSame('publisher_site_verification_failed', $exception->getMessage(), $case);
            }

            self::assertSame('expected_value_not_found', $attempts->attempts[0]->failureReason, $case);
            self::assertNull($attempts->attempts[0]->observedSummary, $case);
        }
    }

    public function testDnsProbeFailuresCoverInvalidResolverReturnType(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static fn (string $url): ?string => null,
            dnsTxtResolver: static fn (string $host): string => 'not-a-record-list',
        );

        try {
            $service->verify(12, PublisherSiteVerificationMethod::DnsTxt, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
            self::fail('Expected invalid DNS resolver return values to fail verification.');
        } catch (RuntimeException $exception) {
            self::assertSame('publisher_site_verification_failed', $exception->getMessage());
        }

        self::assertSame('expected_value_not_found', $attempts->attempts[0]->failureReason);
        self::assertNull($attempts->attempts[0]->observedSummary);
    }

    public function testHttpVerificationRejectsLocalAndPrivateHostsBeforeFetching(): void
    {
        foreach (['localhost', '127.0.0.1', '10.0.0.8', '172.16.0.8', '192.168.1.8', '[::1]'] as $domain) {
            $site = new PublisherSite(12, 7, $domain, PublisherSiteStatus::Pending, 'site-secret', null);
            $repository = new FakePublisherSiteRepository([$site]);
            $attempts = new FakePublisherSiteVerificationAttemptRepository();
            $fetchCalls = 0;
            $service = new PublisherSiteVerificationService(
                $repository,
                $attempts,
                httpFetcher: function () use (&$fetchCalls): ?string {
                    $fetchCalls++;

                    return '<meta name="vertoad-site-verification" content="attacker-controlled">';
                },
                dnsTxtResolver: static fn (string $host): array => [],
                httpHostResolver: self::publicHttpHostResolver(...),
            );

            try {
                $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
                self::fail('Expected unsafe verification hosts to fail verification.');
            } catch (RuntimeException $exception) {
                self::assertSame('publisher_site_verification_failed', $exception->getMessage());
            }

            self::assertSame(0, $fetchCalls, $domain);
            self::assertSame(PublisherSiteStatus::Failed, $repository->findById(12)?->status, $domain);
            self::assertCount(1, $attempts->attempts, $domain);
            self::assertSame('unsafe_verification_host', $attempts->attempts[0]->failureReason, $domain);
            self::assertNull($attempts->attempts[0]->observedSummary, $domain);
        }
    }

    public function testHttpVerificationRejectsDomainsThatResolveToPrivateAddressesBeforeFetching(): void
    {
        $site = new PublisherSite(12, 7, 'tenant.example', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $fetchCalls = 0;
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: function () use (&$fetchCalls): ?string {
                $fetchCalls++;

                return '<meta name="vertoad-site-verification" content="attacker-controlled">';
            },
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: static fn (string $host): array => ['10.0.0.8'],
        );

        try {
            $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
            self::fail('Expected domains resolving to private addresses to fail verification.');
        } catch (RuntimeException $exception) {
            self::assertSame('publisher_site_verification_failed', $exception->getMessage());
        }

        self::assertSame(0, $fetchCalls);
        self::assertSame(PublisherSiteStatus::Failed, $repository->findById(12)?->status);
        self::assertSame('unsafe_verification_host', $attempts->attempts[0]->failureReason);
        self::assertNull($attempts->attempts[0]->observedSummary);
    }

    public function testHttpVerificationRejectsDomainsWithoutResolvedAddressesBeforeFetching(): void
    {
        $site = new PublisherSite(12, 7, 'tenant.example', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $fetchCalls = 0;
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: function () use (&$fetchCalls): ?string {
                $fetchCalls++;

                return '<meta name="vertoad-site-verification" content="attacker-controlled">';
            },
            dnsTxtResolver: static fn (string $host): array => [],
            httpHostResolver: static fn (string $host): array => [],
        );

        try {
            $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
            self::fail('Expected domains without resolved addresses to fail verification.');
        } catch (RuntimeException $exception) {
            self::assertSame('publisher_site_verification_failed', $exception->getMessage());
        }

        self::assertSame(0, $fetchCalls);
        self::assertSame(PublisherSiteStatus::Failed, $repository->findById(12)?->status);
        self::assertSame('unsafe_verification_host', $attempts->attempts[0]->failureReason);
        self::assertNull($attempts->attempts[0]->observedSummary);
    }

    public function testVerifyRequiresAttemptRepositoryForPendingSites(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([$site]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publisher_site_verification_attempt_repository_required');

        $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
    }

    public function testSuspendedSitesCannotBeSelfVerified(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Suspended, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static function (): ?string {
                self::fail('Suspended sites must not be probed by publisher self-verification.');
            },
            dnsTxtResolver: static function (): array {
                self::fail('Suspended sites must not be probed by publisher self-verification.');
            },
            httpHostResolver: static function (): array {
                self::fail('Suspended sites must not be resolved by publisher self-verification.');
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publisher_site_suspended');

        $service->verify(12, PublisherSiteVerificationMethod::HtmlMeta, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));
    }

    public function testDefaultVerificationAdaptersFailClosedAndParseTxtRecords(): void
    {
        $http = new \ReflectionMethod(PublisherSiteVerificationService::class, 'defaultHttpFetcher');
        $dns = new \ReflectionMethod(PublisherSiteVerificationService::class, 'defaultDnsTxtResolver');
        $httpHost = new \ReflectionMethod(PublisherSiteVerificationService::class, 'defaultHttpHostResolver');
        $connectUrl = new \ReflectionMethod(PublisherSiteVerificationService::class, 'connectUrl');
        $txtValues = new \ReflectionMethod(PublisherSiteVerificationService::class, 'txtValuesFromDnsRecords');
        $ipValues = new \ReflectionMethod(PublisherSiteVerificationService::class, 'ipAddressesFromDnsRecords');

        self::assertNull($http->invoke(null, 'ftp://example.com/verification'));
        self::assertNull($http->invoke(null, 'http://127.0.0.1:1/vertoad-verification'));
        self::assertSame('not a url', $connectUrl->invoke(null, 'not a url', '203.0.113.8'));
        self::assertSame(
            'https://[2001:4860:4860::8888]:8443/path?x=1',
            $connectUrl->invoke(null, 'https://example.com:8443/path?x=1', '2001:4860:4860::8888'),
        );
        self::assertSame(
            'https://203.0.113.8/path',
            $connectUrl->invoke(null, 'https://example.com/path', '203.0.113.8'),
        );
        self::assertIsArray($dns->invoke(null, 'localhost'));
        self::assertIsArray($httpHost->invoke(null, 'localhost'));
        self::assertSame(
            ['vertoad-site-verification=va-token'],
            $txtValues->invoke(null, [
                ['txt' => 'vertoad-site-verification=va-token'],
                ['txt' => 123],
                ['value' => 'ignored'],
                'ignored',
            ]),
        );
        self::assertSame(
            ['198.51.100.8', '2001:db8::8'],
            $ipValues->invoke(null, [
                ['ip' => '198.51.100.8'],
                ['ipv6' => '2001:db8::8'],
                ['ip' => 123],
                ['txt' => 'ignored'],
                'ignored',
            ]),
        );
    }

    public function testDefaultHttpFetcherDisablesRedirectFollowing(): void
    {
        if (!in_array('http', stream_get_wrappers(), true)) {
            self::markTestSkipped('The built-in HTTP stream wrapper is unavailable.');
        }

        self::assertTrue(stream_wrapper_unregister('http'));
        $registered = false;

        try {
            $registered = stream_wrapper_register('http', CapturingPublisherVerificationHttpStreamWrapper::class);
            self::assertTrue($registered);
            CapturingPublisherVerificationHttpStreamWrapper::$openedOptions = null;
            CapturingPublisherVerificationHttpStreamWrapper::$body = 'verification-body';

            $http = new \ReflectionMethod(PublisherSiteVerificationService::class, 'defaultHttpFetcher');

            self::assertSame('verification-body', $http->invoke(null, 'http://example.test/verification'));
            self::assertSame(
                0,
                CapturingPublisherVerificationHttpStreamWrapper::$openedOptions['http']['follow_location'] ?? null,
            );
        } finally {
            if ($registered && in_array('http', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('http');
            }
            stream_wrapper_restore('http');
        }
    }

    public function testRejectsMissingSite(): void
    {
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site was not found.');

        $service->expectedChallenge(12, PublisherSiteVerificationMethod::HtmlMeta);
    }

    public function testValidationReturnsAlreadyVerifiedSiteWithoutRecheckingEvidence(): void
    {
        $verifiedAt = new \DateTimeImmutable('2026-06-07 08:00:00+00:00');
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Verified, 'site-secret', $verifiedAt);
        $repository = new FakePublisherSiteRepository([$site]);
        $attempts = new FakePublisherSiteVerificationAttemptRepository();
        $service = new PublisherSiteVerificationService(
            $repository,
            $attempts,
            httpFetcher: static function (): ?string {
                self::fail('Already verified sites should not be probed again.');
            },
            dnsTxtResolver: static function (): array {
                self::fail('Already verified sites should not be probed again.');
            },
        );

        $verified = $service->verify(
            12,
            PublisherSiteVerificationMethod::HtmlMeta,
            new \DateTimeImmutable('2026-06-08 08:00:00+00:00'),
        );

        self::assertSame($site, $verified);
        self::assertSame(0, $repository->markVerifiedCalls);
        self::assertSame([], $attempts->attempts);
    }

    public function testRejectsMissingSiteAndBlankNormalizedDomain(): void
    {
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository());

        try {
            $service->expectedChallenge(0, PublisherSiteVerificationMethod::HtmlMeta);
            self::fail('Expected non-positive site ID to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Publisher site ID must be positive.', $exception->getMessage());
        }

        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([
            new PublisherSite(12, 7, ' https://.../path ', PublisherSiteStatus::Pending, 'site-secret', null),
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Publisher site domain is required.');

        $service->expectedChallenge(12, PublisherSiteVerificationMethod::DnsTxt);
    }

    public function testRejectsBlankVerificationSecret(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, ' ', null);
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([$site]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Publisher site verification token is required.');

        $service->expectedChallenge(12, PublisherSiteVerificationMethod::HtmlMeta);
    }

    /**
     * @return list<string>
     */
    private static function publicHttpHostResolver(string $host): array
    {
        return ['93.184.216.34'];
    }
}

final class FakePublisherSiteRepository implements PublisherSiteRepositoryInterface
{
    /** @var array<int, PublisherSite> */
    private array $sitesById = [];

    public int $markVerifiedCalls = 0;

    public int $markVerificationFailedCalls = 0;

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
        $this->markVerifiedCalls++;
        $verified = $site->withVerification(PublisherSiteStatus::Verified, $verifiedAt);
        $this->sitesById[$site->id] = $verified;

        return $verified;
    }

    public function markVerificationFailed(PublisherSite $site): PublisherSite
    {
        $this->markVerificationFailedCalls++;
        $failed = $site->withVerification(PublisherSiteStatus::Failed, null);
        $this->sitesById[$site->id] = $failed;

        return $failed;
    }
}

final class CapturingPublisherVerificationHttpStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @var array<string, mixed>|null */
    public static ?array $openedOptions = null;

    public static string $body = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$openedOptions = stream_context_get_options($this->context);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /** @return array<string, mixed> */
    public function stream_stat(): array
    {
        return [];
    }
}

final class FakePublisherSiteVerificationAttemptRepository implements PublisherSiteVerificationAttemptRepositoryInterface
{
    /** @var list<PublisherSiteVerificationAttempt> */
    public array $attempts = [];

    public function record(PublisherSiteVerificationAttempt $attempt): PublisherSiteVerificationAttempt
    {
        $recorded = new PublisherSiteVerificationAttempt(
            id: count($this->attempts) + 1,
            siteId: $attempt->siteId,
            organizationId: $attempt->organizationId,
            method: $attempt->method,
            expectedValue: $attempt->expectedValue,
            observedSummary: $attempt->observedSummary,
            status: $attempt->status,
            failureReason: $attempt->failureReason,
            createdAt: $attempt->createdAt,
            checkedAt: $attempt->checkedAt,
        );
        $this->attempts[] = $recorded;

        return $recorded;
    }

    public function listForSite(int $siteId, int $organizationId): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (PublisherSiteVerificationAttempt $attempt): bool =>
                $attempt->siteId === $siteId && $attempt->organizationId === $organizationId,
        ));
    }
}

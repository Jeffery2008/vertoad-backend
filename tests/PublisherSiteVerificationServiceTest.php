<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
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

    public function testValidationAcceptsMatchingEvidenceAndMarksSiteVerified(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $repository = new FakePublisherSiteRepository([$site]);
        $service = new PublisherSiteVerificationService($repository);
        $challenge = $service->expectedChallenge(12, PublisherSiteVerificationMethod::DnsTxt);

        $verified = $service->verify(
            siteId: 12,
            method: PublisherSiteVerificationMethod::DnsTxt,
            observedValue: '  ' . $challenge->expectedValue . '  ',
            verifiedAt: new \DateTimeImmutable('2026-06-07 08:00:00+00:00'),
        );

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame('2026-06-07T08:00:00+00:00', $verified->verifiedAt?->format(DATE_ATOM));
        self::assertSame($verified, $repository->findById(12));
    }

    public function testValidationRejectsMismatchedEvidence(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, 'site-secret', null);
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([$site]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publisher site verification evidence did not match the expected value.');

        $service->verify(12, PublisherSiteVerificationMethod::VerificationFile, 'wrong-token', new \DateTimeImmutable());
    }

    public function testRejectsBlankVerificationSecret(): void
    {
        $site = new PublisherSite(12, 7, 'example.com', PublisherSiteStatus::Pending, ' ', null);
        $service = new PublisherSiteVerificationService(new FakePublisherSiteRepository([$site]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Publisher site verification token is required.');

        $service->expectedChallenge(12, PublisherSiteVerificationMethod::HtmlMeta);
    }
}

final class FakePublisherSiteRepository implements PublisherSiteRepositoryInterface
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

    public function markVerified(PublisherSite $site, \DateTimeImmutable $verifiedAt): PublisherSite
    {
        $verified = $site->withVerification(PublisherSiteStatus::Verified, $verifiedAt);
        $this->sitesById[$site->id] = $verified;

        return $verified;
    }
}

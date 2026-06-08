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
        $service = new PublisherSiteVerificationService($repository);

        $verified = $service->verify(
            12,
            PublisherSiteVerificationMethod::HtmlMeta,
            'wrong-token-is-ignored-for-already-verified-sites',
            new \DateTimeImmutable('2026-06-08 08:00:00+00:00'),
        );

        self::assertSame($site, $verified);
        self::assertSame(0, $repository->markVerifiedCalls);
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
}

final class FakePublisherSiteRepository implements PublisherSiteRepositoryInterface
{
    /** @var array<int, PublisherSite> */
    private array $sitesById = [];

    public int $markVerifiedCalls = 0;

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
}

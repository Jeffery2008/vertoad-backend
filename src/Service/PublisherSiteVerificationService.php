<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Domain\Publisher\PublisherSiteVerificationChallenge;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final class PublisherSiteVerificationService
{
    private const TOKEN_PREFIX = 'va-';

    public function __construct(private readonly PublisherSiteRepositoryInterface $sites)
    {
    }

    public function expectedChallenge(
        int $siteId,
        PublisherSiteVerificationMethod $method,
    ): PublisherSiteVerificationChallenge {
        $site = $this->requireSite($siteId);
        $domain = $this->normalizeDomain($site->domain);
        $token = $this->expectedToken($site, $domain);

        return match ($method) {
            PublisherSiteVerificationMethod::HtmlMeta => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'meta',
                name: 'vertoad-site-verification',
                expectedValue: $token,
            ),
            PublisherSiteVerificationMethod::DnsTxt => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'dns_txt',
                name: '_vertoad.' . $domain,
                expectedValue: 'vertoad-site-verification=' . $token,
            ),
            PublisherSiteVerificationMethod::VerificationFile => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'file',
                name: '/.well-known/vertoad-site-verification.txt',
                expectedValue: $token,
            ),
        };
    }

    public function verify(
        int $siteId,
        PublisherSiteVerificationMethod $method,
        string $observedValue,
        DateTimeImmutable $verifiedAt,
    ): PublisherSite {
        $site = $this->requireSite($siteId);
        if ($site->status === PublisherSiteStatus::Verified) {
            return $site;
        }

        $challenge = $this->expectedChallenge($siteId, $method);
        if (!$this->matches($challenge->expectedValue, $observedValue)) {
            throw new RuntimeException('Publisher site verification evidence did not match the expected value.');
        }

        return $this->sites->markVerified($site, $verifiedAt);
    }

    private function requireSite(int $siteId): PublisherSite
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Publisher site ID must be positive.');
        }

        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new RuntimeException('Publisher site was not found.');
        }

        return $site;
    }

    private function expectedToken(PublisherSite $site, string $domain): string
    {
        $verificationToken = trim($site->verificationToken);
        if ($verificationToken === '') {
            throw new InvalidArgumentException('Publisher site verification token is required.');
        }

        return self::TOKEN_PREFIX . substr(hash('sha256', $site->id . '|' . $domain . '|' . $verificationToken), 0, 40);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];
        $domain = trim($domain, ". \t\n\r\0\x0B");

        if ($domain === '') {
            throw new InvalidArgumentException('Publisher site domain is required.');
        }

        return $domain;
    }

    private function matches(string $expectedValue, string $observedValue): bool
    {
        return hash_equals($expectedValue, trim($observedValue));
    }
}

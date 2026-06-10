<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Publisher\PublisherSiteVerificationAttempt;

interface PublisherSiteVerificationAttemptRepositoryInterface
{
    public function record(PublisherSiteVerificationAttempt $attempt): PublisherSiteVerificationAttempt;

    /**
     * @return list<PublisherSiteVerificationAttempt>
     */
    public function listForSite(int $siteId, int $organizationId): array;
}

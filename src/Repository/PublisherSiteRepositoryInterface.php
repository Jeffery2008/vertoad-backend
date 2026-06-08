<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Publisher\PublisherSite;

interface PublisherSiteRepositoryInterface
{
    public function create(int $organizationId, string $name, string $domain, string $verificationToken): PublisherSite;

    /**
     * @return list<PublisherSite>
     */
    public function listForOrganization(int $organizationId): array;

    public function findById(int $id): ?PublisherSite;

    public function markVerified(PublisherSite $site, DateTimeImmutable $verifiedAt): PublisherSite;
}

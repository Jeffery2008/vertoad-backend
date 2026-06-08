<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

use DateTimeImmutable;

final readonly class PublisherSite
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $domain,
        public PublisherSiteStatus $status,
        public string $verificationToken,
        public ?DateTimeImmutable $verifiedAt,
        public string $name = '',
    ) {
    }

    public function withVerification(PublisherSiteStatus $status, ?DateTimeImmutable $verifiedAt): self
    {
        return new self(
            id: $this->id,
            organizationId: $this->organizationId,
            domain: $this->domain,
            status: $status,
            verificationToken: $this->verificationToken,
            verifiedAt: $verifiedAt,
            name: $this->name,
        );
    }
}

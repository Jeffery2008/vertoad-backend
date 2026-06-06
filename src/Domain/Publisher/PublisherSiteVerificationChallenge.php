<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

final readonly class PublisherSiteVerificationChallenge
{
    public function __construct(
        public PublisherSiteVerificationMethod $method,
        public string $token,
        public string $placement,
        public string $name,
        public string $expectedValue,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Auth\OAuthClient;

interface OAuthConsentRepositoryInterface
{
    /** @param list<string> $scopes */
    public function grantConsent(OAuthClient $client, int $userId, ?int $organizationId, array $scopes, DateTimeImmutable $now): void;

    /** @param list<string> $scopes */
    public function hasConsentFor(OAuthClient $client, int $userId, ?int $organizationId, array $scopes): bool;
}

<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Auth\OAuthClient;

interface OAuthClientRepositoryInterface
{
    public function store(OAuthClient $client): OAuthClient;

    public function findActiveByIdentifier(string $clientIdentifier): ?OAuthClient;

    /**
     * @return list<OAuthClient>
     */
    public function listActiveForOrganization(int $organizationId): array;

    public function rotateSecret(string $clientIdentifier, string $secretHash): bool;
}

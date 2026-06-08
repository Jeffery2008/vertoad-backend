<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use VertoAD\Domain\Auth\OAuthClient;

final readonly class LeagueOAuthClientEntity implements ClientEntityInterface
{
    public function __construct(private OAuthClient $client)
    {
    }

    public function domainClient(): OAuthClient
    {
        return $this->client;
    }

    public function getIdentifier(): string
    {
        return $this->client->clientIdentifier;
    }

    public function getName(): string
    {
        return $this->client->name;
    }

    public function getRedirectUri(): array
    {
        return $this->client->redirectUris;
    }

    public function isConfidential(): bool
    {
        return $this->client->isConfidential;
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

final readonly class OAuthAccessTokenContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public int $accessTokenId,
        public int $clientId,
        public string $clientIdentifier,
        public ?int $organizationId,
        public ?AuthenticatedUser $user,
        public array $scopes,
    ) {
    }
}

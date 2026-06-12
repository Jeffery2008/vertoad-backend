<?php

declare(strict_types=1);

namespace VertoAD\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;

final readonly class RequestUserContext
{
    public const string ATTRIBUTE = 'vertoad.auth_context';

    public function __construct(
        public ?AuthenticatedUser $user = null,
        public ?int $organizationId = null,
        public ?OAuthAccessTokenContext $oauthToken = null,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        return $context instanceof self ? $context : new self();
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null || $this->oauthToken !== null;
    }

    public function hasOAuthScope(string $requiredScope): bool
    {
        if ($this->oauthToken === null) {
            return false;
        }

        foreach ($this->oauthToken->scopes as $scope) {
            if ($scope === '*' || $scope === $requiredScope) {
                return true;
            }

            if (str_ends_with($scope, '.*')) {
                $prefix = substr($scope, 0, -1);
                if (str_starts_with($requiredScope, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}

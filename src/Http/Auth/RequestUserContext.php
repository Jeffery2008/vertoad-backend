<?php

declare(strict_types=1);

namespace VertoAD\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Auth\AuthenticatedUser;

final readonly class RequestUserContext
{
    public const string ATTRIBUTE = 'vertoad.auth_context';

    public function __construct(
        public ?AuthenticatedUser $user = null,
        public ?int $organizationId = null,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        return $context instanceof self ? $context : new self();
    }
}

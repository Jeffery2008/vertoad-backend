<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\OAuth;

use JsonSerializable;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class LeagueOAuthScopeEntity implements ScopeEntityInterface, JsonSerializable
{
    use EntityTrait;
    use ScopeTrait;

    public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }
}

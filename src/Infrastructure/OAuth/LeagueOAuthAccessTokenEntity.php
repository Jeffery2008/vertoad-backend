<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

final class LeagueOAuthAccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    private ?int $persistedId = null;

    public function setPersistedId(int $persistedId): void
    {
        $this->persistedId = $persistedId;
    }

    public function persistedId(): ?int
    {
        return $this->persistedId;
    }
}

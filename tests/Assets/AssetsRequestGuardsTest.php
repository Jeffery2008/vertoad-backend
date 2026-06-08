<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Assets\AssetsRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;

final class AssetsRequestGuardsTest extends TestCase
{
    public function testAcceptsIntegerOrganizationQueryParameterWhenContextIsScoped(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/assets/upload-intents')
            ->withQueryParams(['organization_id' => 99]);
        $context = new RequestUserContext(new AuthenticatedUser(7, 'assets@example.com', false), 99);

        self::assertNull(AssetsRequestGuards::requireAuthenticatedOrganization($context, $request));
    }
}

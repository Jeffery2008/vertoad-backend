<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Billing\BillingRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;

final class BillingRequestGuardsTest extends TestCase
{
    public function testRequiresAuthenticatedUserBeforeOrganizationScope(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/billing/balance?organization_id=99');

        $error = BillingRequestGuards::requireAuthenticatedOrganization(new RequestUserContext(), $request);

        self::assertSame(401, $error['status']);
        self::assertSame('authentication_required', $error['payload']['code']);
    }

    public function testRequiresPositiveOrganizationQueryParameter(): void
    {
        $context = new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 99);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/billing/balance');

        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);

        self::assertSame(400, $error['status']);
        self::assertSame('organization_scope_required', $error['payload']['code']);
    }

    public function testRequiresAuthenticatorResolvedOrganizationScope(): void
    {
        $context = new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), null);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/billing/balance?organization_id=99');

        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);

        self::assertSame(400, $error['status']);
        self::assertSame('organization_scope_required', $error['payload']['code']);
    }

    public function testAcceptsIntegerOrganizationQueryParameterWhenContextIsScoped(): void
    {
        $context = new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 99);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/billing/balance')
            ->withQueryParams(['organization_id' => 99]);

        self::assertNull(BillingRequestGuards::requireAuthenticatedOrganization($context, $request));
    }
}

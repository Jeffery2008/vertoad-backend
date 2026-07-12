<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\OAuthScopeCatalog;
use VertoAD\Service\PermissionMatcher;

final class OAuthScopeCatalogTest extends TestCase
{
    public function testCatalogContainsOnlyAdvertiserOpenApiScopes(): void
    {
        self::assertSame([
            'attribution.conversion.write.own',
            'campaign.read.own',
            'campaign.write.own',
            'creative.design.read.own',
            'creative.design.write.own',
            'creative.read.own',
            'creative.template.read.own',
            'creative.template.write.own',
            'creative.write.own',
            'report.export.own',
            'report.read.own',
        ], OAuthScopeCatalog::all());
        self::assertTrue(OAuthScopeCatalog::isGrantable('report.read.own'));
        self::assertFalse(OAuthScopeCatalog::isGrantable('organizations.members.manage'));
        self::assertFalse(OAuthScopeCatalog::isGrantable('ops.dashboard.read.platform'));
        self::assertFalse(OAuthScopeCatalog::isGrantable('*'));
    }

    public function testCreatorMayGrantOnlyCatalogScopesAlreadyHeldInTargetOrganization(): void
    {
        $creator = new AuthenticatedUser(7, 'developer@example.com', false);
        $membership = new OrganizationMembership(
            organizationId: 99,
            userId: 7,
            status: 'active',
            roleSlugs: ['developer'],
            permissions: ['campaign.read.own', 'report.*'],
        );
        $catalog = $this->catalog($membership);

        $catalog->assertCreatorMayGrant($creator, 99, ['campaign.read.own', 'report.read.own']);
        $this->addToAssertionCount(1);

        try {
            $catalog->assertCreatorMayGrant($creator, 99, ['organizations.members.manage']);
            self::fail('Expected non-catalog scope to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('not available through the advertiser Open API', $exception->getMessage());
        }

        try {
            $catalog->assertCreatorMayGrant($creator, 99, ['creative.write.own']);
            self::fail('Expected creator permission escalation to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString("exceeds the creator's permissions", $exception->getMessage());
        }
    }

    public function testCreatorMustBeActiveMemberOfTargetOrganization(): void
    {
        $catalog = $this->catalog(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an active member of the target organization');
        $catalog->assertCreatorMayGrant(
            new AuthenticatedUser(7, 'developer@example.com', true),
            99,
            ['report.read.own'],
        );
    }

    private function catalog(?OrganizationMembership $membership): OAuthScopeCatalog
    {
        return new OAuthScopeCatalog(
            new OAuthScopeCatalogMembershipRepository($membership),
            new PermissionMatcher(),
        );
    }
}

final readonly class OAuthScopeCatalogMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function __construct(private ?OrganizationMembership $membership)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        if ($this->membership?->userId !== $userId || $this->membership->organizationId !== $organizationId) {
            return null;
        }

        return $this->membership;
    }

    public function listActiveOrganizationsForUser(int $userId): array
    {
        return [];
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}

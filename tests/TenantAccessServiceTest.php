<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class TenantAccessServiceTest extends TestCase
{
    public function testActiveMemberWithPermissionCanAccessTenant(): void
    {
        $service = new TenantAccessService(
            new InMemoryMembershipRepository([
                '20:10' => new OrganizationMembership(10, 20, 'active', ['billing'], [Permission::LedgerRead]),
            ]),
            new PermissionMatcher(),
        );

        $decision = $service->decide(new AuthenticatedUser(20, 'member@example.com', false), 10, Permission::LedgerRead);

        self::assertTrue($decision->allowed);
        self::assertSame('allowed', $decision->reason);
    }

    public function testCrossTenantMemberIsDenied(): void
    {
        $service = new TenantAccessService(
            new InMemoryMembershipRepository([
                '20:11' => new OrganizationMembership(11, 20, 'active', ['billing'], [Permission::LedgerRead]),
            ]),
            new PermissionMatcher(),
        );

        $decision = $service->decide(new AuthenticatedUser(20, 'member@example.com', false), 10, Permission::LedgerRead);

        self::assertFalse($decision->allowed);
        self::assertSame('membership_required', $decision->reason);
    }

    public function testMemberWithoutRequiredPermissionIsDenied(): void
    {
        $service = new TenantAccessService(
            new InMemoryMembershipRepository([
                '20:10' => new OrganizationMembership(10, 20, 'active', ['publisher'], [Permission::PublisherSitesRead]),
            ]),
            new PermissionMatcher(),
        );

        $decision = $service->decide(new AuthenticatedUser(20, 'member@example.com', false), 10, Permission::LedgerRead);

        self::assertFalse($decision->allowed);
        self::assertSame('permission_required', $decision->reason);
    }

    public function testSuperAdminBypassesTenantMembershipAndPermissionChecks(): void
    {
        $service = new TenantAccessService(new InMemoryMembershipRepository([]), new PermissionMatcher());

        $decision = $service->decide(new AuthenticatedUser(1, 'admin@example.com', true), 999, Permission::AuditLogsRead);

        self::assertTrue($decision->allowed);
        self::assertSame('super_admin', $decision->reason);
    }
}

/**
 * @phpstan-type MembershipMap array<string, OrganizationMembership>
 */
final class InMemoryMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    public function __construct(private readonly array $memberships)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        return $this->memberships[$userId . ':' . $organizationId] ?? null;
    }
}

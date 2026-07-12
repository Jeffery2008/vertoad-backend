<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Auth\OrganizationMembership;

interface OrganizationMembershipRepositoryInterface
{
    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership;

    /**
     * @return list<array{
     *     id:int,
     *     name:string,
     *     slug:string,
     *     roles:list<string>,
     *     permissions:list<string>
     * }>
     */
    public function listActiveOrganizationsForUser(int $userId): array;

    /**
     * @return list<array{
     *     member_id:int,
     *     organization_id:int,
     *     user_id:int,
     *     email:string,
     *     display_name:string,
     *     status:string,
     *     title:string|null,
     *     roles:list<string>,
     *     permissions:list<string>
     * }>
     */
    public function listForOrganization(int $organizationId): array;
}

<?php

declare(strict_types=1);

namespace VertoAD\Repository;

interface OrganizationMemberManagementRepositoryInterface
{
    /**
     * @return array{
     *     member_id:int,
     *     organization_id:int,
     *     user_id:int,
     *     email:string,
     *     display_name:string,
     *     status:string,
     *     title:string|null,
     *     roles:list<string>,
     *     permissions:list<string>
     * }
     */
    public function inviteMember(int $organizationId, string $email, string $roleId): array;

    /**
     * @return array{
     *     member_id:int,
     *     organization_id:int,
     *     user_id:int,
     *     email:string,
     *     display_name:string,
     *     status:string,
     *     title:string|null,
     *     roles:list<string>,
     *     permissions:list<string>
     * }|null
     */
    public function updateMemberRole(int $organizationId, int $memberId, string $roleId): ?array;

    public function removeMember(int $organizationId, int $memberId): bool;
}

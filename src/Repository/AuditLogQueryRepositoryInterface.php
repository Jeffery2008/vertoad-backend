<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use VertoAD\Domain\Audit\AuditLogRecord;

interface AuditLogQueryRepositoryInterface
{
    /**
     * @param array{
     *     action?: string,
     *     organization_id?: int,
     *     actor_user_id?: int,
     *     subject_type?: string,
     *     subject_id?: int,
     *     request_id?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     limit: int,
     *     offset: int
     * } $filters
     * @return array{
     *     items: list<AuditLogRecord>,
     *     limit: int,
     *     offset: int,
     *     total: int,
     *     has_more: bool
     * }
     */
    public function search(array $filters): array;
}

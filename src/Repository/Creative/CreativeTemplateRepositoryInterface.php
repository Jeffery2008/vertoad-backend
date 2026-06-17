<?php

declare(strict_types=1);

namespace VertoAD\Repository\Creative;

use VertoAD\Domain\Creative\CreativeTemplate;

interface CreativeTemplateRepositoryInterface
{
    public function save(CreativeTemplate $template): CreativeTemplate;

    public function findVisibleToOrganization(string $templateId, int $organizationId): ?CreativeTemplate;

    /**
     * @return list<CreativeTemplate>
     */
    public function listVisibleToOrganization(int $organizationId, string $scope = 'all'): array;
}

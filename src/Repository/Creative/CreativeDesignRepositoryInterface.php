<?php

declare(strict_types=1);

namespace VertoAD\Repository\Creative;

use DateTimeImmutable;
use VertoAD\Domain\Creative\CreativeDesign;
use VertoAD\Domain\Creative\CreativeDesignVersion;

interface CreativeDesignRepositoryInterface
{
    public function createWithInitialVersion(CreativeDesign $design, CreativeDesignVersion $version): CreativeDesign;

    public function findForOrganization(string $designId, int $organizationId): ?CreativeDesign;

    /**
     * @param array<string, mixed> $fabricJson
     */
    public function appendVersion(
        string $designId,
        int $organizationId,
        string $versionId,
        array $fabricJson,
        ?string $snapshotUrl,
        ?string $changeSummary,
        int $createdByUserId,
        DateTimeImmutable $createdAt,
    ): ?CreativeDesignVersion;

    /**
     * @return list<CreativeDesignVersion>
     */
    public function listVersions(string $designId, int $organizationId): array;
}

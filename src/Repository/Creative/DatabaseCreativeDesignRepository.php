<?php

declare(strict_types=1);

namespace VertoAD\Repository\Creative;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Creative\CreativeDesign;
use VertoAD\Domain\Creative\CreativeDesignVersion;

final readonly class DatabaseCreativeDesignRepository implements CreativeDesignRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function createWithInitialVersion(CreativeDesign $design, CreativeDesignVersion $version): CreativeDesign
    {
        return $this->connection->transactional(function () use ($design, $version): CreativeDesign {
            $this->connection->insert('creative_designs', $this->rowFromDesign($design));
            $this->connection->insert('creative_design_versions', $this->rowFromVersion($version));

            return $design;
        });
    }

    public function findForOrganization(string $designId, int $organizationId): ?CreativeDesign
    {
        if ($organizationId <= 0 || trim($designId) === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(...$this->designColumns())
            ->from('creative_designs')
            ->where('design_id = :design_id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('design_id', trim($designId))
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateDesign($row);
    }

    public function appendVersion(
        string $designId,
        int $organizationId,
        string $versionId,
        array $fabricJson,
        ?string $snapshotUrl,
        ?string $changeSummary,
        int $createdByUserId,
        DateTimeImmutable $createdAt,
    ): ?CreativeDesignVersion {
        if ($organizationId <= 0 || trim($designId) === '' || trim($versionId) === '') {
            return null;
        }

        return $this->connection->transactional(function () use (
            $designId,
            $organizationId,
            $versionId,
            $fabricJson,
            $snapshotUrl,
            $changeSummary,
            $createdByUserId,
            $createdAt,
        ): ?CreativeDesignVersion {
            $updated = $this->connection->executeStatement(
                'UPDATE creative_designs
                    SET current_version = current_version + 1,
                        updated_by_user_id = :updated_by_user_id,
                        updated_at = :updated_at
                  WHERE design_id = :design_id
                    AND organization_id = :organization_id',
                [
                    'updated_by_user_id' => $createdByUserId,
                    'updated_at' => $this->formatDate($createdAt),
                    'design_id' => trim($designId),
                    'organization_id' => $organizationId,
                ],
            );
            if ($updated !== 1) {
                return null;
            }

            $design = $this->findForOrganization($designId, $organizationId);
            if ($design === null) {
                throw new \RuntimeException('Creative design version increment could not be reloaded.');
            }

            $version = new CreativeDesignVersion(
                versionId: trim($versionId),
                designId: $design->designId,
                versionNumber: $design->currentVersion,
                fabricJson: $fabricJson,
                snapshotUrl: $snapshotUrl,
                changeSummary: $changeSummary,
                createdByUserId: $createdByUserId,
                createdAt: $createdAt,
            );
            $this->connection->insert('creative_design_versions', $this->rowFromVersion($version));

            return $version;
        });
    }

    public function listVersions(string $designId, int $organizationId): array
    {
        if ($this->findForOrganization($designId, $organizationId) === null) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->versionColumns())
            ->from('creative_design_versions')
            ->where('design_id = :design_id')
            ->orderBy('version_number', 'DESC')
            ->setParameter('design_id', trim($designId))
            ->fetchAllAssociative();

        return array_map(fn (array $row): CreativeDesignVersion => $this->hydrateVersion($row), $rows);
    }

    /**
     * @return list<string>
     */
    private function designColumns(): array
    {
        return [
            'design_id',
            'organization_id',
            'name',
            'width',
            'height',
            'current_version',
            'template_id',
            'status',
            'created_by_user_id',
            'updated_by_user_id',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function versionColumns(): array
    {
        return [
            'version_id',
            'design_id',
            'version_number',
            'fabric_json',
            'snapshot_url',
            'change_summary',
            'created_by_user_id',
            'created_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromDesign(CreativeDesign $design): array
    {
        return [
            'design_id' => $design->designId,
            'organization_id' => $design->organizationId,
            'name' => $design->name,
            'width' => $design->width,
            'height' => $design->height,
            'current_version' => $design->currentVersion,
            'template_id' => $design->templateId,
            'status' => $design->status,
            'created_by_user_id' => $design->createdByUserId,
            'updated_by_user_id' => $design->updatedByUserId,
            'created_at' => $this->formatDate($design->createdAt),
            'updated_at' => $this->formatDate($design->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromVersion(CreativeDesignVersion $version): array
    {
        return [
            'version_id' => $version->versionId,
            'design_id' => $version->designId,
            'version_number' => $version->versionNumber,
            'fabric_json' => json_encode($version->fabricJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'snapshot_url' => $version->snapshotUrl,
            'change_summary' => $version->changeSummary,
            'created_by_user_id' => $version->createdByUserId,
            'created_at' => $this->formatDate($version->createdAt),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDesign(array $row): CreativeDesign
    {
        return new CreativeDesign(
            designId: (string) $row['design_id'],
            organizationId: (int) $row['organization_id'],
            name: (string) $row['name'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            currentVersion: (int) $row['current_version'],
            templateId: $row['template_id'] === null ? null : (string) $row['template_id'],
            status: (string) $row['status'],
            createdByUserId: (int) $row['created_by_user_id'],
            updatedByUserId: (int) $row['updated_by_user_id'],
            createdAt: $this->parseDate((string) $row['created_at']),
            updatedAt: $this->parseDate((string) $row['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateVersion(array $row): CreativeDesignVersion
    {
        $fabricJson = json_decode((string) $row['fabric_json'], true, flags: JSON_THROW_ON_ERROR);

        return new CreativeDesignVersion(
            versionId: (string) $row['version_id'],
            designId: (string) $row['design_id'],
            versionNumber: (int) $row['version_number'],
            fabricJson: is_array($fabricJson) ? $fabricJson : [],
            snapshotUrl: $row['snapshot_url'] === null ? null : (string) $row['snapshot_url'],
            changeSummary: $row['change_summary'] === null ? null : (string) $row['change_summary'],
            createdByUserId: (int) $row['created_by_user_id'],
            createdAt: $this->parseDate((string) $row['created_at']),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

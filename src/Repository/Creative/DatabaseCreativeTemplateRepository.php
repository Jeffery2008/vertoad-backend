<?php

declare(strict_types=1);

namespace VertoAD\Repository\Creative;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Creative\CreativeTemplate;

final readonly class DatabaseCreativeTemplateRepository implements CreativeTemplateRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(CreativeTemplate $template): CreativeTemplate
    {
        $row = $this->rowFromTemplate($template);
        $types = [
            'organization_id' => $template->organizationId === null ? ParameterType::NULL : ParameterType::INTEGER,
            'created_by_user_id' => ParameterType::INTEGER,
        ];

        if ($this->findById($template->templateId) === null) {
            $this->connection->insert('creative_templates', $row, $types);

            return $template;
        }

        $this->connection->update('creative_templates', $row, ['template_id' => $template->templateId], $types);

        return $template;
    }

    public function findVisibleToOrganization(string $templateId, int $organizationId): ?CreativeTemplate
    {
        if ($organizationId <= 0 || trim($templateId) === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('creative_templates')
            ->where('template_id = :template_id')
            ->andWhere('(organization_id IS NULL OR organization_id = :organization_id)')
            ->setParameter('template_id', trim($templateId))
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function listVisibleToOrganization(int $organizationId, string $scope = 'all'): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $scope = trim($scope) === '' ? 'all' : trim($scope);
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('creative_templates');

        if ($scope === 'platform') {
            $query->where('organization_id IS NULL');
        } elseif ($scope === 'organization') {
            $query->where('organization_id = :organization_id')
                ->setParameter('organization_id', $organizationId);
        } else {
            $query->where('(organization_id IS NULL OR organization_id = :organization_id)')
                ->setParameter('organization_id', $organizationId);
        }

        $rows = $query
            ->orderBy('CASE WHEN organization_id IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('updated_at', 'DESC')
            ->addOrderBy('template_id', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): CreativeTemplate => $this->hydrate($row), $rows);
    }

    private function findById(string $templateId): ?CreativeTemplate
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('creative_templates')
            ->where('template_id = :template_id')
            ->setParameter('template_id', trim($templateId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'template_id',
            'organization_id',
            'name',
            'description',
            'width',
            'height',
            'fabric_json',
            'snapshot_url',
            'tags_json',
            'created_by_user_id',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromTemplate(CreativeTemplate $template): array
    {
        return [
            'template_id' => $template->templateId,
            'organization_id' => $template->organizationId,
            'name' => $template->name,
            'description' => $template->description,
            'width' => $template->width,
            'height' => $template->height,
            'fabric_json' => json_encode($template->fabricJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'snapshot_url' => $template->snapshotUrl,
            'tags_json' => json_encode($template->tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_by_user_id' => $template->createdByUserId,
            'created_at' => $this->formatDate($template->createdAt),
            'updated_at' => $this->formatDate($template->updatedAt),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CreativeTemplate
    {
        $fabricJson = json_decode((string) $row['fabric_json'], true, flags: JSON_THROW_ON_ERROR);
        $tags = json_decode((string) $row['tags_json'], true, flags: JSON_THROW_ON_ERROR);

        return new CreativeTemplate(
            templateId: (string) $row['template_id'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            name: (string) $row['name'],
            description: $row['description'] === null ? null : (string) $row['description'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            fabricJson: is_array($fabricJson) ? $fabricJson : [],
            snapshotUrl: $row['snapshot_url'] === null ? null : (string) $row['snapshot_url'],
            tags: is_array($tags) ? array_values(array_map('strval', $tags)) : [],
            createdByUserId: (int) $row['created_by_user_id'],
            createdAt: $this->parseDate((string) $row['created_at']),
            updatedAt: $this->parseDate((string) $row['updated_at']),
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

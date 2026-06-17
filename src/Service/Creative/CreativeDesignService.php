<?php

declare(strict_types=1);

namespace VertoAD\Service\Creative;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VertoAD\Domain\Creative\CreativeDesign;
use VertoAD\Domain\Creative\CreativeDesignVersion;
use VertoAD\Domain\Creative\CreativeTemplate;
use VertoAD\Repository\Creative\CreativeDesignRepositoryInterface;
use VertoAD\Repository\Creative\CreativeTemplateRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class CreativeDesignService
{
    /**
     * @param callable(string): string|null $idGenerator
     */
    public function __construct(
        private CreativeTemplateRepositoryInterface $templates,
        private CreativeDesignRepositoryInterface $designs,
        private AuditLogService $audit,
        private mixed $idGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createTemplate(array $payload, int $actorUserId, ?string $requestId = null): CreativeTemplate
    {
        $scope = $this->scope($payload['scope'] ?? 'organization');
        $organizationId = $scope === 'platform'
            ? $this->platformOrganizationId($payload['organization_id'] ?? null)
            : $this->positiveInt($payload['organization_id'] ?? null, 'organization_id');
        $now = $this->now();
        $template = new CreativeTemplate(
            templateId: $this->newId('tpl'),
            organizationId: $organizationId,
            name: $this->requiredString($payload['name'] ?? null, 'name', 160),
            description: $this->optionalString($payload['description'] ?? null, 'description', 2000),
            width: $this->positiveInt($payload['width'] ?? null, 'width'),
            height: $this->positiveInt($payload['height'] ?? null, 'height'),
            fabricJson: $this->objectField($payload['fabric_json'] ?? null, 'fabric_json'),
            snapshotUrl: $this->optionalUrl($payload['snapshot_url'] ?? null, 'snapshot_url'),
            tags: $this->tags($payload['tags'] ?? []),
            createdByUserId: $this->positiveActor($actorUserId),
            createdAt: $now,
            updatedAt: $now,
        );

        $saved = $this->templates->save($template);
        $this->audit->record(
            action: 'creative.template.created',
            subjectType: 'creative_template',
            actorUserId: $actorUserId,
            organizationId: $saved->organizationId,
            requestId: $requestId,
            metadata: [
                'template_id' => $saved->templateId,
                'scope' => $saved->scope(),
            ],
        );

        return $saved;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{design: CreativeDesign, version: CreativeDesignVersion}
     */
    public function createDesign(array $payload, int $actorUserId, ?string $requestId = null): array
    {
        $organizationId = $this->positiveInt($payload['organization_id'] ?? null, 'organization_id');
        $templateId = $this->optionalString($payload['template_id'] ?? null, 'template_id', 80);
        $template = $templateId === null ? null : $this->templates->findVisibleToOrganization($templateId, $organizationId);
        if ($templateId !== null && $template === null) {
            throw new InvalidArgumentException('template_id was not found for this organization.');
        }

        $width = $template?->width ?? $this->positiveInt($payload['width'] ?? null, 'width');
        $height = $template?->height ?? $this->positiveInt($payload['height'] ?? null, 'height');
        $fabricJson = array_key_exists('fabric_json', $payload)
            ? $this->objectField($payload['fabric_json'], 'fabric_json')
            : ($template?->fabricJson ?? throw new InvalidArgumentException('fabric_json is required.'));
        $snapshotUrl = array_key_exists('snapshot_url', $payload)
            ? $this->optionalUrl($payload['snapshot_url'], 'snapshot_url')
            : $template?->snapshotUrl;
        $summary = $this->optionalString($payload['change_summary'] ?? 'Initial draft', 'change_summary', 512);
        $now = $this->now();
        $actorUserId = $this->positiveActor($actorUserId);
        $designId = $this->newId('dsn');
        $design = new CreativeDesign(
            designId: $designId,
            organizationId: $organizationId,
            name: $this->requiredString($payload['name'] ?? null, 'name', 160),
            width: $width,
            height: $height,
            currentVersion: 1,
            templateId: $template?->templateId,
            status: 'draft',
            createdByUserId: $actorUserId,
            updatedByUserId: $actorUserId,
            createdAt: $now,
            updatedAt: $now,
        );
        $version = new CreativeDesignVersion(
            versionId: $this->newId('dsv'),
            designId: $designId,
            versionNumber: 1,
            fabricJson: $fabricJson,
            snapshotUrl: $snapshotUrl,
            changeSummary: $summary,
            createdByUserId: $actorUserId,
            createdAt: $now,
        );

        $saved = $this->designs->createWithInitialVersion($design, $version);
        $this->audit->record(
            action: 'creative.design.created',
            subjectType: 'creative_design',
            actorUserId: $actorUserId,
            organizationId: $organizationId,
            requestId: $requestId,
            metadata: [
                'design_id' => $saved->designId,
                'template_id' => $saved->templateId,
                'version_number' => 1,
            ],
        );

        return ['design' => $saved, 'version' => $version];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createVersion(
        string $designId,
        int $organizationId,
        array $payload,
        int $actorUserId,
        ?string $requestId = null,
    ): ?CreativeDesignVersion {
        $organizationId = $this->positiveInt($organizationId, 'organization_id');
        $actorUserId = $this->positiveActor($actorUserId);
        $designId = $this->requiredString($designId, 'design_id', 80);
        $version = $this->designs->appendVersion(
            designId: $designId,
            organizationId: $organizationId,
            versionId: $this->newId('dsv'),
            fabricJson: $this->objectField($payload['fabric_json'] ?? null, 'fabric_json'),
            snapshotUrl: $this->optionalUrl($payload['snapshot_url'] ?? null, 'snapshot_url'),
            changeSummary: $this->optionalString($payload['change_summary'] ?? null, 'change_summary', 512),
            createdByUserId: $actorUserId,
            createdAt: $this->now(),
        );

        if ($version !== null) {
            $this->audit->record(
                action: 'creative.design.version_created',
                subjectType: 'creative_design_version',
                actorUserId: $actorUserId,
                organizationId: $organizationId,
                requestId: $requestId,
                metadata: [
                    'design_id' => $designId,
                    'version_id' => $version->versionId,
                    'version_number' => $version->versionNumber,
                ],
            );
        }

        return $version;
    }

    /**
     * @return array{design: CreativeDesign, versions: list<CreativeDesignVersion>}|null
     */
    public function listVersions(string $designId, int $organizationId): ?array
    {
        $designId = $this->requiredString($designId, 'design_id', 80);
        $organizationId = $this->positiveInt($organizationId, 'organization_id');
        $design = $this->designs->findForOrganization($designId, $organizationId);
        if ($design === null) {
            return null;
        }

        return [
            'design' => $design,
            'versions' => $this->designs->listVersions($designId, $organizationId),
        ];
    }

    /**
     * @return list<CreativeTemplate>
     */
    public function listTemplates(int $organizationId, string $scope = 'all'): array
    {
        $scope = $this->scope($scope, allowAll: true);

        return $this->templates->listVisibleToOrganization(
            $this->positiveInt($organizationId, 'organization_id'),
            $scope,
        );
    }

    private function newId(string $prefix): string
    {
        if (is_callable($this->idGenerator)) {
            return (string) ($this->idGenerator)($prefix);
        }

        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function scope(mixed $value, bool $allowAll = false): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('scope must be a string.');
        }

        $scope = trim($value);
        $allowed = $allowAll ? ['all', 'platform', 'organization'] : ['platform', 'organization'];
        if (!in_array($scope, $allowed, true)) {
            throw new InvalidArgumentException('scope is invalid.');
        }

        return $scope;
    }

    private function platformOrganizationId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        throw new InvalidArgumentException('Platform templates must not include organization_id.');
    }

    private function positiveActor(int $actorUserId): int
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('actor_user_id must be a positive integer.');
        }

        return $actorUserId;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException($field . ' must be a positive integer.');
    }

    private function requiredString(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }

        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' is too long.');
        }

        return $value;
    }

    private function optionalString(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' is too long.');
        }

        return $value;
    }

    private function optionalUrl(mixed $value, string $field): ?string
    {
        $value = $this->optionalString($value, $field, 1024);
        if ($value === null) {
            return null;
        }

        if (!str_starts_with($value, 'https://')) {
            throw new InvalidArgumentException($field . ' must be an HTTPS URL.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectField(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException($field . ' must be an object.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('tags must be an array.');
        }

        $tags = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('tags must contain strings.');
            }

            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > 40) {
                throw new InvalidArgumentException('tags entries are too long.');
            }

            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }
}

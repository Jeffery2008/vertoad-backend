<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Creative\DatabaseCreativeDesignRepository;
use VertoAD\Repository\Creative\DatabaseCreativeTemplateRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Creative\CreativeDesignService;

final class CreativeDesignServiceTest extends TestCase
{
    public function testCreatesTemplatesDesignsAndVersionsWithAuditEvents(): void
    {
        $auditRepository = new CreativeServiceAuditRepository();
        $service = $this->service($this->connection(), $auditRepository);

        $template = $service->createTemplate([
            'scope' => 'organization',
            'organization_id' => 101,
            'name' => 'Summer Sale',
            'description' => 'Private organization template.',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'type' => 'textbox']]],
            'snapshot_url' => 'https://assets.example.test/templates/summer.webp',
            'tags' => ['summer', 'sale'],
        ], actorUserId: 7, requestId: 'req-template-create');

        self::assertSame('organization', $template->scope());
        self::assertSame(101, $template->organizationId);
        self::assertStringStartsWith('tpl_', $template->templateId);

        $design = $service->createDesign([
            'organization_id' => 101,
            'template_id' => $template->templateId,
            'name' => 'Summer Launch',
            'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Launch']]],
            'snapshot_url' => 'https://assets.example.test/designs/v1.webp',
        ], actorUserId: 7, requestId: 'req-design-create');

        self::assertSame(1, $design['design']->currentVersion);
        self::assertSame(1, $design['version']->versionNumber);
        self::assertSame(300, $design['design']->width);
        self::assertSame(250, $design['design']->height);

        $version2 = $service->createVersion(
            $design['design']->designId,
            101,
            [
                'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Launch today']]],
                'snapshot_url' => 'https://assets.example.test/designs/v2.webp',
                'change_summary' => 'Autosave after headline edit',
            ],
            actorUserId: 7,
            requestId: 'req-version-create',
        );

        self::assertNotNull($version2);
        self::assertSame(2, $version2->versionNumber);

        $version3 = $service->createVersion(
            $design['design']->designId,
            101,
            [
                'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Launch today']]],
                'snapshot_url' => null,
                'change_summary' => '   ',
            ],
            actorUserId: 7,
            requestId: 'req-version-whitespace-summary',
        );

        self::assertNotNull($version3);
        self::assertSame(3, $version3->versionNumber);
        self::assertNull($version3->changeSummary);
        self::assertSame([3, 2, 1], array_map(
            static fn ($version): int => $version->versionNumber,
            $service->listVersions($design['design']->designId, 101)['versions'],
        ));

        self::assertSame(
            ['creative.template.created', 'creative.design.created', 'creative.design.version_created', 'creative.design.version_created'],
            array_map(static fn (AuditLogEntry $entry): string => $entry->action, $auditRepository->entries),
        );
        self::assertSame('req-template-create', $auditRepository->entries[0]->requestId);
        self::assertSame('creative_template', $auditRepository->entries[0]->subjectType);
        self::assertSame('creative_design', $auditRepository->entries[1]->subjectType);
        self::assertSame('creative_design_version', $auditRepository->entries[2]->subjectType);
        self::assertSame(101, $auditRepository->entries[2]->organizationId);
        self::assertSame(2, $auditRepository->entries[2]->metadata['version_number'] ?? null);
    }

    public function testOrganizationTemplateDefaultsTrimTagsAndPlatformTemplatesAuditWithoutOrganization(): void
    {
        $auditRepository = new CreativeServiceAuditRepository();
        $service = $this->service($this->connection(), $auditRepository);

        $organizationTemplate = $service->createTemplate([
            'organization_id' => '101',
            'name' => '  Organization Starter  ',
            'description' => '  Trimmed description  ',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
            'tags' => [' sale ', '', 'sale', 'launch'],
        ], actorUserId: 7, requestId: 'req-org-template');

        self::assertSame('organization', $organizationTemplate->scope());
        self::assertSame(101, $organizationTemplate->organizationId);
        self::assertSame('Organization Starter', $organizationTemplate->name);
        self::assertSame('Trimmed description', $organizationTemplate->description);
        self::assertSame(['sale', 'launch'], $organizationTemplate->tags);

        $platformTemplate = $service->createTemplate([
            'scope' => 'platform',
            'name' => 'Platform Starter',
            'width' => 728,
            'height' => 90,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], actorUserId: 1, requestId: 'req-platform-template');

        self::assertSame('platform', $platformTemplate->scope());
        self::assertNull($platformTemplate->organizationId);
        self::assertSame([], $platformTemplate->tags);
        self::assertNull($auditRepository->entries[1]->organizationId);
        self::assertSame('platform', $auditRepository->entries[1]->metadata['scope'] ?? null);
    }

    public function testCreateDesignCanSeedInitialVersionFromVisibleTemplate(): void
    {
        $auditRepository = new CreativeServiceAuditRepository();
        $service = $this->service($this->connection(), $auditRepository);
        $template = $service->createTemplate([
            'scope' => 'platform',
            'name' => 'Seed Template',
            'width' => 970,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Seed']]],
            'snapshot_url' => 'https://assets.example.test/templates/seed.webp',
            'tags' => [],
        ], actorUserId: 1, requestId: 'req-template-seed');

        $created = $service->createDesign([
            'organization_id' => 101,
            'template_id' => $template->templateId,
            'name' => 'Seeded Design',
        ], actorUserId: 7, requestId: 'req-seeded-design');

        self::assertSame($template->templateId, $created['design']->templateId);
        self::assertSame(970, $created['design']->width);
        self::assertSame(250, $created['design']->height);
        self::assertSame($template->fabricJson, $created['version']->fabricJson);
        self::assertSame('https://assets.example.test/templates/seed.webp', $created['version']->snapshotUrl);
        self::assertSame('Initial draft', $created['version']->changeSummary);
        self::assertSame('creative.design.created', $auditRepository->entries[1]->action);
        self::assertSame($template->templateId, $auditRepository->entries[1]->metadata['template_id'] ?? null);
    }

    public function testDefaultIdGeneratorCreatesExpectedCreativePrefixes(): void
    {
        $auditRepository = new CreativeServiceAuditRepository();
        $service = new CreativeDesignService(
            new DatabaseCreativeTemplateRepository($this->connection()),
            new DatabaseCreativeDesignRepository($this->connection()),
            new AuditLogService($auditRepository),
        );

        $template = $service->createTemplate([
            'scope' => 'organization',
            'organization_id' => 101,
            'name' => 'Generated IDs Template',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'tags' => [],
        ], actorUserId: 7, requestId: 'req-generated-template');

        self::assertMatchesRegularExpression('/^tpl_[a-f0-9]{24}$/', $template->templateId);

        $created = $service->createDesign([
            'organization_id' => 101,
            'name' => 'Generated IDs Design',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], actorUserId: 7, requestId: 'req-generated-design');

        self::assertMatchesRegularExpression('/^dsn_[a-f0-9]{24}$/', $created['design']->designId);
        self::assertMatchesRegularExpression('/^dsv_[a-f0-9]{24}$/', $created['version']->versionId);
    }

    public function testCrossOrganizationDesignAccessIsHidden(): void
    {
        $service = $this->service($this->connection(), new CreativeServiceAuditRepository());
        $design = $service->createDesign([
            'organization_id' => 101,
            'name' => 'Org 101 Draft',
            'width' => 728,
            'height' => 90,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
        ], actorUserId: 7, requestId: 'req-design-create');

        self::assertNull($service->createVersion($design['design']->designId, 202, [
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
            'change_summary' => 'Cross org attempt',
        ], actorUserId: 8, requestId: 'req-hidden-version'));
        self::assertNull($service->listVersions($design['design']->designId, 202));
    }

    public function testMissingDesignDoesNotCreateVersionAuditEvent(): void
    {
        $auditRepository = new CreativeServiceAuditRepository();
        $service = $this->service($this->connection(), $auditRepository);

        self::assertNull($service->createVersion('dsn_missing', 101, [
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
            'change_summary' => 'No design',
        ], actorUserId: 7, requestId: 'req-missing-version'));

        self::assertSame([], $auditRepository->entries);
    }

    public function testValidationRejectsInvalidPayloads(): void
    {
        $service = $this->service($this->connection(), new CreativeServiceAuditRepository());

        foreach (
            [
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'organization',
                    'organization_id' => 101,
                    'name' => '',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => [],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'platform',
                    'organization_id' => 101,
                    'name' => 'Bad platform template',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => [],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'name' => 'Missing dimensions',
                    'fabric_json' => ['version' => '5.3.0'],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createVersion('dsn_missing', 101, [
                    'snapshot_url' => null,
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'organization',
                    'organization_id' => 101,
                    'name' => 'Bad snapshot',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'snapshot_url' => 'http://assets.example.test/insecure.webp',
                    'tags' => [],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'organization',
                    'organization_id' => 101,
                    'name' => 'Bad tags',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => ['valid', 123],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'platform',
                    'organization_id' => '101',
                    'name' => 'Platform with organization',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => [],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'organization',
                    'organization_id' => 101,
                    'name' => 'Tags not array',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => 'bad',
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 'organization',
                    'organization_id' => 101,
                    'name' => 'Long tag',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => [str_repeat('x', 41)],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createTemplate([
                    'scope' => 123,
                    'organization_id' => 101,
                    'name' => 'Bad scope type',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'tags' => [],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'template_id' => 'tpl_missing',
                    'name' => 'Missing template',
                    'fabric_json' => ['version' => '5.3.0'],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'template_id' => ['bad'],
                    'name' => 'Bad template type',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'name' => 'Long summary',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'change_summary' => str_repeat('x', 513),
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'name' => str_repeat('x', 161),
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createDesign([
                    'organization_id' => 101,
                    'name' => 'Whitespace summary',
                    'width' => 300,
                    'height' => 250,
                    'fabric_json' => ['version' => '5.3.0'],
                    'snapshot_url' => str_repeat('x', 1025),
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->listTemplates(101, 'partner'),
                static fn (): mixed => $service->listTemplates(0, 'all'),
                static fn (): mixed => $service->createVersion('dsn_missing', 101, [
                    'fabric_json' => ['version' => '5.3.0'],
                    'snapshot_url' => null,
                ], 0, 'req-invalid'),
                static fn (): mixed => $service->createVersion('', 101, [
                    'fabric_json' => ['version' => '5.3.0'],
                    'snapshot_url' => null,
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createVersion('dsn_missing', 0, [
                    'fabric_json' => ['version' => '5.3.0'],
                    'snapshot_url' => null,
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->createVersion('dsn_missing', 101, [
                    'fabric_json' => ['version' => '5.3.0'],
                    'change_summary' => ['bad'],
                ], 7, 'req-invalid'),
                static fn (): mixed => $service->listVersions('', 101),
                static fn (): mixed => $service->listVersions('dsn_missing', 0),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected InvalidArgumentException.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CreativeSchema::create($connection);

        return $connection;
    }

    private function service(Connection $connection, CreativeServiceAuditRepository $auditRepository): CreativeDesignService
    {
        return new CreativeDesignService(
            new DatabaseCreativeTemplateRepository($connection),
            new DatabaseCreativeDesignRepository($connection),
            new AuditLogService($auditRepository),
            idGenerator: static function (string $prefix): string {
                return $prefix . '_' . bin2hex(random_bytes(6));
            },
        );
    }
}

final class CreativeServiceAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

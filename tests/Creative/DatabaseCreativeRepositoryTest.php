<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Creative\CreativeDesign;
use VertoAD\Domain\Creative\CreativeDesignVersion;
use VertoAD\Domain\Creative\CreativeTemplate;
use VertoAD\Repository\Creative\DatabaseCreativeDesignRepository;
use VertoAD\Repository\Creative\DatabaseCreativeTemplateRepository;

final class DatabaseCreativeRepositoryTest extends TestCase
{
    public function testTemplatesListPlatformAndRequestedOrganizationOnly(): void
    {
        $connection = $this->connection();
        $templates = new DatabaseCreativeTemplateRepository($connection);

        $templates->save(new CreativeTemplate(
            templateId: 'tpl_platform_hero',
            organizationId: null,
            name: 'Platform Hero',
            description: 'Reusable platform layout.',
            width: 300,
            height: 250,
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'type' => 'textbox']]],
            snapshotUrl: 'https://assets.example.test/platform.webp',
            tags: ['platform', 'banner'],
            createdByUserId: 1,
            createdAt: new DateTimeImmutable('2026-06-17T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-17T00:00:00+00:00'),
        ));
        $templates->save(new CreativeTemplate(
            templateId: 'tpl_org_101_sale',
            organizationId: 101,
            name: 'Org 101 Sale',
            description: null,
            width: 728,
            height: 90,
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'sale', 'type' => 'text']]],
            snapshotUrl: null,
            tags: ['sale'],
            createdByUserId: 7,
            createdAt: new DateTimeImmutable('2026-06-17T00:01:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-17T00:01:00+00:00'),
        ));
        $templates->save(new CreativeTemplate(
            templateId: 'tpl_org_202_hidden',
            organizationId: 202,
            name: 'Org 202 Hidden',
            description: null,
            width: 160,
            height: 600,
            fabricJson: ['version' => '5.3.0', 'objects' => []],
            snapshotUrl: null,
            tags: ['hidden'],
            createdByUserId: 8,
            createdAt: new DateTimeImmutable('2026-06-17T00:02:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-17T00:02:00+00:00'),
        ));

        self::assertSame(
            ['tpl_platform_hero', 'tpl_org_101_sale'],
            array_map(static fn (CreativeTemplate $template): string => $template->templateId, $templates->listVisibleToOrganization(101, 'all')),
        );
        self::assertSame(
            ['tpl_platform_hero'],
            array_map(static fn (CreativeTemplate $template): string => $template->templateId, $templates->listVisibleToOrganization(101, 'platform')),
        );
        self::assertSame(
            ['tpl_org_101_sale'],
            array_map(static fn (CreativeTemplate $template): string => $template->templateId, $templates->listVisibleToOrganization(101, 'organization')),
        );

        $loaded = $templates->findVisibleToOrganization('tpl_platform_hero', 101);
        self::assertNotNull($loaded);
        self::assertSame('platform', $loaded->scope());
        self::assertSame(['platform', 'banner'], $loaded->tags);
        self::assertSame('textbox', $loaded->fabricJson['objects'][0]['type']);
        self::assertNull($templates->findVisibleToOrganization('tpl_org_202_hidden', 101));
    }

    public function testTemplateSaveUpdatesExistingRowsAndKeepsVisibilityScoped(): void
    {
        $connection = $this->connection();
        $templates = new DatabaseCreativeTemplateRepository($connection);
        $createdAt = new DateTimeImmutable('2026-06-17T02:00:00+00:00');

        $templates->save(new CreativeTemplate(
            templateId: 'tpl_update_me',
            organizationId: null,
            name: 'Original platform template',
            description: 'Original description.',
            width: 300,
            height: 250,
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'old']]],
            snapshotUrl: 'https://assets.example.test/original.webp',
            tags: ['platform'],
            createdByUserId: 1,
            createdAt: $createdAt,
            updatedAt: $createdAt,
        ));

        $updatedAt = new DateTimeImmutable('2026-06-17T02:05:00+00:00');
        $templates->save(new CreativeTemplate(
            templateId: 'tpl_update_me',
            organizationId: 101,
            name: 'Updated organization template',
            description: null,
            width: 970,
            height: 250,
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'new', 'type' => 'textbox']]],
            snapshotUrl: null,
            tags: ['updated', 'hero'],
            createdByUserId: 7,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        ));

        self::assertNull($templates->findVisibleToOrganization('tpl_update_me', 202));
        self::assertSame([], $templates->listVisibleToOrganization(101, 'platform'));

        $loaded = $templates->findVisibleToOrganization(' tpl_update_me ', 101);
        self::assertNotNull($loaded);
        self::assertSame('organization', $loaded->scope());
        self::assertSame(101, $loaded->organizationId);
        self::assertSame('Updated organization template', $loaded->name);
        self::assertNull($loaded->description);
        self::assertSame(970, $loaded->width);
        self::assertNull($loaded->snapshotUrl);
        self::assertSame(['updated', 'hero'], $loaded->tags);
        self::assertSame('textbox', $loaded->fabricJson['objects'][0]['type']);
        self::assertSame('2026-06-17T02:05:00+00:00', $loaded->updatedAt->format(DATE_ATOM));
    }

    public function testTemplateListOrdersPlatformBeforeOrganizationAndNewestFirstWithinEachScope(): void
    {
        $connection = $this->connection();
        $templates = new DatabaseCreativeTemplateRepository($connection);

        foreach (
            [
                ['tpl_platform_old', null, 'Platform old', '2026-06-17T01:00:00+00:00'],
                ['tpl_platform_new', null, 'Platform new', '2026-06-17T03:00:00+00:00'],
                ['tpl_org_old', 101, 'Org old', '2026-06-17T02:00:00+00:00'],
                ['tpl_org_new', 101, 'Org new', '2026-06-17T04:00:00+00:00'],
            ] as [$templateId, $organizationId, $name, $updatedAt]
        ) {
            $templates->save(new CreativeTemplate(
                templateId: $templateId,
                organizationId: $organizationId,
                name: $name,
                description: null,
                width: 300,
                height: 250,
                fabricJson: ['version' => '5.3.0', 'objects' => []],
                snapshotUrl: null,
                tags: [],
                createdByUserId: 7,
                createdAt: new DateTimeImmutable('2026-06-17T00:00:00+00:00'),
                updatedAt: new DateTimeImmutable($updatedAt),
            ));
        }

        self::assertSame(
            ['tpl_platform_new', 'tpl_platform_old', 'tpl_org_new', 'tpl_org_old'],
            array_map(
                static fn (CreativeTemplate $template): string => $template->templateId,
                $templates->listVisibleToOrganization(101, ''),
            ),
        );
    }

    public function testDesignCreationStoresInitialVersionAndAppendsNewestFirst(): void
    {
        $connection = $this->connection();
        $designs = new DatabaseCreativeDesignRepository($connection);
        $createdAt = new DateTimeImmutable('2026-06-17T01:00:00+00:00');

        $created = $designs->createWithInitialVersion(
            new CreativeDesign(
                designId: 'dsn_101_launch',
                organizationId: 101,
                name: 'Launch Banner',
                width: 300,
                height: 250,
                currentVersion: 1,
                templateId: 'tpl_platform_hero',
                status: 'draft',
                createdByUserId: 7,
                updatedByUserId: 7,
                createdAt: $createdAt,
                updatedAt: $createdAt,
            ),
            new CreativeDesignVersion(
                versionId: 'dsv_101_launch_v1',
                designId: 'dsn_101_launch',
                versionNumber: 1,
                fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Launch']]],
                snapshotUrl: 'https://assets.example.test/v1.webp',
                changeSummary: 'Initial draft',
                createdByUserId: 7,
                createdAt: $createdAt,
            ),
        );

        self::assertSame(1, $created->currentVersion);
        self::assertSame(1, $designs->listVersions('dsn_101_launch', 101)[0]->versionNumber);
        self::assertSame('Initial draft', $designs->listVersions('dsn_101_launch', 101)[0]->changeSummary);

        $version2 = $designs->appendVersion(
            designId: 'dsn_101_launch',
            organizationId: 101,
            versionId: 'dsv_101_launch_v2',
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'text' => 'Launch now']]],
            snapshotUrl: 'https://assets.example.test/v2.webp',
            changeSummary: 'Autosave',
            createdByUserId: 7,
            createdAt: new DateTimeImmutable('2026-06-17T01:05:00+00:00'),
        );

        self::assertSame(2, $version2?->versionNumber);
        $versions = $designs->listVersions('dsn_101_launch', 101);
        self::assertSame([2, 1], array_map(static fn (CreativeDesignVersion $version): int => $version->versionNumber, $versions));
        self::assertSame(2, $designs->findForOrganization('dsn_101_launch', 101)?->currentVersion);
        self::assertNull($designs->appendVersion(
            designId: 'dsn_101_launch',
            organizationId: 202,
            versionId: 'dsv_cross_org',
            fabricJson: ['version' => '5.3.0', 'objects' => []],
            snapshotUrl: null,
            changeSummary: null,
            createdByUserId: 8,
            createdAt: new DateTimeImmutable('2026-06-17T01:06:00+00:00'),
        ));
        self::assertSame([], $designs->listVersions('dsn_101_launch', 202));
    }

    public function testAppendVersionRollsBackCurrentVersionWhenVersionInsertFails(): void
    {
        $connection = $this->connection();
        $designs = new DatabaseCreativeDesignRepository($connection);
        $createdAt = new DateTimeImmutable('2026-06-17T05:00:00+00:00');

        $designs->createWithInitialVersion(
            new CreativeDesign(
                designId: 'dsn_rollback',
                organizationId: 101,
                name: 'Rollback Banner',
                width: 300,
                height: 250,
                currentVersion: 1,
                templateId: null,
                status: 'draft',
                createdByUserId: 7,
                updatedByUserId: 7,
                createdAt: $createdAt,
                updatedAt: $createdAt,
            ),
            new CreativeDesignVersion(
                versionId: 'dsv_duplicate_id',
                designId: 'dsn_rollback',
                versionNumber: 1,
                fabricJson: ['version' => '5.3.0', 'objects' => []],
                snapshotUrl: null,
                changeSummary: 'Initial draft',
                createdByUserId: 7,
                createdAt: $createdAt,
            ),
        );

        try {
            $designs->appendVersion(
                designId: 'dsn_rollback',
                organizationId: 101,
                versionId: 'dsv_duplicate_id',
                fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'changed']]],
                snapshotUrl: null,
                changeSummary: 'Should roll back',
                createdByUserId: 7,
                createdAt: new DateTimeImmutable('2026-06-17T05:05:00+00:00'),
            );
            self::fail('Expected duplicate version insert to fail.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame(1, $designs->findForOrganization('dsn_rollback', 101)?->currentVersion);
            self::assertSame(
                [1],
                array_map(
                    static fn (CreativeDesignVersion $version): int => $version->versionNumber,
                    $designs->listVersions('dsn_rollback', 101),
                ),
            );
        }
    }

    public function testAppendVersionFailsWhenIncrementedDesignCannotBeReloaded(): void
    {
        $connection = $this->connection();
        $designs = new DatabaseCreativeDesignRepository($connection);
        $createdAt = new DateTimeImmutable('2026-06-17T05:30:00+00:00');

        $designs->createWithInitialVersion(
            new CreativeDesign(
                designId: 'dsn_reload_missing',
                organizationId: 101,
                name: 'Reload Missing Banner',
                width: 300,
                height: 250,
                currentVersion: 1,
                templateId: null,
                status: 'draft',
                createdByUserId: 7,
                updatedByUserId: 7,
                createdAt: $createdAt,
                updatedAt: $createdAt,
            ),
            new CreativeDesignVersion(
                versionId: 'dsv_reload_missing_v1',
                designId: 'dsn_reload_missing',
                versionNumber: 1,
                fabricJson: ['version' => '5.3.0', 'objects' => []],
                snapshotUrl: null,
                changeSummary: 'Initial draft',
                createdByUserId: 7,
                createdAt: $createdAt,
            ),
        );
        $connection->executeStatement(
            "CREATE TRIGGER delete_reload_missing_design
                AFTER UPDATE ON creative_designs
                WHEN NEW.design_id = 'dsn_reload_missing'
                BEGIN
                    DELETE FROM creative_designs WHERE design_id = NEW.design_id;
                END",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Creative design version increment could not be reloaded.');

        $designs->appendVersion(
            designId: 'dsn_reload_missing',
            organizationId: 101,
            versionId: 'dsv_reload_missing_v2',
            fabricJson: ['version' => '5.3.0', 'objects' => [['id' => 'changed']]],
            snapshotUrl: null,
            changeSummary: 'Should fail reload',
            createdByUserId: 7,
            createdAt: new DateTimeImmutable('2026-06-17T05:35:00+00:00'),
        );
    }

    public function testRepositoriesReturnEmptyResultsForInvalidOrganizationOrIdentifiers(): void
    {
        $connection = $this->connection();
        $templates = new DatabaseCreativeTemplateRepository($connection);
        $designs = new DatabaseCreativeDesignRepository($connection);

        self::assertNull($templates->findVisibleToOrganization('', 101));
        self::assertNull($templates->findVisibleToOrganization('tpl_missing', 0));
        self::assertSame([], $templates->listVisibleToOrganization(0));

        self::assertNull($designs->findForOrganization('', 101));
        self::assertNull($designs->findForOrganization('dsn_missing', 0));
        self::assertSame([], $designs->listVersions('', 101));
        self::assertNull($designs->appendVersion(
            designId: '',
            organizationId: 101,
            versionId: 'dsv_unused',
            fabricJson: ['version' => '5.3.0', 'objects' => []],
            snapshotUrl: null,
            changeSummary: null,
            createdByUserId: 7,
            createdAt: new DateTimeImmutable('2026-06-17T06:00:00+00:00'),
        ));
        self::assertNull($designs->appendVersion(
            designId: 'dsn_missing',
            organizationId: 101,
            versionId: '',
            fabricJson: ['version' => '5.3.0', 'objects' => []],
            snapshotUrl: null,
            changeSummary: null,
            createdByUserId: 7,
            createdAt: new DateTimeImmutable('2026-06-17T06:00:00+00:00'),
        ));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CreativeSchema::create($connection);

        return $connection;
    }
}

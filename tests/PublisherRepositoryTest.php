<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\PublisherSiteRepository;

final class PublisherRepositoryTest extends TestCase
{
    public function testPublisherSiteRepositoryFindsAndMarksSiteVerified(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT "",
                domain TEXT NOT NULL,
                status TEXT NOT NULL,
                verification_token TEXT NULL,
                verified_at TEXT NULL
            )'
        );
        $connection->insert('sites', [
            'organization_id' => 7,
            'name' => 'Publisher Home',
            'domain' => 'example.com',
            'status' => 'pending',
            'verification_token' => 'site-secret',
            'verified_at' => null,
        ]);

        $repository = new PublisherSiteRepository($connection);
        $site = $repository->findById(1);

        self::assertNotNull($site);
        self::assertSame('Publisher Home', $site->name);
        self::assertSame(PublisherSiteStatus::Pending, $site->status);

        $verified = $repository->markVerified($site, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame('verified', $connection->fetchOne('SELECT status FROM sites WHERE id = 1'));
        self::assertSame('2026-06-07 08:00:00', $connection->fetchOne('SELECT verified_at FROM sites WHERE id = 1'));
    }

    public function testPublisherSiteRepositoryReturnsNullForMissingSiteAndHydratesVerifiedAt(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL DEFAULT "",
                domain TEXT NOT NULL,
                status TEXT NOT NULL,
                verification_token TEXT NULL,
                verified_at TEXT NULL
            )'
        );
        $connection->insert('sites', [
            'organization_id' => 7,
            'name' => 'Publisher Home',
            'domain' => 'example.com',
            'status' => 'verified',
            'verification_token' => null,
            'verified_at' => '2026-06-07 08:00:00',
        ]);

        $repository = new PublisherSiteRepository($connection);

        self::assertNull($repository->findById(404));

        $site = $repository->findById(1);
        self::assertSame(PublisherSiteStatus::Verified, $site?->status);
        self::assertSame('', $site?->verificationToken);
        self::assertSame('2026-06-07 08:00:00', $site?->verifiedAt?->format('Y-m-d H:i:s'));
    }

    public function testPublisherSiteRepositoryCreatesAndListsSitesForOrganization(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                domain TEXT NOT NULL,
                status TEXT NOT NULL,
                verification_token TEXT NULL,
                verified_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $repository = new PublisherSiteRepository($connection);
        $created = $repository->create(7, 'Publisher Home', 'example.com', 'site-secret');
        $repository->create(8, 'Other Publisher', 'other.example', 'other-secret');

        self::assertSame(1, $created->id);
        self::assertSame(7, $created->organizationId);
        self::assertSame('Publisher Home', $created->name);
        self::assertSame('example.com', $created->domain);
        self::assertSame(PublisherSiteStatus::Pending, $created->status);

        $sites = $repository->listForOrganization(7);
        self::assertCount(1, $sites);
        self::assertSame('Publisher Home', $sites[0]->name);
    }

    public function testAdSlotRepositoryStoresPresetAndResponsiveMetadata(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE ad_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slot_key TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                size_preset TEXT NULL,
                is_responsive INTEGER NOT NULL DEFAULT 0,
                responsive_rules_json TEXT NULL,
                status TEXT NOT NULL
            )'
        );

        $repository = new AdSlotRepository($connection);
        $slot = $repository->store(new AdSlot(
            id: null,
            siteId: 12,
            name: 'Article Inline',
            slotKey: 'article-inline',
            size: new AdSlotSize(640, 320),
            responsive: true,
            responsiveRules: [
                ['min_width' => 0, 'width' => 320, 'height' => 160],
                ['min_width' => 768, 'width' => 640, 'height' => 320],
            ],
            presetKey: null,
        ));

        self::assertSame(1, $slot->id);
        $row = $connection->fetchAssociative('SELECT * FROM ad_slots WHERE id = 1');
        self::assertIsArray($row);
        self::assertSame(12, (int) $row['site_id']);
        self::assertSame('article-inline', $row['slot_key']);
        self::assertSame(640, (int) $row['width']);
        self::assertSame(320, (int) $row['height']);
        self::assertSame(1, (int) $row['is_responsive']);
        self::assertSame(
            [
                ['min_width' => 0, 'width' => 320, 'height' => 160],
                ['min_width' => 768, 'width' => 640, 'height' => 320],
            ],
            json_decode((string) $row['responsive_rules_json'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAdSlotRepositoryUpdatesExistingSlotAndClearsEmptyResponsiveRules(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE ad_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slot_key TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                size_preset TEXT NULL,
                is_responsive INTEGER NOT NULL DEFAULT 0,
                responsive_rules_json TEXT NULL,
                status TEXT NOT NULL
            )'
        );
        $connection->insert('ad_slots', [
            'site_id' => 12,
            'name' => 'Old',
            'slot_key' => 'old',
            'width' => 300,
            'height' => 250,
            'size_preset' => 'medium_rectangle',
            'is_responsive' => 1,
            'responsive_rules_json' => '[{"min_width":0,"width":300,"height":250}]',
            'status' => 'active',
        ]);

        $repository = new AdSlotRepository($connection);
        $slot = new AdSlot(
            id: 1,
            siteId: 12,
            name: 'Updated',
            slotKey: 'updated',
            size: new AdSlotSize(728, 90),
            responsive: false,
            responsiveRules: [],
            presetKey: 'leaderboard',
            status: 'paused',
        );

        self::assertSame($slot, $repository->store($slot));

        $row = $connection->fetchAssociative('SELECT * FROM ad_slots WHERE id = 1');
        self::assertIsArray($row);
        self::assertSame('Updated', $row['name']);
        self::assertSame('updated', $row['slot_key']);
        self::assertSame(728, (int) $row['width']);
        self::assertSame(90, (int) $row['height']);
        self::assertSame('leaderboard', $row['size_preset']);
        self::assertSame(0, (int) $row['is_responsive']);
        self::assertNull($row['responsive_rules_json']);
        self::assertSame('paused', $row['status']);
    }

    public function testAdSlotRepositoryListsSlotsForSiteAndHydratesMetadata(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE ad_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slot_key TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                size_preset TEXT NULL,
                is_responsive INTEGER NOT NULL DEFAULT 0,
                responsive_rules_json TEXT NULL,
                status TEXT NOT NULL
            )'
        );
        $connection->insert('ad_slots', [
            'site_id' => 12,
            'name' => 'Article Inline',
            'slot_key' => 'article-inline',
            'width' => 640,
            'height' => 320,
            'size_preset' => null,
            'is_responsive' => 1,
            'responsive_rules_json' => '[{"min_width":0,"width":320,"height":160}]',
            'status' => 'active',
        ]);
        $connection->insert('ad_slots', [
            'site_id' => 99,
            'name' => 'Other',
            'slot_key' => 'other',
            'width' => 300,
            'height' => 250,
            'size_preset' => 'medium_rectangle',
            'is_responsive' => 0,
            'responsive_rules_json' => null,
            'status' => 'active',
        ]);

        $slots = (new AdSlotRepository($connection))->listForSite(12);

        self::assertCount(1, $slots);
        self::assertSame(1, $slots[0]->id);
        self::assertSame('Article Inline', $slots[0]->name);
        self::assertSame(640, $slots[0]->size->width);
        self::assertTrue($slots[0]->responsive);
        self::assertSame([['min_width' => 0, 'width' => 320, 'height' => 160]], $slots[0]->responsiveRules);

        $staticSlots = (new AdSlotRepository($connection))->listForSite(99);
        self::assertCount(1, $staticSlots);
        self::assertFalse($staticSlots[0]->responsive);
        self::assertSame([], $staticSlots[0]->responsiveRules);
    }
}

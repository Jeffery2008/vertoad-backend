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
                domain TEXT NOT NULL,
                status TEXT NOT NULL,
                verification_token TEXT NULL,
                verified_at TEXT NULL
            )'
        );
        $connection->insert('sites', [
            'organization_id' => 7,
            'domain' => 'example.com',
            'status' => 'pending',
            'verification_token' => 'site-secret',
            'verified_at' => null,
        ]);

        $repository = new PublisherSiteRepository($connection);
        $site = $repository->findById(1);

        self::assertNotNull($site);
        self::assertSame(PublisherSiteStatus::Pending, $site->status);

        $verified = $repository->markVerified($site, new \DateTimeImmutable('2026-06-07 08:00:00+00:00'));

        self::assertSame(PublisherSiteStatus::Verified, $verified->status);
        self::assertSame('verified', $connection->fetchOne('SELECT status FROM sites WHERE id = 1'));
        self::assertSame('2026-06-07 08:00:00', $connection->fetchOne('SELECT verified_at FROM sites WHERE id = 1'));
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
}

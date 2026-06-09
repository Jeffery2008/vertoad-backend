<?php

declare(strict_types=1);

namespace VertoAD\Tests\FeatureFlags;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\FeatureFlags\FeatureFlag;
use VertoAD\Repository\FeatureFlags\DatabaseFeatureFlagRepository;

final class DatabaseFeatureFlagRepositoryTest extends TestCase
{
    public function testPersistsFindsUpdatesAndListsFlagsWithJsonFields(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseFeatureFlagRepository($connection);

        $repository->save($this->flag('sdk.lazy_loader', updatedAt: '2026-06-09T10:00:00+00:00'));
        $repository->save($this->flag('ai_review.fast_path', enabled: false, updatedAt: '2026-06-09T11:00:00+00:00'));

        $stored = $repository->find('sdk.lazy_loader');
        self::assertNotNull($stored);
        self::assertSame('prod', $stored->environment);
        self::assertTrue($stored->enabled);
        self::assertSame([
            'organization_ids' => [101],
            'roles' => ['advertiser'],
            'site_ids' => [301],
            'slot_ids' => [401],
            'user_ids' => [501],
        ], $stored->targets);
        self::assertSame([
            'starts_at' => '2026-06-09T00:00:00+00:00',
            'ends_at' => '2026-06-30T23:59:59+00:00',
        ], $stored->time_window);
        self::assertTrue($stored->published);

        $repository->save($this->flag('sdk.lazy_loader', enabled: false, published: false, updatedAt: '2026-06-09T12:00:00+00:00'));

        $freshRepository = new DatabaseFeatureFlagRepository($connection);
        $fresh = $freshRepository->find('sdk.lazy_loader');
        self::assertNotNull($fresh);
        self::assertFalse($fresh->enabled);
        self::assertFalse($fresh->published);
        self::assertSame('2026-06-09T08:00:00+00:00', $fresh->created_at->format(DATE_ATOM));

        self::assertSame(['ai_review.fast_path', 'sdk.lazy_loader'], array_map(
            static fn (FeatureFlag $flag): string => $flag->flag_key,
            $freshRepository->all(),
        ));
    }

    public function testFindReturnsNullForMissingFlag(): void
    {
        self::assertNull((new DatabaseFeatureFlagRepository($this->createConnection()))->find('missing.flag'));
    }

    public function testPersistsFlagsWithoutTimeWindow(): void
    {
        $repository = new DatabaseFeatureFlagRepository($this->createConnection());
        $flag = new FeatureFlag(
            flag_key: 'global.rollout',
            environment: 'prod',
            enabled: true,
            targets: [],
            percentage_rollout: 100,
            time_window: null,
            published: true,
            created_at: new DateTimeImmutable('2026-06-09T08:00:00+00:00', new DateTimeZone('UTC')),
            updated_at: new DateTimeImmutable('2026-06-09T08:30:00+00:00', new DateTimeZone('UTC')),
        );

        $repository->save($flag);

        self::assertNull($repository->find('global.rollout')?->time_window);
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE feature_flags (
    flag_key VARCHAR(128) PRIMARY KEY,
    environment VARCHAR(32) NOT NULL,
    enabled INTEGER NOT NULL,
    targets_json TEXT NOT NULL,
    percentage_rollout INTEGER NOT NULL,
    time_window_json TEXT NULL,
    published INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
)
SQL);

        return $connection;
    }

    private function flag(
        string $flagKey,
        bool $enabled = true,
        bool $published = true,
        string $updatedAt = '2026-06-09T10:00:00+00:00',
    ): FeatureFlag {
        return new FeatureFlag(
            flag_key: $flagKey,
            environment: 'prod',
            enabled: $enabled,
            targets: [
                'organization_ids' => [101],
                'roles' => ['advertiser'],
                'site_ids' => [301],
                'slot_ids' => [401],
                'user_ids' => [501],
            ],
            percentage_rollout: 25,
            time_window: [
                'starts_at' => '2026-06-09T00:00:00+00:00',
                'ends_at' => '2026-06-30T23:59:59+00:00',
            ],
            published: $published,
            created_at: new DateTimeImmutable('2026-06-09T08:00:00+00:00', new DateTimeZone('UTC')),
            updated_at: new DateTimeImmutable($updatedAt, new DateTimeZone('UTC')),
        );
    }
}

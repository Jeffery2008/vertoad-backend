<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Repository\OAuthClientRepository;

final class OAuthClientRepositoryTest extends TestCase
{
    public function testStoreFindListAndRotateSecretWithoutExposingPlaintext(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        $repository = new OAuthClientRepository($connection);
        $stored = $repository->store(new OAuthClient(
            id: null,
            organizationId: 10,
            ownerUserId: 20,
            clientIdentifier: 'client_123',
            name: 'Developer App',
            secretHash: 'hash-v1',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['campaigns.manage'],
            isConfidential: true,
            revokedAt: null,
        ));

        self::assertNotNull($stored->id);
        self::assertSame('hash-v1', $connection->fetchOne('SELECT secret_hash FROM oauth_clients'));
        self::assertStringNotContainsString('plain-secret', json_encode($connection->fetchAssociative('SELECT * FROM oauth_clients'), JSON_THROW_ON_ERROR));

        $found = $repository->findActiveByIdentifier('client_123');
        self::assertNotNull($found);
        self::assertSame(['https://app.example.com/callback'], $found->redirectUris);
        self::assertSame(['authorization_code'], $found->grantTypes);
        self::assertSame(['campaigns.manage'], $found->scopes);

        self::assertSame(['client_123'], array_map(
            static fn (OAuthClient $client): string => $client->clientIdentifier,
            $repository->listActiveForOrganization(10),
        ));

        $repository->rotateSecret('client_123', 'hash-v2');
        self::assertSame('hash-v2', $connection->fetchOne('SELECT secret_hash FROM oauth_clients'));
    }

    public function testRevokedClientsAreNotReturnedAsActive(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new OAuthClientRepository($connection);
        $repository->store(new OAuthClient(
            id: null,
            organizationId: 10,
            ownerUserId: 20,
            clientIdentifier: 'client_123',
            name: 'Developer App',
            secretHash: 'hash-v1',
            redirectUris: ['https://app.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['campaigns.manage'],
            isConfidential: true,
            revokedAt: new DateTimeImmutable('2026-06-07 12:00:00'),
        ));

        self::assertNull($repository->findActiveByIdentifier('client_123'));
        self::assertSame([], $repository->listActiveForOrganization(10));
    }

    public function testBlankLookupsAndSecretRotationAreRejectedWithoutQuerySideEffects(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new OAuthClientRepository($connection);

        self::assertNull($repository->findActiveByIdentifier('   '));
        self::assertSame([], $repository->listActiveForOrganization(0));
        self::assertFalse($repository->rotateSecret(' ', 'hash-v2'));
        self::assertFalse($repository->rotateSecret('client_123', ' '));
    }

    public function testHydratesNullScopesAndFiltersNonStringJsonListValues(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new OAuthClientRepository($connection);
        $connection->insert('oauth_clients', [
            'organization_id' => 10,
            'owner_user_id' => null,
            'client_identifier' => 'client_123',
            'name' => 'Developer App',
            'secret_hash' => null,
            'redirect_uris_json' => json_encode(['https://app.example.com/callback', 99], JSON_THROW_ON_ERROR),
            'grant_types_json' => json_encode(['authorization_code', false], JSON_THROW_ON_ERROR),
            'scopes_json' => 'false',
            'is_confidential' => 0,
            'revoked_at' => null,
        ]);

        $client = $repository->findActiveByIdentifier('client_123');

        self::assertNotNull($client);
        self::assertSame(['https://app.example.com/callback'], $client->redirectUris);
        self::assertSame(['authorization_code'], $client->grantTypes);
        self::assertSame([], $client->scopes);
        self::assertFalse($client->isConfidential);
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE oauth_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NULL,
    owner_user_id INTEGER NULL,
    client_identifier TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    secret_hash TEXT NULL,
    redirect_uris_json TEXT NOT NULL,
    grant_types_json TEXT NOT NULL,
    scopes_json TEXT NULL,
    is_confidential INTEGER NOT NULL DEFAULT 1,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
    }
}

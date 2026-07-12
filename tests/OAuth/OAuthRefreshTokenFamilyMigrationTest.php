<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use PHPUnit\Framework\TestCase;

final class OAuthRefreshTokenFamilyMigrationTest extends TestCase
{
    public function testInitialMigrationDefinesAnIndexedStableRefreshTokenFamily(): void
    {
        $migration = $this->normalizedSource(
            dirname(__DIR__, 2) . '/db/migrations/20260607090000_add_auth_oauth_security_tables.php',
        );

        self::assertStringContainsString('family_identifier char(64) not null', $migration);
        self::assertStringContainsString(
            'key idx_oauth_refresh_tokens_family (family_identifier)',
            $migration,
        );
    }

    public function testRepositoryRevokesFamiliesByIdentifierWithoutRecursiveChainTraversal(): void
    {
        $repository = $this->normalizedSource(
            dirname(__DIR__, 2) . '/src/Repository/OAuthTokenRepository.php',
        );

        self::assertStringContainsString('where family_identifier = ? and client_id = ?', $repository);
        self::assertStringNotContainsString('with recursive', $repository);
    }

    private function normalizedSource(string $path): string
    {
        self::assertFileExists($path);

        return preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthTokenService;

final class OAuthTokenServiceTest extends TestCase
{
    public function testAuthorizationCodeWithPkceIssuesAccessAndRefreshTokens(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $verifier = 'pkce-verifier-123';
        $this->grantAllConsent($connection, $client, $now);

        $authorization = $service->authorize(
            userId: 7,
            organizationId: 99,
            clientId: $client->clientIdentifier,
            redirectUri: 'https://app.example.com/oauth/callback',
            scopes: ['report.read.own'],
            codeChallenge: $this->pkceChallenge($verifier),
            codeChallengeMethod: 'S256',
            now: $now,
        );

        self::assertStringStartsWith('voac_code', $authorization['code']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes WHERE revoked_at IS NULL'));

        $token = $service->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => $verifier,
        ], $now->modify('+10 seconds'));

        self::assertSame('Bearer', $token['token_type']);
        self::assertStringStartsWith('voat_', $token['access_token']);
        self::assertStringStartsWith('vort_refresh', $token['refresh_token']);
        self::assertSame('report.read.own', $token['scope']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes WHERE revoked_at IS NOT NULL'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens'));
    }

    public function testAuthorizationCodeRejectsMismatchedPkceVerifierAndSingleUseCode(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);
        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('right-verifier'), 'S256', $now);

        $this->expectExceptionMessage('OAuth PKCE verifier does not match');
        $service->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => 'wrong-verifier',
        ], $now->modify('+1 second'));
    }

    public function testAuthorizeRejectsMismatchedRedirectUriAndMissingS256Pkce(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);

        try {
            $service->authorize(7, 99, $client->clientIdentifier, 'https://evil.example.com/callback', [], $this->pkceChallenge('verifier'), 'S256', $now);
            self::fail('Expected mismatched redirect URI to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('redirect URI does not match', $exception->getMessage());
        }

        $this->expectExceptionMessage('requires S256 PKCE');
        $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], '', 'plain', $now);
    }

    public function testAuthorizeRequiresPriorConsentForRequestedScopes(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        try {
            $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', ['report.read.own'], $this->pkceChallenge('verifier'), 'S256', $now);
            self::fail('Expected authorization to require prior consent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('requires user consent', $exception->getMessage());
        }

        $consent = $service->consent(7, 99, $client->clientIdentifier, ['report.read.own'], $now);
        self::assertSame($client->clientIdentifier, $consent['client']['client_id']);
        self::assertSame(['report.read.own'], $consent['scopes']);

        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', ['report.read.own'], $this->pkceChallenge('verifier'), 'S256', $now);
        self::assertStringStartsWith('voac_', $authorization['code']);
    }

    public function testAuthorizationCodeExchangeRejectsMissingInvalidAndMismatchedRedirectRequests(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);

        try {
            $service->token(['grant_type' => 'authorization_code'], $now);
            self::fail('Expected missing authorization code request fields to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('verifier, and redirect URI are required', $exception->getMessage());
        }

        try {
            $service->token([
                'grant_type' => 'authorization_code',
                'code' => 'missing-code',
                'redirect_uri' => 'https://app.example.com/oauth/callback',
                'code_verifier' => 'verifier',
            ], $now);
            self::fail('Expected invalid authorization code to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('invalid, expired, or already used', $exception->getMessage());
        }

        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('verifier'), 'S256', $now);

        $this->expectExceptionMessage('redirect URI does not match the authorization request');
        $service->token([
            'grant_type' => 'authorization_code',
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/other',
            'code_verifier' => 'verifier',
        ], $now->modify('+1 second'));
    }

    public function testClientCredentialsRequiresSecretAndConstrainedScopes(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['client_credentials']);

        $token = $service->token([
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'plain-secret',
            'scope' => 'campaign.read.own report.read.own',
        ], new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertStringStartsWith('voat_', $token['access_token']);
        self::assertArrayNotHasKey('refresh_token', $token);
        self::assertSame('campaign.read.own report.read.own', $token['scope']);
        self::assertNull($connection->fetchOne('SELECT user_id FROM oauth_access_tokens'));

        $this->expectExceptionMessage('OAuth scope is not allowed');
        $service->token([
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'plain-secret',
            'scope' => 'admin.root',
        ], new DateTimeImmutable('2026-06-08 10:00:01'));
    }

    public function testRefreshTokenRotatesAndReuseIsRejected(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);
        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('verifier'), 'S256', $now);
        $first = $service->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => 'verifier',
        ], $now->modify('+1 second'));

        $second = $service->token([
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'plain-secret',
            'refresh_token' => $first['refresh_token'],
        ], $now->modify('+2 seconds'));

        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE rotated_at IS NOT NULL'));

        $this->expectExceptionMessage('already rotated');
        try {
            $service->token([
                'grant_type' => 'refresh_token',
                'client_id' => $client->clientIdentifier,
                'client_secret' => 'plain-secret',
                'refresh_token' => $first['refresh_token'],
            ], $now->modify('+3 seconds'));
        } finally {
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE reuse_detected_at IS NOT NULL'));
        }
    }

    public function testRefreshTokenRejectsMissingTokenAndWrongClient(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);
        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('verifier'), 'S256', $now);
        $token = $service->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => 'verifier',
        ], $now->modify('+1 second'));

        try {
            $service->token([
                'grant_type' => 'refresh_token',
                'client_id' => $client->clientIdentifier,
                'client_secret' => 'plain-secret',
            ], $now->modify('+2 seconds'));
            self::fail('Expected missing refresh token to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Refresh token is required', $exception->getMessage());
        }

        $otherClient = (new OAuthClientRepository($connection))->store(new OAuthClient(
            id: null,
            organizationId: 99,
            ownerUserId: 7,
            clientIdentifier: 'vocl_other_client',
            name: 'Other Client',
            secretHash: (new OAuthClientSecretHasher())->hash('plain-secret'),
            redirectUris: ['https://app.example.com/oauth/callback'],
            grantTypes: ['refresh_token'],
            scopes: ['campaign.read.own', 'report.read.own'],
            isConfidential: true,
            revokedAt: null,
        ));

        $this->expectExceptionMessage('does not belong to this client');
        $service->token([
            'grant_type' => 'refresh_token',
            'client_id' => $otherClient->clientIdentifier,
            'client_secret' => 'plain-secret',
            'refresh_token' => $token['refresh_token'],
        ], $now->modify('+3 seconds'));
    }

    public function testUnsupportedGrantUnauthorizedClientAndMissingPersistedClientIdAreRejected(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code']);

        try {
            $service->token(['grant_type' => 'password'], new DateTimeImmutable('2026-06-08 10:00:00'));
            self::fail('Expected unsupported grant to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('unsupported', $exception->getMessage());
        }

        try {
            $service->token([
                'grant_type' => 'client_credentials',
                'client_id' => $client->clientIdentifier,
                'client_secret' => 'plain-secret',
            ], new DateTimeImmutable('2026-06-08 10:00:00'));
            self::fail('Expected client without grant to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not authorized', $exception->getMessage());
        }

        $missingIdService = new OAuthTokenService(
            $this->clientRepositoryFor(new OAuthClient(
                id: null,
                organizationId: 99,
                ownerUserId: 7,
                clientIdentifier: 'vocl_missing_id',
                name: 'Missing ID Client',
                secretHash: null,
                redirectUris: ['https://app.example.com/oauth/callback'],
                grantTypes: ['authorization_code'],
                scopes: ['report.read.own'],
                isConfidential: false,
                revokedAt: null,
            )),
            new OAuthTokenRepository($connection),
            new OAuthConsentRepository($connection),
            new OAuthClientSecretHasher(),
        );

        $this->expectExceptionMessage('missing a persisted ID');
        $missingIdService->authorize(7, 99, 'vocl_missing_id', 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('verifier'), 'S256', new DateTimeImmutable('2026-06-08 10:00:00'));
    }

    public function testRevokePreventsBearerTokenAuthentication(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token']);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $this->grantAllConsent($connection, $client, $now);
        $authorization = $service->authorize(7, 99, $client->clientIdentifier, 'https://app.example.com/oauth/callback', [], $this->pkceChallenge('verifier'), 'S256', $now);
        $token = $service->token([
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorization['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => 'verifier',
        ], $now->modify('+1 second'));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me?organization_id=99')
            ->withHeader('Authorization', 'Bearer ' . $token['access_token']);
        $authenticator = new BearerTokenAuthenticator(new FirstPartySessionRepository($connection), new OAuthTokenRepository($connection));
        self::assertSame(7, RequestUserContext::fromRequest($authenticator->authenticate($request, $now->modify('+2 seconds')))->user?->id);

        self::assertSame(['revoked' => true], $service->revoke($token['access_token'], 'access_token', $now->modify('+3 seconds')));
        self::assertNull(RequestUserContext::fromRequest($authenticator->authenticate($request, $now->modify('+4 seconds')))->user);
    }

    public function testRevokeUnknownTokenReturnsFalseAndRandomTokenFactoryFallbackIssuesOpaqueTokens(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        self::assertSame(['revoked' => false], $service->revoke('missing-token', null, new DateTimeImmutable('2026-06-08 10:00:00')));

        $randomService = new OAuthTokenService(
            new OAuthClientRepository($connection),
            new OAuthTokenRepository($connection),
            new OAuthConsentRepository($connection),
            new OAuthClientSecretHasher(),
        );
        $client = $this->storeClient($connection, ['client_credentials']);

        $token = $randomService->token([
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'plain-secret',
        ], new DateTimeImmutable('2026-06-08 10:00:00'));

        self::assertMatchesRegularExpression('/^voat_[a-f0-9]{64}$/', $token['access_token']);
    }

    public function testRepositoryReturnsNullForMissingRowsAndDecodesNonListJsonAsEmptyScopes(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        self::assertNull($repository->consumeAuthorizationCode(hash('sha256', 'missing'), $now));
        self::assertNull($repository->findUsableRefreshToken(hash('sha256', 'missing-refresh'), $now));

        $connection->insert('oauth_clients', [
            'id' => 50,
            'organization_id' => 99,
            'owner_user_id' => 7,
            'client_identifier' => 'vocl_json_client',
            'name' => 'JSON Client',
            'secret_hash' => null,
            'redirect_uris_json' => '["https://app.example.com/oauth/callback"]',
            'grant_types_json' => '["authorization_code"]',
            'scopes_json' => 'true',
            'is_confidential' => 0,
            'revoked_at' => null,
        ]);
        $connection->insert('oauth_authorization_codes', [
            'client_id' => 50,
            'user_id' => 7,
            'organization_id' => 99,
            'code_identifier' => hash('sha256', 'json-code'),
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'scopes_json' => 'true',
            'code_challenge' => $this->pkceChallenge('verifier'),
            'code_challenge_method' => 'S256',
            'expires_at' => '2026-06-08 10:05:00',
            'revoked_at' => null,
        ]);

        $grant = $repository->consumeAuthorizationCode(hash('sha256', 'json-code'), $now);
        self::assertIsArray($grant);
        self::assertSame([], $grant['scopes']);
        self::assertSame([], $grant['client']->scopes);
        self::assertSame(['https://app.example.com/oauth/callback'], $grant['client']->redirectUris);
        self::assertSame(['authorization_code'], $grant['client']->grantTypes);
    }

    public function testConsentRepositoryUpdatesGlobalConsentAndRejectsMissingOrPartialScopes(): void
    {
        $connection = $this->createConnection();
        $client = $this->storeClient($connection, ['authorization_code']);
        $repository = new OAuthConsentRepository($connection);
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        self::assertFalse($repository->hasConsentFor($client, 7, null, ['report.read.own']));

        $repository->grantConsent($client, 7, null, ['report.read.own'], $now);
        self::assertTrue($repository->hasConsentFor($client, 7, null, ['report.read.own']));
        self::assertFalse($repository->hasConsentFor($client, 7, null, ['report.read.own', 'campaign.read.own']));

        $repository->grantConsent($client, 7, null, ['campaign.read.own'], $now->modify('+1 minute'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_user_consents WHERE client_id = ?', [$client->id]));
        self::assertFalse($repository->hasConsentFor($client, 7, null, ['report.read.own']));
        self::assertTrue($repository->hasConsentFor($client, 7, null, ['campaign.read.own']));

        $connection->executeStatement('UPDATE oauth_user_consents SET scopes_json = ? WHERE client_id = ?', ['true', $client->id]);
        self::assertFalse($repository->hasConsentFor($client, 7, null, ['campaign.read.own']));
    }

    private function createService(Connection $connection): OAuthTokenService
    {
        $tokens = ['code', 'access', 'refresh', 'access-2', 'refresh-2', 'access-3'];

        return new OAuthTokenService(
            new OAuthClientRepository($connection),
            new OAuthTokenRepository($connection),
            new OAuthConsentRepository($connection),
            new OAuthClientSecretHasher(),
            static function () use (&$tokens): string {
                return (string) array_shift($tokens);
            },
        );
    }

    private function clientRepositoryFor(OAuthClient $client): OAuthClientRepositoryInterface
    {
        return new class ($client) implements OAuthClientRepositoryInterface {
            public function __construct(private OAuthClient $client)
            {
            }

            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function store(OAuthClient $client): OAuthClient
            {
                return $client;
            }

            public function findActiveByIdentifier(string $clientIdentifier): ?OAuthClient
            {
                return $clientIdentifier === $this->client->clientIdentifier ? $this->client : null;
            }

            public function listActiveForOrganization(int $organizationId): array
            {
                return $organizationId === $this->client->organizationId ? [$this->client] : [];
            }

            public function rotateSecret(string $clientIdentifier, string $secretHash): bool
            {
                return false;
            }
        };
    }

    private function grantAllConsent(Connection $connection, OAuthClient $client, DateTimeImmutable $now): void
    {
        $connection->insert('oauth_user_consents', [
            'client_id' => $client->id,
            'user_id' => 7,
            'organization_id' => 99,
            'scopes_json' => json_encode($client->scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'granted_at' => $now->format('Y-m-d H:i:s'),
            'revoked_at' => null,
        ]);
    }

    /** @param list<string> $grantTypes */
    private function storeClient(Connection $connection, array $grantTypes): OAuthClient
    {
        $repository = new OAuthClientRepository($connection);

        return $repository->store(new OAuthClient(
            id: null,
            organizationId: 99,
            ownerUserId: 7,
            clientIdentifier: 'vocl_test_client',
            name: 'Test Client',
            secretHash: (new OAuthClientSecretHasher())->hash('plain-secret'),
            redirectUris: ['https://app.example.com/oauth/callback'],
            grantTypes: $grantTypes,
            scopes: ['campaign.read.own', 'report.read.own'],
            isConfidential: true,
            revokedAt: null,
        ));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE first_party_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, session_token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, revoked_at TEXT NULL, last_seen_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, owner_user_id INTEGER NULL, client_identifier TEXT NOT NULL UNIQUE, name TEXT NOT NULL, secret_hash TEXT NULL, redirect_uris_json TEXT NOT NULL, grant_types_json TEXT NOT NULL, scopes_json TEXT NULL, is_confidential INTEGER NOT NULL DEFAULT 1, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_authorization_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, code_identifier TEXT NOT NULL UNIQUE, redirect_uri TEXT NOT NULL, scopes_json TEXT NULL, code_challenge TEXT NULL, code_challenge_method TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NULL, organization_id INTEGER NULL, authorization_code_id INTEGER NULL, access_token_identifier TEXT NOT NULL UNIQUE, scopes_json TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_id INTEGER NOT NULL, client_id INTEGER NOT NULL, user_id INTEGER NULL, refresh_token_identifier TEXT NOT NULL UNIQUE, previous_refresh_token_id INTEGER NULL, rotated_to_refresh_token_id INTEGER NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, rotated_at TEXT NULL, reuse_detected_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_user_consents (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, scopes_json TEXT NOT NULL, granted_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->insert('users', ['id' => 7, 'email' => 'owner@example.com', 'password_hash' => 'unused', 'display_name' => 'Owner', 'status' => 'active']);

        return $connection;
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}

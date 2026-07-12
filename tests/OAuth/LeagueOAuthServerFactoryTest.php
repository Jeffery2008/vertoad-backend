<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Infrastructure\OAuth\LeagueOAuthAccessTokenEntity;
use VertoAD\Infrastructure\OAuth\LeagueOAuthAuthCodeEntity;
use VertoAD\Infrastructure\OAuth\LeagueOAuthClientEntity;
use VertoAD\Infrastructure\OAuth\LeagueOAuthRefreshTokenEntity;
use VertoAD\Infrastructure\OAuth\LeagueOAuthRepository;
use VertoAD\Infrastructure\OAuth\LeagueOAuthScopeEntity;
use VertoAD\Infrastructure\OAuth\LeagueOAuthServerFactory;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Service\OAuthClientSecretHasher;

final class LeagueOAuthServerFactoryTest extends TestCase
{
    public function testBuildsLeagueAuthorizationAndResourceServersFromCurrentRepositories(): void
    {
        $connection = $this->createConnection();
        $repository = $this->createRepository($connection);
        $settings = $this->createKeySettings();

        $factory = new LeagueOAuthServerFactory($repository, $settings + [
            'authorization_code_ttl_seconds' => 60,
            'access_token_ttl_seconds' => 120,
            'refresh_token_ttl_seconds' => 180,
        ]);

        self::assertInstanceOf(AuthorizationServer::class, $factory->authorizationServer());
        self::assertInstanceOf(ResourceServer::class, $factory->resourceServer());
    }

    public function testRejectsMissingLeagueOAuthSettings(): void
    {
        $this->expectExceptionMessage('OAuth setting private_key_path is required');

        (new LeagueOAuthServerFactory($this->createRepository($this->createConnection()), []))->authorizationServer();
    }

    public function testValidatesClientsAndFinalizesScopesAgainstDomainClient(): void
    {
        $connection = $this->createConnection();
        $repository = $this->createRepository($connection);
        $client = $this->storeClient($connection, true);

        $entity = $repository->getClientEntity($client->clientIdentifier);
        self::assertInstanceOf(LeagueOAuthClientEntity::class, $entity);
        self::assertSame($client->clientIdentifier, $entity->getIdentifier());
        self::assertSame('Test Client', $entity->getName());
        self::assertSame(['https://app.example.com/oauth/callback'], $entity->getRedirectUri());
        self::assertTrue($entity->isConfidential());
        self::assertSame($client->id, $entity->domainClient()->id);
        self::assertSame($client->clientIdentifier, $entity->domainClient()->clientIdentifier);

        self::assertTrue($repository->validateClient($client->clientIdentifier, 'plain-secret', 'client_credentials'));
        self::assertFalse($repository->validateClient($client->clientIdentifier, 'bad-secret', 'client_credentials'));
        self::assertFalse($repository->validateClient($client->clientIdentifier, 'plain-secret', 'password'));
        self::assertNull($repository->getClientEntity('missing'));

        $scope = $repository->getScopeEntityByIdentifier('report.read.own');
        self::assertInstanceOf(LeagueOAuthScopeEntity::class, $scope);
        self::assertSame('"report.read.own"', json_encode($scope, JSON_THROW_ON_ERROR));
        self::assertNull($repository->getScopeEntityByIdentifier(''));

        self::assertSame(
            ['campaign.read.own', 'report.read.own'],
            $this->scopeIds($repository->finalizeScopes([], 'client_credentials', $entity))
        );
        self::assertSame(
            ['report.read.own'],
            $this->scopeIds($repository->finalizeScopes([$scope], 'client_credentials', $entity))
        );
        self::assertSame([], $repository->finalizeScopes([new LeagueOAuthScopeEntity('admin.root')], 'client_credentials', $entity));
        self::assertSame([], $repository->finalizeScopes([], 'client_credentials', $this->foreignClient()));

        $unsafeClient = $this->storeClient(
            $connection,
            true,
            'vocl_unsafe_client',
            ['organizations.members.manage'],
        );
        $unsafeEntity = new LeagueOAuthClientEntity($unsafeClient);
        self::assertSame([], $repository->finalizeScopes([], 'client_credentials', $unsafeEntity));
        self::assertSame(
            ['organizations.members.manage'],
            $this->scopeIds($repository->finalizeScopes([], 'authorization_code', $unsafeEntity)),
        );

        $publicClient = $this->storeClient($connection, false, 'vocl_public_client');
        self::assertTrue($repository->validateClient($publicClient->clientIdentifier, null, 'authorization_code'));
        self::assertFalse($repository->validateClient($publicClient->clientIdentifier, null, 'client_credentials'));
    }

    public function testRealClientCredentialsGrantRequiresConfidentialClientValidSecretAndAllowedScope(): void
    {
        $connection = $this->createConnection();
        $repository = $this->createRepository($connection);
        $server = (new LeagueOAuthServerFactory($repository, $this->createKeySettings()))->authorizationServer();
        $confidentialClient = $this->storeClient($connection, true);
        $publicClient = $this->storeClient($connection, false, 'vocl_public_grant_client');
        $requests = new ServerRequestFactory();
        $responses = new ResponseFactory();

        try {
            $server->respondToAccessTokenRequest(
                $requests->createServerRequest('POST', '/api/v1/oauth/token')->withParsedBody([
                    'grant_type' => 'client_credentials',
                    'client_id' => $publicClient->clientIdentifier,
                    'scope' => 'report.read.own',
                ]),
                $responses->createResponse(),
            );
            self::fail('Expected the real League grant to reject a public client.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
        }

        try {
            $server->respondToAccessTokenRequest(
                $requests->createServerRequest('POST', '/api/v1/oauth/token')->withParsedBody([
                    'grant_type' => 'client_credentials',
                    'client_id' => $confidentialClient->clientIdentifier,
                    'client_secret' => 'wrong-secret',
                    'scope' => 'report.read.own',
                ]),
                $responses->createResponse(),
            );
            self::fail('Expected the real League grant to reject an invalid client secret.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
        }

        $response = $server->respondToAccessTokenRequest(
            $requests->createServerRequest('POST', '/api/v1/oauth/token')->withParsedBody([
                'grant_type' => 'client_credentials',
                'client_id' => $confidentialClient->clientIdentifier,
                'client_secret' => 'plain-secret',
                'scope' => 'report.read.own',
            ]),
            $responses->createResponse(),
        );
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Bearer', $payload['token_type']);
        self::assertIsString($payload['access_token']);
        self::assertNotSame('', $payload['access_token']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));
        self::assertSame(
            ['organization_id' => 99, 'user_id' => null, 'scopes_json' => '["report.read.own"]'],
            $connection->fetchAssociative(
                'SELECT organization_id, user_id, scopes_json FROM oauth_access_tokens ORDER BY id DESC LIMIT 1',
            ),
        );
    }

    public function testPersistsAndRevokesLeagueAccessAuthCodeAndRefreshEntities(): void
    {
        $connection = $this->createConnection();
        $repository = $this->createRepository($connection);
        $client = new LeagueOAuthClientEntity($this->storeClient($connection, true));
        $scope = new LeagueOAuthScopeEntity('report.read.own');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $accessToken = $repository->getNewToken($client, [$scope], 7);
        $accessToken->setIdentifier('league-access');
        $accessToken->setExpiryDateTime($expiresAt);
        $repository->persistNewAccessToken($accessToken);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));
        self::assertFalse($repository->isAccessTokenRevoked('league-access'));
        $repository->revokeAccessToken('league-access');
        self::assertTrue($repository->isAccessTokenRevoked('league-access'));

        $authCode = $repository->getNewAuthCode();
        $authCode->setIdentifier('league-code');
        $authCode->setClient($client);
        $authCode->setUserIdentifier(7);
        $authCode->setRedirectUri('https://app.example.com/oauth/callback');
        $authCode->addScope($scope);
        $authCode->setExpiryDateTime($expiresAt);
        $repository->persistNewAuthCode($authCode);
        self::assertFalse($repository->isAuthCodeRevoked('league-code'));
        $repository->revokeAuthCode('league-code');
        self::assertTrue($repository->isAuthCodeRevoked('league-code'));

        $refreshAccessToken = new LeagueOAuthAccessTokenEntity();
        $refreshAccessToken->setIdentifier('refresh-access');
        $refreshAccessToken->setClient($client);
        $refreshAccessToken->setUserIdentifier(7);
        $refreshAccessToken->addScope($scope);
        $refreshAccessToken->setExpiryDateTime($expiresAt);
        $repository->persistNewAccessToken($refreshAccessToken);

        $refreshToken = $repository->getNewRefreshToken();
        self::assertInstanceOf(LeagueOAuthRefreshTokenEntity::class, $refreshToken);
        $refreshToken->setIdentifier('league-refresh');
        $refreshToken->setAccessToken($refreshAccessToken);
        $refreshToken->setExpiryDateTime($expiresAt);
        $repository->persistNewRefreshToken($refreshToken);
        self::assertFalse($repository->isRefreshTokenRevoked('league-refresh'));
        $repository->revokeRefreshToken('league-refresh');
        self::assertTrue($repository->isRefreshTokenRevoked('league-refresh'));
    }

    public function testRejectsForeignLeagueEntitiesWhenPersistingTokens(): void
    {
        $repository = $this->createRepository($this->createConnection());
        $foreignClient = $this->foreignClient();
        $accessToken = $repository->getNewToken($foreignClient, [], null);
        $accessToken->setIdentifier('foreign-access');
        $accessToken->setExpiryDateTime(new DateTimeImmutable('+1 hour'));

        try {
            $repository->persistNewAccessToken($accessToken);
            self::fail('Expected foreign access token client to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('access token requires a VertoAD client entity', $exception->getMessage());
        }

        $authCode = new LeagueOAuthAuthCodeEntity();
        $authCode->setIdentifier('foreign-code');
        $authCode->setClient($foreignClient);
        $authCode->setUserIdentifier(7);
        $authCode->setRedirectUri('https://app.example.com/oauth/callback');
        $authCode->setExpiryDateTime(new DateTimeImmutable('+1 hour'));

        try {
            $repository->persistNewAuthCode($authCode);
            self::fail('Expected foreign auth code client to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('authorization code requires a VertoAD client entity', $exception->getMessage());
        }

        $refreshToken = new LeagueOAuthRefreshTokenEntity();
        $refreshToken->setIdentifier('foreign-refresh');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime(new DateTimeImmutable('+1 hour'));

        try {
            $repository->persistNewRefreshToken($refreshToken);
            self::fail('Expected foreign refresh token client to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('refresh token requires a VertoAD client entity', $exception->getMessage());
        }

        $vertoAccessToken = new LeagueOAuthAccessTokenEntity();
        $vertoAccessToken->setIdentifier('unpersisted-access');
        $vertoAccessToken->setClient(new LeagueOAuthClientEntity($this->storeClient($this->createConnection(), true)));
        $vertoAccessToken->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
        $refreshToken->setAccessToken($vertoAccessToken);

        $this->expectExceptionMessage('refresh token requires a persisted access token');
        $repository->persistNewRefreshToken($refreshToken);
    }

    private function createRepository(Connection $connection): LeagueOAuthRepository
    {
        return new LeagueOAuthRepository(
            new OAuthClientRepository($connection),
            new OAuthTokenRepository($connection),
            new OAuthClientSecretHasher(),
        );
    }

    /** @param list<string> $scopes */
    private function storeClient(
        Connection $connection,
        bool $confidential,
        string $identifier = 'vocl_test_client',
        array $scopes = ['campaign.read.own', 'report.read.own'],
    ): OAuthClient
    {
        return (new OAuthClientRepository($connection))->store(new OAuthClient(
            id: null,
            organizationId: 99,
            ownerUserId: 7,
            clientIdentifier: $identifier,
            name: 'Test Client',
            secretHash: $confidential ? (new OAuthClientSecretHasher())->hash('plain-secret') : null,
            redirectUris: ['https://app.example.com/oauth/callback'],
            grantTypes: $confidential
                ? ['authorization_code', 'client_credentials', 'refresh_token']
                : ['authorization_code', 'refresh_token'],
            scopes: $scopes,
            isConfidential: $confidential,
            revokedAt: null,
        ));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, owner_user_id INTEGER NULL, client_identifier TEXT NOT NULL UNIQUE, name TEXT NOT NULL, secret_hash TEXT NULL, redirect_uris_json TEXT NOT NULL, grant_types_json TEXT NOT NULL, scopes_json TEXT NULL, is_confidential INTEGER NOT NULL DEFAULT 1, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_authorization_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, code_identifier TEXT NOT NULL UNIQUE, redirect_uri TEXT NOT NULL, scopes_json TEXT NULL, code_challenge TEXT NULL, code_challenge_method TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NULL, organization_id INTEGER NULL, authorization_code_id INTEGER NULL, access_token_identifier TEXT NOT NULL UNIQUE, scopes_json TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_id INTEGER NOT NULL, client_id INTEGER NOT NULL, user_id INTEGER NULL, refresh_token_identifier TEXT NOT NULL UNIQUE, family_identifier TEXT NOT NULL, previous_refresh_token_id INTEGER NULL, rotated_to_refresh_token_id INTEGER NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, rotated_at TEXT NULL, reuse_detected_at TEXT NULL)');

        return $connection;
    }

    /** @return array<string, string> */
    private function createKeySettings(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-oauth-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $privateKeyPath = $directory . DIRECTORY_SEPARATOR . 'private.key';
        $publicKeyPath = $directory . DIRECTORY_SEPARATOR . 'public.key';
        file_put_contents($privateKeyPath, self::TEST_PRIVATE_KEY);
        file_put_contents($publicKeyPath, self::TEST_PUBLIC_KEY);

        return [
            'private_key_path' => $privateKeyPath,
            'public_key_path' => $publicKeyPath,
            'encryption_key' => '0123456789abcdef0123456789abcdef',
        ];
    }

    /** @param list<ScopeEntityInterface> $scopes */
    private function scopeIds(array $scopes): array
    {
        return array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);
    }

    private function foreignClient(): ClientEntityInterface
    {
        return new class implements ClientEntityInterface {
            public function getIdentifier(): string
            {
                return 'foreign';
            }

            public function getName(): string
            {
                return 'Foreign';
            }

            public function getRedirectUri(): string
            {
                return 'https://foreign.example.com/callback';
            }

            public function isConfidential(): bool
            {
                return true;
            }
        };
    }

    private const TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAt+W432cQ7nvRIZHlsw5xhn/g1IzAI6LYP71qVWev8+c4ogKB
sHVwA1lQwueIqmNJeSCfD3qDqGORDGjHv6Kd/6v98FWFD9oA/RDMp6HYBe09ktdK
tluPyP/+WSnwGYav0eEUBSYswfHUlmI3WDzJ4m9cdK1EQamORxHHnnJ+0vb0R+cD
aebMBSWXWAnUNfyUP+Cto/WuUXfo0qtRKNMzJG982tBXXbS7ZY0hA3iVQhn/DRrs
l7MQRwgowHR/77Ph+jf/GpNNBAOCvKfUrw7jDhpD6ecFgMcJtMan2BRn9dGs7G4a
XENost2/AnHyz2K8NRMJqwFSWQTMSssdt08+gwIDAQABAoIBAARs0bMGfuDON+0P
3rAdU9wBrb5PmLwCyiNWgn2FnjVHRhSX7Nj7KnPaLVhTS/WVqAnzIAC2WP6vTqk2
yD+zQQwK7nRfCnGkNEvioJoUCeeymr2y0ohq0Z3rkwpAORfUJtztBpdNINyV3iC0
QlKsO8toFJh2JuNRmivZoK0OYkDfR++z1yImWCPI2oo5JnHRSn+Biwa3kT8iL/sf
PGT71sKi1XVqPlnwU2gdFnYnwBRiJAztUbVsMA48gX6Vw9IG66AdFeEcZ7bFmq7t
zUtMa+igw4HAlYI4L2/2RbACwJGUcDuGidlOEUtrqLrZsKutvex9aDDRl53o1YLp
EDdOMcECgYEA6M/bny9nhQa+mTSPaXY4VT8/fyj8WNCBhjZEZ8jn4mTlyVIm3Zks
3PaVnXKvP+lT6EsYBw0LOE4uKtmwxjqg8YeTnebcgz6K3KZVWUWB8MVdjdKvPhKt
X0d+ouYEVc+Q58ORlAaIAJYgA2c1ivDUH9MVvQdjQe9aOzTA3lYxW+ECgYEAyjap
C0V1U/WjKnFy11sAGI/2pRqdImrQivtU+JkvX+4xie+hPA0lcEzL6kiDfeiQxeec
2RV3txyi15NyAX4VwV2V7uMmgIe7nl74Bq2dTvfyvxj9Il5nUR6YwYf80kxmdkV4
JE+TszGHAIP0Pr02NCCD7Pzvpa2cYBpUadnThuMCgYAEXamDsbLiRr8aRmcOFj52
MspxCwa4b6iOKMRdoeHfV/8LKHQ8IZw6xJEHs9ffffOp3oaj0zXLp4OsIAr1nLHZ
9a3p/yNRfsHB44ikNO574mefujy5EEaaC9AvI9se9NaF0iAPw5OXVzlgdvYFFgEU
W7QDqHjPCrsJczLOuJUSYQKBgQDESUrOJh494bMBAB757Nuq/BPvMGZXglfskQtq
RUg3Vn+/5VwdbqVo3SMTyE/baGUftjQKhUwv8xwfJoED8eAsUyu3N8en/BmjIYyg
7uZEQWrhFOCi/ABOPeUJ93byrDbJl6WHmbdFuk3RskTkocZ70xQ8d0opCN1CbEyE
c21hAQKBgQC9qF02A73hvfpyJg5gomW3Phl+SeeZIDTLTZE8aFmwvI9RrZSrpDPk
XTBxOXndLB8JYBKsm6i0HDdz35ZGzJo9S4eEoCcREIPQluBW2lY5XUhEVPdTIN3c
029gr4D60inN7Me+Wn/CEg6bNqfpYKCH5HDTX1rB0suLjdhGketMNw==
-----END RSA PRIVATE KEY-----
PEM;

    private const TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAt+W432cQ7nvRIZHlsw5x
hn/g1IzAI6LYP71qVWev8+c4ogKBsHVwA1lQwueIqmNJeSCfD3qDqGORDGjHv6Kd
/6v98FWFD9oA/RDMp6HYBe09ktdKtluPyP/+WSnwGYav0eEUBSYswfHUlmI3WDzJ
4m9cdK1EQamORxHHnnJ+0vb0R+cDaebMBSWXWAnUNfyUP+Cto/WuUXfo0qtRKNMz
JG982tBXXbS7ZY0hA3iVQhn/DRrsl7MQRwgowHR/77Ph+jf/GpNNBAOCvKfUrw7j
DhpD6ecFgMcJtMan2BRn9dGs7G4aXENost2/AnHyz2K8NRMJqwFSWQTMSssdt08+
gwIDAQAB
-----END PUBLIC KEY-----
PEM;
}

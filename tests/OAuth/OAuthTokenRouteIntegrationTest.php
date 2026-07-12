<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\OAuth\AuthorizeAction;
use VertoAD\Http\Action\OAuth\ConsentAction;
use VertoAD\Http\Action\OAuth\RevokeAction;
use VertoAD\Http\Action\OAuth\TokenAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthTokenService;

final class OAuthTokenRouteIntegrationTest extends TestCase
{
    public function testPkceTokenExchangeAndRevokeRoutesUseEnvelope(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient($connection, ['authorization_code', 'refresh_token'], confidential: false);
        $verifier = 'route-verifier';

        $consent = $this->handleJson($app, 'POST', '/api/v1/oauth/consent?organization_id=99', [
            'client_id' => $client->clientIdentifier,
            'scope' => 'report.read.own',
        ], 'fixed-session');
        self::assertSame(200, $consent['status']);
        self::assertSame(['report.read.own'], $consent['body']['data']['scopes']);

        $authorize = $this->handleJson(
            $app,
            'GET',
            '/api/v1/oauth/authorize?organization_id=99&client_id=' . $client->clientIdentifier . '&redirect_uri=https%3A%2F%2Fapp.example.com%2Foauth%2Fcallback&scope=report.read.own&code_challenge=' . $this->pkceChallenge($verifier) . '&code_challenge_method=S256',
            null,
            'fixed-session',
        );

        self::assertSame(200, $authorize['status']);
        self::assertNull($authorize['body']['error']);
        self::assertStringStartsWith('voac_', $authorize['body']['data']['code']);

        $token = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorize['body']['data']['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => $verifier,
        ]);

        self::assertSame(200, $token['status']);
        self::assertNull($token['body']['error']);
        self::assertSame('Bearer', $token['body']['data']['token_type']);

        $me = $this->handleJson($app, 'GET', '/api/v1/auth/me?organization_id=99', null, $token['body']['data']['access_token']);
        self::assertSame(200, $me['status']);
        self::assertSame(7, $me['body']['data']['user']['id']);

        $revoked = $this->handleJson($app, 'POST', '/api/v1/oauth/revoke', [
            'token' => $token['body']['data']['access_token'],
            'token_type_hint' => 'access_token',
        ]);
        self::assertSame(200, $revoked['status']);
        self::assertTrue($revoked['body']['data']['revoked']);

        $afterRevoke = $this->handleJson($app, 'GET', '/api/v1/auth/me?organization_id=99', null, $token['body']['data']['access_token']);
        self::assertSame(401, $afterRevoke['status']);
        self::assertSame('authentication_required', $afterRevoke['body']['error']['code']);
    }

    public function testClientCredentialsRouteRejectsBadSecretAndIssuesScopedToken(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient($connection, ['client_credentials']);

        $badSecret = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'wrong',
            'scope' => 'report.read.own',
        ]);
        self::assertSame(400, $badSecret['status']);
        self::assertSame('invalid_grant', $badSecret['body']['error']['code']);

        $token = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'client_secret' => 'plain-secret',
            'scope' => 'report.read.own',
        ]);
        self::assertSame(200, $token['status']);
        self::assertSame('report.read.own', $token['body']['data']['scope']);
        self::assertArrayNotHasKey('refresh_token', $token['body']['data']);
    }

    public function testPublicClientCannotUseClientCredentialsWithoutSecret(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient(
            $connection,
            ['authorization_code', 'refresh_token'],
            confidential: false,
            identifier: 'vocl_public_route_client',
        );

        $response = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->clientIdentifier,
            'scope' => 'report.read.own',
        ]);

        self::assertSame(400, $response['status']);
        self::assertSame('invalid_grant', $response['body']['error']['code']);
        self::assertStringContainsString('not authorized for this grant', $response['body']['error']['message']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));

        $connection->insert('oauth_clients', [
            'organization_id' => 99,
            'owner_user_id' => 7,
            'client_identifier' => 'vocl_legacy_public_client',
            'name' => 'Legacy Public Client',
            'secret_hash' => null,
            'redirect_uris_json' => json_encode(['https://app.example.com/oauth/callback'], JSON_THROW_ON_ERROR),
            'grant_types_json' => json_encode(['client_credentials'], JSON_THROW_ON_ERROR),
            'scopes_json' => json_encode(['report.read.own'], JSON_THROW_ON_ERROR),
            'is_confidential' => 0,
            'revoked_at' => null,
        ]);
        $legacyResponse = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'vocl_legacy_public_client',
            'scope' => 'report.read.own',
        ]);
        self::assertSame(400, $legacyResponse['status']);
        self::assertSame('invalid_grant', $legacyResponse['body']['error']['code']);
        self::assertStringContainsString('Public OAuth clients cannot use the client_credentials grant', $legacyResponse['body']['error']['message']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));
    }

    public function testAuthorizeRouteRejectsUnauthenticatedAndInvalidOAuthRequests(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient($connection, ['authorization_code']);

        $unauthenticated = $this->handleJson(
            $app,
            'GET',
            '/api/v1/oauth/authorize?client_id=' . $client->clientIdentifier . '&redirect_uri=https%3A%2F%2Fapp.example.com%2Foauth%2Fcallback',
            null,
        );
        self::assertSame(401, $unauthenticated['status']);
        self::assertSame('authentication_required', $unauthenticated['body']['error']['code']);

        $invalid = $this->handleJson(
            $app,
            'GET',
            '/api/v1/oauth/authorize?organization_id=99&client_id=' . $client->clientIdentifier . '&redirect_uri=https%3A%2F%2Fevil.example.com%2Fcallback&code_challenge=bad&code_challenge_method=S256',
            null,
            'fixed-session',
        );
        self::assertSame(400, $invalid['status']);
        self::assertSame('invalid_oauth_request', $invalid['body']['error']['code']);
    }

    public function testConsentRouteRejectsUnauthenticatedAndInvalidBodies(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient($connection, ['authorization_code']);

        $unauthenticated = $this->handleJson($app, 'POST', '/api/v1/oauth/consent?organization_id=99', [
            'client_id' => $client->clientIdentifier,
            'scope' => 'report.read.own',
        ]);
        self::assertSame(401, $unauthenticated['status']);
        self::assertSame('authentication_required', $unauthenticated['body']['error']['code']);

        $invalidBody = $this->handleJson($app, 'POST', '/api/v1/oauth/consent?organization_id=99', null, 'fixed-session');
        self::assertSame(400, $invalidBody['status']);
        self::assertSame('invalid_request', $invalidBody['body']['error']['code']);

        $invalidClient = $this->handleJson($app, 'POST', '/api/v1/oauth/consent?organization_id=99', [
            'client_id' => 'missing',
            'scope' => 'report.read.own',
        ], 'fixed-session');
        self::assertSame(400, $invalidClient['status']);
        self::assertSame('invalid_oauth_request', $invalidClient['body']['error']['code']);
    }

    public function testTokenAndRevokeRoutesRejectInvalidRequestBodies(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $token = $this->handleJson($app, 'POST', '/api/v1/oauth/token', null);
        self::assertSame(400, $token['status']);
        self::assertSame('invalid_request', $token['body']['error']['code']);

        $revoke = $this->handleJson($app, 'POST', '/api/v1/oauth/revoke', ['token' => '']);
        self::assertSame(400, $revoke['status']);
        self::assertSame('invalid_request', $revoke['body']['error']['code']);
    }

    public function testRefreshReplayRevokesLatestRefreshAndAccessTokensThroughRoutes(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient(
            $connection,
            ['authorization_code', 'refresh_token'],
            confidential: false,
        );
        $first = $this->issueUserTokenSet($app, $client, 'family-route-verifier');
        $secondResponse = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertSame(200, $secondResponse['status']);
        $second = $secondResponse['body']['data'];
        self::assertSame(
            200,
            $this->handleJson(
                $app,
                'GET',
                '/api/v1/auth/me?organization_id=99',
                null,
                $second['access_token'],
            )['status'],
        );

        $replay = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertSame(400, $replay['status']);
        self::assertSame('invalid_grant', $replay['body']['error']['code']);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE revoked_at IS NOT NULL'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked_at IS NOT NULL'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE reuse_detected_at IS NOT NULL'));

        $latestAccess = $this->handleJson(
            $app,
            'GET',
            '/api/v1/auth/me?organization_id=99',
            null,
            $second['access_token'],
        );
        self::assertSame(401, $latestAccess['status']);
        self::assertSame('authentication_required', $latestAccess['body']['error']['code']);

        $latestRefresh = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'refresh_token' => $second['refresh_token'],
        ]);
        self::assertSame(400, $latestRefresh['status']);
        self::assertSame('invalid_grant', $latestRefresh['body']['error']['code']);
    }

    public function testRandomInvalidRefreshTokenDoesNotRevokeAValidFamily(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $client = $this->storeClient(
            $connection,
            ['authorization_code', 'refresh_token'],
            confidential: false,
        );
        $first = $this->issueUserTokenSet($app, $client, 'random-route-verifier');

        $invalid = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'refresh_token' => 'vort_random-invalid-token',
        ]);
        self::assertSame(400, $invalid['status']);
        self::assertSame('invalid_grant', $invalid['body']['error']['code']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE reuse_detected_at IS NOT NULL'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE revoked_at IS NOT NULL'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked_at IS NOT NULL'));

        $validRotation = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->clientIdentifier,
            'refresh_token' => $first['refresh_token'],
        ]);
        self::assertSame(200, $validRotation['status']);
        self::assertStringStartsWith('voat_', $validRotation['body']['data']['access_token']);
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $tokens = [
            'code',
            'access',
            'refresh',
            'access-2',
            'refresh-2',
            'code-2',
            'access-3',
            'refresh-3',
        ];
        $container = (new ContainerBuilder())->addDefinitions([
            Connection::class => $connection,
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new FirstPartySessionRepository($connection),
            OAuthTokenRepositoryInterface::class => static fn (): OAuthTokenRepositoryInterface =>
                new OAuthTokenRepository($connection),
            OAuthConsentRepositoryInterface::class => static fn (): OAuthConsentRepositoryInterface =>
                new OAuthConsentRepository($connection),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
                OAuthTokenRepositoryInterface $oauthTokens,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions, $oauthTokens),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new OrganizationMembershipRepository($connection),
            OAuthClientRepositoryInterface::class => static fn (): OAuthClientRepositoryInterface =>
                new OAuthClientRepository($connection),
            OAuthClientSecretHasher::class => static fn (): OAuthClientSecretHasher => new OAuthClientSecretHasher(),
            OAuthTokenService::class => static fn (
                OAuthClientRepositoryInterface $clients,
                OAuthTokenRepositoryInterface $tokenRepository,
                OAuthConsentRepositoryInterface $consents,
                OAuthClientSecretHasher $secrets,
            ): OAuthTokenService => new OAuthTokenService(
                $clients,
                $tokenRepository,
                $consents,
                $secrets,
                static function () use (&$tokens): string {
                    return (string) array_shift($tokens);
                },
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/oauth/authorize', AuthorizeAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/consent', ConsentAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/token', TokenAction::class);
        $app->post('/api/v1/oauth/revoke', RevokeAction::class);
        $app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /** @param array<string, mixed>|null $payload */
    private function handleJson(\Slim\App $app, string $method, string $uri, ?array $payload, ?string $token = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    /** @return array<string, mixed> */
    private function issueUserTokenSet(\Slim\App $app, OAuthClient $client, string $verifier): array
    {
        $consent = $this->handleJson($app, 'POST', '/api/v1/oauth/consent?organization_id=99', [
            'client_id' => $client->clientIdentifier,
            'scope' => 'report.read.own',
        ], 'fixed-session');
        self::assertSame(200, $consent['status']);

        $authorize = $this->handleJson(
            $app,
            'GET',
            '/api/v1/oauth/authorize?organization_id=99&client_id=' . $client->clientIdentifier
                . '&redirect_uri=https%3A%2F%2Fapp.example.com%2Foauth%2Fcallback'
                . '&scope=report.read.own&code_challenge=' . $this->pkceChallenge($verifier)
                . '&code_challenge_method=S256',
            null,
            'fixed-session',
        );
        self::assertSame(200, $authorize['status']);

        $token = $this->handleJson($app, 'POST', '/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->clientIdentifier,
            'code' => $authorize['body']['data']['code'],
            'redirect_uri' => 'https://app.example.com/oauth/callback',
            'code_verifier' => $verifier,
        ]);
        self::assertSame(200, $token['status']);
        self::assertIsArray($token['body']['data']);

        return $token['body']['data'];
    }

    /** @param list<string> $grantTypes */
    private function storeClient(
        Connection $connection,
        array $grantTypes,
        bool $confidential = true,
        string $identifier = 'vocl_route_client',
    ): OAuthClient
    {
        return (new OAuthClientRepository($connection))->store(new OAuthClient(
            id: null,
            organizationId: 99,
            ownerUserId: 7,
            clientIdentifier: $identifier,
            name: 'Route Client',
            secretHash: $confidential ? (new OAuthClientSecretHasher())->hash('plain-secret') : null,
            redirectUris: ['https://app.example.com/oauth/callback'],
            grantTypes: $grantTypes,
            scopes: ['campaign.read.own', 'report.read.own'],
            isConfidential: $confidential,
            revokedAt: null,
        ));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE first_party_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, session_token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, revoked_at TEXT NULL, last_seen_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL, billing_status TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE organization_members (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL, title TEXT NULL)');
        $connection->executeStatement('CREATE TABLE roles (id INTEGER PRIMARY KEY, organization_id INTEGER NULL, slug TEXT NOT NULL, name TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE permissions (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, description TEXT NULL)');
        $connection->executeStatement('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)');
        $connection->executeStatement('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, owner_user_id INTEGER NULL, client_identifier TEXT NOT NULL UNIQUE, name TEXT NOT NULL, secret_hash TEXT NULL, redirect_uris_json TEXT NOT NULL, grant_types_json TEXT NOT NULL, scopes_json TEXT NULL, is_confidential INTEGER NOT NULL DEFAULT 1, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_authorization_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, code_identifier TEXT NOT NULL UNIQUE, redirect_uri TEXT NOT NULL, scopes_json TEXT NULL, code_challenge TEXT NULL, code_challenge_method TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NULL, organization_id INTEGER NULL, authorization_code_id INTEGER NULL, access_token_identifier TEXT NOT NULL UNIQUE, scopes_json TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_id INTEGER NOT NULL, client_id INTEGER NOT NULL, user_id INTEGER NULL, refresh_token_identifier TEXT NOT NULL UNIQUE, family_identifier TEXT NOT NULL, previous_refresh_token_id INTEGER NULL, rotated_to_refresh_token_id INTEGER NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, rotated_at TEXT NULL, reuse_detected_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_user_consents (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, scopes_json TEXT NOT NULL, granted_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->insert('users', ['id' => 7, 'email' => 'owner@example.com', 'password_hash' => 'unused', 'display_name' => 'Owner', 'status' => 'active']);
        $connection->insert('first_party_sessions', ['user_id' => 7, 'session_token_hash' => hash('sha256', 'fixed-session'), 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null, 'last_seen_at' => null]);
        $connection->insert('organizations', ['id' => 99, 'name' => 'OAuth Advertiser', 'slug' => 'oauth-advertiser', 'billing_status' => 'active']);
        $connection->insert('organization_members', ['id' => 1, 'organization_id' => 99, 'user_id' => 7, 'status' => 'active', 'title' => null]);

        return $connection;
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Action\Auth\LoginAction;
use VertoAD\Http\Action\Auth\LogoutAction;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\Auth\PasswordResetConfirmAction;
use VertoAD\Http\Action\Auth\PasswordResetRequestAction;
use VertoAD\Http\Action\Auth\RegisterAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Service\AuthService;
use VertoAD\Service\PasswordHasher;

final class AuthActionTest extends TestCase
{
    public function testRegisterActionReturnsCreatedUserWithoutPasswordHash(): void
    {
        $action = new RegisterAction($this->createService($this->createConnection()));
        $response = $action(
            $this->jsonRequest('/api/v1/auth/register', [
                'email' => 'Owner@Example.COM',
                'password' => 'correct horse battery staple',
                'display_name' => 'Owner',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $payload = $this->payload($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('owner@example.com', $payload['email']);
        self::assertArrayNotHasKey('password_hash', $payload);
    }

    public function testLoginActionReturnsTokenAndMapsInvalidCredentialsToUnauthorized(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection, static fn (): string => 'fixed-token');
        $service->register('owner@example.com', 'correct horse battery staple', 'Owner');
        $action = new LoginAction($service);

        $success = $action(
            $this->jsonRequest('/api/v1/auth/login', [
                'email' => 'owner@example.com',
                'password' => 'correct horse battery staple',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $successPayload = $this->payload($success);

        self::assertSame(200, $success->getStatusCode());
        self::assertSame('fixed-token', $successPayload['token']['access_token']);

        $failure = $action(
            $this->jsonRequest('/api/v1/auth/login', [
                'email' => 'owner@example.com',
                'password' => 'wrong password',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $failurePayload = $this->payload($failure);

        self::assertSame(401, $failure->getStatusCode());
        self::assertSame('invalid_credentials', $failurePayload['code']);
    }

    public function testLoginActionMapsInvalidEmailToValidationError(): void
    {
        $response = (new LoginAction($this->createService($this->createConnection())))(
            $this->jsonRequest('/api/v1/auth/login', [
                'email' => 'not-an-email',
                'password' => 'secret',
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $this->payload($response)['code']);
    }

    public function testPasswordResetActionsAcceptRequestsAndConsumeToken(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection, static fn (): string => 'reset-token');
        $service->register('owner@example.com', 'old secure password', 'Owner');

        $requestAction = new PasswordResetRequestAction($service);
        $requestResponse = $requestAction(
            $this->jsonRequest('/api/v1/auth/password-reset/request', ['email' => 'owner@example.com']),
            (new ResponseFactory())->createResponse(),
        );
        $requestPayload = $this->payload($requestResponse);

        self::assertSame(202, $requestResponse->getStatusCode());
        self::assertTrue($requestPayload['accepted']);
        self::assertArrayNotHasKey('reset_token', $requestPayload);

        $confirmAction = new PasswordResetConfirmAction($service);
        $confirmResponse = $confirmAction(
            $this->jsonRequest('/api/v1/auth/password-reset/confirm', [
                'token' => 'reset-token',
                'password' => 'new secure password',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        $confirmPayload = $this->payload($confirmResponse);

        self::assertSame(200, $confirmResponse->getStatusCode());
        self::assertTrue($confirmPayload['password_reset']);
    }

    public function testPasswordResetActionsMapInvalidRequestsToErrors(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection, static fn (): string => 'reset-token');

        $requestResponse = (new PasswordResetRequestAction($service))(
            $this->jsonRequest('/api/v1/auth/password-reset/request', ['email' => 'not-an-email']),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $requestResponse->getStatusCode());
        self::assertSame('invalid_request', $this->payload($requestResponse)['code']);

        $invalidConfirm = (new PasswordResetConfirmAction($service))(
            $this->jsonRequest('/api/v1/auth/password-reset/confirm', [
                'token' => 'missing-token',
                'password' => 'new secure password',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(400, $invalidConfirm->getStatusCode());
        self::assertSame('invalid_reset_token', $this->payload($invalidConfirm)['code']);

        $connection->insert('users', [
            'id' => 1,
            'email' => 'owner@example.com',
            'password_hash' => (new PasswordHasher())->hash('old secure password'),
            'display_name' => 'Owner',
            'status' => 'active',
        ]);
        $service->requestPasswordReset('owner@example.com');
        $invalidPassword = (new PasswordResetConfirmAction($service))(
            $this->jsonRequest('/api/v1/auth/password-reset/confirm', [
                'token' => 'reset-token',
                'password' => '   ',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $invalidPassword->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalidPassword)['code']);
    }

    public function testAuthActionsRejectMissingAndWrongTypeFieldsWithoutStringCasting(): void
    {
        $service = $this->createService($this->createConnection());

        $register = (new RegisterAction($service))(
            $this->jsonRequest('/api/v1/auth/register', [
                'email' => 'owner@example.com',
                'password' => ['not', 'a', 'string'],
                'display_name' => 'Owner',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $register->getStatusCode());
        self::assertSame('invalid_request', $this->payload($register)['code']);

        $login = (new LoginAction($service))(
            $this->jsonRequest('/api/v1/auth/login', [
                'email' => ['owner@example.com'],
                'password' => 'correct horse battery staple',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $login->getStatusCode());
        self::assertSame('invalid_request', $this->payload($login)['code']);

        $resetRequest = (new PasswordResetRequestAction($service))(
            $this->jsonRequest('/api/v1/auth/password-reset/request', []),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $resetRequest->getStatusCode());
        self::assertSame('invalid_request', $this->payload($resetRequest)['code']);

        $resetConfirm = (new PasswordResetConfirmAction($service))(
            $this->jsonRequest('/api/v1/auth/password-reset/confirm', [
                'token' => 'reset-token',
                'password' => 123456,
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $resetConfirm->getStatusCode());
        self::assertSame('invalid_request', $this->payload($resetConfirm)['code']);
    }

    public function testMeActionReturnsAuthenticatedUserMembershipAndPermissions(): void
    {
        $action = new MeAction(new FixedMembershipRepository());
        $request = $this->jsonRequest('/api/v1/auth/me?organization_id=77', [])
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                user: new AuthenticatedUser(5, 'owner@example.com', false),
                organizationId: 77,
            ));

        $response = $action($request, (new ResponseFactory())->createResponse());
        $payload = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 5, 'email' => 'owner@example.com', 'is_super_admin' => false], $payload['user']);
        self::assertSame(77, $payload['organization_id']);
        self::assertSame(['publisher-manager'], $payload['membership']['roles']);
        self::assertSame(['publisher.sites.manage'], $payload['membership']['permissions']);
    }

    public function testMeActionReturnsNullMembershipWithoutOrganizationScope(): void
    {
        $request = $this->jsonRequest('/api/v1/auth/me', [])
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                user: new AuthenticatedUser(5, 'owner@example.com', false),
                organizationId: null,
            ));

        $payload = $this->payload((new MeAction(new FixedMembershipRepository()))(
            $request,
            (new ResponseFactory())->createResponse(),
        ));

        self::assertNull($payload['organization_id']);
        self::assertNull($payload['membership']);
    }

    public function testMeAndLogoutActionsRejectAnonymousRequests(): void
    {
        $response = (new MeAction(new FixedMembershipRepository()))(
            $this->jsonRequest('/api/v1/auth/me', []),
            (new ResponseFactory())->createResponse(),
        );
        $logout = (new LogoutAction(new BearerTokenAuthenticator(new FirstPartySessionRepository($this->createConnection())), new FirstPartySessionRepository($this->createConnection())))(
            $this->jsonRequest('/api/v1/auth/logout', []),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $this->payload($response)['code']);
        self::assertSame(401, $logout->getStatusCode());
        self::assertSame('authentication_required', $this->payload($logout)['code']);
    }

    public function testLogoutActionRevokesBearerToken(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection, static fn (): string => 'fixed-token');
        $service->register('owner@example.com', 'correct horse battery staple', 'Owner');
        $service->login('owner@example.com', 'correct horse battery staple');

        $sessions = new FirstPartySessionRepository($connection);
        $action = new LogoutAction(new BearerTokenAuthenticator($sessions), $sessions);
        $request = $this->jsonRequest('/api/v1/auth/logout', [])
            ->withHeader('Authorization', 'Bearer fixed-token')
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                user: new AuthenticatedUser(1, 'owner@example.com', false),
            ));

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->payload($response)['revoked']);
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM first_party_sessions'));
    }

    public function testLogoutActionRejectsAuthenticatedRequestWithoutRevokableToken(): void
    {
        $connection = $this->createConnection();
        $sessions = new FirstPartySessionRepository($connection);
        $action = new LogoutAction(new BearerTokenAuthenticator($sessions), $sessions);
        $request = $this->jsonRequest('/api/v1/auth/logout', [])
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                user: new AuthenticatedUser(1, 'owner@example.com', false),
            ));

        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('session_not_found', $this->payload($response)['code']);
    }

    public function testRegisterActionMapsValidationAndDuplicateErrors(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);
        $action = new RegisterAction($service);

        $invalid = $action(
            $this->jsonRequest('/api/v1/auth/register', [
                'email' => 'not-an-email',
                'password' => 'correct horse battery staple',
                'display_name' => 'Owner',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalid)['code']);

        $service->register('owner@example.com', 'correct horse battery staple', 'Owner');
        $duplicate = $action(
            $this->jsonRequest('/api/v1/auth/register', [
                'email' => 'owner@example.com',
                'password' => 'correct horse battery staple',
                'display_name' => 'Owner',
            ]),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('email_already_registered', $this->payload($duplicate)['code']);
    }

    private function createService(Connection $connection, ?callable $tokenFactory = null): AuthService
    {
        return new AuthService(
            $connection,
            new PasswordHasher(),
            new PasswordResetTokenRepository($connection),
            new FirstPartySessionRepository($connection),
            $tokenFactory,
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                last_login_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                requested_ip BLOB NULL,
                user_agent TEXT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE first_party_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        return $connection;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(string $uri, array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $uri)
            ->withParsedBody($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(\Psr\Http\Message\ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}

final class FixedMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function listForOrganization(int $organizationId): array
    {
        return [];
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        if ($userId !== 5 || $organizationId !== 77) {
            return null;
        }

        return new OrganizationMembership(
            organizationId: 77,
            userId: 5,
            status: 'active',
            roleSlugs: ['publisher-manager'],
            permissions: ['publisher.sites.manage'],
        );
    }
}

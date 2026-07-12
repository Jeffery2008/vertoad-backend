<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use Closure;
use DateTimeImmutable;
use Defuse\Crypto\Key;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\CallableResolver;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Routing\Route;
use Slim\Routing\RouteContext;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Action\Auth\LoginAction;
use VertoAD\Http\Action\Auth\RegisterAction;
use VertoAD\Http\Action\Billing\BillingBalanceAction;
use VertoAD\Http\Action\Billing\GenerateRechargeKeyBatchAction;
use VertoAD\Http\Action\Billing\BillingLedgerListAction;
use VertoAD\Http\Action\Billing\LedgerAdjustmentAction;
use VertoAD\Http\Action\Billing\RevealRechargeKeyPlaintextAction;
use VertoAD\Http\Action\Billing\RechargeKeyRedeemAction;
use VertoAD\Http\Action\Billing\WithdrawalAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\AuditLogRepository;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepository;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\AuthService;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\PasswordHasher;
use VertoAD\Service\DefuseRechargeKeyPlaintextCipher;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\TenantAccessService;
use VertoAD\Tests\Assets\InMemoryObjectStorageInspector;

final class BillingRouteIntegrationTest extends TestCase
{
    private string $appKey;

    protected function setUp(): void
    {
        $this->appKey = Key::createNewRandomKey()->saveToAsciiSafeString();
    }

    public function testRedeemRechargeKeyCreditsOrganizationBalanceAndLedgerList(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->issueRechargeKey($connection, 'rk_live_ROUTE', 1200);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);

        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $redemption = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem?organization_id=99',
            ['key' => ' rk_live_ROUTE '],
            $token,
        );

        self::assertSame(1200, $redemption['data']['points_amount']);
        self::assertSame('redeemed', $redemption['data']['status']);
        self::assertSame(99, $redemption['data']['organization_id']);
        self::assertSame('credit', $redemption['data']['ledger_entry']['direction']);
        self::assertSame('advertiser_balance', $redemption['data']['ledger_entry']['account_type']);

        $balance = $this->handleJson($app, 'GET', '/api/v1/billing/balance?organization_id=99', null, $token);
        self::assertSame(1200, $balance['data']['balance_points']);
        self::assertSame('12.00', $balance['data']['balance_cny']);

        $ledger = $this->handleJson($app, 'GET', '/api/v1/billing/ledger?organization_id=99', null, $token);
        self::assertCount(1, $ledger['data']['entries']);
        self::assertSame(1200, $ledger['data']['entries'][0]['points_amount']);
        self::assertSame('recharge_key', $ledger['data']['entries'][0]['reference_type']);
        self::assertSame(1200, $ledger['data']['entries'][0]['balance_after_points']);
    }

    public function testBillingRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->createConnection());

        $unauthenticated = $this->handleJson($app, 'GET', '/api/v1/billing/balance?organization_id=99');
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $unauthenticatedLedger = $this->handleJson($app, 'GET', '/api/v1/billing/ledger?organization_id=99');
        self::assertSame('authentication_required', $unauthenticatedLedger['error']['code']);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $missingScope = $this->handleJson($app, 'GET', '/api/v1/billing/balance', null, $login['data']['token']['access_token']);
        self::assertSame('organization_scope_required', $missingScope['error']['code']);

        $missingRedeemScope = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem',
            ['key' => 'rk_live_ROUTE'],
            $login['data']['token']['access_token'],
        );
        self::assertSame('organization_scope_required', $missingRedeemScope['error']['code']);
    }

    public function testLedgerLimitAndRechargeKeyErrorsAreControlled(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->issueRechargeKey($connection, 'rk_live_ROUTE', 1200);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $ledger = $this->handleJson($app, 'GET', '/api/v1/billing/ledger?organization_id=99&limit=999', null, $token);
        self::assertSame(200, $ledger['data']['limit']);

        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledgerService = new PointsLedgerService($ledgerRepository);
        $ledgerService->credit(99, 'advertiser_balance', null, 500, 'route:advertiser-ledger-filter');
        $ledgerService->credit(99, 'publisher_earnings', null, 300, 'route:publisher-ledger-filter');
        $publisherLedger = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/ledger?organization_id=99&account_type=publisher_earnings',
            null,
            $token,
        );
        self::assertSame('publisher_earnings', $publisherLedger['data']['account_type']);
        self::assertSame(['publisher_earnings'], array_values(array_unique(array_column($publisherLedger['data']['entries'], 'account_type'))));

        $blankKey = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem?organization_id=99',
            ['key' => ' '],
            $token,
        );
        self::assertSame('invalid_request', $blankKey['error']['code']);

        $missingKey = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem?organization_id=99',
            ['key' => 'rk_live_MISSING'],
            $token,
        );
        self::assertSame('recharge_key_rejected', $missingKey['error']['code']);
    }

    public function testBillingRoutesRejectInvalidOrganizationIdAndLimitBoundaries(): void
    {
        $app = $this->createApp($this->createConnection());
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        foreach (['0', '-1', 'abc', '1.5'] as $organizationId) {
            $balance = $this->handleJson(
                $app,
                'GET',
                '/api/v1/billing/balance?organization_id=' . rawurlencode($organizationId),
                null,
                $token,
            );
            self::assertSame('organization_scope_required', $balance['error']['code']);
        }

        $invalidLimit = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/ledger?organization_id=99&limit=abc',
            null,
            $token,
        );
        self::assertSame('invalid_request', $invalidLimit['error']['code']);

        $tooSmallLimit = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/ledger?organization_id=99&limit=0',
            null,
            $token,
        );
        self::assertSame(1, $tooSmallLimit['data']['limit']);

        $invalidAccountType = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/ledger?organization_id=99&account_type[]=publisher_earnings',
            null,
            $token,
        );
        self::assertSame('invalid_request', $invalidAccountType['error']['code']);
    }

    public function testRechargeKeyRedeemRejectsMissingAndWrongTypeKey(): void
    {
        $app = $this->createApp($this->createConnection());
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $missing = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem?organization_id=99',
            [],
            $token,
        );
        self::assertSame('invalid_request', $missing['error']['code']);

        $wrongType = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/redeem?organization_id=99',
            ['key' => 12345],
            $token,
        );
        self::assertSame('invalid_request', $wrongType['error']['code']);
    }

    public function testAdminGeneratesRechargeKeyBatchAndRevealsPlaintextWithAudit(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'admin-billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'admin-billing@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $generated = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 2500,
            'count' => 2,
            'batch_code' => 'route-admin-batch',
            'batch_metadata' => ['channel' => 'ops-console'],
            'expires_at' => '2026-12-31 23:59:59',
        ], $token, ['REMOTE_ADDR' => '198.51.100.24'], [
            'CF-Connecting-IP' => '203.0.113.44',
            'User-Agent' => 'BillingAdmin/1.0',
        ]);

        self::assertSame('route-admin-batch', $generated['data']['batch_code']);
        self::assertSame(2, $generated['data']['count']);
        self::assertCount(2, $generated['data']['keys']);
        self::assertSame(2500, $generated['data']['keys'][0]['points_amount']);
        self::assertSame('issued', $generated['data']['keys'][0]['status']);
        self::assertSame('route-admin-batch', $generated['data']['keys'][0]['batch_code']);
        self::assertSame(['channel' => 'ops-console'], $generated['data']['keys'][0]['batch_metadata']);
        self::assertStringStartsWith('rk_live_', $generated['data']['keys'][0]['plaintext_key']);

        $reveal = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/' . $generated['data']['keys'][0]['id'] . '/reveal',
            [],
            $token,
            ['REMOTE_ADDR' => '198.51.100.24'],
            [
                'CF-Connecting-IP' => '203.0.113.45',
                'User-Agent' => 'BillingAdmin/1.0',
            ],
        );

        self::assertSame($generated['data']['keys'][0]['id'], $reveal['data']['id']);
        self::assertSame($generated['data']['keys'][0]['plaintext_key'], $reveal['data']['plaintext_key']);
        self::assertSame('route-admin-batch', $reveal['data']['batch_code']);

        $auditRows = $connection->fetchAllAssociative('SELECT action, subject_type, subject_id, actor_user_id, ip_address, user_agent, metadata_json FROM audit_logs ORDER BY id ASC');
        self::assertCount(2, $auditRows);
        self::assertSame('billing.recharge_key.generate_batch', $auditRows[0]['action']);
        self::assertSame('recharge_key_batch', $auditRows[0]['subject_type']);
        self::assertNull($auditRows[0]['subject_id']);
        self::assertSame(1, (int) $auditRows[0]['actor_user_id']);
        self::assertSame(inet_pton('203.0.113.44'), $auditRows[0]['ip_address']);
        self::assertSame('BillingAdmin/1.0', $auditRows[0]['user_agent']);
        self::assertStringContainsString('route-admin-batch', (string) $auditRows[0]['metadata_json']);
        self::assertStringNotContainsString($generated['data']['keys'][0]['plaintext_key'], (string) $auditRows[0]['metadata_json']);

        self::assertSame('billing.recharge_key.reveal_plaintext', $auditRows[1]['action']);
        self::assertSame('recharge_key', $auditRows[1]['subject_type']);
        self::assertSame((int) $generated['data']['keys'][0]['id'], (int) $auditRows[1]['subject_id']);
        self::assertSame(1, (int) $auditRows[1]['actor_user_id']);
        self::assertSame(inet_pton('203.0.113.45'), $auditRows[1]['ip_address']);
        self::assertSame('BillingAdmin/1.0', $auditRows[1]['user_agent']);
        self::assertStringContainsString('route-admin-batch', (string) $auditRows[1]['metadata_json']);
        self::assertStringNotContainsString($generated['data']['keys'][0]['plaintext_key'], (string) $auditRows[1]['metadata_json']);
    }

    public function testAdminRechargeKeyRoutesEnforcePlatformPermissions(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection, platformPermissions: []);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'admin-permissions@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'admin-permissions@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $missingScope = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
        ], $token);
        self::assertSame('organization_scope_required', $missingScope['error']['code']);
        self::assertSame('billing.recharge_key.generate.platform', $missingScope['error']['required_permission']);

        $missingPermission = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate?organization_id=10', [
            'points_amount' => 100,
            'count' => 1,
        ], $token);
        self::assertSame('permission_required', $missingPermission['error']['code']);
        self::assertSame('billing.recharge_key.generate.platform', $missingPermission['error']['required_permission']);

        $this->issueRechargeKey($connection, 'rk_live_PERMISSION_SECRET', 100);
        $missingRevealScope = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/1/reveal',
            [],
            $token,
        );
        self::assertSame('organization_scope_required', $missingRevealScope['error']['code']);
        self::assertSame('billing.recharge_key.view_plaintext.platform', $missingRevealScope['error']['required_permission']);
        self::assertStringNotContainsString('plaintext_key', json_encode($missingRevealScope, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('rk_live_PERMISSION_SECRET', json_encode($missingRevealScope, JSON_THROW_ON_ERROR));

        $missingRevealPermission = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/1/reveal?organization_id=10',
            [],
            $token,
        );
        self::assertSame('permission_required', $missingRevealPermission['error']['code']);
        self::assertSame('billing.recharge_key.view_plaintext.platform', $missingRevealPermission['error']['required_permission']);
        self::assertStringNotContainsString('plaintext_key', json_encode($missingRevealPermission, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('rk_live_PERMISSION_SECRET', json_encode($missingRevealPermission, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'billing.recharge_key.reveal_plaintext'",
        ));

        $allowedApp = $this->createApp($connection, platformPermissions: [
            'billing.recharge_key.generate.platform',
            'billing.recharge_key.view_plaintext.platform',
        ]);
        $generated = $this->handleJson($allowedApp, 'POST', '/api/v1/billing/recharge-keys/generate?organization_id=10', [
            'points_amount' => 100,
            'count' => 1,
        ], $token);
        self::assertSame(1, $generated['data']['count']);

        $revealed = $this->handleJson(
            $allowedApp,
            'POST',
            '/api/v1/billing/recharge-keys/' . $generated['data']['keys'][0]['id'] . '/reveal?organization_id=10',
            [],
            $token,
        );
        self::assertSame($generated['data']['keys'][0]['plaintext_key'], $revealed['data']['plaintext_key']);
    }

    public function testAdminRechargeKeyRoutesReturnControlledErrors(): void
    {
        $app = $this->createApp($this->createConnection());

        $unauthenticatedGenerate = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
        ]);
        self::assertSame('authentication_required', $unauthenticatedGenerate['error']['code']);

        $unauthenticatedReveal = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/1/reveal');
        self::assertSame('authentication_required', $unauthenticatedReveal['error']['code']);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'admin-errors@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'admin-errors@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $invalidGenerate = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 0,
        ], $token);
        self::assertSame('invalid_request', $invalidGenerate['error']['code']);

        $missingCount = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
        ], $token);
        self::assertSame('invalid_request', $missingCount['error']['code']);

        $stringIntegersAndOmittedOptionalFields = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => '100',
            'count' => '1',
            'batch_code' => ' ',
        ], $token);
        self::assertNull($stringIntegersAndOmittedOptionalFields['data']['batch_code']);
        self::assertSame(1, $stringIntegersAndOmittedOptionalFields['data']['count']);
        self::assertSame(100, $stringIntegersAndOmittedOptionalFields['data']['keys'][0]['points_amount']);
        self::assertNull($stringIntegersAndOmittedOptionalFields['data']['keys'][0]['batch_metadata']);
        self::assertNull($stringIntegersAndOmittedOptionalFields['data']['keys'][0]['expires_at']);

        $wrongBatchCodeType = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
            'batch_code' => ['not' => 'a-string'],
        ], $token);
        self::assertSame('invalid_request', $wrongBatchCodeType['error']['code']);

        $wrongMetadataType = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
            'batch_metadata' => 'not-json-object',
        ], $token);
        self::assertSame('invalid_request', $wrongMetadataType['error']['code']);

        $metadataList = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
            'batch_metadata' => ['not', 'an', 'object'],
        ], $token);
        self::assertSame('invalid_request', $metadataList['error']['code']);

        $wrongDateType = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
            'expires_at' => 'not-a-valid-datetime',
        ], $token);
        self::assertSame('invalid_request', $wrongDateType['error']['code']);

        $badRevealId = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/abc/reveal', [], $token);
        self::assertSame('invalid_request', $badRevealId['error']['code']);

        $missingReveal = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/999/reveal', [], $token);
        self::assertSame('recharge_key_not_found', $missingReveal['error']['code']);
    }

    public function testAdminRechargeKeyGenerateReturnsControlledConflictWhenUniqueKeyGenerationFails(): void
    {
        $connection = $this->createConnection();
        $this->issueRechargeKey($connection, 'rk_live_DUPLICATE', 100);
        $app = $this->createApp($connection, static fn (): string => 'rk_live_DUPLICATE');

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'admin-duplicate-generator@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'admin-duplicate-generator@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $conflict = $this->handleJson($app, 'POST', '/api/v1/billing/recharge-keys/generate', [
            'points_amount' => 100,
            'count' => 1,
        ], $login['data']['token']['access_token']);

        self::assertSame('recharge_key_generation_failed', $conflict['error']['code']);
        self::assertSame('Unable to generate a unique recharge key after repeated attempts.', $conflict['error']['message']);
    }

    public function testAdminAdjustsAndReversesLedgerEntriesWithAudit(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection, platformPermissions: ['billing.ledger.adjust.platform']);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'ledger-admin@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Ledger Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'ledger-admin@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $adjustmentResponse = $this->handleJsonResponse($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'account_id' => null,
            'points_amount' => '450',
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:route-1',
            'reason' => 'compensate failed recharge import',
        ], $token, ['REMOTE_ADDR' => '198.51.100.24'], [
            'CF-Connecting-IP' => '203.0.113.88',
            'User-Agent' => 'LedgerAdmin/1.0',
            'X-Request-Id' => 'req-ledger-adjust',
        ]);
        self::assertSame(201, $adjustmentResponse['status']);
        $adjustment = $adjustmentResponse['body'];

        self::assertSame(10, $adjustment['data']['ledger_entry']['organization_id']);
        self::assertSame('advertiser_balance', $adjustment['data']['ledger_entry']['account_type']);
        self::assertNull($adjustment['data']['ledger_entry']['account_id']);
        self::assertSame(450, $adjustment['data']['ledger_entry']['points_amount']);
        self::assertSame('credit', $adjustment['data']['ledger_entry']['direction']);
        self::assertSame(450, $adjustment['data']['ledger_entry']['balance_after_points']);
        self::assertSame('manual_adjustment', $adjustment['data']['ledger_entry']['reference_type']);
        self::assertSame('Adjustment: compensate failed recharge import', $adjustment['data']['ledger_entry']['memo']);
        self::assertSame('adjustment', $adjustment['data']['ledger_entry']['metadata']['entry_kind']);
        self::assertSame(1, $adjustment['data']['ledger_entry']['metadata']['actor_user_id']);
        self::assertStringNotContainsString('idempotency_key', json_encode($adjustment, JSON_THROW_ON_ERROR));

        $duplicateResponse = $this->handleJsonResponse($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 999,
            'direction' => 'debit',
            'idempotency_key' => 'ops:ledger-adjust:route-1',
            'reason' => 'duplicate submission',
        ], $token);
        self::assertSame(200, $duplicateResponse['status']);
        $duplicate = $duplicateResponse['body'];
        self::assertSame($adjustment['data']['ledger_entry']['id'], $duplicate['data']['ledger_entry']['id']);
        self::assertSame(450, $duplicate['data']['ledger_entry']['points_amount']);

        $reversalResponse = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $adjustment['data']['ledger_entry']['id'] . '/reversals?organization_id=10',
            [
                'idempotency_key' => 'ops:ledger-reversal:route-1',
                'reason' => 'operator found duplicate compensation',
            ],
            $token,
            ['REMOTE_ADDR' => '198.51.100.24'],
            [
                'CF-Connecting-IP' => '203.0.113.89',
                'User-Agent' => 'LedgerAdmin/1.0',
                'X-Request-Id' => 'req-ledger-reverse',
            ],
        );
        self::assertSame(201, $reversalResponse['status']);
        $reversal = $reversalResponse['body'];

        self::assertSame(10, $reversal['data']['ledger_entry']['organization_id']);
        self::assertSame('debit', $reversal['data']['ledger_entry']['direction']);
        self::assertSame(450, $reversal['data']['ledger_entry']['points_amount']);
        self::assertSame(0, $reversal['data']['ledger_entry']['balance_after_points']);
        self::assertSame('ledger_entry', $reversal['data']['ledger_entry']['reference_type']);
        self::assertSame($adjustment['data']['ledger_entry']['id'], $reversal['data']['ledger_entry']['reference_id']);
        self::assertSame('reversal', $reversal['data']['ledger_entry']['metadata']['entry_kind']);
        self::assertSame(
            $adjustment['data']['ledger_entry']['id'],
            $reversal['data']['ledger_entry']['metadata']['reverses_ledger_entry_id'],
        );

        $reversalReplayResponse = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $adjustment['data']['ledger_entry']['id'] . '/reversals?organization_id=10',
            [
                'idempotency_key' => 'ops:ledger-reversal:route-1',
                'reason' => 'replayed after timeout',
            ],
            $token,
        );
        self::assertSame(200, $reversalReplayResponse['status']);
        $reversalReplay = $reversalReplayResponse['body'];
        self::assertSame($reversal['data']['ledger_entry']['id'], $reversalReplay['data']['ledger_entry']['id']);
        self::assertSame(450, $reversalReplay['data']['ledger_entry']['points_amount']);

        $duplicateReversalResponse = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $adjustment['data']['ledger_entry']['id'] . '/reversals?organization_id=10',
            [
                'idempotency_key' => 'ops:ledger-reversal:route-2',
                'reason' => 'second reversal should be blocked',
            ],
            $token,
        );
        self::assertSame(409, $duplicateReversalResponse['status']);
        $duplicateReversal = $duplicateReversalResponse['body'];
        self::assertSame('ledger_reversal_conflict', $duplicateReversal['error']['code']);

        $auditRows = $connection->fetchAllAssociative('SELECT action, subject_type, subject_id, actor_user_id, organization_id, ip_address, user_agent, request_id, metadata_json FROM audit_logs ORDER BY id ASC');
        self::assertCount(2, $auditRows);
        self::assertSame('billing.ledger.adjust', $auditRows[0]['action']);
        self::assertSame('ledger_entry', $auditRows[0]['subject_type']);
        self::assertSame((int) $adjustment['data']['ledger_entry']['id'], (int) $auditRows[0]['subject_id']);
        self::assertSame(1, (int) $auditRows[0]['actor_user_id']);
        self::assertSame(10, (int) $auditRows[0]['organization_id']);
        self::assertSame(inet_pton('203.0.113.88'), $auditRows[0]['ip_address']);
        self::assertSame('LedgerAdmin/1.0', $auditRows[0]['user_agent']);
        self::assertSame('req-ledger-adjust', $auditRows[0]['request_id']);
        self::assertStringContainsString('compensate failed recharge import', (string) $auditRows[0]['metadata_json']);

        self::assertSame('billing.ledger.reverse', $auditRows[1]['action']);
        self::assertSame('ledger_entry', $auditRows[1]['subject_type']);
        self::assertSame((int) $reversal['data']['ledger_entry']['id'], (int) $auditRows[1]['subject_id']);
        self::assertSame(1, (int) $auditRows[1]['actor_user_id']);
        self::assertSame(10, (int) $auditRows[1]['organization_id']);
        self::assertSame(inet_pton('203.0.113.89'), $auditRows[1]['ip_address']);
        self::assertSame('LedgerAdmin/1.0', $auditRows[1]['user_agent']);
        self::assertSame('req-ledger-reverse', $auditRows[1]['request_id']);
        self::assertStringContainsString('operator found duplicate compensation', (string) $auditRows[1]['metadata_json']);
    }

    public function testAdminLedgerAdjustmentRoutesEnforcePlatformPermission(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection, platformPermissions: []);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'ledger-permission@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Ledger Permission',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'ledger-permission@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $missingScope = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:permission',
            'reason' => 'permission test',
        ], $token);
        self::assertSame('organization_scope_required', $missingScope['error']['code']);
        self::assertSame('billing.ledger.adjust.platform', $missingScope['error']['required_permission']);

        $missingAdjustmentPermission = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:permission',
            'reason' => 'permission test',
        ], $token);
        self::assertSame('permission_required', $missingAdjustmentPermission['error']['code']);
        self::assertSame('billing.ledger.adjust.platform', $missingAdjustmentPermission['error']['required_permission']);

        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $original = $ledger->credit(10, 'advertiser_balance', null, 100, 'ops:ledger-original:permission');
        $missingReversalPermission = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $original->id . '/reversals?organization_id=10',
            [
                'idempotency_key' => 'ops:ledger-reversal:permission',
                'reason' => 'permission test',
            ],
            $token,
        );
        self::assertSame('permission_required', $missingReversalPermission['error']['code']);
        self::assertSame('billing.ledger.adjust.platform', $missingReversalPermission['error']['required_permission']);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'billing.ledger.%'"));
    }

    public function testAdminLedgerAdjustmentRoutesReturnControlledErrors(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection, platformPermissions: ['billing.ledger.adjust.platform']);

        $unauthenticatedAdjustment = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:unauth',
            'reason' => 'unauthenticated',
        ]);
        self::assertSame('authentication_required', $unauthenticatedAdjustment['error']['code']);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'ledger-errors@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Ledger Errors',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'ledger-errors@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $appWithoutPlatformGuard = $this->createApp($connection);
        $missingAdjustmentScope = $this->handleJson($appWithoutPlatformGuard, 'POST', '/api/v1/billing/ledger/adjustments', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:missing-scope',
            'reason' => 'missing scope',
        ], $token);
        self::assertSame('organization_scope_required', $missingAdjustmentScope['error']['code']);

        $badDirection = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'refund',
            'idempotency_key' => 'ops:ledger-adjust:bad-direction',
            'reason' => 'bad direction',
        ], $token);
        self::assertSame('invalid_request', $badDirection['error']['code']);

        $blankReason = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:blank-reason',
            'reason' => ' ',
        ], $token);
        self::assertSame('invalid_request', $blankReason['error']['code']);
        self::assertSame('reason must be a non-empty string.', $blankReason['error']['message']);

        $wrongReasonType = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:wrong-reason-type',
            'reason' => ['not' => 'a string'],
        ], $token);
        self::assertSame('invalid_request', $wrongReasonType['error']['code']);
        self::assertSame('reason must be a string.', $wrongReasonType['error']['message']);

        $missingPointsAmount = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:missing-points',
            'reason' => 'missing points',
        ], $token);
        self::assertSame('invalid_request', $missingPointsAmount['error']['code']);
        self::assertSame('points_amount must be a positive integer.', $missingPointsAmount['error']['message']);

        $badAccountId = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'account_id' => 'not-int',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:bad-account',
            'reason' => 'bad account',
        ], $token);
        self::assertSame('invalid_request', $badAccountId['error']['code']);

        $badAccountType = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'settlement',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:bad-account-type',
            'reason' => 'bad account type',
        ], $token);
        self::assertSame('invalid_request', $badAccountType['error']['code']);
        self::assertSame('account_type must be either advertiser_balance or publisher_earnings.', $badAccountType['error']['message']);

        $tooLongReason = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:too-long-reason',
            'reason' => str_repeat('x', 241),
        ], $token);
        self::assertSame('invalid_request', $tooLongReason['error']['code']);
        self::assertSame('reason must be at most 240 characters.', $tooLongReason['error']['message']);

        $badEntryId = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/abc/reversals?organization_id=10', [
            'idempotency_key' => 'ops:ledger-reversal:bad-id',
            'reason' => 'bad id',
        ], $token);
        self::assertSame('invalid_request', $badEntryId['error']['code']);

        $missingOriginal = $this->handleJson($app, 'POST', '/api/v1/billing/ledger/999/reversals?organization_id=10', [
            'idempotency_key' => 'ops:ledger-reversal:missing',
            'reason' => 'missing original',
        ], $token);
        self::assertSame('ledger_entry_not_found', $missingOriginal['error']['code']);

        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $original = $ledger->credit(10, 'advertiser_balance', null, 100, 'ops:ledger-original:conflict');

        $missingReversalScope = $this->handleJson(
            $appWithoutPlatformGuard,
            'POST',
            '/api/v1/billing/ledger/' . $original->id . '/reversals',
            [
                'idempotency_key' => 'ops:ledger-reversal:missing-scope',
                'reason' => 'missing scope',
            ],
            $token,
        );
        self::assertSame('organization_scope_required', $missingReversalScope['error']['code']);

        $tooLongReversalIdempotencyKey = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $original->id . '/reversals?organization_id=10',
            [
                'idempotency_key' => str_repeat('r', 161),
                'reason' => 'too long idempotency key',
            ],
            $token,
        );
        self::assertSame('invalid_request', $tooLongReversalIdempotencyKey['error']['code']);
        self::assertSame('idempotency_key must be at most 160 characters.', $tooLongReversalIdempotencyKey['error']['message']);

        $ledger->credit(10, 'advertiser_balance', null, 1, 'ops:ledger-adjust:conflicting-key');
        $conflictingAdjustmentKeyResponse = $this->handleJsonResponse($app, 'POST', '/api/v1/billing/ledger/adjustments?organization_id=10', [
            'account_type' => 'advertiser_balance',
            'points_amount' => 100,
            'direction' => 'credit',
            'idempotency_key' => 'ops:ledger-adjust:conflicting-key',
            'reason' => 'attempt conflicting adjustment key reuse',
        ], $token);
        self::assertSame(409, $conflictingAdjustmentKeyResponse['status']);
        $conflictingAdjustmentKey = $conflictingAdjustmentKeyResponse['body'];
        self::assertSame('ledger_idempotency_conflict', $conflictingAdjustmentKey['error']['code']);

        $adjustment = $ledger->adjust(
            organizationId: 10,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: 100,
            direction: \VertoAD\Domain\Ledger\LedgerDirection::Credit,
            idempotencyKey: 'ops:ledger-shared-key',
            reason: 'reserve idempotency key',
        );
        self::assertSame('adjustment', $adjustment->metadata['entry_kind'] ?? null);

        $reusedKeyResponse = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/ledger/' . $original->id . '/reversals?organization_id=10',
            [
                'idempotency_key' => 'ops:ledger-shared-key',
                'reason' => 'attempt conflicting reuse',
            ],
            $token,
        );
        self::assertSame(409, $reusedKeyResponse['status']);
        $reusedKey = $reusedKeyResponse['body'];
        self::assertSame('ledger_idempotency_conflict', $reusedKey['error']['code']);

        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'billing.ledger.%'"));
    }

    public function testAdminRechargeKeyRevealReturnsControlledConflictWhenAuditIsUnavailable(): void
    {
        $connection = $this->createConnection();
        $this->issueRechargeKey($connection, 'rk_live_SECRET_NO_AUDIT', 100);
        $app = $this->createApp($connection, wireRechargeKeyAudit: false);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'admin-no-audit@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'admin-no-audit@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $rejected = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/recharge-keys/1/reveal',
            [],
            $login['data']['token']['access_token'],
        );

        self::assertSame('recharge_key_rejected', $rejected['error']['code']);
        self::assertSame('Audit log service is required to reveal recharge key plaintext.', $rejected['error']['message']);
    }

    public function testRevealRechargeKeyActionRejectsMissingRouteAndServiceValidationErrors(): void
    {
        $repository = new RechargeKeyRepository($connection = $this->createConnection());
        $service = new RechargeKeyService(
            repository: $repository,
            ledger: new PointsLedgerService(new PointsLedgerRepository($connection)),
            cipher: new DefuseRechargeKeyPlaintextCipher($this->appKey),
            audit: new AuditLogService(new AuditLogRepository($connection)),
        );
        $issued = $service->issue('rk_live_ACTION_DIRECT', 100, null, null, null, 7, null);
        self::assertNotNull($issued->id);

        $action = new RevealRechargeKeyPlaintextAction($service, new ClientIpResolver());
        $responseFactory = new ResponseFactory();

        $missingRoute = $action(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/billing/recharge-keys/1/reveal')
                ->withAttribute(
                    RequestUserContext::ATTRIBUTE,
                    new RequestUserContext(new AuthenticatedUser(1, 'admin@example.com', true)),
                ),
            $responseFactory->createResponse(),
        );
        $missingRouteDecoded = json_decode((string) $missingRoute->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid_request', $missingRouteDecoded['code']);

        $route = new Route(
            ['POST'],
            '/api/v1/billing/recharge-keys/{key_id}/reveal',
            static fn (): null => null,
            $responseFactory,
            new CallableResolver(),
        );
        $route->setArgument('key_id', (string) $issued->id);

        $invalidActor = $action(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/billing/recharge-keys/' . $issued->id . '/reveal')
                ->withAttribute(RouteContext::ROUTE, $route)
                ->withAttribute(
                    RequestUserContext::ATTRIBUTE,
                    new RequestUserContext(new AuthenticatedUser(0, 'admin@example.com', true)),
                ),
            $responseFactory->createResponse(),
        );
        $invalidActorDecoded = json_decode((string) $invalidActor->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid_request', $invalidActorDecoded['code']);
        self::assertSame(
            'Recharge key plaintext reveal actor user ID must be positive.',
            $invalidActorDecoded['message'],
        );
    }

    public function testMalformedJsonRechargeKeyRedeemReturnsEnvelopeValidationError(): void
    {
        $app = $this->createApp($this->createConnection());
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Billing Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'billing@example.com',
            'password' => 'correct horse battery staple',
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/billing/recharge-keys/redeem?organization_id=99')
            ->withHeader('Authorization', 'Bearer ' . $login['data']['token']['access_token'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{'));
        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertNull($decoded['data']);
        self::assertSame('invalid_request', $decoded['error']['code']);
    }

    public function testWithdrawalRoutesHoldRestorePayAndManageProofs(): void
    {
        $connection = $this->createConnection();
        $proofInspector = new InMemoryObjectStorageInspector();
        $app = $this->createApp($connection, proofInspector: $proofInspector);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawals@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawals@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        (new PointsLedgerService(new PointsLedgerRepository($connection)))->credit(99, 'publisher_earnings', null, 3000, 'route:publisher-earning');

        $requested = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'notes' => 'route payout',
            'idempotency_key' => 'withdrawal:route:request:1',
        ], $token);

        self::assertSame('pending', $requested['data']['review_status']);
        self::assertSame('not_started', $requested['data']['payment_status']);
        self::assertSame(1000, $requested['data']['points_amount']);
        self::assertSame('10.00', $requested['data']['amount_cny']);
        self::assertSame(100, $requested['data']['points_per_cny']);
        self::assertSame('withdrawal:route:request:1', $requested['data']['idempotency_key']);

        $requestedAgain = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'notes' => 'route payout',
            'idempotency_key' => 'withdrawal:route:request:1',
        ], $token);
        self::assertSame($requested['data']['id'], $requestedAgain['data']['id']);
        self::assertSame($requested['data']['ledger_entry_id'], $requestedAgain['data']['ledger_entry_id']);
        self::assertSame(2000, (new PointsLedgerRepository($connection))->balanceForOrganization(99, 'publisher_earnings'));

        $approved = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/approve', [
            'notes' => 'account reviewed',
        ], $token);
        self::assertSame('approved', $approved['data']['review_status']);
        self::assertSame('pending', $approved['data']['payment_status']);

        $proofIntent = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=100', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);
        self::assertSame('pending_upload', $proofIntent['data']['proof']['status']);
        self::assertSame(99, $proofIntent['data']['proof']['organization_id']);
        self::assertSame('PUT', $proofIntent['data']['upload']['method']);
        self::assertNotSame('', $proofIntent['data']['proof']['created_at']);

        $pendingProofs = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=100&limit=10',
            null,
            $token,
        );
        self::assertSame($requested['data']['id'], $pendingProofs['data']['withdrawal_request_id']);
        self::assertSame(10, $pendingProofs['data']['limit']);
        self::assertSame([$proofIntent['data']['proof']['id']], array_column($pendingProofs['data']['proofs'], 'id'));
        self::assertSame(['pending_upload'], array_column($pendingProofs['data']['proofs'], 'status'));

        $proofBody = "%PDF-1.7\nroute payment receipt";
        $proofInspector->put(new StoredObjectInspection(
            $proofIntent['data']['proof']['object_key'],
            'application/pdf',
            2048,
            1,
            1,
            null,
            'sha256:' . hash('sha256', $proofBody),
            $proofBody,
        ));

        $confirmed = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm', [
            'proof_id' => $proofIntent['data']['proof']['id'],
        ], $token);
        self::assertSame('verified', $confirmed['data']['status']);

        $verifiedProofs = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=100',
            null,
            $token,
        );
        self::assertSame(['verified'], array_column($verifiedProofs['data']['proofs'], 'status'));

        $paid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid', [
            'proof_id' => $confirmed['data']['id'],
            'notes' => 'paid',
        ], $token);
        self::assertSame('approved', $paid['data']['review_status']);
        self::assertSame('paid', $paid['data']['payment_status']);
        self::assertSame($confirmed['data']['id'], $paid['data']['payment_proof_id']);

        $confirmedReplay = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm', [
            'proof_id' => $proofIntent['data']['proof']['id'],
        ], $token);
        self::assertSame('verified', $confirmedReplay['data']['status']);

        $second = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 500,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****5678'],
            'idempotency_key' => 'withdrawal:route:request:2',
        ], $token);
        $rejected = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $second['data']['id'] . '/reject?organization_id=99', [
            'notes' => 'bad account',
        ], $token);
        self::assertSame('rejected', $rejected['data']['review_status']);
        self::assertSame('not_started', $rejected['data']['payment_status']);

        $third = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 300,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****9999'],
            'idempotency_key' => 'withdrawal:route:request:3',
        ], $token);
        $revoked = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $third['data']['id'] . '/revoke?organization_id=99', [], $token);
        self::assertSame('revoked', $revoked['data']['review_status']);

        $resubmitted = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $third['data']['id'] . '/resubmit?organization_id=99', [
            'payout_account' => ['account_no' => '****0000'],
            'notes' => 'updated payout account',
        ], $token);
        self::assertSame('pending', $resubmitted['data']['review_status']);
        self::assertSame('not_started', $resubmitted['data']['payment_status']);
        self::assertSame(['account_no' => '****0000'], $resubmitted['data']['payout_account']);
        self::assertSame('updated payout account', $resubmitted['data']['applicant_notes']);
        self::assertSame('3.00', $resubmitted['data']['amount_cny']);
        self::assertSame(100, $resubmitted['data']['points_per_cny']);
        self::assertSame(1700, (new PointsLedgerRepository($connection))->balanceForOrganization(99, 'publisher_earnings'));
    }

    public function testWithdrawalProofCreateReturnsControlledConflictWhenStorageSigningFails(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp(
            $connection,
            proofSigner: new class implements ObjectStorageUploadSignerInterface {
                public function presignPut(\VertoAD\Infrastructure\Storage\PresignedUploadRequest $request): \VertoAD\Infrastructure\Storage\PresignedUpload
                {
                    throw new \RuntimeException('object_storage_unavailable');
                }
            },
        );
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-proof-conflict@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-proof-conflict@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        (new PointsLedgerService(new PointsLedgerRepository($connection)))->credit(99, 'publisher_earnings', null, 1500, 'route:withdrawal-proof-conflict');

        $withdrawal = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'idempotency_key' => 'withdrawal:route:proof-conflict',
        ], $token);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/approve', [], $token);

        $intent = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/proofs?organization_id=99',
            [
                'filename' => 'receipt.pdf',
                'content_type' => 'application/pdf',
                'byte_size' => 2048,
            ],
            $token,
        );

        self::assertSame(409, $intent['status']);
        self::assertSame('withdrawal_proof_rejected', $intent['body']['error']['code']);
        self::assertSame('object_storage_unavailable', $intent['body']['error']['message']);
    }

    public function testWithdrawalProofConfirmRejectsProofThatDoesNotBelongToWithdrawal(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-proof-mismatch@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-proof-mismatch@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        (new PointsLedgerService(new PointsLedgerRepository($connection)))->credit(99, 'publisher_earnings', null, 2500, 'route:withdrawal-proof-mismatch');

        $first = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'idempotency_key' => 'withdrawal:route:proof-mismatch:first',
        ], $token);
        $second = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****5678'],
            'idempotency_key' => 'withdrawal:route:proof-mismatch:second',
        ], $token);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $first['data']['id'] . '/approve', [], $token);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $second['data']['id'] . '/approve', [], $token);

        $proofIntent = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $first['data']['id'] . '/proofs?organization_id=99', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);

        $confirmed = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $second['data']['id'] . '/proofs/confirm?organization_id=99',
            [
                'proof_id' => $proofIntent['data']['proof']['id'],
            ],
            $token,
        );

        self::assertSame(404, $confirmed['status']);
        self::assertSame('withdrawal_proof_not_found', $confirmed['body']['error']['code']);
        self::assertSame(
            'pending_upload',
            (string) $connection->fetchOne('SELECT status FROM withdrawal_proofs WHERE id = ?', [$proofIntent['data']['proof']['id']]),
        );
    }

    public function testAdminListsWithdrawalQueueWithStatusOrganizationLimitAndSnapshotAmounts(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection, platformPermissions: ['billing.withdrawal.read.platform']);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-admin@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Withdrawal Admin',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-admin@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $ledger->credit(99, 'publisher_earnings', null, 4000, 'route:withdrawal-admin-99');
        $ledger->credit(100, 'publisher_earnings', null, 4000, 'route:withdrawal-admin-100');

        $first = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1200,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1111'],
            'idempotency_key' => 'withdrawal:route:admin:first',
        ], $token);
        $paid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 800,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****2222'],
            'idempotency_key' => 'withdrawal:route:admin:paid',
        ], $token);
        $latest = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=100', [
            'points_amount' => 2500,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****3333'],
            'idempotency_key' => 'withdrawal:route:admin:latest',
        ], $token);
        $repository = new WithdrawalRepository($connection);
        $withdrawalService = new WithdrawalService($repository, $ledger, new PointsLedgerRepository($connection));
        $withdrawalService->approve((int) $paid['data']['id'], 1, null, new DateTimeImmutable('2026-07-10 12:00:00'));
        $proof = $repository->createProof(
            (int) $paid['data']['id'],
            99,
            1,
            'withdrawals/99/' . $paid['data']['id'] . '/route-paid.pdf',
            'application/pdf',
            2048,
            new DateTimeImmutable('2026-07-10 12:01:00'),
        );
        $verifiedProof = $repository->verifyProofIfPending(
            (int) $proof->id,
            'sha256:' . hash('sha256', 'route-paid'),
            new DateTimeImmutable('2026-07-10 12:02:00'),
        );
        self::assertNotNull($verifiedProof);
        $withdrawalService->markPaid(
            (int) $paid['data']['id'],
            1,
            (int) $proof->id,
            'paid',
            new DateTimeImmutable('2026-07-10 12:03:00'),
        );

        $requestedQueue = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&review_status=pending&limit=5', null, $token);
        self::assertSame('pending', $requestedQueue['data']['review_status']);
        self::assertNull($requestedQueue['data']['payment_status']);
        self::assertSame(5, $requestedQueue['data']['limit']);
        self::assertSame([$latest['data']['id'], $first['data']['id']], array_column($requestedQueue['data']['withdrawals'], 'id'));
        self::assertSame(['25.00', '12.00'], array_column($requestedQueue['data']['withdrawals'], 'amount_cny'));
        self::assertSame([100, 100], array_column($requestedQueue['data']['withdrawals'], 'points_per_cny'));

        $organizationQueue = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&publisher_organization_id=99', null, $token);
        self::assertSame(99, $organizationQueue['data']['publisher_organization_id']);
        self::assertSame([$paid['data']['id'], $first['data']['id']], array_column($organizationQueue['data']['withdrawals'], 'id'));

        $limitedQueue = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&limit=1', null, $token);
        self::assertSame([$latest['data']['id']], array_column($limitedQueue['data']['withdrawals'], 'id'));

        $invalidReviewStatus = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&review_status=processing', null, $token);
        self::assertSame('invalid_request', $invalidReviewStatus['error']['code']);

        $invalidPaymentStatus = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&payment_status=processing', null, $token);
        self::assertSame('invalid_request', $invalidPaymentStatus['error']['code']);

        $invalidPublisherFilter = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&publisher_organization_id=0', null, $token);
        self::assertSame('invalid_request', $invalidPublisherFilter['error']['code']);

        $invalidLimit = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&limit=soon', null, $token);
        self::assertSame('invalid_request', $invalidLimit['error']['code']);

        $unboundedLimit = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals?organization_id=10&review_status=all&payment_status=all&limit=999', null, $token);
        self::assertNull($unboundedLimit['data']['review_status']);
        self::assertNull($unboundedLimit['data']['payment_status']);
        self::assertSame(200, $unboundedLimit['data']['limit']);

        $forbiddenApp = $this->createApp($connection, platformPermissions: []);
        $forbidden = $this->handleJson($forbiddenApp, 'GET', '/api/v1/billing/withdrawals?organization_id=10', null, $token);
        self::assertSame('permission_required', $forbidden['error']['code']);
        self::assertSame('billing.withdrawal.read.platform', $forbidden['error']['required_permission']);
    }

    public function testWithdrawalOwnRoutesRejectCrossOrganizationRequestIds(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-owner-scope@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-owner-scope@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $ledger->credit(99, 'publisher_earnings', null, 4000, 'route:withdrawal-scope-99');
        $ledger->credit(100, 'publisher_earnings', null, 4000, 'route:withdrawal-scope-100');

        $requested = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'idempotency_key' => 'withdrawal:route:scope:requested',
        ], $token);
        $revoked = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 500,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****5678'],
            'idempotency_key' => 'withdrawal:route:scope:revoked',
        ], $token);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $revoked['data']['id'] . '/revoke?organization_id=99', [], $token);

        $ownHistory = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/own?organization_id=99',
            null,
            $token,
        );
        self::assertSame([$revoked['data']['id'], $requested['data']['id']], array_column($ownHistory['data']['withdrawals'], 'id'));
        $otherHistory = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/own?organization_id=100',
            null,
            $token,
        );
        self::assertSame([], $otherHistory['data']['withdrawals']);

        foreach ([
            ['POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/revoke?organization_id=100', []],
            ['POST', '/api/v1/billing/withdrawals/' . $revoked['data']['id'] . '/resubmit?organization_id=100', [
                'payout_account' => ['account_no' => '****0000'],
            ]],
        ] as [$method, $uri, $payload]) {
            $response = $this->handleJsonResponse($app, $method, $uri, $payload, $token);
            self::assertSame(404, $response['status'], $method . ' ' . $uri);
            self::assertSame('withdrawal_not_found', $response['body']['error']['code'], $method . ' ' . $uri);
        }

        self::assertSame(
            'pending',
            (string) $connection->fetchOne('SELECT review_status FROM withdrawal_requests WHERE id = ?', [$requested['data']['id']]),
        );
        self::assertSame(
            'revoked',
            (string) $connection->fetchOne('SELECT review_status FROM withdrawal_requests WHERE id = ?', [$revoked['data']['id']]),
        );
    }

    public function testWithdrawalQueueActionAcceptsTypedQueryParamsFromProgrammaticRequests(): void
    {
        $connection = $this->createConnection();
        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $ledger->credit(99, 'publisher_earnings', null, 1000, 'route:withdrawal-typed-query');
        $withdrawals = new WithdrawalService(new WithdrawalRepository($connection), $ledger, new PointsLedgerRepository($connection));
        $withdrawal = $withdrawals->requestWithdrawal(
            organizationId: 99,
            requestedByUserId: 7,
            pointsAmount: 700,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_no' => '****7777'],
            notes: null,
            idempotencyKey: 'withdrawal:route:typed-query',
            now: new DateTimeImmutable('2026-06-08 12:00:00'),
        );

        $action = new WithdrawalAction(
            $withdrawals,
            new WithdrawalProofService(
                new WithdrawalRepository($connection),
                new DeterministicPresignedUploadSigner([
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'withdrawal-proofs',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ]),
                static fn (): string => 'route-proof',
            ),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/billing/withdrawals')
            ->withQueryParams([
                'review_status' => 123,
                'publisher_organization_id' => 99,
                'limit' => 1,
            ]);
        $responseFactory = new ResponseFactory();

        $badStatus = $action->list($request, $responseFactory->createResponse());
        $badStatusDecoded = json_decode((string) $badStatus->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(422, $badStatus->getStatusCode());
        self::assertSame('invalid_request', $badStatusDecoded['code']);

        $valid = $action->list(
            $request->withQueryParams([
                'publisher_organization_id' => 99,
                'limit' => 1,
            ]),
            $responseFactory->createResponse(),
        );
        $validDecoded = json_decode((string) $valid->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $valid->getStatusCode());
        self::assertSame($withdrawal->id, $validDecoded['withdrawals'][0]['id']);
        self::assertSame(1, $validDecoded['limit']);
        self::assertSame(99, $validDecoded['publisher_organization_id']);
    }

    public function testWithdrawalOwnRouteReturnsEnvelopesForMissingScopeAndInvalidFilters(): void
    {
        $app = $this->createApp($this->createConnection());
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-own-errors@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-own-errors@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $missingScope = $this->handleJsonResponse(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/own',
            null,
            $token,
            headers: ['X-Request-Id' => 'req-withdrawal-own-scope'],
        );
        self::assertSame(400, $missingScope['status']);
        $this->assertApiEnvelope($missingScope['body'], 'req-withdrawal-own-scope');
        self::assertNull($missingScope['body']['data']);
        self::assertSame('organization_scope_required', $missingScope['body']['error']['code']);

        $invalidFilter = $this->handleJsonResponse(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/own?organization_id=99&limit=soon',
            null,
            $token,
            headers: ['X-Request-Id' => 'req-withdrawal-own-filter'],
        );
        self::assertSame(422, $invalidFilter['status']);
        $this->assertApiEnvelope($invalidFilter['body'], 'req-withdrawal-own-filter');
        self::assertNull($invalidFilter['body']['data']);
        self::assertSame('invalid_request', $invalidFilter['body']['error']['code']);
        self::assertSame('limit must be a positive integer.', $invalidFilter['body']['error']['message']);
    }

    public function testWithdrawalRoutesHandleNonScalarAndTypedQueryParamsThroughHttpPipeline(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-typed-http@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-typed-http@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        (new PointsLedgerService(new PointsLedgerRepository($connection)))
            ->credit(99, 'publisher_earnings', null, 500, 'route:withdrawal-typed-http');
        $withdrawal = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 500,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****1234'],
            'idempotency_key' => 'withdrawal:route:typed-http',
        ], $token);

        $nonScalarPaymentStatus = $this->handleJsonResponse(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/own?organization_id=99&payment_status[]=pending',
            null,
            $token,
            headers: ['X-Request-Id' => 'req-withdrawal-non-scalar-payment'],
        );
        self::assertSame(422, $nonScalarPaymentStatus['status']);
        $this->assertApiEnvelope($nonScalarPaymentStatus['body'], 'req-withdrawal-non-scalar-payment');
        self::assertNull($nonScalarPaymentStatus['body']['data']);
        self::assertSame('invalid_request', $nonScalarPaymentStatus['body']['error']['code']);
        self::assertSame(
            'payment_status must be not_started, pending, paid, or all.',
            $nonScalarPaymentStatus['body']['error']['message'],
        );

        $typedProofLimitRequest = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/proofs')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('X-Request-Id', 'req-withdrawal-typed-proof-limit')
            ->withQueryParams(['limit' => 1]);
        $typedProofLimitResponse = $app->handle($typedProofLimitRequest);
        $typedProofLimit = json_decode(
            (string) $typedProofLimitResponse->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($typedProofLimit);
        self::assertSame(200, $typedProofLimitResponse->getStatusCode());
        $this->assertApiEnvelope($typedProofLimit, 'req-withdrawal-typed-proof-limit');
        self::assertNull($typedProofLimit['error']);
        self::assertSame($withdrawal['data']['id'], $typedProofLimit['data']['withdrawal_request_id']);
        self::assertSame(1, $typedProofLimit['data']['limit']);
        self::assertSame([], $typedProofLimit['data']['proofs']);
    }

    public function testWithdrawalProofRoutesMapValidationAndPersistenceFailuresToEnvelopes(): void
    {
        $connection = $this->createConnection();
        $proofInspector = new InMemoryObjectStorageInspector();
        $app = $this->createApp($connection, proofInspector: $proofInspector);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-proof-errors@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Finance Operator',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-proof-errors@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];
        (new PointsLedgerService(new PointsLedgerRepository($connection)))
            ->credit(99, 'publisher_earnings', null, 1000, 'route:withdrawal-proof-errors');
        $withdrawal = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 1000,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****5678'],
            'idempotency_key' => 'withdrawal:route:proof-errors',
        ], $token);

        $invalidType = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/proofs',
            [
                'filename' => 'receipt.png',
                'content_type' => 'application/pdf',
                'byte_size' => 2048,
            ],
            $token,
            headers: ['X-Request-Id' => 'req-withdrawal-proof-type'],
        );
        self::assertSame(422, $invalidType['status']);
        $this->assertApiEnvelope($invalidType['body'], 'req-withdrawal-proof-type');
        self::assertNull($invalidType['body']['data']);
        self::assertSame('withdrawal_proof_type_not_allowed', $invalidType['body']['error']['code']);

        $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/approve',
            [],
            $token,
        );
        $proofIntent = $this->handleJson(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/proofs',
            [
                'filename' => 'receipt.pdf',
                'content_type' => 'application/pdf',
                'byte_size' => 2048,
            ],
            $token,
        );
        $proofBody = "%PDF-1.7\npersistence failure receipt";
        $proofInspector->put(new StoredObjectInspection(
            $proofIntent['data']['proof']['object_key'],
            'application/pdf',
            2048,
            1,
            1,
            null,
            'sha256:' . hash('sha256', $proofBody),
            $proofBody,
        ));
        $connection->executeStatement(
            'CREATE TRIGGER simulate_withdrawal_proof_persistence_failure
             AFTER UPDATE OF status ON withdrawal_proofs
             WHEN NEW.status = \'verified\'
             BEGIN
                 DELETE FROM withdrawal_proofs WHERE id = NEW.id;
             END',
        );

        $persistenceFailure = $this->handleJsonResponse(
            $app,
            'POST',
            '/api/v1/billing/withdrawals/' . $withdrawal['data']['id'] . '/proofs/confirm',
            ['proof_id' => $proofIntent['data']['proof']['id']],
            $token,
            headers: ['X-Request-Id' => 'req-withdrawal-proof-persistence'],
        );
        self::assertSame(409, $persistenceFailure['status']);
        $this->assertApiEnvelope($persistenceFailure['body'], 'req-withdrawal-proof-persistence');
        self::assertNull($persistenceFailure['body']['data']);
        self::assertSame('withdrawal_proof_rejected', $persistenceFailure['body']['error']['code']);
        self::assertSame(
            'withdrawal_proof_persistence_failed',
            $persistenceFailure['body']['error']['message'],
        );
        self::assertSame(
            'pending_upload',
            (string) $connection->fetchOne(
                'SELECT status FROM withdrawal_proofs WHERE id = ?',
                [$proofIntent['data']['proof']['id']],
            ),
        );
    }

    public function testWithdrawalRoutesReturnControlledErrors(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $unauthenticated = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
        ]);
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'withdrawal-errors@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Publisher Owner',
        ]);
        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'withdrawal-errors@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $token = $login['data']['token']['access_token'];

        $wrongType = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => '100',
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
            'idempotency_key' => 'withdrawal:route:error:wrong-type',
        ], $token);
        self::assertSame('invalid_request', $wrongType['error']['code']);

        $wrongAccountType = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => 'x',
            'idempotency_key' => 'withdrawal:route:error:wrong-account',
        ], $token);
        self::assertSame('invalid_request', $wrongAccountType['error']['code']);

        $missingIdempotencyKey = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
        ], $token);
        self::assertSame('invalid_request', $missingIdempotencyKey['error']['code']);

        $insufficient = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
            'idempotency_key' => 'withdrawal:route:error:insufficient',
        ], $token);
        self::assertSame('withdrawal_rejected', $insufficient['error']['code']);

        (new PointsLedgerService(new PointsLedgerRepository($connection)))->credit(99, 'publisher_earnings', null, 100, 'route:error-earning');
        $requested = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
            'notes' => null,
            'idempotency_key' => 'withdrawal:route:error:paid',
        ], $token);
        $missingPaymentProof = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid', [
            'notes' => 'paid',
        ], $token);
        self::assertSame('invalid_request', $missingPaymentProof['error']['code']);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/approve', [], $token);

        $lateReject = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/reject?organization_id=99', [
            'notes' => 'late',
        ], $token);
        self::assertSame('withdrawal_transition_rejected', $lateReject['error']['code']);

        $badProof = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/abc/proofs?organization_id=99', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);
        self::assertSame('invalid_request', $badProof['error']['code']);

        foreach ([
            '/api/v1/billing/withdrawals/abc/proofs?organization_id=99',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99&limit=soon',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99&limit=0',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99&limit=101',
        ] as $invalidProofListUri) {
            $invalidProofList = $this->handleJson($app, 'GET', $invalidProofListUri, null, $token);
            self::assertSame('invalid_request', $invalidProofList['error']['code']);
        }

        $missingProofList = $this->handleJson($app, 'GET', '/api/v1/billing/withdrawals/999/proofs?organization_id=99', null, $token);
        self::assertSame('withdrawal_not_found', $missingProofList['error']['code']);

        $badProofBody = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99', [
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);
        self::assertSame('invalid_request', $badProofBody['error']['code']);

        $badConfirm = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm?organization_id=99', [
            'proof_id' => 'bad',
        ], $token);
        self::assertSame('invalid_request', $badConfirm['error']['code']);

        $untrustedConfirmMetadata = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm', [
            'proof_id' => 1,
            'checksum' => 'sha256:client-controlled',
            'object_key' => 'client-controlled',
        ], $token);
        self::assertSame('invalid_request', $untrustedConfirmMetadata['error']['code']);
        self::assertStringContainsString('checksum', $untrustedConfirmMetadata['error']['message']);
        self::assertStringContainsString('object_key', $untrustedConfirmMetadata['error']['message']);

        $unauthenticatedProof = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ]);
        self::assertSame('authentication_required', $unauthenticatedProof['error']['code']);

        $unauthenticatedProofList = $this->handleJson(
            $app,
            'GET',
            '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99',
        );
        self::assertSame('authentication_required', $unauthenticatedProofList['error']['code']);

        $unauthenticatedConfirm = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm?organization_id=99', [
            'proof_id' => 1,
        ]);
        self::assertSame('authentication_required', $unauthenticatedConfirm['error']['code']);

        $unauthenticatedPaid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid?organization_id=99');
        self::assertSame('authentication_required', $unauthenticatedPaid['error']['code']);

        $unauthenticatedResubmit = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/resubmit?organization_id=99', [
            'payout_account' => ['account_no' => 'x'],
        ]);
        self::assertSame('authentication_required', $unauthenticatedResubmit['error']['code']);

        $missingPaid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/999/paid', [
            'proof_id' => 1,
            'notes' => 'paid',
        ], $token);
        self::assertSame('withdrawal_not_found', $missingPaid['error']['code']);

        $badPaidId = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/abc/paid?organization_id=99', [], $token);
        self::assertSame('invalid_request', $badPaidId['error']['code']);

        $badResubmitId = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/abc/resubmit?organization_id=99', [
            'payout_account' => ['account_no' => 'x'],
        ], $token);
        self::assertSame('invalid_request', $badResubmitId['error']['code']);

        $badResubmitBody = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/resubmit?organization_id=99', [
            'notes' => 'missing account',
        ], $token);
        self::assertSame('invalid_request', $badResubmitBody['error']['code']);

        $invalidResubmitState = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/resubmit?organization_id=99', [
            'payout_account' => ['account_no' => 'x'],
        ], $token);
        self::assertSame('withdrawal_transition_rejected', $invalidResubmitState['error']['code']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?string $bearerToken = null,
        array $serverParams = [],
        array $headers = [],
    ): array {
        return $this->handleJsonResponse($app, $method, $uri, $payload, $bearerToken, $serverParams, $headers)['body'];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function handleJsonResponse(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?string $bearerToken = null,
        array $serverParams = [],
        array $headers = [],
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $serverParams);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string) $name, (string) $value);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    /** @param array<string, mixed> $body */
    private function assertApiEnvelope(array $body, string $requestId): void
    {
        self::assertSame(['data', 'error', 'meta', 'request_id'], array_keys($body));
        self::assertSame(['api_version' => 'v1'], $body['meta']);
        self::assertSame($requestId, $body['request_id']);
    }

    private function createApp(
        Connection $connection,
        ?Closure $plaintextGenerator = null,
        bool $wireRechargeKeyAudit = true,
        ?array $platformPermissions = null,
        ?ObjectStorageUploadSignerInterface $proofSigner = null,
        ?ObjectStorageInspectorInterface $proofInspector = null,
    ): \Slim\App
    {
        $appKey = $this->appKey;
        $ipResolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/24'],
        ]);

        $container = (new ContainerBuilder())->addDefinitions([
            Connection::class => $connection,
            PasswordHasher::class => static fn (): PasswordHasher => new PasswordHasher(),
            PasswordResetTokenRepositoryInterface::class => static fn (): PasswordResetTokenRepositoryInterface =>
                new PasswordResetTokenRepository($connection),
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new FirstPartySessionRepository($connection),
            AuthService::class => static fn (
                Connection $db,
                PasswordHasher $hasher,
                PasswordResetTokenRepositoryInterface $resetTokens,
                FirstPartySessionRepositoryInterface $sessions,
            ): AuthService => new AuthService($db, $hasher, $resetTokens, $sessions, static fn (): string => 'billing-token'),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            ClientIpResolver::class => static fn (): ClientIpResolver => $ipResolver,
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new BillingPermissionMembershipRepository($platformPermissions ?? []),
            PermissionMatcher::class => static fn (): PermissionMatcher => new PermissionMatcher(),
            TenantAccessService::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
                PermissionMatcher $permissions,
            ): TenantAccessService => new TenantAccessService($memberships, $permissions),
            PointsLedgerRepositoryInterface::class => static fn (): PointsLedgerRepositoryInterface =>
                new PointsLedgerRepository($connection),
            PointsLedgerService::class => static fn (PointsLedgerRepositoryInterface $repository): PointsLedgerService =>
                new PointsLedgerService($repository),
            RechargeKeyPlaintextCipherInterface::class => static fn (): RechargeKeyPlaintextCipherInterface =>
                new DefuseRechargeKeyPlaintextCipher($appKey),
            RechargeKeyRepositoryInterface::class => static fn (): RechargeKeyRepositoryInterface =>
                new RechargeKeyRepository($connection),
            RechargeKeyService::class => static fn (
                RechargeKeyRepositoryInterface $repository,
                PointsLedgerService $ledger,
                RechargeKeyPlaintextCipherInterface $cipher,
                AuditLogService $audit,
            ): RechargeKeyService => new RechargeKeyService(
                $repository,
                $ledger,
                $cipher,
                plaintextGenerator: $plaintextGenerator,
                audit: $wireRechargeKeyAudit ? $audit : null,
            ),
            AuditLogRepositoryInterface::class => static fn (): AuditLogRepositoryInterface =>
                new AuditLogRepository($connection),
            AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                new AuditLogService($repository),
            WithdrawalRepository::class => static fn (): WithdrawalRepository => new WithdrawalRepository($connection),
            WithdrawalService::class => static fn (
                WithdrawalRepository $repository,
                PointsLedgerService $ledger,
                PointsLedgerRepositoryInterface $ledgerRepository,
            ): WithdrawalService => new WithdrawalService($repository, $ledger, $ledgerRepository),
            ObjectStorageUploadSignerInterface::class => static fn (): ObjectStorageUploadSignerInterface =>
                $proofSigner ?? new DeterministicPresignedUploadSigner([
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'withdrawal-proofs',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ]),
            ObjectStorageInspectorInterface::class => static fn (): ObjectStorageInspectorInterface =>
                $proofInspector ?? new InMemoryObjectStorageInspector(),
            WithdrawalProofService::class => static fn (
                WithdrawalRepository $repository,
                ObjectStorageUploadSignerInterface $signer,
                ObjectStorageInspectorInterface $inspector,
            ): WithdrawalProofService => new WithdrawalProofService($repository, $signer, static fn (): string => 'route-proof', $inspector),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $platformPermission = static fn (string $permission): RequirePermissionMiddleware => new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forPlatform($permission),
        );
        $app->post('/api/v1/auth/register', RegisterAction::class);
        $app->post('/api/v1/auth/login', LoginAction::class);
        $app->get('/api/v1/billing/balance', BillingBalanceAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/billing/ledger', BillingLedgerListAction::class)->add(AuthenticateRequestMiddleware::class);
        $adjustmentRoute = $app->post('/api/v1/billing/ledger/adjustments', [LedgerAdjustmentAction::class, 'createAdjustment']);
        if ($platformPermissions !== null) {
            $adjustmentRoute->add($platformPermission('billing.ledger.adjust.platform'));
        }
        $adjustmentRoute->add(AuthenticateRequestMiddleware::class);
        $reversalRoute = $app->post('/api/v1/billing/ledger/{entry_id}/reversals', [LedgerAdjustmentAction::class, 'reverseEntry']);
        if ($platformPermissions !== null) {
            $reversalRoute->add($platformPermission('billing.ledger.adjust.platform'));
        }
        $reversalRoute->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/recharge-keys/redeem', RechargeKeyRedeemAction::class)->add(AuthenticateRequestMiddleware::class);
        $generateRoute = $app->post('/api/v1/billing/recharge-keys/generate', GenerateRechargeKeyBatchAction::class);
        if ($platformPermissions !== null) {
            $generateRoute->add($platformPermission('billing.recharge_key.generate.platform'));
        }
        $generateRoute->add(AuthenticateRequestMiddleware::class);
        $revealRoute = $app->post('/api/v1/billing/recharge-keys/{key_id}/reveal', RevealRechargeKeyPlaintextAction::class);
        if ($platformPermissions !== null) {
            $revealRoute->add($platformPermission('billing.recharge_key.view_plaintext.platform'));
        }
        $revealRoute->add(AuthenticateRequestMiddleware::class);
        $withdrawalListRoute = $app->get('/api/v1/billing/withdrawals', [WithdrawalAction::class, 'list']);
        if ($platformPermissions !== null) {
            $withdrawalListRoute->add($platformPermission('billing.withdrawal.read.platform'));
        }
        $withdrawalListRoute->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/billing/withdrawals/own', [WithdrawalAction::class, 'listOwn'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals', [WithdrawalAction::class, 'request'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/paid', [WithdrawalAction::class, 'markPaid'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/approve', [WithdrawalAction::class, 'approve'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/reject', [WithdrawalAction::class, 'reject'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/revoke', [WithdrawalAction::class, 'revoke'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/resubmit', [WithdrawalAction::class, 'resubmit'])->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/billing/withdrawals/{withdrawal_id}/proofs', [WithdrawalAction::class, 'listProofs'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/proofs', [WithdrawalAction::class, 'createProofIntent'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/proofs/confirm', [WithdrawalAction::class, 'confirmProof'])->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function issueRechargeKey(Connection $connection, string $plaintext, int $points): void
    {
        $service = new RechargeKeyService(
            new RechargeKeyRepository($connection),
            new PointsLedgerService(new PointsLedgerRepository($connection)),
            new DefuseRechargeKeyPlaintextCipher($this->appKey),
        );
        $service->issue($plaintext, $points, 'route-test', null, new DateTimeImmutable('2026-12-31 00:00:00'), 1, null);
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
                last_login_at TEXT NULL
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
                used_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE first_party_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                account_type TEXT NOT NULL,
                account_id INTEGER NULL,
                points_amount INTEGER NOT NULL,
                direction TEXT NOT NULL,
                balance_after_points INTEGER NULL,
                reference_type TEXT NULL,
                reference_id INTEGER NULL,
                idempotency_key TEXT NOT NULL UNIQUE,
                memo TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ledger_account_balances (
                organization_id INTEGER NOT NULL,
                account_type TEXT NOT NULL,
                balance_points INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (organization_id, account_type)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE recharge_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NULL,
                key_hash TEXT NOT NULL UNIQUE,
                encrypted_plaintext_key TEXT NOT NULL,
                points_amount INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "issued",
                batch_code TEXT NULL,
                batch_metadata_json TEXT NULL,
                issued_by_user_id INTEGER NULL,
                redeemed_by_user_id INTEGER NULL,
                redeemed_ledger_entry_id INTEGER NULL,
                expires_at TEXT NULL,
                redeemed_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id INTEGER NULL,
                actor_user_id INTEGER NULL,
                organization_id INTEGER NULL,
                ip_address BLOB NULL,
                user_agent TEXT NULL,
                request_id TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE withdrawal_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                requested_by_user_id INTEGER NOT NULL,
                points_amount INTEGER NOT NULL,
                amount_cny TEXT NOT NULL,
                points_per_cny INTEGER NOT NULL,
                idempotency_key VARCHAR(160) NOT NULL,
                review_status TEXT NOT NULL,
                payment_status TEXT NOT NULL,
                payout_method TEXT NOT NULL,
                payout_account_json TEXT NOT NULL,
                applicant_notes TEXT NULL,
                reviewer_user_id INTEGER NULL,
                reviewer_notes TEXT NULL,
                payment_proof_id INTEGER NULL,
                payment_completed_by_user_id INTEGER NULL,
                payment_notes TEXT NULL,
                ledger_entry_id INTEGER NOT NULL,
                requested_at TEXT NOT NULL,
                reviewed_at TEXT NULL,
                approved_at TEXT NULL,
                paid_at TEXT NULL,
                rejected_at TEXT NULL,
                revoked_at TEXT NULL,
                resubmitted_at TEXT NULL,
                UNIQUE (organization_id, idempotency_key),
                UNIQUE (ledger_entry_id),
                FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE RESTRICT,
                CHECK (points_amount > 0),
                CHECK (review_status IN (\'pending\', \'approved\', \'rejected\', \'revoked\')),
                CHECK (payment_status IN (\'not_started\', \'pending\', \'paid\'))
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE withdrawal_proofs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                withdrawal_request_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                uploaded_by_user_id INTEGER NOT NULL,
                object_key TEXT NOT NULL UNIQUE,
                content_type TEXT NOT NULL,
                byte_size INTEGER NOT NULL,
                checksum TEXT NULL,
                status TEXT NOT NULL,
                verification_error_code TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verification_attempted_at TEXT NULL,
                verified_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE withdrawal_audit_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                withdrawal_request_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                actor_user_id INTEGER NULL,
                action TEXT NOT NULL,
                from_review_status TEXT NULL,
                to_review_status TEXT NOT NULL,
                from_payment_status TEXT NULL,
                to_payment_status TEXT NOT NULL,
                proof_id INTEGER NULL,
                notes TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        return $connection;
    }
}

final class BillingPermissionMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(private readonly array $permissions)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        if ($userId !== 1 || $organizationId !== 10) {
            return null;
        }

        return new OrganizationMembership(
            organizationId: 10,
            userId: 1,
            status: 'active',
            roleSlugs: ['billing-admin'],
            permissions: $this->permissions,
        );
    }

    public function listActiveOrganizationsForUser(int $userId): array
    {
        return [];
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}

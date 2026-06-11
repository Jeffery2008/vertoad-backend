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
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
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

        $auditRows = $connection->fetchAllAssociative('SELECT action, subject_type, subject_id, actor_user_id, organization_id, ip_address, user_agent, metadata_json FROM audit_logs ORDER BY id ASC');
        self::assertCount(2, $auditRows);
        self::assertSame('billing.ledger.adjust', $auditRows[0]['action']);
        self::assertSame('ledger_entry', $auditRows[0]['subject_type']);
        self::assertSame((int) $adjustment['data']['ledger_entry']['id'], (int) $auditRows[0]['subject_id']);
        self::assertSame(1, (int) $auditRows[0]['actor_user_id']);
        self::assertSame(10, (int) $auditRows[0]['organization_id']);
        self::assertSame(inet_pton('203.0.113.88'), $auditRows[0]['ip_address']);
        self::assertSame('LedgerAdmin/1.0', $auditRows[0]['user_agent']);
        self::assertStringContainsString('compensate failed recharge import', (string) $auditRows[0]['metadata_json']);

        self::assertSame('billing.ledger.reverse', $auditRows[1]['action']);
        self::assertSame('ledger_entry', $auditRows[1]['subject_type']);
        self::assertSame((int) $reversal['data']['ledger_entry']['id'], (int) $auditRows[1]['subject_id']);
        self::assertSame(1, (int) $auditRows[1]['actor_user_id']);
        self::assertSame(10, (int) $auditRows[1]['organization_id']);
        self::assertSame(inet_pton('203.0.113.89'), $auditRows[1]['ip_address']);
        self::assertSame('LedgerAdmin/1.0', $auditRows[1]['user_agent']);
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
        $app = $this->createApp($connection);
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
        ], $token);

        self::assertSame('requested', $requested['data']['status']);
        self::assertSame(1000, $requested['data']['points_amount']);

        $proofIntent = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);
        self::assertSame('pending_upload', $proofIntent['data']['proof']['status']);
        self::assertSame('PUT', $proofIntent['data']['upload']['method']);

        $confirmed = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm?organization_id=99', [
            'proof_id' => $proofIntent['data']['proof']['id'],
            'object_key' => $proofIntent['data']['proof']['object_key'],
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
            'checksum' => 'sha256:route',
        ], $token);
        self::assertSame('confirmed', $confirmed['data']['status']);

        $paid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid?organization_id=99', [
            'notes' => 'paid',
        ], $token);
        self::assertSame('paid', $paid['data']['status']);

        $second = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 500,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****5678'],
        ], $token);
        $rejected = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $second['data']['id'] . '/reject?organization_id=99', [
            'notes' => 'bad account',
        ], $token);
        self::assertSame('rejected', $rejected['data']['status']);

        $third = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 300,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => '****9999'],
        ], $token);
        $revoked = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $third['data']['id'] . '/revoke?organization_id=99', [], $token);
        self::assertSame('revoked', $revoked['data']['status']);
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
        ], $token);
        self::assertSame('invalid_request', $wrongType['error']['code']);

        $wrongAccountType = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => 'x',
        ], $token);
        self::assertSame('invalid_request', $wrongAccountType['error']['code']);

        $insufficient = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
        ], $token);
        self::assertSame('withdrawal_rejected', $insufficient['error']['code']);

        (new PointsLedgerService(new PointsLedgerRepository($connection)))->credit(99, 'publisher_earnings', null, 100, 'route:error-earning');
        $requested = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals?organization_id=99', [
            'points_amount' => 100,
            'payout_method' => 'bank_transfer',
            'payout_account' => ['account_no' => 'x'],
            'notes' => null,
        ], $token);
        $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid?organization_id=99', [], $token);

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

        $badProofBody = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99', [
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ], $token);
        self::assertSame('invalid_request', $badProofBody['error']['code']);

        $badConfirm = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm?organization_id=99', [
            'proof_id' => 'bad',
            'object_key' => 'x',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
            'checksum' => 'sha256:x',
        ], $token);
        self::assertSame('invalid_request', $badConfirm['error']['code']);

        $unauthenticatedProof = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs?organization_id=99', [
            'filename' => 'receipt.pdf',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
        ]);
        self::assertSame('authentication_required', $unauthenticatedProof['error']['code']);

        $unauthenticatedConfirm = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/proofs/confirm?organization_id=99', [
            'proof_id' => 1,
            'object_key' => 'x',
            'content_type' => 'application/pdf',
            'byte_size' => 2048,
            'checksum' => 'sha256:x',
        ]);
        self::assertSame('authentication_required', $unauthenticatedConfirm['error']['code']);

        $unauthenticatedPaid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/' . $requested['data']['id'] . '/paid?organization_id=99');
        self::assertSame('authentication_required', $unauthenticatedPaid['error']['code']);

        $missingPaid = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/999/paid?organization_id=99', [], $token);
        self::assertSame('withdrawal_transition_rejected', $missingPaid['error']['code']);

        $badPaidId = $this->handleJson($app, 'POST', '/api/v1/billing/withdrawals/abc/paid?organization_id=99', [], $token);
        self::assertSame('invalid_request', $badPaidId['error']['code']);
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

    private function createApp(
        Connection $connection,
        ?Closure $plaintextGenerator = null,
        bool $wireRechargeKeyAudit = true,
        ?array $platformPermissions = null,
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
                new DeterministicPresignedUploadSigner([
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'withdrawal-proofs',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ]),
            WithdrawalProofService::class => static fn (
                WithdrawalRepository $repository,
                ObjectStorageUploadSignerInterface $signer,
            ): WithdrawalProofService => new WithdrawalProofService($repository, $signer, static fn (): string => 'route-proof'),
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
        $app->post('/api/v1/billing/withdrawals', [WithdrawalAction::class, 'request'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/paid', [WithdrawalAction::class, 'markPaid'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/reject', [WithdrawalAction::class, 'reject'])->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/revoke', [WithdrawalAction::class, 'revoke'])->add(AuthenticateRequestMiddleware::class);
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
                status TEXT NOT NULL,
                payout_method TEXT NOT NULL,
                payout_account_json TEXT NOT NULL,
                applicant_notes TEXT NULL,
                reviewer_user_id INTEGER NULL,
                reviewer_notes TEXT NULL,
                ledger_entry_id INTEGER NULL,
                requested_at TEXT NOT NULL,
                reviewed_at TEXT NULL,
                paid_at TEXT NULL,
                rejected_at TEXT NULL,
                revoked_at TEXT NULL,
                resubmitted_at TEXT NULL
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
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE withdrawal_audit_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                withdrawal_request_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                actor_user_id INTEGER NULL,
                action TEXT NOT NULL,
                from_status TEXT NULL,
                to_status TEXT NOT NULL,
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

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}

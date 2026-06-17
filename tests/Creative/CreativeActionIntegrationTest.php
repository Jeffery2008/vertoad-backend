<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Action\Creative\CreateCreativeDesignAction;
use VertoAD\Http\Action\Creative\CreateCreativeDesignVersionAction;
use VertoAD\Http\Action\Creative\CreateCreativeTemplateAction;
use VertoAD\Http\Action\Creative\ListCreativeDesignVersionsAction;
use VertoAD\Http\Action\Creative\ListCreativeTemplatesAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\CreativeTemplateWritePermissionMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Creative\DatabaseCreativeDesignRepository;
use VertoAD\Repository\Creative\DatabaseCreativeTemplateRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Creative\CreativeDesignService;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class CreativeActionIntegrationTest extends TestCase
{
    public function testCreativeRoutesReturnEnvelopesAuditAndHiddenCrossOrganizationResults(): void
    {
        $auditRepository = new CreativeActionAuditRepository();
        $app = $this->createApp($this->connection(), $auditRepository);
        $platformStaff = new RequestUserContext(new AuthenticatedUser(1, 'platform@example.com', false), 101);
        $member = new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101);
        $otherMember = new RequestUserContext(new AuthenticatedUser(8, 'other@example.com', false), 202);

        $platformTemplate = $this->handle($app, 'POST', '/api/v1/creative/templates', [
            'scope' => 'platform',
            'name' => 'Platform Starter',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => 'https://assets.example.test/platform.webp',
            'tags' => ['platform'],
        ], $platformStaff, 'req-platform-template');

        self::assertSame(201, $platformTemplate['status']);
        self::assertSame('req-platform-template', $platformTemplate['body']['request_id']);
        self::assertNull($platformTemplate['body']['error']);
        self::assertSame('platform', $platformTemplate['body']['data']['scope']);
        self::assertSame('Platform Starter', $platformTemplate['body']['data']['name']);

        $organizationTemplate = $this->handle($app, 'POST', '/api/v1/creative/templates', [
            'scope' => 'organization',
            'organization_id' => 101,
            'name' => 'Org 101 Starter',
            'width' => 728,
            'height' => 90,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'tags' => ['org-101'],
        ], $member, 'req-org-template');

        self::assertSame(201, $organizationTemplate['status']);
        self::assertSame('req-org-template', $organizationTemplate['body']['request_id']);

        $listAll = $this->handle($app, 'GET', '/api/v1/creative/templates?organization_id=101&scope=all', context: $member);
        self::assertSame(200, $listAll['status']);
        self::assertSame('platform', $listAll['body']['data']['templates'][0]['scope']);
        self::assertSame('Org 101 Starter', $listAll['body']['data']['templates'][1]['name']);

        $design = $this->handle($app, 'POST', '/api/v1/creative/designs', [
            'organization_id' => 101,
            'template_id' => $organizationTemplate['body']['data']['template_id'],
            'name' => 'Summer Launch',
            'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'type' => 'textbox']]],
            'snapshot_url' => 'https://assets.example.test/designs/v1.webp',
        ], $member, 'req-design-create');
        self::assertSame(201, $design['status']);
        self::assertSame('req-design-create', $design['body']['request_id']);
        self::assertSame(1, $design['body']['data']['design']['current_version']);
        self::assertSame(1, $design['body']['data']['data_version']['version_number'] ?? 1);

        $versions = $this->handle($app, 'GET', '/api/v1/creative/designs/' . $design['body']['data']['design']['design_id'] . '/versions?organization_id=101', context: $member);
        self::assertSame(200, $versions['status']);
        self::assertSame([1], array_column($versions['body']['data']['versions'], 'version_number'));

        $version2 = $this->handle($app, 'POST', '/api/v1/creative/designs/' . $design['body']['data']['design']['design_id'] . '/versions?organization_id=101', [
            'fabric_json' => ['version' => '5.3.0', 'objects' => [['id' => 'headline', 'type' => 'textbox', 'text' => 'Updated']]],
            'snapshot_url' => 'https://assets.example.test/designs/v2.webp',
            'change_summary' => 'Autosave update',
        ], $member, 'req-version-create');
        self::assertSame(201, $version2['status']);
        self::assertSame('req-version-create', $version2['body']['request_id']);
        self::assertSame(2, $version2['body']['data']['version_number']);

        $forbiddenPlatform = $this->handle($app, 'POST', '/api/v1/creative/templates', [
            'scope' => 'platform',
            'name' => 'Bad attempt',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'tags' => [],
        ], $member);
        self::assertSame(403, $forbiddenPlatform['status']);
        self::assertSame('permission_required', $forbiddenPlatform['body']['error']['code']);

        $mismatchedDesign = $this->handle($app, 'POST', '/api/v1/creative/designs', [
            'organization_id' => 202,
            'name' => 'Wrong org',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
            'change_summary' => 'Wrong org attempt',
        ], $member);
        self::assertSame(403, $mismatchedDesign['status']);
        self::assertSame('organization_scope_mismatch', $mismatchedDesign['body']['error']['code']);

        $mismatchedVersion = $this->handle($app, 'POST', '/api/v1/creative/designs/' . $design['body']['data']['design']['design_id'] . '/versions?organization_id=101', [
            'organization_id' => 202,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
            'change_summary' => 'Wrong org attempt',
        ], $member);
        self::assertSame(403, $mismatchedVersion['status']);
        self::assertSame('organization_scope_mismatch', $mismatchedVersion['body']['error']['code']);

        $missingScope = $this->handle($app, 'GET', '/api/v1/creative/templates', context: new RequestUserContext(new AuthenticatedUser(9, 'no-org@example.com', false), null));
        self::assertSame(400, $missingScope['status']);
        self::assertSame('organization_scope_required', $missingScope['body']['error']['code']);

        $crossOrgVersions = $this->handle($app, 'GET', '/api/v1/creative/designs/' . $design['body']['data']['design']['design_id'] . '/versions?organization_id=202', context: $otherMember);
        self::assertSame(404, $crossOrgVersions['status']);
        self::assertSame('not_found', $crossOrgVersions['body']['error']['code']);

        self::assertSame('creative.template.created', $auditRepository->entries[0]->action ?? null);
        self::assertSame('creative.template.created', $auditRepository->entries[1]->action ?? null);
        self::assertSame('creative.design.created', $auditRepository->entries[2]->action ?? null);
        self::assertSame('creative.design.version_created', $auditRepository->entries[3]->action ?? null);
        self::assertSame('req-version-create', $auditRepository->entries[3]->requestId);
    }

    public function testCreativeRoutesRejectInvalidBodiesWithValidationEnvelope(): void
    {
        $app = $this->createApp($this->connection(), new CreativeActionAuditRepository());
        $member = new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101);

        $invalidTemplate = $this->handle($app, 'POST', '/api/v1/creative/templates', [
            'scope' => 'organization',
            'organization_id' => 101,
            'name' => '',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0'],
            'tags' => [],
        ], $member);
        self::assertSame(422, $invalidTemplate['status']);
        self::assertSame('invalid_request', $invalidTemplate['body']['error']['code']);

        $invalidDesign = $this->handle($app, 'POST', '/api/v1/creative/designs', [
            'organization_id' => 101,
            'name' => 'Bad design',
            'fabric_json' => 'bad',
        ], $member);
        self::assertSame(422, $invalidDesign['status']);
        self::assertSame('invalid_request', $invalidDesign['body']['error']['code']);
    }

    public function testCreativeRoutesReturnEnvelopeForAuthenticationAndScopeErrors(): void
    {
        $app = $this->createApp($this->connection(), new CreativeActionAuditRepository());
        $member = new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101);

        $unauthenticatedTemplates = $this->handle($app, 'GET', '/api/v1/creative/templates?organization_id=101', context: null);
        self::assertSame(401, $unauthenticatedTemplates['status']);
        self::assertSame('authentication_required', $unauthenticatedTemplates['body']['error']['code']);
        self::assertNull($unauthenticatedTemplates['body']['data']);
        self::assertSame('v1', $unauthenticatedTemplates['body']['meta']['api_version'] ?? null);

        $missingListScope = $this->handle($app, 'GET', '/api/v1/creative/templates', context: $member);
        self::assertSame(400, $missingListScope['status']);
        self::assertSame('organization_scope_required', $missingListScope['body']['error']['code']);

        $missingDesignScope = $this->handle($app, 'POST', '/api/v1/creative/designs', [
            'name' => 'Missing org',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], $member);
        self::assertSame(400, $missingDesignScope['status']);
        self::assertSame('organization_scope_required', $missingDesignScope['body']['error']['code']);

        $queryBodyMismatch = $this->handle($app, 'POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', [
            'organization_id' => 202,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], $member);
        self::assertSame(403, $queryBodyMismatch['status']);
        self::assertSame('organization_scope_mismatch', $queryBodyMismatch['body']['error']['code']);

        $unauthenticatedDesignCreate = $this->handle($app, 'POST', '/api/v1/creative/designs', [
            'organization_id' => 101,
            'name' => 'No auth',
            'width' => 300,
            'height' => 250,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], context: null);
        self::assertSame(401, $unauthenticatedDesignCreate['status']);
        self::assertSame('authentication_required', $unauthenticatedDesignCreate['body']['error']['code']);

        $nonObjectDesignCreate = $this->handle($app, 'POST', '/api/v1/creative/designs', payload: null, context: $member);
        self::assertSame(422, $nonObjectDesignCreate['status']);
        self::assertSame('invalid_request', $nonObjectDesignCreate['body']['error']['code']);

        $unauthenticatedVersionCreate = $this->handle($app, 'POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', [
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], context: null);
        self::assertSame(401, $unauthenticatedVersionCreate['status']);
        self::assertSame('authentication_required', $unauthenticatedVersionCreate['body']['error']['code']);

        $nonObjectVersionCreate = $this->handle($app, 'POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', payload: null, context: $member);
        self::assertSame(422, $nonObjectVersionCreate['status']);
        self::assertSame('invalid_request', $nonObjectVersionCreate['body']['error']['code']);
    }

    public function testCreativeVersionRoutesHideMissingDesignsAndValidateArguments(): void
    {
        $app = $this->createApp($this->connection(), new CreativeActionAuditRepository());
        $member = new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101);

        $missingVersions = $this->handle($app, 'GET', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', context: $member);
        self::assertSame(404, $missingVersions['status']);
        self::assertSame('not_found', $missingVersions['body']['error']['code']);

        $missingOrganization = $this->handle($app, 'GET', '/api/v1/creative/designs/dsn_missing/versions', context: $member);
        self::assertSame(400, $missingOrganization['status']);
        self::assertSame('organization_scope_required', $missingOrganization['body']['error']['code']);

        $missingCreatedVersion = $this->handle($app, 'POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', [
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
            'snapshot_url' => null,
        ], $member);
        self::assertSame(404, $missingCreatedVersion['status']);
        self::assertSame('not_found', $missingCreatedVersion['body']['error']['code']);

        $invalidScope = $this->handle($app, 'GET', '/api/v1/creative/templates?organization_id=101&scope=partner', context: $member);
        self::assertSame(422, $invalidScope['status']);
        self::assertSame('invalid_request', $invalidScope['body']['error']['code']);

        $unauthenticatedVersionList = $this->handle($app, 'GET', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', context: null);
        self::assertSame(401, $unauthenticatedVersionList['status']);
        self::assertSame('authentication_required', $unauthenticatedVersionList['body']['error']['code']);

        $invalidDesignId = $this->handle($app, 'GET', '/api/v1/creative/designs/%20/versions?organization_id=101', context: $member);
        self::assertSame(422, $invalidDesignId['status']);
        self::assertSame('invalid_request', $invalidDesignId['body']['error']['code']);

        $bodyOnlyOrganization = $this->handle($app, 'POST', '/api/v1/creative/designs/dsn_missing/versions', [
            'organization_id' => 101,
            'fabric_json' => ['version' => '5.3.0', 'objects' => []],
        ], $member);
        self::assertSame(404, $bodyOnlyOrganization['status']);
        self::assertSame('not_found', $bodyOnlyOrganization['body']['error']['code']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    private function handle(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?RequestUserContext $context = null,
        string $requestId = 'req-creative',
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withHeader('X-Request-Id', $requestId);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($context !== null) {
            $request = $request->withAttribute(RequestUserContext::ATTRIBUTE, $context);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    private function createApp(Connection $connection, CreativeActionAuditRepository $auditRepository): \Slim\App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            DatabaseCreativeTemplateRepository::class => static fn (): DatabaseCreativeTemplateRepository =>
                new DatabaseCreativeTemplateRepository($connection),
            DatabaseCreativeDesignRepository::class => static fn (): DatabaseCreativeDesignRepository =>
                new DatabaseCreativeDesignRepository($connection),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new CreativeActionMembershipRepository(),
            TenantAccessService::class => static fn (OrganizationMembershipRepositoryInterface $memberships): TenantAccessService =>
                new TenantAccessService($memberships, new PermissionMatcher()),
            CreativeTemplateWritePermissionMiddleware::class => static fn (TenantAccessService $tenantAccess): CreativeTemplateWritePermissionMiddleware =>
                new CreativeTemplateWritePermissionMiddleware(SlimAppFactory::determineResponseFactory(), $tenantAccess),
            'creative.permission.write' => static fn (TenantAccessService $tenantAccess): RequirePermissionMiddleware =>
                new RequirePermissionMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $tenantAccess,
                    PermissionRequirement::forOrganization('creative.design.write.own'),
                ),
            'creative.permission.read' => static fn (TenantAccessService $tenantAccess): RequirePermissionMiddleware =>
                new RequirePermissionMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $tenantAccess,
                    PermissionRequirement::forOrganization('creative.design.read.own'),
                ),
            AuditLogRepositoryInterface::class => static fn (): AuditLogRepositoryInterface => $auditRepository,
            AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                new AuditLogService($repository),
            CreativeDesignService::class => static fn (
                DatabaseCreativeTemplateRepository $templates,
                DatabaseCreativeDesignRepository $designs,
                AuditLogService $audit,
            ): CreativeDesignService => new CreativeDesignService($templates, $designs, $audit, static fn (string $prefix): string => $prefix . '_' . bin2hex(random_bytes(4))),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/creative/templates', ListCreativeTemplatesAction::class);
        $app->post('/api/v1/creative/templates', CreateCreativeTemplateAction::class)
            ->add(CreativeTemplateWritePermissionMiddleware::class);
        $app->post('/api/v1/creative/designs', CreateCreativeDesignAction::class)
            ->add($container->get('creative.permission.write'));
        $app->get('/api/v1/creative/designs/{design_id}/versions', ListCreativeDesignVersionsAction::class)
            ->add($container->get('creative.permission.read'));
        $app->post('/api/v1/creative/designs/{design_id}/versions', CreateCreativeDesignVersionAction::class)
            ->add($container->get('creative.permission.write'));
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CreativeSchema::create($connection);

        return $connection;
    }
}

final class CreativeActionAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class CreativeActionMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        $memberships = [
            '1:101' => new OrganizationMembership(101, 1, 'active', ['platform-creative'], ['creative.template.manage.platform']),
            '7:101' => new OrganizationMembership(101, 7, 'active', ['creative-owner'], [
                'creative.template.write.own',
                'creative.design.read.own',
                'creative.design.write.own',
            ]),
            '8:202' => new OrganizationMembership(202, 8, 'active', ['creative-owner'], [
                'creative.template.write.own',
                'creative.design.read.own',
                'creative.design.write.own',
            ]),
        ];

        return $memberships[$userId . ':' . $organizationId] ?? null;
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}

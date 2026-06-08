<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\FeatureFlags\CreateFeatureFlagAction;
use VertoAD\Http\Action\FeatureFlags\EvaluateFeatureFlagAction;
use VertoAD\Http\Action\FeatureFlags\ListFeatureFlagsAction;
use VertoAD\Http\Action\Support\AddSupportTicketNoteAction;
use VertoAD\Http\Action\Support\CreateSupportTicketAction;
use VertoAD\Http\Action\Support\ListSupportTicketsAction;
use VertoAD\Http\Action\Support\UpdateSupportTicketStatusAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\FeatureFlags\FeatureFlagRepositoryInterface;
use VertoAD\Repository\FeatureFlags\InMemoryFeatureFlagRepository;
use VertoAD\Repository\Support\InMemorySupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\FeatureFlags\FeatureFlagService;
use VertoAD\Service\Support\SupportTicketService;

final class SupportFeatureFlagsActionIntegrationTest extends TestCase
{
    public function testSupportTicketRoutesReturnEnvelopesAndWriteAuditForStatusAndInternalNotes(): void
    {
        $auditRepository = new Task29ActionAuditRepository();
        $app = $this->createApp($auditRepository);
        $member = new RequestUserContext(new AuthenticatedUser(501, 'member@example.com', false), 101);
        $admin = new RequestUserContext(new AuthenticatedUser(700, 'admin@example.com', true), null);

        $created = $this->handle($app, 'POST', '/api/v1/support/tickets', [
            'subject' => 'Publisher payout question',
            'description' => 'Please check my latest withdrawal.',
            'priority' => 'high',
            'linked_entity' => ['type' => 'withdrawal', 'id' => 3001],
            'attachments' => [
                ['filename' => 'proof.png', 'object_key' => 'support/101/proof.png'],
            ],
        ], $member, 'req-support-create');

        self::assertSame(201, $created['status']);
        self::assertSame('req-support-create', $created['body']['request_id']);
        self::assertSame('v1', $created['body']['meta']['api_version']);
        self::assertNull($created['body']['error']);
        self::assertSame(101, $created['body']['data']['organization_id']);
        self::assertSame(501, $created['body']['data']['created_by_user_id']);
        self::assertSame('open', $created['body']['data']['status']);

        $ticketId = (string) $created['body']['data']['ticket_id'];
        $listedForMember = $this->handle($app, 'GET', '/api/v1/support/tickets', context: $member);
        self::assertSame($ticketId, $listedForMember['body']['data']['tickets'][0]['ticket_id']);

        $forbiddenNote = $this->handle($app, 'POST', '/api/v1/support/tickets/' . $ticketId . '/notes', [
            'role' => 'member',
            'body' => 'Hidden handling detail.',
        ], $member);
        self::assertSame(403, $forbiddenNote['status']);
        self::assertSame('forbidden', $forbiddenNote['body']['error']['code']);

        $inProgress = $this->handle($app, 'POST', '/api/v1/support/tickets/' . $ticketId . '/status', [
            'status' => 'in_progress',
        ], $admin);
        self::assertSame(200, $inProgress['status']);
        self::assertSame('in_progress', $inProgress['body']['data']['status']);

        $note = $this->handle($app, 'POST', '/api/v1/support/tickets/' . $ticketId . '/notes', [
            'role' => 'admin',
            'body' => 'Escalated to finance operations.',
        ], $admin);
        self::assertSame(200, $note['status']);
        self::assertSame('Escalated to finance operations.', $note['body']['data']['internal_notes'][0]['body']);

        $missing = $this->handle($app, 'POST', '/api/v1/support/tickets/missing/status', [
            'status' => 'in_progress',
        ], $admin);
        self::assertSame(404, $missing['status']);
        self::assertSame('not_found', $missing['body']['error']['code']);

        $invalid = $this->handle($app, 'POST', '/api/v1/support/tickets/' . $ticketId . '/status', [
            'status' => 'open',
        ], $admin);
        self::assertSame(422, $invalid['status']);
        self::assertSame('invalid_request', $invalid['body']['error']['code']);

        self::assertSame('support.ticket.status_changed', $auditRepository->entries[0]->action ?? null);
        self::assertSame('support.ticket.internal_note_added', $auditRepository->entries[1]->action ?? null);
        self::assertSame($ticketId, $auditRepository->entries[1]->metadata['ticket_id'] ?? null);
    }

    public function testSupportTicketListSeparatesOrganizationMemberAndAssignedSupportVisibility(): void
    {
        $auditRepository = new Task29ActionAuditRepository();
        $app = $this->createApp($auditRepository);
        $member101 = new RequestUserContext(new AuthenticatedUser(501, 'member101@example.com', false), 101);
        $member202 = new RequestUserContext(new AuthenticatedUser(502, 'member202@example.com', false), 202);
        $support700 = new RequestUserContext(new AuthenticatedUser(700, 'support@example.com', false), null);

        $own = $this->handle($app, 'POST', '/api/v1/support/tickets', [
            'subject' => 'Own organization issue',
            'description' => 'Visible to organization 101.',
            'priority' => 'normal',
            'linked_entity' => ['type' => 'campaign', 'id' => 9001],
        ], $member101);
        $urgent = $this->handle($app, 'POST', '/api/v1/support/tickets', [
            'subject' => 'Urgent publisher escalation',
            'description' => 'Visible to support queue by urgency.',
            'priority' => 'urgent',
            'linked_entity' => ['type' => 'site', 'id' => 301],
        ], $member202);

        $memberList = $this->handle($app, 'GET', '/api/v1/support/tickets', context: $member101);
        $supportList = $this->handle($app, 'GET', '/api/v1/support/tickets?roles=support', context: $support700);

        self::assertSame([(string) $own['body']['data']['ticket_id']], array_column($memberList['body']['data']['tickets'], 'ticket_id'));
        self::assertSame([(string) $urgent['body']['data']['ticket_id']], array_column($supportList['body']['data']['tickets'], 'ticket_id'));

        $adminList = $this->handle($app, 'GET', '/api/v1/support/tickets?roles=admin', context: $support700);
        self::assertSame(
            [(string) $own['body']['data']['ticket_id'], (string) $urgent['body']['data']['ticket_id']],
            array_column($adminList['body']['data']['tickets'], 'ticket_id'),
        );
    }

    public function testSupportRoutesReturnValidationEnvelopesForInvalidCreateAndInternalNotePayloads(): void
    {
        $app = $this->createApp(new Task29ActionAuditRepository());
        $member = new RequestUserContext(new AuthenticatedUser(501, 'member@example.com', false), 101);
        $admin = new RequestUserContext(new AuthenticatedUser(700, 'admin@example.com', true), null);

        foreach (
            [
                [null, null],
                [['description' => 'Missing subject.', 'linked_entity' => ['type' => 'campaign', 'id' => 1]], $member],
                [['subject' => 'Bad priority', 'description' => 'Invalid.', 'priority' => 'critical', 'linked_entity' => ['type' => 'campaign', 'id' => 1]], $member],
                [['subject' => 'Missing link', 'description' => 'Invalid.'], $member],
                [['subject' => 'Bad attachments', 'description' => 'Invalid.', 'linked_entity' => ['type' => 'campaign', 'id' => 1], 'attachments' => 'bad'], $member],
                [['subject' => 'Bad attachment item', 'description' => 'Invalid.', 'linked_entity' => ['type' => 'campaign', 'id' => 1], 'attachments' => ['bad']], $member],
            ] as [$payload, $context]
        ) {
            $invalid = $this->handle($app, 'POST', '/api/v1/support/tickets', $payload, $context);
            self::assertSame(422, $invalid['status']);
            self::assertSame('invalid_request', $invalid['body']['error']['code']);
        }

        $created = $this->handle($app, 'POST', '/api/v1/support/tickets', [
            'subject' => 'Valid ticket',
            'description' => 'Create a ticket for invalid note coverage.',
            'linked_entity' => ['type' => 'campaign', 'id' => 1],
        ], $member);

        $invalidNote = $this->handle($app, 'POST', '/api/v1/support/tickets/' . $created['body']['data']['ticket_id'] . '/notes', [
            'role' => 'admin',
            'body' => '',
        ], $admin);
        self::assertSame(422, $invalidNote['status']);
        self::assertSame('invalid_request', $invalidNote['body']['error']['code']);
    }

    public function testFeatureFlagRoutesReturnEnvelopesEvaluateReasonsAndWriteAudit(): void
    {
        $auditRepository = new Task29ActionAuditRepository();
        $app = $this->createApp($auditRepository);
        $admin = new RequestUserContext(new AuthenticatedUser(700, 'admin@example.com', true), null);

        $created = $this->handle($app, 'POST', '/api/v1/feature-flags', [
            'flag_key' => 'sdk.lazy_loader',
            'environment' => 'staging',
            'enabled' => true,
            'targets' => [
                'organization_ids' => [101],
                'roles' => ['publisher'],
                'site_ids' => [301],
                'slot_ids' => [401],
            ],
            'percentage_rollout' => 100,
            'time_window' => [
                'starts_at' => '2026-06-08T00:00:00+00:00',
                'ends_at' => '2026-06-30T00:00:00+00:00',
            ],
            'publish' => true,
        ], $admin, 'req-flag-create');

        self::assertSame(201, $created['status']);
        self::assertSame('req-flag-create', $created['body']['request_id']);
        self::assertTrue($created['body']['data']['published']);

        $listed = $this->handle($app, 'GET', '/api/v1/feature-flags', context: $admin);
        self::assertSame('sdk.lazy_loader', $listed['body']['data']['feature_flags'][0]['flag_key']);

        $enabled = $this->handle($app, 'POST', '/api/v1/feature-flags/sdk.lazy_loader/evaluate', [
            'environment' => 'staging',
            'organization_id' => 101,
            'roles' => ['publisher'],
            'site_id' => 301,
            'slot_id' => 401,
            'now' => '2026-06-09T00:00:00+00:00',
            'rollout_seed' => 'org-101',
        ], $admin);
        self::assertSame(200, $enabled['status']);
        self::assertTrue($enabled['body']['data']['enabled']);
        self::assertSame('target_match', $enabled['body']['data']['reason']);

        $targetMiss = $this->handle($app, 'POST', '/api/v1/feature-flags/sdk.lazy_loader/evaluate', [
            'environment' => 'staging',
            'organization_id' => 202,
            'roles' => ['advertiser'],
            'now' => '2026-06-09T00:00:00+00:00',
        ], $admin);
        self::assertFalse($targetMiss['body']['data']['enabled']);
        self::assertSame('target_miss', $targetMiss['body']['data']['reason']);

        $missingFlag = $this->handle($app, 'POST', '/api/v1/feature-flags/missing.flag/evaluate', [
            'environment' => 'staging',
        ], $admin);
        self::assertFalse($missingFlag['body']['data']['enabled']);
        self::assertSame('flag_disabled', $missingFlag['body']['data']['reason']);

        self::assertSame('feature_flag.updated', $auditRepository->entries[0]->action ?? null);
        self::assertSame('feature_flag.published', $auditRepository->entries[1]->action ?? null);
        self::assertSame('sdk.lazy_loader', $auditRepository->entries[1]->metadata['flag_key'] ?? null);
    }

    public function testFeatureFlagRouteReturnsValidationEnvelopeForInvalidPayload(): void
    {
        $app = $this->createApp(new Task29ActionAuditRepository());
        $admin = new RequestUserContext(new AuthenticatedUser(700, 'admin@example.com', true), null);

        $invalid = $this->handle($app, 'POST', '/api/v1/feature-flags', [
            'flag_key' => '../secrets',
            'environment' => 'prod',
            'enabled' => true,
            'targets' => [],
            'percentage_rollout' => 101,
        ], $admin, 'req-flag-invalid');

        self::assertSame(422, $invalid['status']);
        self::assertSame('invalid_request', $invalid['body']['error']['code']);
        self::assertSame('req-flag-invalid', $invalid['body']['request_id']);
        self::assertNull($invalid['body']['data']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int,body:array<string,mixed>,request_id:string}
     */
    private function handle(
        App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?RequestUserContext $context = null,
        string $requestId = 'req-task29-action',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
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

        return [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
            'request_id' => $response->getHeaderLine('X-Request-Id'),
        ];
    }

    private function createApp(Task29ActionAuditRepository $auditRepository): App
    {
        $ticketRepository = new InMemorySupportTicketRepository();
        $flagRepository = new InMemoryFeatureFlagRepository();
        $audit = new AuditLogService($auditRepository);

        $container = (new ContainerBuilder())->addDefinitions([
            SupportTicketRepositoryInterface::class => static fn (): SupportTicketRepositoryInterface => $ticketRepository,
            SupportTicketService::class => static fn (): SupportTicketService => new SupportTicketService($ticketRepository, $audit),
            FeatureFlagRepositoryInterface::class => static fn (): FeatureFlagRepositoryInterface => $flagRepository,
            FeatureFlagService::class => static fn (): FeatureFlagService => new FeatureFlagService($flagRepository, $audit),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/support/tickets', CreateSupportTicketAction::class);
        $app->get('/api/v1/support/tickets', ListSupportTicketsAction::class);
        $app->post('/api/v1/support/tickets/{ticket_id}/notes', AddSupportTicketNoteAction::class);
        $app->post('/api/v1/support/tickets/{ticket_id}/status', UpdateSupportTicketStatusAction::class);
        $app->post('/api/v1/feature-flags', CreateFeatureFlagAction::class);
        $app->get('/api/v1/feature-flags', ListFeatureFlagsAction::class);
        $app->post('/api/v1/feature-flags/{flag_key}/evaluate', EvaluateFeatureFlagAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}

final class Task29ActionAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

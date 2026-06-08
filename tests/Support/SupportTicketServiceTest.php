<?php

declare(strict_types=1);

namespace VertoAD\Tests\Support;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class SupportTicketServiceTest extends TestCase
{
    public function testAdvertiserAndPublisherCanCreateTicketsWithFullContractFields(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Support\\InMemorySupportTicketRepository';
        $serviceClass = 'VertoAD\\Service\\Support\\SupportTicketService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new SupportTicketAuditRepository()));

        $ticket = $service->createTicket([
            'organization_id' => 101,
            'created_by_user_id' => 501,
            'subject' => 'Creative review is stuck',
            'description' => 'The campaign has been waiting for review for two days.',
            'priority' => 'high',
            'linked_entity' => ['type' => 'campaign', 'id' => 9001],
            'attachments' => [
                ['filename' => 'screenshot.png', 'object_key' => 'support/101/screenshot.png'],
            ],
        ]);

        foreach (
            [
                'ticket_id',
                'organization_id',
                'created_by_user_id',
                'subject',
                'description',
                'priority',
                'status',
                'linked_entity',
                'assignee_user_id',
                'internal_notes',
                'attachments',
                'created_at',
                'updated_at',
            ] as $field
        ) {
            self::assertArrayHasKey($field, $this->record($ticket), $field . ' must be present.');
        }

        self::assertSame(101, $this->value($ticket, 'organization_id'));
        self::assertSame(501, $this->value($ticket, 'created_by_user_id'));
        self::assertSame('open', $this->value($ticket, 'status'));
        self::assertSame(['type' => 'campaign', 'id' => 9001], $this->value($ticket, 'linked_entity'));
        self::assertSame([], $this->value($ticket, 'internal_notes'));
        self::assertNotEmpty($this->value($ticket, 'ticket_id'));
    }

    public function testStatusTransitionsOnlyAllowOpenToInProgressToResolvedOrClosed(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Support\\InMemorySupportTicketRepository';
        $serviceClass = 'VertoAD\\Service\\Support\\SupportTicketService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new SupportTicketAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));
        $ticket = $service->createTicket($this->validTicketPayload());

        $inProgress = $service->transitionStatus((string) $this->value($ticket, 'ticket_id'), 'in_progress', 700);
        $resolved = $service->transitionStatus((string) $this->value($ticket, 'ticket_id'), 'resolved', 700);

        self::assertSame('in_progress', $this->value($inProgress, 'status'));
        self::assertSame('resolved', $this->value($resolved, 'status'));
        self::assertSame('support.ticket.status_changed', $auditRepository->entries[0]->action ?? null);

        $closed = $service->createTicket($this->validTicketPayload());
        $closed = $service->transitionStatus((string) $this->value($closed, 'ticket_id'), 'in_progress', 700);
        $closed = $service->transitionStatus((string) $this->value($closed, 'ticket_id'), 'closed', 700);
        self::assertSame('closed', $this->value($closed, 'status'));

        $this->expectException(\InvalidArgumentException::class);
        $service->transitionStatus((string) $this->value($ticket, 'ticket_id'), 'open', 700);
    }

    public function testInternalNotesRequireAdminAndWriteAudit(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Support\\InMemorySupportTicketRepository';
        $serviceClass = 'VertoAD\\Service\\Support\\SupportTicketService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new SupportTicketAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));
        $ticket = $service->createTicket($this->validTicketPayload());

        try {
            $service->addInternalNote((string) $this->value($ticket, 'ticket_id'), 501, 'member', 'Do not expose this note.');
            self::fail('Organization members must not add internal notes.');
        } catch (\RuntimeException $exception) {
            self::assertSame('support_admin_required', $exception->getMessage());
        }

        $updated = $service->addInternalNote((string) $this->value($ticket, 'ticket_id'), 700, 'admin', 'Escalated to review ops.');

        self::assertCount(1, $this->value($updated, 'internal_notes'));
        self::assertSame('Escalated to review ops.', $this->value($updated, 'internal_notes')[0]['body'] ?? null);
        self::assertSame('support.ticket.internal_note_added', $auditRepository->entries[0]->action ?? null);
        self::assertSame(700, $auditRepository->entries[0]->actorUserId ?? null);
    }

    public function testVisibilityKeepsMembersInsideTheirOrganizationAndAllowsAssignedSupportWork(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Support\\InMemorySupportTicketRepository';
        $serviceClass = 'VertoAD\\Service\\Support\\SupportTicketService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new SupportTicketAuditRepository()));
        $own = $service->createTicket($this->validTicketPayload(['organization_id' => 101, 'created_by_user_id' => 501]));
        $other = $service->createTicket($this->validTicketPayload(['organization_id' => 202, 'created_by_user_id' => 502]));
        $service->assignTicket((string) $this->value($other, 'ticket_id'), 700, 1);

        $memberTickets = $service->listVisibleTickets(['user_id' => 501, 'organization_id' => 101, 'roles' => ['member']]);
        $supportTickets = $service->listVisibleTickets(['user_id' => 700, 'organization_id' => null, 'roles' => ['support']]);

        self::assertSame([(string) $this->value($own, 'ticket_id')], array_map(
            fn (mixed $ticket): string => (string) $this->value($ticket, 'ticket_id'),
            $memberTickets,
        ));
        self::assertSame([(string) $this->value($other, 'ticket_id')], array_map(
            fn (mixed $ticket): string => (string) $this->value($ticket, 'ticket_id'),
            $supportTickets,
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validTicketPayload(array $overrides = []): array
    {
        return array_replace([
            'organization_id' => 101,
            'created_by_user_id' => 501,
            'subject' => 'Payout question',
            'description' => 'Please help verify the payout status.',
            'priority' => 'normal',
            'linked_entity' => ['type' => 'withdrawal', 'id' => 3001],
            'attachments' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        return get_object_vars($record);
    }

    private function value(mixed $record, string $key): mixed
    {
        return $this->record($record)[$key] ?? null;
    }
}

final class SupportTicketAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

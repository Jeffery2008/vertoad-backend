<?php

declare(strict_types=1);

namespace VertoAD\Service\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Support\SupportTicket;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class SupportTicketService
{
    public function __construct(
        private SupportTicketRepositoryInterface $tickets,
        private AuditLogService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTicket(array $payload): array
    {
        $organizationId = $this->positiveInt($payload['organization_id'] ?? null, 'organization_id');
        $createdByUserId = $this->positiveInt($payload['created_by_user_id'] ?? null, 'created_by_user_id');
        $subject = $this->requiredString($payload['subject'] ?? null, 'subject');
        $description = $this->requiredString($payload['description'] ?? null, 'description');
        $priority = $this->priority((string) ($payload['priority'] ?? 'normal'));
        $linkedEntity = $this->linkedEntity($payload['linked_entity'] ?? null);
        $attachments = $this->listOfRecords($payload['attachments'] ?? []);
        $now = new DateTimeImmutable();

        $ticket = new SupportTicket(
            ticket_id: 'ticket_' . sha1($organizationId . '|' . $createdByUserId . '|' . $subject . '|' . $now->format(DATE_ATOM)),
            organization_id: $organizationId,
            created_by_user_id: $createdByUserId,
            subject: $subject,
            description: $description,
            priority: $priority,
            status: 'open',
            linked_entity: $linkedEntity,
            assignee_user_id: null,
            internal_notes: [],
            attachments: $attachments,
            created_at: $now,
            updated_at: $now,
        );

        return $this->tickets->append($ticket)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function transitionStatus(string $ticketId, string $status, int $actorUserId): array
    {
        $ticket = $this->requiredTicket($ticketId);
        $allowed = match ($ticket->status) {
            'open' => ['in_progress'],
            'in_progress' => ['resolved', 'closed'],
            default => [],
        };

        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid support ticket status transition.');
        }

        $updated = $this->replace($ticket, status: $status);
        $this->audit->record(
            action: 'support.ticket.status_changed',
            subjectType: 'support_ticket',
            actorUserId: $actorUserId,
            organizationId: $ticket->organization_id,
            metadata: ['ticket_id' => $ticket->ticket_id, 'from' => $ticket->status, 'to' => $status],
        );

        return $this->tickets->save($updated)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function addInternalNote(string $ticketId, int $actorUserId, string $role, string $body): array
    {
        if (!in_array($role, ['admin', 'support', 'super_admin'], true)) {
            throw new RuntimeException('support_admin_required');
        }

        $ticket = $this->requiredTicket($ticketId);
        $noteBody = $this->requiredString($body, 'body');
        $notes = $ticket->internal_notes;
        $notes[] = [
            'note_id' => 'note_' . sha1($ticketId . '|' . $actorUserId . '|' . $noteBody . '|' . count($notes)),
            'actor_user_id' => $actorUserId,
            'body' => $noteBody,
            'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $updated = $this->replace($ticket, internalNotes: $notes);
        $this->audit->record(
            action: 'support.ticket.internal_note_added',
            subjectType: 'support_ticket',
            actorUserId: $actorUserId,
            organizationId: $ticket->organization_id,
            metadata: ['ticket_id' => $ticket->ticket_id],
        );

        return $this->tickets->save($updated)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function assignTicket(string $ticketId, int $assigneeUserId, int $actorUserId): array
    {
        $ticket = $this->requiredTicket($ticketId);

        return $this->tickets->save($this->replace($ticket, assigneeUserId: $assigneeUserId))->toArray();
    }

    /**
     * @param array{user_id:int,organization_id:int|null,roles:list<string>,filter_organization_id?:int|null} $context
     * @return list<array<string, mixed>>
     */
    public function listVisibleTickets(array $context): array
    {
        $userId = (int) $context['user_id'];
        $organizationId = $context['organization_id'];
        $roles = $context['roles'];
        $filterOrganizationId = $context['filter_organization_id'] ?? null;

        $visible = array_filter($this->tickets->all(), static function (SupportTicket $ticket) use ($userId, $organizationId, $roles, $filterOrganizationId): bool {
            if (in_array('admin', $roles, true) || in_array('super_admin', $roles, true)) {
                $visibleToRole = true;
            } elseif (in_array('support', $roles, true)) {
                $visibleToRole = $ticket->assignee_user_id === $userId || $ticket->priority === 'urgent';
            } else {
                $visibleToRole = $organizationId !== null && $ticket->organization_id === $organizationId;
            }

            return $visibleToRole && ($filterOrganizationId === null || $ticket->organization_id === $filterOrganizationId);
        });

        return array_map(static fn (SupportTicket $ticket): array => $ticket->toArray(), array_values($visible));
    }

    private function requiredTicket(string $ticketId): SupportTicket
    {
        $ticket = $this->tickets->find($ticketId);
        if ($ticket === null) {
            throw new RuntimeException('support_ticket_not_found');
        }

        return $ticket;
    }

    /**
     * @param list<array<string, mixed>>|null $internalNotes
     */
    private function replace(
        SupportTicket $ticket,
        ?string $status = null,
        ?int $assigneeUserId = null,
        ?array $internalNotes = null,
    ): SupportTicket {
        return new SupportTicket(
            ticket_id: $ticket->ticket_id,
            organization_id: $ticket->organization_id,
            created_by_user_id: $ticket->created_by_user_id,
            subject: $ticket->subject,
            description: $ticket->description,
            priority: $ticket->priority,
            status: $status ?? $ticket->status,
            linked_entity: $ticket->linked_entity,
            assignee_user_id: $assigneeUserId ?? $ticket->assignee_user_id,
            internal_notes: $internalNotes ?? $ticket->internal_notes,
            attachments: $ticket->attachments,
            created_at: $ticket->created_at,
            updated_at: new DateTimeImmutable(),
        );
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return trim($value);
    }

    private function priority(string $priority): string
    {
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException('priority is invalid.');
        }

        return $priority;
    }

    /**
     * @return array{type:string,id:int|string}
     */
    private function linkedEntity(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['type'] ?? null) || !isset($value['id'])) {
            throw new InvalidArgumentException('linked_entity is required.');
        }

        return ['type' => trim($value['type']), 'id' => $value['id']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfRecords(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('attachments must be a list.');
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('attachments must contain objects.');
            }
        }

        return array_values($value);
    }
}

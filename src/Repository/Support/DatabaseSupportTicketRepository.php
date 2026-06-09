<?php

declare(strict_types=1);

namespace VertoAD\Repository\Support;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Support\SupportTicket;

final readonly class DatabaseSupportTicketRepository implements SupportTicketRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(SupportTicket $ticket): SupportTicket
    {
        return $this->save($ticket);
    }

    public function save(SupportTicket $ticket): SupportTicket
    {
        $row = $this->rowFromTicket($ticket);
        if ($this->find($ticket->ticket_id) === null) {
            $this->connection->insert('support_tickets', $row);

            return $ticket;
        }

        $this->connection->update('support_tickets', $row, ['ticket_id' => $ticket->ticket_id]);

        return $ticket;
    }

    public function find(string $ticketId): ?SupportTicket
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('support_tickets')
            ->where('ticket_id = :ticket_id')
            ->setParameter('ticket_id', trim($ticketId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('support_tickets')
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('ticket_id', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): SupportTicket => $this->hydrate($row), $rows);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'ticket_id',
            'organization_id',
            'created_by_user_id',
            'subject',
            'description',
            'priority',
            'status',
            'linked_entity_json',
            'assignee_user_id',
            'internal_notes_json',
            'attachments_json',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string, int|string|null> */
    private function rowFromTicket(SupportTicket $ticket): array
    {
        return [
            'ticket_id' => $ticket->ticket_id,
            'organization_id' => $ticket->organization_id,
            'created_by_user_id' => $ticket->created_by_user_id,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'linked_entity_json' => json_encode($ticket->linked_entity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'assignee_user_id' => $ticket->assignee_user_id,
            'internal_notes_json' => json_encode($ticket->internal_notes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attachments_json' => json_encode($ticket->attachments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $this->formatDate($ticket->created_at),
            'updated_at' => $this->formatDate($ticket->updated_at),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SupportTicket
    {
        $linkedEntity = json_decode((string) $row['linked_entity_json'], true, flags: JSON_THROW_ON_ERROR);
        $internalNotes = json_decode((string) $row['internal_notes_json'], true, flags: JSON_THROW_ON_ERROR);
        $attachments = json_decode((string) $row['attachments_json'], true, flags: JSON_THROW_ON_ERROR);

        return new SupportTicket(
            ticket_id: (string) $row['ticket_id'],
            organization_id: (int) $row['organization_id'],
            created_by_user_id: (int) $row['created_by_user_id'],
            subject: (string) $row['subject'],
            description: (string) $row['description'],
            priority: (string) $row['priority'],
            status: (string) $row['status'],
            linked_entity: is_array($linkedEntity) ? $linkedEntity : [],
            assignee_user_id: $row['assignee_user_id'] === null ? null : (int) $row['assignee_user_id'],
            internal_notes: is_array($internalNotes) ? array_values($internalNotes) : [],
            attachments: is_array($attachments) ? array_values($attachments) : [],
            created_at: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updated_at: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}

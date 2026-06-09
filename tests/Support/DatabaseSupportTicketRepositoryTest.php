<?php

declare(strict_types=1);

namespace VertoAD\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Support\SupportTicket;
use VertoAD\Repository\Support\DatabaseSupportTicketRepository;

final class DatabaseSupportTicketRepositoryTest extends TestCase
{
    public function testPersistsFindsUpdatesAndListsTicketsWithJsonFields(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseSupportTicketRepository($connection);

        $first = $this->ticket('ticket_1', updatedAt: '2026-06-09T10:00:00+00:00');
        $second = $this->ticket('ticket_2', organizationId: 202, updatedAt: '2026-06-09T11:00:00+00:00');

        self::assertSame($first, $repository->append($first));
        $repository->append($second);

        $stored = $repository->find('ticket_1');
        self::assertNotNull($stored);
        self::assertSame('ticket_1', $stored->ticket_id);
        self::assertSame(['type' => 'campaign', 'id' => 9001], $stored->linked_entity);
        self::assertSame([['body' => 'Escalated', 'author_user_id' => 700]], $stored->internal_notes);
        self::assertSame([['filename' => 'proof.png', 'object_key' => 'support/101/proof.png']], $stored->attachments);
        self::assertSame('2026-06-09T08:00:00+00:00', $stored->created_at->format(DATE_ATOM));
        self::assertSame('2026-06-09T10:00:00+00:00', $stored->updated_at->format(DATE_ATOM));

        $updated = $this->ticket(
            'ticket_1',
            status: 'resolved',
            assigneeUserId: 701,
            updatedAt: '2026-06-09T12:00:00+00:00',
        );
        $repository->save($updated);

        $freshRepository = new DatabaseSupportTicketRepository($connection);
        $fresh = $freshRepository->find('ticket_1');
        self::assertNotNull($fresh);
        self::assertSame('resolved', $fresh->status);
        self::assertSame(701, $fresh->assignee_user_id);
        self::assertSame('2026-06-09T08:00:00+00:00', $fresh->created_at->format(DATE_ATOM));

        self::assertSame(['ticket_1', 'ticket_2'], array_map(
            static fn (SupportTicket $ticket): string => $ticket->ticket_id,
            $freshRepository->all(),
        ));
    }

    public function testFindReturnsNullForMissingTicket(): void
    {
        self::assertNull((new DatabaseSupportTicketRepository($this->createConnection()))->find('missing'));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
CREATE TABLE support_tickets (
    ticket_id VARCHAR(64) PRIMARY KEY,
    organization_id INTEGER NOT NULL,
    created_by_user_id INTEGER NOT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priority VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    linked_entity_json TEXT NOT NULL,
    assignee_user_id INTEGER NULL,
    internal_notes_json TEXT NOT NULL,
    attachments_json TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL,
    updated_at VARCHAR(32) NOT NULL
)
SQL);

        return $connection;
    }

    private function ticket(
        string $ticketId,
        int $organizationId = 101,
        string $status = 'open',
        ?int $assigneeUserId = null,
        string $updatedAt = '2026-06-09T10:00:00+00:00',
    ): SupportTicket {
        return new SupportTicket(
            ticket_id: $ticketId,
            organization_id: $organizationId,
            created_by_user_id: 501,
            subject: 'Review is stuck',
            description: 'The campaign has been waiting for review.',
            priority: 'high',
            status: $status,
            linked_entity: ['type' => 'campaign', 'id' => 9001],
            assignee_user_id: $assigneeUserId,
            internal_notes: [['body' => 'Escalated', 'author_user_id' => 700]],
            attachments: [['filename' => 'proof.png', 'object_key' => 'support/101/proof.png']],
            created_at: new DateTimeImmutable('2026-06-09T08:00:00+00:00', new DateTimeZone('UTC')),
            updated_at: new DateTimeImmutable($updatedAt, new DateTimeZone('UTC')),
        );
    }
}

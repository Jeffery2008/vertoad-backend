<?php

declare(strict_types=1);

namespace VertoAD\Repository\Support;

use VertoAD\Domain\Support\SupportTicket;

final class InMemorySupportTicketRepository implements SupportTicketRepositoryInterface
{
    /** @var array<string, SupportTicket> */
    private array $tickets = [];

    public function append(SupportTicket $ticket): SupportTicket
    {
        $this->tickets[$ticket->ticket_id] = $ticket;

        return $ticket;
    }

    public function save(SupportTicket $ticket): SupportTicket
    {
        $this->tickets[$ticket->ticket_id] = $ticket;

        return $ticket;
    }

    public function find(string $ticketId): ?SupportTicket
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->tickets);
    }
}

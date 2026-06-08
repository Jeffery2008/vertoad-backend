<?php

declare(strict_types=1);

namespace VertoAD\Repository\Support;

use VertoAD\Domain\Support\SupportTicket;

interface SupportTicketRepositoryInterface
{
    public function append(SupportTicket $ticket): SupportTicket;

    public function save(SupportTicket $ticket): SupportTicket;

    public function find(string $ticketId): ?SupportTicket;

    /**
     * @return list<SupportTicket>
     */
    public function all(): array;
}

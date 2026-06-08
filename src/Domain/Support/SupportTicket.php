<?php

declare(strict_types=1);

namespace VertoAD\Domain\Support;

use DateTimeImmutable;

final readonly class SupportTicket
{
    /**
     * @param array{type:string,id:int|string} $linked_entity
     * @param list<array<string, mixed>> $internal_notes
     * @param list<array<string, mixed>> $attachments
     */
    public function __construct(
        public string $ticket_id,
        public int $organization_id,
        public int $created_by_user_id,
        public string $subject,
        public string $description,
        public string $priority,
        public string $status,
        public array $linked_entity,
        public ?int $assignee_user_id,
        public array $internal_notes,
        public array $attachments,
        public DateTimeImmutable $created_at,
        public DateTimeImmutable $updated_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticket_id,
            'organization_id' => $this->organization_id,
            'created_by_user_id' => $this->created_by_user_id,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'linked_entity' => $this->linked_entity,
            'assignee_user_id' => $this->assignee_user_id,
            'internal_notes' => $this->internal_notes,
            'attachments' => $this->attachments,
            'created_at' => $this->created_at->format(DATE_ATOM),
            'updated_at' => $this->updated_at->format(DATE_ATOM),
        ];
    }
}

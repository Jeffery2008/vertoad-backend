<?php

declare(strict_types=1);

namespace VertoAD\Domain\Creative;

use DateTimeImmutable;

final readonly class CreativeDesign
{
    public function __construct(
        public string $designId,
        public int $organizationId,
        public string $name,
        public int $width,
        public int $height,
        public int $currentVersion,
        public ?string $templateId,
        public string $status,
        public int $createdByUserId,
        public int $updatedByUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'design_id' => $this->designId,
            'organization_id' => $this->organizationId,
            'name' => $this->name,
            'width' => $this->width,
            'height' => $this->height,
            'current_version' => $this->currentVersion,
            'template_id' => $this->templateId,
            'status' => $this->status,
            'created_by_user_id' => $this->createdByUserId,
            'updated_by_user_id' => $this->updatedByUserId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}

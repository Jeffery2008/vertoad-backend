<?php

declare(strict_types=1);

namespace VertoAD\Domain\Creative;

use DateTimeImmutable;

final readonly class CreativeTemplate
{
    /**
     * @param array<string, mixed> $fabricJson
     * @param list<string> $tags
     */
    public function __construct(
        public string $templateId,
        public ?int $organizationId,
        public string $name,
        public ?string $description,
        public int $width,
        public int $height,
        public array $fabricJson,
        public ?string $snapshotUrl,
        public array $tags,
        public int $createdByUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function scope(): string
    {
        return $this->organizationId === null ? 'platform' : 'organization';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template_id' => $this->templateId,
            'organization_id' => $this->organizationId,
            'scope' => $this->scope(),
            'name' => $this->name,
            'description' => $this->description,
            'width' => $this->width,
            'height' => $this->height,
            'fabric_json' => $this->fabricJson,
            'snapshot_url' => $this->snapshotUrl,
            'tags' => $this->tags,
            'created_by_user_id' => $this->createdByUserId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}

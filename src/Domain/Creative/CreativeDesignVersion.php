<?php

declare(strict_types=1);

namespace VertoAD\Domain\Creative;

use DateTimeImmutable;

final readonly class CreativeDesignVersion
{
    /**
     * @param array<string, mixed> $fabricJson
     */
    public function __construct(
        public string $versionId,
        public string $designId,
        public int $versionNumber,
        public array $fabricJson,
        public ?string $snapshotUrl,
        public ?string $changeSummary,
        public int $createdByUserId,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version_id' => $this->versionId,
            'design_id' => $this->designId,
            'version_number' => $this->versionNumber,
            'fabric_json' => $this->fabricJson,
            'snapshot_url' => $this->snapshotUrl,
            'change_summary' => $this->changeSummary,
            'created_by_user_id' => $this->createdByUserId,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}

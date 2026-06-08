<?php

declare(strict_types=1);

namespace VertoAD\Domain\Operations;

use DateTimeImmutable;

final readonly class ConfigVersion
{
    /**
     * @param array<string, mixed> $value
     */
    public function __construct(
        public string $version_id,
        public string $config_key,
        public int $version_number,
        public array $value,
        public int $created_by_user_id,
        public DateTimeImmutable $created_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version_id' => $this->version_id,
            'config_key' => $this->config_key,
            'version_number' => $this->version_number,
            'value' => $this->value,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at->format(DATE_ATOM),
        ];
    }
}

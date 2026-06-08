<?php

declare(strict_types=1);

namespace VertoAD\Domain\FeatureFlags;

use DateTimeImmutable;

final readonly class FeatureFlag
{
    /**
     * @param array<string, list<int|string>> $targets
     * @param array{starts_at:string,ends_at:string}|null $time_window
     */
    public function __construct(
        public string $flag_key,
        public string $environment,
        public bool $enabled,
        public array $targets,
        public int $percentage_rollout,
        public ?array $time_window,
        public bool $published,
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
            'flag_key' => $this->flag_key,
            'environment' => $this->environment,
            'enabled' => $this->enabled,
            'targets' => $this->targets,
            'percentage_rollout' => $this->percentage_rollout,
            'time_window' => $this->time_window,
            'published' => $this->published,
            'created_at' => $this->created_at->format(DATE_ATOM),
            'updated_at' => $this->updated_at->format(DATE_ATOM),
        ];
    }
}

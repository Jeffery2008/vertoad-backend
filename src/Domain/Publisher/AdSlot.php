<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

final readonly class AdSlot
{
    /**
     * @param list<array{min_width:int,width:int,height:int}> $responsiveRules
     */
    public function __construct(
        public ?int $id,
        public int $siteId,
        public string $name,
        public string $slotKey,
        public AdSlotSize $size,
        public bool $responsive,
        public array $responsiveRules,
        public ?string $presetKey,
        public string $status = 'active',
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            siteId: $this->siteId,
            name: $this->name,
            slotKey: $this->slotKey,
            size: $this->size,
            responsive: $this->responsive,
            responsiveRules: $this->responsiveRules,
            presetKey: $this->presetKey,
            status: $this->status,
        );
    }
}

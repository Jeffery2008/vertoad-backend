<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

final readonly class FabricCreativePayload
{
    /**
     * @param array<string, mixed> $fabricJson
     */
    public function __construct(
        public array $fabricJson,
        public string $snapshotContentType,
        public string $snapshotBytes,
        public int $width,
        public int $height,
    ) {
    }
}

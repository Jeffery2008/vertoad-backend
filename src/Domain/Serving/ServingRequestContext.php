<?php

declare(strict_types=1);

namespace VertoAD\Domain\Serving;

use VertoAD\Domain\IpGeo\GeoIpRecord;

final readonly class ServingRequestContext
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $geoCode = null,
        public ?GeoIpRecord $geoRecord = null,
        public ?string $requestId = null,
    ) {
    }
}

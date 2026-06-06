<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

enum PublisherSiteStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Suspended = 'suspended';
}

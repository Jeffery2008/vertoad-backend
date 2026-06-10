<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

enum PublisherSiteStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Verified = 'verified';
    case Suspended = 'suspended';
}

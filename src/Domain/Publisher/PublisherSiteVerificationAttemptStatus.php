<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

enum PublisherSiteVerificationAttemptStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}

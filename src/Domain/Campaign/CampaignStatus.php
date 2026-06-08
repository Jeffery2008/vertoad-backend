<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}

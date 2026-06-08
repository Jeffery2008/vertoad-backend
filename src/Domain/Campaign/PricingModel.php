<?php

declare(strict_types=1);

namespace VertoAD\Domain\Campaign;

enum PricingModel: string
{
    case Cpm = 'cpm';
    case Cpc = 'cpc';
}

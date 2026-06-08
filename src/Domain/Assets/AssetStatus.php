<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

enum AssetStatus: string
{
    case PendingUpload = 'pending_upload';
    case PendingReview = 'pending_review';
}

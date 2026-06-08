<?php

declare(strict_types=1);

namespace VertoAD\Domain\Review;

enum CreativeReviewStatus: string
{
    case Draft = 'draft';
    case PendingAi = 'pending_ai';
    case AiReviewing = 'ai_reviewing';
    case NeedsHuman = 'needs_human';
    case Approved = 'approved';
    case Rejected = 'rejected';
}

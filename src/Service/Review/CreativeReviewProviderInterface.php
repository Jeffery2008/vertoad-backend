<?php

declare(strict_types=1);

namespace VertoAD\Service\Review;

use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\AiReviewResult;

interface CreativeReviewProviderInterface
{
    public function review(AiReviewInput $input): AiReviewResult;
}

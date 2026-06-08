<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use VertoAD\Infrastructure\Storage\PresignedUpload;

final readonly class WithdrawalProofUploadIntent
{
    public function __construct(
        public WithdrawalProof $proof,
        public PresignedUpload $upload,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Domain\Recharge;

final readonly class RechargeKeyPlaintext
{
    public function __construct(
        public RechargeKey $key,
        public string $plaintextKey,
    ) {
    }
}

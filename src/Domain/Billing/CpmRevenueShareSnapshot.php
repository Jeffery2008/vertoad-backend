<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use InvalidArgumentException;

final readonly class CpmRevenueShareSnapshot
{
    public function __construct(
        public ?int $ruleId,
        public string $ruleKey,
        public int $shareRatioBps,
    ) {
        if ($this->ruleId !== null && $this->ruleId <= 0) {
            throw new InvalidArgumentException('CPM revenue share rule ID must be positive when provided.');
        }
        if (trim($this->ruleKey) === '') {
            throw new InvalidArgumentException('CPM revenue share rule key is required.');
        }
        if ($this->shareRatioBps < 0 || $this->shareRatioBps > 10_000) {
            throw new InvalidArgumentException('CPM revenue share ratio must be between 0 and 10000 basis points.');
        }
    }

    public static function fromRule(RevenueShareRule $rule): self
    {
        $ruleKey = $rule->id === null
            ? implode(':', ['version', $rule->version, $rule->scope, $rule->shareRatioBps])
            : 'id:' . $rule->id;

        return new self($rule->id, $ruleKey, $rule->shareRatioBps);
    }

    public static function missing(): self
    {
        return new self(null, 'missing', 0);
    }
}

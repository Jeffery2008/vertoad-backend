<?php

declare(strict_types=1);

namespace VertoAD\Domain\Operations;

use DateTimeImmutable;

final readonly class OperationRiskDecisionLog
{
    /**
     * @param list<string> $reason_codes
     */
    public function __construct(
        public string $decision_id,
        public string $request_id,
        public string $action,
        public ?int $risk_score,
        public array $reason_codes,
        public ?string $subject_type,
        public ?string $subject_id,
        public ?string $ip_address,
        public ?string $endpoint,
        public ?string $http_method,
        public ?string $user_agent,
        public ?int $site_id,
        public ?int $slot_id,
        public ?int $campaign_id,
        public ?string $viewer_id,
        public ?string $ad_decision_id,
        public DateTimeImmutable $occurred_at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision_id' => $this->decision_id,
            'request_id' => $this->request_id,
            'action' => $this->action,
            'risk_score' => $this->risk_score,
            'reason_codes' => $this->reason_codes,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'ip_address' => $this->ip_address,
            'endpoint' => $this->endpoint,
            'http_method' => $this->http_method,
            'user_agent' => $this->user_agent,
            'site_id' => $this->site_id,
            'slot_id' => $this->slot_id,
            'campaign_id' => $this->campaign_id,
            'viewer_id' => $this->viewer_id,
            'ad_decision_id' => $this->ad_decision_id,
            'occurred_at' => $this->occurred_at->format(DATE_ATOM),
        ];
    }
}

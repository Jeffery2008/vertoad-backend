<?php

declare(strict_types=1);

namespace VertoAD\Service\Campaign;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Budget\CampaignBudgetCaps;
use VertoAD\Domain\Campaign\Campaign;
use VertoAD\Domain\Campaign\CampaignStatus;
use VertoAD\Domain\Campaign\CampaignTargeting;
use VertoAD\Domain\Campaign\PricingModel;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Repository\Campaign\CampaignRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\CampaignBudgetService;

final readonly class CampaignService
{
    public function __construct(
        private CampaignRepositoryInterface $campaigns,
        private ReviewRepositoryInterface $reviews,
        private CampaignBudgetService $budgets,
    ) {
    }

    /** @return list<Campaign> */
    public function list(int $organizationId): array
    {
        return $this->campaigns->listForOrganization($organizationId);
    }

    public function get(int $organizationId, int $campaignId): Campaign
    {
        return $this->campaigns->find($organizationId, $campaignId)
            ?? throw new CampaignValidationException('campaign_not_found', 'Campaign was not found.', 404);
    }

    /** @param array<string, mixed> $payload */
    public function create(int $organizationId, array $payload): Campaign
    {
        $campaign = $this->campaignFromPayload($organizationId, null, null, $payload);
        $this->assertCreativeExists($campaign);
        $this->assertCanUseStatus($campaign);

        $created = $this->campaigns->create($campaign);
        $this->saveBudget($created, $campaign->budget);

        return $this->get($organizationId, (int) $created->id);
    }

    /** @param array<string, mixed> $payload */
    public function update(int $organizationId, int $campaignId, array $payload): Campaign
    {
        $existing = $this->get($organizationId, $campaignId);
        $campaign = $this->campaignFromPayload($organizationId, $campaignId, $existing, $payload);
        $this->assertCreativeExists($campaign);
        $this->assertCanUseStatus($campaign);

        $updated = $this->campaigns->update($campaign);
        $this->saveBudget($updated, $campaign->budget);

        return $this->get($organizationId, $campaignId);
    }

    /** @param array<string, mixed> $payload */
    private function campaignFromPayload(
        int $organizationId,
        ?int $campaignId,
        ?Campaign $existing,
        array $payload,
    ): Campaign {
        $name = array_key_exists('name', $payload)
            ? $this->requiredString($payload, 'name', 'campaign_name_invalid')
            : $existing?->name;
        if ($name === null) {
            throw new CampaignValidationException('campaign_name_invalid', 'Campaign name is required.');
        }

        $status = array_key_exists('status', $payload)
            ? $this->status($payload['status'])
            : ($existing?->status ?? CampaignStatus::Draft);
        $pricingModel = array_key_exists('pricing_model', $payload)
            ? $this->pricingModel($payload['pricing_model'])
            : ($existing?->pricingModel ?? null);
        if ($pricingModel === null) {
            throw new CampaignValidationException('campaign_pricing_model_invalid', 'pricing_model is required.');
        }

        $bidPoints = array_key_exists('bid_points', $payload)
            ? $this->positiveInt($payload['bid_points'], 'campaign_bid_invalid', 'bid_points must be a positive integer.')
            : ($existing?->bidPoints ?? null);
        if ($bidPoints === null) {
            throw new CampaignValidationException('campaign_bid_invalid', 'bid_points is required.');
        }

        $landingUrl = array_key_exists('landing_url', $payload)
            ? $this->landingUrl($payload['landing_url'])
            : ($existing?->landingUrl ?? null);
        if ($landingUrl === null) {
            throw new CampaignValidationException('campaign_landing_url_invalid', 'landing_url is required.');
        }

        $creativeAssetId = array_key_exists('creative_asset_id', $payload)
            ? $this->positiveInt($payload['creative_asset_id'], 'campaign_creative_invalid', 'creative_asset_id must be a positive integer.')
            : ($existing?->creativeAssetId ?? null);
        if ($creativeAssetId === null) {
            throw new CampaignValidationException('campaign_creative_invalid', 'creative_asset_id is required.');
        }

        [$startsAt, $endsAt] = array_key_exists('schedule', $payload)
            ? $this->schedule($payload['schedule'])
            : [$existing?->startsAt, $existing?->endsAt];
        $targeting = array_key_exists('targeting', $payload)
            ? $this->targeting($payload['targeting'])
            : ($existing?->targeting ?? new CampaignTargeting());
        $budget = array_key_exists('budget', $payload)
            ? $this->budget($campaignId, $organizationId, $payload['budget'])
            : $existing?->budget;

        return new Campaign(
            id: $campaignId,
            organizationId: $organizationId,
            name: $name,
            status: $status,
            pricingModel: $pricingModel,
            bidPoints: $bidPoints,
            landingUrl: $landingUrl,
            creativeAssetId: $creativeAssetId,
            startsAt: $startsAt,
            endsAt: $endsAt,
            targeting: $targeting,
            budget: $budget,
        );
    }

    private function assertCreativeExists(Campaign $campaign): void
    {
        if ($this->reviews->findAsset($campaign->creativeAssetId, $campaign->organizationId) === null) {
            throw new CampaignValidationException('campaign_creative_not_found', 'Creative asset was not found.', 404);
        }
    }

    private function assertCanUseStatus(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Active) {
            return;
        }

        $review = $this->reviews->findByAsset($campaign->creativeAssetId, $campaign->organizationId);
        if ($review === null || $review->status !== CreativeReviewStatus::Approved || $review->finalDecision !== 'approved') {
            throw new CampaignValidationException('campaign_creative_not_approved', 'Campaign creative must be approved before activation.', 409);
        }

        if ($campaign->budget === null) {
            throw new CampaignValidationException('campaign_budget_required', 'Budget caps are required before activation.');
        }
    }

    private function saveBudget(Campaign $campaign, ?CampaignBudgetCaps $budget): void
    {
        if ($budget === null || $campaign->id === null) {
            return;
        }

        $this->budgets->saveCaps(new CampaignBudgetCaps(
            campaignId: (int) $campaign->id,
            organizationId: $campaign->organizationId,
            totalCapPoints: $budget->totalCapPoints,
            dailyCapPoints: $budget->dailyCapPoints,
            hourlyCapPoints: $budget->hourlyCapPoints,
        ));
    }

    /** @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} */
    private function schedule(mixed $value): array
    {
        if (!is_array($value)) {
            throw new CampaignValidationException('campaign_schedule_invalid', 'schedule must be an object.');
        }

        $startsAt = $this->optionalDate($value['starts_at'] ?? null);
        $endsAt = $this->optionalDate($value['ends_at'] ?? null);
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw new CampaignValidationException('campaign_schedule_invalid', 'ends_at must be after starts_at.');
        }

        return [$startsAt, $endsAt];
    }

    private function targeting(mixed $value): CampaignTargeting
    {
        if (!is_array($value)) {
            throw new CampaignValidationException('campaign_targeting_invalid', 'targeting must be an object.');
        }

        return new CampaignTargeting(
            devices: $this->stringList($value['devices'] ?? [], 'campaign_targeting_invalid'),
            geos: $this->stringList($value['geos'] ?? [], 'campaign_targeting_invalid'),
            siteIds: $this->intList($value['site_ids'] ?? [], 'campaign_targeting_invalid'),
            slotIds: $this->intList($value['slot_ids'] ?? [], 'campaign_targeting_invalid'),
            timeWindows: $this->timeWindows($value['time_windows'] ?? []),
        );
    }

    private function budget(?int $campaignId, int $organizationId, mixed $value): CampaignBudgetCaps
    {
        if (!is_array($value)) {
            throw new CampaignValidationException('campaign_budget_invalid', 'budget must be an object.');
        }

        $total = $this->nullablePositiveInt($value['total_cap_points'] ?? null);
        $daily = $this->nullablePositiveInt($value['daily_cap_points'] ?? null);
        $hourly = $this->nullablePositiveInt($value['hourly_cap_points'] ?? null);
        if ($hourly !== null && $daily !== null && $hourly > $daily) {
            throw new CampaignValidationException('campaign_budget_invalid', 'hourly cap cannot exceed daily cap.');
        }

        if ($daily !== null && $total !== null && $daily > $total) {
            throw new CampaignValidationException('campaign_budget_invalid', 'daily cap cannot exceed total cap.');
        }

        try {
            return new CampaignBudgetCaps($campaignId ?? 1, $organizationId, $total, $daily, $hourly);
        } catch (InvalidArgumentException $exception) {
            throw new CampaignValidationException('campaign_budget_invalid', $exception->getMessage());
        }
    }

    private function requiredString(array $payload, string $key, string $code): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new CampaignValidationException($code, $key . ' is required.');
        }

        return trim($value);
    }

    private function landingUrl(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new CampaignValidationException('campaign_landing_url_invalid', 'landing_url must be a valid HTTPS URL.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new CampaignValidationException('campaign_landing_url_invalid', 'landing_url must be a valid HTTPS URL.');
        }

        return trim($value);
    }

    private function status(mixed $value): CampaignStatus
    {
        if (!is_string($value)) {
            throw new CampaignValidationException('campaign_status_invalid', 'status is invalid.');
        }

        return CampaignStatus::tryFrom($value)
            ?? throw new CampaignValidationException('campaign_status_invalid', 'status is invalid.');
    }

    private function pricingModel(mixed $value): PricingModel
    {
        if (!is_string($value)) {
            throw new CampaignValidationException('campaign_pricing_model_invalid', 'pricing_model is invalid.');
        }

        return PricingModel::tryFrom($value)
            ?? throw new CampaignValidationException('campaign_pricing_model_invalid', 'pricing_model is invalid.');
    }

    private function positiveInt(mixed $value, string $code, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new CampaignValidationException($code, $message);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new CampaignValidationException('campaign_budget_invalid', 'Budget caps must be positive integers when present.');
    }

    private function optionalDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new CampaignValidationException('campaign_schedule_invalid', 'Schedule values must be ISO-8601 timestamps.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new CampaignValidationException('campaign_schedule_invalid', 'Schedule values must be ISO-8601 timestamps.');
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $code): array
    {
        if (!is_array($value)) {
            throw new CampaignValidationException($code, 'Targeting list fields must be arrays.');
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new CampaignValidationException($code, 'Targeting list fields must contain strings.');
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    /** @return list<int> */
    private function intList(mixed $value, string $code): array
    {
        if (!is_array($value)) {
            throw new CampaignValidationException($code, 'Targeting ID fields must be arrays.');
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item <= 0) {
                throw new CampaignValidationException($code, 'Targeting ID fields must contain positive integers.');
            }

            $items[] = $item;
        }

        return array_values(array_unique($items));
    }

    /** @return list<array{day_of_week: int, start: string, end: string}> */
    private function timeWindows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new CampaignValidationException('campaign_targeting_invalid', 'time_windows must be an array.');
        }

        $windows = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new CampaignValidationException('campaign_targeting_invalid', 'time_windows entries must be objects.');
            }

            $dayOfWeek = $item['day_of_week'] ?? null;
            $start = $item['start'] ?? null;
            $end = $item['end'] ?? null;
            if (!is_int($dayOfWeek) || $dayOfWeek < 0 || $dayOfWeek > 6 || !is_string($start) || !is_string($end)) {
                throw new CampaignValidationException('campaign_targeting_invalid', 'time_windows entries are invalid.');
            }

            if (!$this->validClock($start) || !$this->validClock($end) || $end <= $start) {
                throw new CampaignValidationException('campaign_targeting_invalid', 'time_windows clock range is invalid.');
            }

            $windows[] = ['day_of_week' => $dayOfWeek, 'start' => $start, 'end' => $end];
        }

        return $windows;
    }

    private function validClock(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}

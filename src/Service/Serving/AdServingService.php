<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEventResult;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Repository\Serving\AdCandidateRepositoryInterface;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\ServingInventoryRepositoryInterface;

final readonly class AdServingService
{
    private const FRAME_SANDBOX = 'allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts';
    private const VIDEO_EVENT_TYPES = [
        'video_start',
        'video_25',
        'video_50',
        'video_75',
        'video_complete',
        'video_mute',
        'video_pause',
    ];

    public function __construct(
        private ServingInventoryRepositoryInterface $inventory,
        private AdCandidateRepositoryInterface $candidates,
        private AdDecisionRepositoryInterface $decisions,
        private AdEventRepositoryInterface $events,
        ?CampaignSpendEligibilityInterface $spendEligibility = null,
        ?AdSelectionPolicyInterface $selectionPolicy = null,
        ?ServingEventPolicy $eventPolicy = null,
    ) {
        $this->spendEligibility = $spendEligibility ?? new AllowAllCampaignSpendEligibility();
        $this->selectionPolicy = $selectionPolicy ?? new DefaultAdSelectionPolicy();
        $this->eventPolicy = $eventPolicy ?? new ServingEventPolicy(0.5, 1000, 30);
    }

    private CampaignSpendEligibilityInterface $spendEligibility;
    private AdSelectionPolicyInterface $selectionPolicy;
    private ServingEventPolicy $eventPolicy;

    /**
     * @param array{width:int,height:int}|null $size
     */
    public function serve(
        int $siteId,
        int $slotId,
        string $viewerId,
        ?array $size,
        bool $debug,
        DateTimeImmutable $now,
        string|ServingRequestContext|null $context = null,
    ): AdDecision {
        $width = $size['width'] ?? 1;
        $height = $size['height'] ?? 1;
        $context = $this->requestContext($context);

        if (!$this->inventory->isVerifiedActiveSlot($siteId, $slotId)) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'unverified_inventory', $now, $context));
        }

        $candidates = $this->candidates->eligibleCandidatesForSlot($siteId, $slotId, $size);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'no_eligible_ad', $now, $context));
        }

        $candidates = $this->filterGeoTargetedCandidates($candidates, $context->geoCode);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'geo_target_mismatch', $now, $context));
        }

        $trafficRisk = $this->selectionPolicy->trafficRisk($siteId, $slotId, $viewerId);
        if (!$trafficRisk->allowed) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, $trafficRisk->reason ?? 'fraud_high_risk', $now, $context));
        }

        $candidates = $this->selectionPolicy->rankCandidates($candidates, $siteId, $slotId, $viewerId, $now);
        $budgetRejection = null;
        $frequencyCapped = false;
        foreach ($candidates as $candidate) {
            if (!$this->isSafeLandingUrl($candidate->landingUrl)) {
                continue;
            }

            if (!$this->selectionPolicy->canServeCandidate($candidate, $siteId, $slotId, $viewerId, $now)) {
                $frequencyCapped = true;
                continue;
            }

            $budgetRejection = $this->budgetRejection($candidate, $now);
            if ($budgetRejection !== null) {
                continue;
            }

            $decision = $this->filled($siteId, $slotId, $viewerId, $candidate, $now, $context);
            $this->selectionPolicy->recordServe($candidate, $siteId, $slotId, $viewerId, $now);

            return $this->save($decision);
        }

        if ($budgetRejection !== null) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'budget_' . $budgetRejection->value, $now, $context));
        }

        if ($frequencyCapped) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'frequency_cap_exceeded', $now, $context));
        }

        return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'unsafe_landing_url', $now, $context));
    }

    public function trackImpression(
        string $decisionId,
        string $viewerId,
        float $visibleRatio,
        int $visibleMs,
        string $eventId,
        DateTimeImmutable $occurredAt,
        ?string $requestId = null,
    ): AdEventResult {
        $decision = $this->decisions->find($decisionId);
        if ($decision === null || !$decision->filled || $decision->viewerId !== $viewerId) {
            return AdEventResult::rejected('decision_not_found');
        }

        if ($this->events->hasEvent('impression', $eventId)) {
            return AdEventResult::accepted(duplicate: true);
        }

        if ($visibleRatio < $this->eventPolicy->minVisibleRatio || $visibleMs < $this->eventPolicy->minVisibleMs) {
            return AdEventResult::rejected('viewability_threshold_not_met');
        }

        $this->events->recordImpression($decision, $eventId, $visibleRatio, $visibleMs, $occurredAt, $requestId);

        return AdEventResult::accepted();
    }

    public function trackVideoEvent(
        string $decisionId,
        string $viewerId,
        string $eventType,
        string $eventId,
        DateTimeImmutable $occurredAt,
        ?string $requestId = null,
    ): AdEventResult {
        $eventType = trim($eventType);
        if (!in_array($eventType, self::VIDEO_EVENT_TYPES, true)) {
            return AdEventResult::rejected('invalid_event_type');
        }

        $decision = $this->decisions->find($decisionId);
        if ($decision === null || !$decision->filled || $decision->viewerId !== $viewerId) {
            return AdEventResult::rejected('decision_not_found');
        }

        if ($this->events->hasEvent($eventType, $eventId)) {
            return AdEventResult::accepted(duplicate: true);
        }

        $this->events->recordVideoEvent($decision, $eventType, $eventId, $occurredAt, $requestId);

        return AdEventResult::accepted();
    }

    public function recordClick(
        string $decisionId,
        string $viewerId,
        string $eventId,
        DateTimeImmutable $occurredAt,
        ?string $requestId = null,
    ): AdEventResult {
        $decision = $this->decisions->find($decisionId);
        if ($decision === null || !$decision->filled || $decision->viewerId !== $viewerId || $decision->landingUrl === null) {
            return AdEventResult::rejected('decision_not_found');
        }

        if (!$this->isSafeLandingUrl($decision->landingUrl)) {
            return AdEventResult::rejected('unsafe_landing_url');
        }

        $existingClick = $this->events->findEvent('click', $eventId);
        if ($existingClick !== null) {
            if (!$existingClick->valid) {
                return AdEventResult::rejected($existingClick->reason ?? 'invalid_click');
            }

            return AdEventResult::accepted(duplicate: true, redirectUrl: $decision->landingUrl);
        }

        if (!$this->events->hasValidImpression($decision->decisionId, $viewerId)) {
            return AdEventResult::rejected('valid_impression_required');
        }

        if ($this->events->hasRecentValidClick(
            $decision->decisionId,
            $viewerId,
            $occurredAt,
            $this->eventPolicy->repeatClickWindowSeconds,
        )) {
            $this->events->recordInvalidClick($decision, $eventId, $occurredAt, 'repeat_click_window', $requestId);

            return AdEventResult::rejected('repeat_click_window');
        }

        $this->events->recordClick($decision, $eventId, $occurredAt, $requestId);
        if ($decision->campaignId !== null) {
            $this->selectionPolicy->recordClick($decision->campaignId, $decision->slotId, $viewerId, $occurredAt);
        }

        return AdEventResult::accepted(redirectUrl: $decision->landingUrl);
    }

    private function save(AdDecision $decision): AdDecision
    {
        $this->decisions->save($decision);

        return $decision;
    }

    private function noFill(
        int $siteId,
        int $slotId,
        string $viewerId,
        int $width,
        int $height,
        string $reason,
        DateTimeImmutable $now,
        ServingRequestContext $context,
    ): AdDecision {
        $escapedReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new AdDecision(
            decisionId: 'no-fill:' . $siteId . ':' . $slotId . ':' . $viewerId,
            siteId: $siteId,
            slotId: $slotId,
            viewerId: $viewerId,
            filled: false,
            reason: $reason,
            iframeHtml: '<iframe sandbox="" width="' . $width . '" height="' . $height . '" data-vertoad-no-fill="1" title="Advertisement" srcdoc="<div data-reason=&quot;' . $escapedReason . '&quot;></div>"></iframe>',
            width: $width,
            height: $height,
            adId: null,
            campaignId: null,
            advertiserOrganizationId: null,
            publisherOrganizationId: null,
            impressionCostPoints: null,
            clickCostPoints: null,
            landingUrl: null,
            decidedAt: $now,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            geoCode: $context->geoCode,
        );
    }

    private function filled(int $siteId, int $slotId, string $viewerId, AdCandidate $candidate, DateTimeImmutable $now, ServingRequestContext $context): AdDecision
    {
        $decisionId = 'ad:' . hash('sha256', $siteId . '|' . $slotId . '|' . $viewerId . '|' . $candidate->adId . '|' . $now->format(DATE_ATOM));
        $creative = htmlspecialchars($this->creativeSrcdoc($decisionId, $viewerId, $candidate), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new AdDecision(
            decisionId: $decisionId,
            siteId: $siteId,
            slotId: $slotId,
            viewerId: $viewerId,
            filled: true,
            reason: null,
            iframeHtml: '<iframe sandbox="' . self::FRAME_SANDBOX . '" width="' . $candidate->width . '" height="' . $candidate->height . '" title="Advertisement" srcdoc="' . $creative . '"></iframe>',
            width: $candidate->width,
            height: $candidate->height,
            adId: $candidate->adId,
            campaignId: $candidate->campaignId,
            advertiserOrganizationId: $candidate->advertiserOrganizationId,
            publisherOrganizationId: $this->inventory->publisherOrganizationIdForSlot($siteId, $slotId),
            impressionCostPoints: $candidate->impressionCostPoints,
            clickCostPoints: $candidate->clickCostPoints,
            landingUrl: $candidate->landingUrl,
            decidedAt: $now,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            geoCode: $context->geoCode,
        );
    }

    private function creativeSrcdoc(string $decisionId, string $viewerId, AdCandidate $candidate): string
    {
        $payload = [
            'decision_id' => $decisionId,
            'render_mode' => $candidate->assetType === 'fabric_snapshot' ? 'fabric-json' : 'asset-fallback',
            'asset_type' => $candidate->assetType,
            'asset_object_key' => $candidate->assetObjectKey,
            'asset_content_type' => $candidate->assetContentType,
            'fallback_object_key' => $candidate->assetObjectKey,
            'width' => $candidate->width,
            'height' => $candidate->height,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $asset = htmlspecialchars($candidate->assetObjectKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = htmlspecialchars($candidate->assetType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $clickUrl = htmlspecialchars($this->fallbackClickUrl($decisionId, $viewerId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fallback = $candidate->assetObjectKey !== ''
            ? '<img alt="Advertisement" data-vertoad-fallback="snapshot" src="' . $asset . '">'
            : '<div data-vertoad-fallback="empty"></div>';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}.vertoad-frame{display:grid;place-items:center;width:100%;height:100%}.vertoad-click-target{display:grid;place-items:center;width:100%;height:100%;text-decoration:none;color:inherit}.vertoad-frame img{display:block;max-width:100%;max-height:100%;object-fit:contain}</style>'
            . '</head><body><div class="vertoad-frame" data-vertoad-renderer="platform-controlled" data-vertoad-asset-type="' . $type . '">'
            . '<script type="application/json" id="vertoad-render-payload">' . $json . '</script>'
            . '<a class="vertoad-click-target" data-vertoad-click-target href="' . $clickUrl . '" target="_blank" rel="noopener noreferrer">'
            . $fallback
            . '</a>'
            . '</div></body></html>';
    }

    private function fallbackClickUrl(string $decisionId, string $viewerId): string
    {
        return $this->clickBaseUrl($decisionId, $viewerId)
            . '&event_id=' . rawurlencode('clk:fallback:' . substr(hash('sha256', $decisionId . '|' . $viewerId), 0, 32));
    }

    private function clickBaseUrl(string $decisionId, string $viewerId): string
    {
        return '/api/v1/ads/click?' . http_build_query([
            'decision_id' => $decisionId,
            'viewer_id' => $viewerId,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function budgetRejection(AdCandidate $candidate, DateTimeImmutable $now): ?SpendFailureReason
    {
        return $this->spendEligibility->rejectionReason(
            $candidate->advertiserOrganizationId,
            $candidate->campaignId,
            max($candidate->impressionCostPoints, $candidate->clickCostPoints),
            $now,
        );
    }

    /**
     * @param list<AdCandidate> $candidates
     * @return list<AdCandidate>
     */
    private function filterGeoTargetedCandidates(array $candidates, ?string $geoCode): array
    {
        $geoCode = $this->normalizeGeo($geoCode);

        return array_values(array_filter(
            $candidates,
            function (AdCandidate $candidate) use ($geoCode): bool {
                if ($candidate->geos === []) {
                    return true;
                }

                if ($geoCode === null) {
                    return false;
                }

                foreach ($candidate->geos as $targetGeo) {
                    $normalizedTarget = $this->normalizeGeo($targetGeo);
                    if ($normalizedTarget !== null && ($geoCode === $normalizedTarget || str_starts_with($geoCode, $normalizedTarget . '-'))) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    private function requestContext(string|ServingRequestContext|null $context): ServingRequestContext
    {
        if ($context instanceof ServingRequestContext) {
            return $context;
        }

        if (is_string($context)) {
            return new ServingRequestContext(geoCode: $context);
        }

        return new ServingRequestContext();
    }

    private function normalizeGeo(?string $geo): ?string
    {
        if ($geo === null) {
            return null;
        }

        $geo = strtoupper(trim($geo));

        return $geo === '' ? null : $geo;
    }

    private function isSafeLandingUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }

        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || filter_var($host, FILTER_VALIDATE_IP) === false;
    }
}

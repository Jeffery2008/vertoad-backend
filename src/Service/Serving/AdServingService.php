<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use Throwable;
use VertoAD\Domain\Budget\SpendFailureReason;
use VertoAD\Domain\Operations\OperationRiskDecisionLog;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEventResult;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Domain\Serving\ServingEventPolicy;
use VertoAD\Repository\Operations\OperationRiskDecisionLogRepositoryInterface;
use VertoAD\Repository\Cron\ServingRequestEventBufferInterface;
use VertoAD\Repository\Serving\AdCandidateRepositoryInterface;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\ServingInventoryRepositoryInterface;
use VertoAD\Service\Billing\CpmBillingUnavailableException;
use VertoAD\Service\Billing\CpmChargeEstimatorInterface;

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
    private const SERVABLE_ASSET_TYPES = ['image', 'video', 'fabric_snapshot', 'text', 'html_placeholder'];

    public function __construct(
        private ServingInventoryRepositoryInterface $inventory,
        private AdCandidateRepositoryInterface $candidates,
        private AdDecisionRepositoryInterface $decisions,
        private AdEventRepositoryInterface $events,
        ?CampaignSpendEligibilityInterface $spendEligibility = null,
        ?AdSelectionPolicyInterface $selectionPolicy = null,
        ?ServingEventPolicy $eventPolicy = null,
        ?OperationRiskDecisionLogRepositoryInterface $riskDecisions = null,
        ?UserAgentDeviceClassifier $deviceClassifier = null,
        ?string $fabricRendererUrl = null,
        ?ServingRequestEventBufferInterface $serveEvents = null,
        ?CpmChargeEstimatorInterface $cpmChargeEstimator = null,
    ) {
        $this->spendEligibility = $spendEligibility ?? new AllowAllCampaignSpendEligibility();
        $this->selectionPolicy = $selectionPolicy ?? new DefaultAdSelectionPolicy();
        $this->eventPolicy = $eventPolicy ?? new ServingEventPolicy(0.5, 1000, 30);
        $this->riskDecisions = $riskDecisions;
        $this->deviceClassifier = $deviceClassifier ?? new UserAgentDeviceClassifier();
        $fabricRendererUrl = trim((string) $fabricRendererUrl);
        if ($fabricRendererUrl !== '' && !$this->isSafePublicResourceUrl($fabricRendererUrl)) {
            throw new \InvalidArgumentException('Fabric renderer URL must use HTTPS, except for localhost development.');
        }
        $this->fabricRendererUrl = $fabricRendererUrl === '' ? null : $fabricRendererUrl;
        $this->serveEvents = $serveEvents;
        $this->cpmChargeEstimator = $cpmChargeEstimator;
    }

    private CampaignSpendEligibilityInterface $spendEligibility;
    private AdSelectionPolicyInterface $selectionPolicy;
    private ServingEventPolicy $eventPolicy;
    private ?OperationRiskDecisionLogRepositoryInterface $riskDecisions;
    private UserAgentDeviceClassifier $deviceClassifier;
    private ?string $fabricRendererUrl;
    private ?ServingRequestEventBufferInterface $serveEvents;
    private ?CpmChargeEstimatorInterface $cpmChargeEstimator;

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

        $candidates = $this->candidates->eligibleCandidatesForSlot($siteId, $slotId, $size, $now);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'no_eligible_ad', $now, $context));
        }

        $candidates = $this->filterGeoTargetedCandidates($candidates, $context->geoCode);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'geo_target_mismatch', $now, $context));
        }

        $candidates = $this->filterDeviceTargetedCandidates($candidates, $context->userAgent);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'device_target_mismatch', $now, $context));
        }

        $candidates = $this->filterTimeTargetedCandidates($candidates, $now);
        if ($candidates === []) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'time_target_mismatch', $now, $context));
        }

        $trafficRisk = $this->selectionPolicy->trafficRisk($siteId, $slotId, $viewerId);
        if (!$trafficRisk->allowed) {
            $decision = $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, $trafficRisk->reason ?? 'fraud_high_risk', $now, $context));
            $this->appendRiskDecisionLog(
                decision: $decision,
                action: 'ads.serve.risk_rejected',
                reasonCodes: [$trafficRisk->reason ?? 'fraud_high_risk'],
                subjectType: 'ad_decision',
                subjectId: $decision->decisionId,
                occurredAt: $now,
                requestId: $decision->requestId,
                endpoint: $context->endpoint ?? '/api/v1/ads/serve',
                httpMethod: $context->httpMethod ?? 'POST',
            );

            return $decision;
        }

        $candidates = $this->selectionPolicy->rankCandidates($candidates, $siteId, $slotId, $viewerId, $now);
        $budgetRejection = null;
        $frequencyCapped = false;
        $unsafeAsset = false;
        $unsafeLanding = false;
        $cpmBillingRejection = null;
        foreach ($candidates as $candidate) {
            if (!$this->isSafeLandingUrl($candidate->landingUrl)) {
                $unsafeLanding = true;
                continue;
            }

            if (!$this->isRenderableCandidate($candidate)) {
                $unsafeAsset = true;
                continue;
            }

            if (!$this->selectionPolicy->canServeCandidate($candidate, $siteId, $slotId, $viewerId, $now)) {
                $frequencyCapped = true;
                continue;
            }

            try {
                $budgetRejection = $this->budgetRejection($candidate, $siteId, $slotId, $now);
            } catch (CpmBillingUnavailableException $exception) {
                $cpmBillingRejection = $exception->reason;
                continue;
            }
            if ($budgetRejection !== null) {
                continue;
            }

            $decision = $this->filled($siteId, $slotId, $viewerId, $candidate, $now, $context);
            $this->selectionPolicy->recordServe($candidate, $siteId, $slotId, $viewerId, $now);

            return $this->save($decision);
        }

        if ($cpmBillingRejection !== null) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, $cpmBillingRejection, $now, $context));
        }

        if ($budgetRejection !== null) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'budget_' . $budgetRejection->value, $now, $context));
        }

        if ($frequencyCapped) {
            return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, 'frequency_cap_exceeded', $now, $context));
        }

        $reason = $unsafeAsset && !$unsafeLanding ? 'unsafe_asset_url' : 'unsafe_landing_url';

        return $this->save($this->noFill($siteId, $slotId, $viewerId, $width, $height, $reason, $now, $context));
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
        ?ServingRequestContext $context = null,
    ): AdEventResult {
        $decision = $this->decisions->find($decisionId);
        if ($decision === null || !$decision->filled || $decision->viewerId !== $viewerId || $decision->landingUrl === null) {
            return AdEventResult::rejected('decision_not_found');
        }
        $requestId = $this->nullableString($requestId) ?? $this->nullableString($context?->requestId);
        $clickDecision = $this->decisionWithRequestContext($decision, $requestId, $context);

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
            $this->events->recordInvalidClick($clickDecision, $eventId, $occurredAt, 'repeat_click_window', $requestId);
            $this->appendRiskDecisionLog(
                decision: $clickDecision,
                action: 'ads.click.invalid',
                reasonCodes: ['repeat_click_window'],
                subjectType: 'click',
                subjectId: $eventId,
                occurredAt: $occurredAt,
                requestId: $requestId,
                endpoint: $context?->endpoint ?? '/api/v1/ads/click',
                httpMethod: $context?->httpMethod ?? 'GET',
            );

            return AdEventResult::rejected('repeat_click_window');
        }

        $trafficRisk = $this->selectionPolicy->trafficRisk($decision->siteId, $decision->slotId, $viewerId);
        if (!$trafficRisk->allowed) {
            $reason = $this->nullableString($trafficRisk->reason) ?? 'fraud_high_risk';
            $this->events->recordInvalidClick($clickDecision, $eventId, $occurredAt, $reason, $requestId);
            $this->appendRiskDecisionLog(
                decision: $clickDecision,
                action: 'ads.click.invalid',
                reasonCodes: [$reason],
                subjectType: 'click',
                subjectId: $eventId,
                occurredAt: $occurredAt,
                requestId: $requestId,
                endpoint: $context?->endpoint ?? '/api/v1/ads/click',
                httpMethod: $context?->httpMethod ?? 'GET',
            );

            return AdEventResult::rejected($reason);
        }

        $this->events->recordClick($clickDecision, $eventId, $occurredAt, $requestId);
        if ($decision->campaignId !== null) {
            $this->selectionPolicy->recordClick($decision->campaignId, $decision->slotId, $viewerId, $occurredAt);
        }

        return AdEventResult::accepted(redirectUrl: $decision->landingUrl);
    }

    private function save(AdDecision $decision): AdDecision
    {
        $this->decisions->save($decision);
        $this->serveEvents?->recordServe($decision);

        return $decision;
    }

    /**
     * @param list<string> $reasonCodes
     */
    private function appendRiskDecisionLog(
        AdDecision $decision,
        string $action,
        array $reasonCodes,
        ?string $subjectType,
        ?string $subjectId,
        DateTimeImmutable $occurredAt,
        ?string $requestId,
        ?string $endpoint,
        ?string $httpMethod,
    ): void {
        if ($this->riskDecisions === null) {
            return;
        }

        $requestId = $this->nullableString($requestId);
        if ($requestId === null) {
            return;
        }

        $reasonCodes = array_values(array_filter(
            array_map(static fn (string $reason): string => trim($reason), $reasonCodes),
            static fn (string $reason): bool => $reason !== '',
        ));
        if ($reasonCodes === []) {
            $reasonCodes = ['invalid_traffic'];
        }

        try {
            $this->riskDecisions->append(new OperationRiskDecisionLog(
                decision_id: $this->riskDecisionId($action, $requestId, $subjectType, $subjectId, $occurredAt),
                request_id: $requestId,
                action: $action,
                risk_score: 100,
                reason_codes: $reasonCodes,
                subject_type: $subjectType,
                subject_id: $subjectId,
                ip_address: $decision->ipAddress,
                endpoint: $this->nullableString($endpoint),
                http_method: $this->normalizeHttpMethod($httpMethod),
                user_agent: $decision->userAgent,
                site_id: $decision->siteId,
                slot_id: $decision->slotId,
                campaign_id: $decision->campaignId,
                viewer_id: $decision->viewerId,
                ad_decision_id: $decision->decisionId,
                occurred_at: $occurredAt,
            ));
        } catch (Throwable) {
            return;
        }
    }

    private function riskDecisionId(
        string $action,
        string $requestId,
        ?string $subjectType,
        ?string $subjectId,
        DateTimeImmutable $occurredAt,
    ): string {
        return 'risk:' . substr(hash(
            'sha256',
            implode('|', [
                $action,
                $requestId,
                $subjectType ?? '',
                $subjectId ?? '',
                $occurredAt->format('U.u'),
            ]),
        ), 0, 48);
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
            decisionId: $this->newDecisionId('no-fill'),
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
        $decisionId = $this->newDecisionId('ad');
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

    private function newDecisionId(string $prefix): string
    {
        return $prefix . ':' . bin2hex(random_bytes(16));
    }

    private function creativeSrcdoc(string $decisionId, string $viewerId, AdCandidate $candidate): string
    {
        $renderMode = match ($candidate->assetType) {
            'fabric_snapshot' => 'fabric-json',
            'video' => 'video',
            'image', 'text' => 'snapshot',
            default => 'empty',
        };
        $payload = [
            'decision_id' => $decisionId,
            'render_mode' => $renderMode,
            'asset_type' => $candidate->assetType,
            'asset_content_type' => $candidate->assetContentType,
            'asset_url' => $candidate->assetUrl,
            'snapshot_png_url' => $candidate->snapshotPngUrl,
            'fallback_url' => $candidate->snapshotWebpUrl,
            'thumbnail_url' => $candidate->thumbnailWebpUrl,
            'width' => $candidate->width,
            'height' => $candidate->height,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $type = htmlspecialchars($candidate->assetType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $clickUrl = htmlspecialchars($this->fallbackClickUrl($decisionId, $viewerId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $creative = $this->creativeMarkup($candidate);
        $clickTarget = '<a class="vertoad-click-target" data-vertoad-click-target href="' . $clickUrl
            . '" target="_blank" rel="noopener noreferrer">' . $creative . '</a>';
        if ($candidate->assetType === 'video') {
            $clickTarget = $creative
                . '<a class="vertoad-click-target vertoad-video-cta" data-vertoad-click-target data-vertoad-video-cta href="'
                . $clickUrl
                . '" target="_blank" rel="noopener noreferrer" aria-label="Open advertiser landing page" title="Open advertiser landing page">'
                . '<span aria-hidden="true">&#8599;</span></a>';
        }
        $renderer = $candidate->assetType === 'fabric_snapshot' && $this->fabricRendererUrl !== null
            ? '<script defer data-vertoad-fabric-renderer src="'
                . htmlspecialchars($this->fabricRendererUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"></script>'
            : '';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}.vertoad-frame{display:grid;place-items:center;width:100%;height:100%}.vertoad-click-target{display:grid;place-items:center;width:100%;height:100%;text-decoration:none;color:inherit}.vertoad-media{grid-area:1/1;display:block;max-width:100%;max-height:100%;object-fit:contain}.vertoad-video-cta{grid-area:1/1;align-self:start;justify-self:end;z-index:2;width:32px;height:32px;margin:8px;border-radius:4px;background:#111827;color:#fff;font:700 18px/1 system-ui,sans-serif;box-shadow:0 1px 4px rgba(0,0,0,.35)}.vertoad-frame canvas{width:100%;height:100%}[hidden]{display:none!important}</style>'
            . '</head><body><div class="vertoad-frame" data-vertoad-renderer="platform-controlled" data-vertoad-asset-type="' . $type . '">'
            . '<script type="application/json" id="vertoad-render-payload">' . $json . '</script>'
            . $clickTarget
            . $renderer
            . '</div></body></html>';
    }

    private function creativeMarkup(AdCandidate $candidate): string
    {
        $fallback = htmlspecialchars($candidate->snapshotWebpUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($candidate->assetType) {
            'image', 'text' => '<img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" src="' . $fallback . '">',
            'video' => '<video class="vertoad-media" data-vertoad-video controls playsinline preload="metadata" poster="' . $fallback . '"><source src="'
                . htmlspecialchars($candidate->assetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" type="' . htmlspecialchars($candidate->assetContentType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"></video><img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" hidden src="' . $fallback . '">',
            'fabric_snapshot' => '<canvas class="vertoad-media" data-vertoad-fabric-canvas width="' . $candidate->width
                . '" height="' . $candidate->height . '" hidden></canvas><img class="vertoad-media" alt="Advertisement" data-vertoad-fallback="snapshot" src="' . $fallback . '">',
            default => '<div data-vertoad-fallback="empty"></div>',
        };
    }

    private function isRenderableCandidate(AdCandidate $candidate): bool
    {
        if (!in_array($candidate->assetType, self::SERVABLE_ASSET_TYPES, true)) {
            return false;
        }

        return match ($candidate->assetType) {
            'image' => str_starts_with($candidate->assetContentType, 'image/')
                && $this->isSafePublicResourceUrl($candidate->snapshotWebpUrl),
            'video' => in_array($candidate->assetContentType, ['video/mp4', 'video/webm'], true)
                && $this->isSafePublicResourceUrl($candidate->assetUrl)
                && $this->isSafePublicResourceUrl($candidate->snapshotWebpUrl),
            'fabric_snapshot' => $candidate->assetContentType === 'application/json'
                && $this->fabricRendererUrl !== null
                && $this->isSafePublicResourceUrl($candidate->assetUrl)
                && $this->isSafePublicResourceUrl($candidate->snapshotWebpUrl),
            'text' => $candidate->assetContentType === 'text/plain'
                && $this->isSafePublicResourceUrl($candidate->snapshotWebpUrl),
            'html_placeholder' => true,
            default => false,
        };
    }

    private function isSafePublicResourceUrl(string $url): bool
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)));
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

    private function budgetRejection(
        AdCandidate $candidate,
        int $siteId,
        int $slotId,
        DateTimeImmutable $now,
    ): ?SpendFailureReason
    {
        $pointsAmount = $candidate->clickCostPoints;
        if ($pointsAmount <= 0 && $candidate->impressionCostPoints > 0) {
            $publisherOrganizationId = $this->inventory->publisherOrganizationIdForSlot($siteId, $slotId);
            if ($publisherOrganizationId === null) {
                throw new CpmBillingUnavailableException('missing_publisher_organization');
            }
            if ($this->cpmChargeEstimator === null) {
                throw new CpmBillingUnavailableException('cpm_charge_estimator_unavailable');
            }
            $pointsAmount = $this->cpmChargeEstimator->nextChargePoints(
                $candidate->advertiserOrganizationId,
                $candidate->campaignId,
                $publisherOrganizationId,
                $siteId,
                $slotId,
                $candidate->impressionCostPoints,
            );
        }
        if ($pointsAmount <= 0) {
            return null;
        }

        return $this->spendEligibility->rejectionReason(
            $candidate->advertiserOrganizationId,
            $candidate->campaignId,
            $pointsAmount,
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

    /**
     * @param list<AdCandidate> $candidates
     * @return list<AdCandidate>
     */
    private function filterDeviceTargetedCandidates(array $candidates, ?string $userAgent): array
    {
        $device = $this->deviceClassifier->classify($userAgent);

        return array_values(array_filter(
            $candidates,
            static fn (AdCandidate $candidate): bool =>
                $candidate->devices === []
                || ($device !== null && in_array($device, $candidate->devices, true)),
        ));
    }

    /**
     * @param list<AdCandidate> $candidates
     * @return list<AdCandidate>
     */
    private function filterTimeTargetedCandidates(array $candidates, DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $candidates,
            static function (AdCandidate $candidate) use ($now): bool {
                if ($candidate->timeWindows === []) {
                    return true;
                }

                foreach ($candidate->timeWindows as $window) {
                    if ($window->matches($now)) {
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

    private function decisionWithRequestContext(AdDecision $decision, ?string $requestId, ?ServingRequestContext $context): AdDecision
    {
        if ($context === null) {
            return $decision;
        }

        return new AdDecision(
            decisionId: $decision->decisionId,
            siteId: $decision->siteId,
            slotId: $decision->slotId,
            viewerId: $decision->viewerId,
            filled: $decision->filled,
            reason: $decision->reason,
            iframeHtml: $decision->iframeHtml,
            width: $decision->width,
            height: $decision->height,
            adId: $decision->adId,
            campaignId: $decision->campaignId,
            advertiserOrganizationId: $decision->advertiserOrganizationId,
            publisherOrganizationId: $decision->publisherOrganizationId,
            impressionCostPoints: $decision->impressionCostPoints,
            clickCostPoints: $decision->clickCostPoints,
            landingUrl: $decision->landingUrl,
            decidedAt: $decision->decidedAt,
            requestId: $requestId ?? $decision->requestId,
            ipAddress: $this->nullableString($context->ipAddress) ?? $decision->ipAddress,
            userAgent: $this->nullableString($context->userAgent) ?? $decision->userAgent,
            geoCode: $this->normalizeGeo($context->geoCode) ?? $decision->geoCode,
        );
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeHttpMethod(?string $method): ?string
    {
        $method = $this->nullableString($method);

        return $method === null ? null : strtoupper($method);
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

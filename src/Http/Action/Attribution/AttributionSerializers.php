<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Attribution;

use VertoAD\Domain\Attribution\ConversionAttributionResult;

final readonly class AttributionSerializers
{
    /**
     * @return array<string, mixed>
     */
    public static function conversion(ConversionAttributionResult $result): array
    {
        return [
            'conversion_id' => $result->conversionId,
            'source' => $result->source,
            'conversion_name' => $result->conversionName,
            'value_points' => $result->valuePoints,
            'attribution' => [
                'model' => 'last_click',
                'attributed' => $result->attributed,
                'duplicate' => $result->duplicate,
                'click_event_id' => $result->clickEventId,
                'decision_id' => $result->decisionId,
                'campaign_id' => $result->campaignId,
                'window_seconds' => $result->windowSeconds,
            ],
        ];
    }
}

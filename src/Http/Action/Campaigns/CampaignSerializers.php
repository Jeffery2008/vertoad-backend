<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Campaigns;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Campaign\Campaign;

final class CampaignSerializers
{
    /** @return array<string, mixed> */
    public static function campaign(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'organization_id' => $campaign->organizationId,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'pricing_model' => $campaign->pricingModel->value,
            'bid_points' => $campaign->bidPoints,
            'landing_url' => $campaign->landingUrl,
            'creative_asset_id' => $campaign->creativeAssetId,
            'asset_id' => $campaign->creativeAssetId,
            'schedule' => [
                'starts_at' => $campaign->startsAt?->format(DATE_ATOM),
                'ends_at' => $campaign->endsAt?->format(DATE_ATOM),
            ],
            'targeting' => $campaign->targeting->toArray(),
            'budget' => [
                'total_cap_points' => $campaign->budget?->totalCapPoints,
                'daily_cap_points' => $campaign->budget?->dailyCapPoints,
                'hourly_cap_points' => $campaign->budget?->hourlyCapPoints,
            ],
        ];
    }

    /**
     * @param list<Campaign> $campaigns
     * @return list<array<string, mixed>>
     */
    public static function campaigns(array $campaigns): array
    {
        return array_map(static fn (Campaign $campaign): array => self::campaign($campaign), $campaigns);
    }

    /** @param array<string, mixed> $payload */
    public static function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}

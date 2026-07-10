<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Domain\Review\AiReviewPolicy;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\Webhooks\WebhookDeliveryPolicy;
use VertoAD\Install\BootstrapConfigCatalog;
use VertoAD\Service\Operations\ConfigVersionService;

final class BootstrapConfigCatalogTest extends TestCase
{
    public function testCatalogContainsEveryRequiredRuntimeConfigAndPassesBusinessValidation(): void
    {
        $catalog = BootstrapConfigCatalog::all();
        self::assertSame([
            'billing.default_revenue_share',
            'security.rate_limit',
            'attribution.default_window_seconds',
            'serving.event_validation',
            'serving.geo_provider',
            'webhook.delivery_policy',
            'security.turnstile_policy',
            'review.ai_policy',
            'assets.upload_policy',
        ], array_keys($catalog));

        $service = (new ReflectionClass(ConfigVersionService::class))->newInstanceWithoutConstructor();
        $validateKey = new ReflectionMethod(ConfigVersionService::class, 'assertValidKey');
        $validateValue = new ReflectionMethod(ConfigVersionService::class, 'assertValidValue');
        foreach ($catalog as $key => $value) {
            $validateKey->invoke($service, $key);
            $validateValue->invoke($service, $key, $value);
            self::assertNotSame([], $value);
        }

        self::assertTrue(IpGeoProviderPolicy::fromArray($catalog['serving.geo_provider'])->enabled);
        self::assertSame([], $catalog['serving.geo_provider']['providers']);
        self::assertCount(6, IpGeoProviderPolicy::fromArray($catalog['serving.geo_provider'])->providers);
        self::assertSame(900, AssetUploadPolicy::fromArray($catalog['assets.upload_policy'])->uploadIntentTtlSeconds);
        self::assertTrue(AiReviewPolicy::fromArray($catalog['review.ai_policy'])->enabled);
        self::assertTrue(TurnstilePolicy::fromArray($catalog['security.turnstile_policy'])->enabled);
        self::assertSame(50, WebhookDeliveryPolicy::fromArray($catalog['webhook.delivery_policy'])->batchSize);
    }
}

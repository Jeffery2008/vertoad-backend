<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\IpGeo\DisabledIpGeoRequestContextResolver;

final class DisabledIpGeoRequestContextResolverTest extends TestCase
{
    public function testContextPreservesValidIpUserAgentAndRequestIdWithoutGeoLookup(): void
    {
        $resolver = new DisabledIpGeoRequestContextResolver();

        $context = $resolver->contextForIpGeoRequest(
            ' 203.0.113.10 ',
            'Geo disabled browser',
            'CN',
            'req-disabled-geo',
        );

        self::assertSame('203.0.113.10', $context->ipAddress);
        self::assertSame('Geo disabled browser', $context->userAgent);
        self::assertNull($context->geoCode);
        self::assertNull($context->geoRecord);
        self::assertSame('req-disabled-geo', $context->requestId);
    }

    public function testContextDropsMissingBlankAndInvalidIpAddresses(): void
    {
        $resolver = new DisabledIpGeoRequestContextResolver();

        foreach ([null, '', '   ', 'not-an-ip'] as $ipAddress) {
            $context = $resolver->contextForIpGeoRequest($ipAddress, 'Geo disabled browser');

            self::assertNull($context->ipAddress);
            self::assertSame('Geo disabled browser', $context->userAgent);
            self::assertNull($context->geoCode);
            self::assertNull($context->geoRecord);
        }
    }
}

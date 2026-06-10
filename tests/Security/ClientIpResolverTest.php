<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Infrastructure\Security\ClientIpResolver;

final class ClientIpResolverTest extends TestCase
{
    public function testResolvesConfiguredCloudflareHeaderWhenTrustedProxyMatches(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/24'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('203.0.113.44', $resolver->resolve($request));
    }

    public function testFallsBackToRemoteAddressWhenProxyIsNotTrusted(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/24'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('192.0.2.10', $resolver->resolve($request));
    }

    public function testFallsBackToRemoteAddressWhenCloudflareHeaderIsInvalid(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/24'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', 'not-an-ip');

        self::assertSame('198.51.100.24', $resolver->resolve($request));
    }

    public function testRejectsForwardedHeaderWhenTrustedProxyListRequiresRemoteAddress(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/24'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => 'not-an-ip'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertNull($resolver->resolve($request));
    }

    public function testSupportsNonByteAlignedTrustedProxyRanges(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/25'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('203.0.113.44', $resolver->resolve($request));
    }

    public function testSupportsIpv6TrustedProxyRanges(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['2001:db8:100::/48'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '2001:db8:100::10'])
            ->withHeader('CF-Connecting-IP', '2001:db8:200::44');

        self::assertSame('2001:db8:200::44', $resolver->resolve($request));
    }

    public function testFallsBackToRemoteAddressWhenTrustedProxyListIsEmpty(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => [],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('198.51.100.24', $resolver->resolve($request));
    }

    public function testReturnsNullWhenNoValidAddressExists(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => [],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => ' '])
            ->withHeader('CF-Connecting-IP', ' ');

        self::assertNull($resolver->resolve($request));
    }

    public function testFallsBackToDefaultHeaderAndEmptyProxyListForInvalidSettings(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => ' ',
            'trusted_proxies' => 'not-a-list',
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('198.51.100.24', $resolver->resolve($request));
    }

    public function testTrustsExactProxyAddress(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.24'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('203.0.113.44', $resolver->resolve($request));
    }

    public function testIgnoresInvalidTrustedProxyEntries(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['not-an-ip', '198.51.100.0/not-a-prefix', '2001:db8::/32'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('198.51.100.24', $resolver->resolve($request));
    }

    public function testRejectsOutOfRangeTrustedProxyPrefix(): void
    {
        $resolver = ClientIpResolver::fromSettings([
            'real_ip_header' => 'CF-Connecting-IP',
            'trusted_proxies' => ['198.51.100.0/33'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/probe', ['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeader('CF-Connecting-IP', '203.0.113.44');

        self::assertSame('198.51.100.24', $resolver->resolve($request));
    }
}

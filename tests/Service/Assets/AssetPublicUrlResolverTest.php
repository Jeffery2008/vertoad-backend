<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VertoAD\Service\Assets\AssetPublicUrlResolver;

final class AssetPublicUrlResolverTest extends TestCase
{
    public function testBuildsStrictAbsoluteUrlsAndCspSource(): void
    {
        $resolver = new AssetPublicUrlResolver('https://ASSETS.example.test:8443/creative-assets/');
        $url = $resolver->urlFor('organizations/99/assets/space name.webp');

        self::assertSame(
            'https://ASSETS.example.test:8443/creative-assets/organizations/99/assets/space%20name.webp',
            $url,
        );
        self::assertSame('https://ASSETS.example.test:8443/creative-assets', $resolver->baseUrl());
        self::assertSame('https://assets.example.test:8443', $resolver->cspSource());
        self::assertTrue($resolver->isTrustedUrl($url));
    }

    public function testAllowsHttpOnlyWhenExplicitlyConfigured(): void
    {
        $resolver = new AssetPublicUrlResolver('http://localhost:9000/vertoad', true);
        self::assertTrue($resolver->isTrustedUrl('http://localhost:9000/vertoad/assets/a.png'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidBaseUrls(): iterable
    {
        yield 'relative' => ['/assets', false];
        yield 'http production' => ['http://assets.test', false];
        yield 'unsupported scheme' => ['ftp://assets.test', true];
        yield 'missing host' => ['https:///assets', false];
        yield 'credentials' => ['https://user:pass@assets.test', false];
        yield 'query' => ['https://assets.test?x=1', false];
        yield 'fragment' => ['https://assets.test#x', false];
        yield 'dot segment' => ['https://assets.test/a/../b', false];
        yield 'encoded slash' => ['https://assets.test/a%2Fb', false];
        yield 'empty segment' => ['https://assets.test/a//b', false];
    }

    #[DataProvider('invalidBaseUrls')]
    public function testRejectsInvalidBaseUrls(string $url, bool $allowHttp): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetPublicUrlResolver($url, $allowHttp);
    }

    /** @return iterable<string, array{string}> */
    public static function untrustedUrls(): iterable
    {
        yield 'invalid' => ['not-a-url'];
        yield 'credentials' => ['https://user@assets.test/base/a.png'];
        yield 'query' => ['https://assets.test/base/a.png?x=1'];
        yield 'fragment' => ['https://assets.test/base/a.png#x'];
        yield 'scheme mismatch' => ['http://assets.test/base/a.png'];
        yield 'host mismatch' => ['https://evil.test/base/a.png'];
        yield 'port mismatch' => ['https://assets.test:8443/base/a.png'];
        yield 'prefix confusion' => ['https://assets.test/base-evil/a.png'];
        yield 'empty relative path' => ['https://assets.test/base/'];
        yield 'dot segment' => ['https://assets.test/base/../a.png'];
        yield 'encoded slash' => ['https://assets.test/base/a%2Fb.png'];
        yield 'noncanonical encoding' => ['https://assets.test/base/%61.png'];
        yield 'control' => ['https://assets.test/base/%00.png'];
    }

    #[DataProvider('untrustedUrls')]
    public function testRejectsUntrustedOrNoncanonicalUrls(string $url): void
    {
        self::assertFalse((new AssetPublicUrlResolver('https://assets.test/base'))->isTrustedUrl($url));
    }

    public function testSupportsOriginRootWithoutAPath(): void
    {
        $resolver = new AssetPublicUrlResolver('https://assets.test/');
        self::assertTrue($resolver->isTrustedUrl('https://assets.test/a.png'));
    }
}

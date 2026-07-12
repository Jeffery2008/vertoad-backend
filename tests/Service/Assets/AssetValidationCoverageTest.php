<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\Assets\AssetPublicUrlResolver;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class AssetValidationCoverageTest extends TestCase
{
    public function testOverlongDecodedObjectKeyIsUntrustedInsteadOfEscapingValidation(): void
    {
        $resolver = new AssetPublicUrlResolver('https://assets.example.test/base');

        self::assertFalse($resolver->isTrustedUrl('https://assets.example.test/base/' . str_repeat('a', 513)));
    }

    public function testNullAndEmptyColorsRemainValidStaticColorValues(): void
    {
        $payload = (new FabricCreativePayloadValidator())->validate(AssetTestFixtures::fabric([
            ['id' => 'shape-null', 'type' => 'rect', 'fill' => null],
            ['id' => 'shape-empty', 'type' => 'circle', 'stroke' => ''],
        ]));

        self::assertCount(2, $payload->fabricJson['objects']);
    }
}

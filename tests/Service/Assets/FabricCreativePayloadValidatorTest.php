<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Service\Assets\AssetPublicUrlResolver;
use VertoAD\Service\Assets\AssetValidationException;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class FabricCreativePayloadValidatorTest extends TestCase
{
    public function testAcceptsControlledRendererPayloadAndVerifiedImageSources(): void
    {
        $embedded = AssetTestFixtures::dataUrl('image/jpeg', AssetTestFixtures::image('image/jpeg'));
        $objects = [
            ['id' => 'copy-1', 'type' => 'Textbox', 'text' => 'Launch today', 'fill' => '#0f172a', 'filters' => []],
            ['id' => 'shape-1', 'type' => 'Rect', 'fill' => 'rgba(14, 116, 144, 0.8)'],
            ['id' => 'image-1', 'type' => 'Image', 'src' => $embedded, 'filters' => [['type' => 'Brightness']]],
            [
                'id' => 'image-2',
                'type' => 'image',
                'src' => 'https://assets.example.test/creative-assets/organizations/99/assets/source.webp',
                'filters' => [['type' => 'grayscale']],
            ],
        ];
        $validator = new FabricCreativePayloadValidator(
            new AssetPublicUrlResolver('https://assets.example.test/creative-assets'),
        );

        $payload = $validator->validate(AssetTestFixtures::fabric($objects));

        self::assertSame('image/png', $payload->snapshotContentType);
        self::assertSame(4, $payload->width);
        self::assertSame(3, $payload->height);
        self::assertCount(4, $payload->fabricJson['objects']);
    }

    public function testAcceptsWebpFallbackAndEverySupportedObjectAndFilterType(): void
    {
        $objects = [];
        foreach (['textbox', 'text', 'itext'] as $index => $type) {
            $objects[] = ['id' => 'text-' . $index, 'type' => $type, 'text' => '', 'textBackgroundColor' => 'transparent'];
        }
        foreach (['rect', 'circle', 'ellipse', 'line'] as $index => $type) {
            $objects[] = ['id' => 'shape-' . $index, 'type' => $type, 'fill' => 'hsl(190, 82%, 31%)'];
        }
        $objects[] = [
            'id' => 'image-filters',
            'type' => 'image',
            'src' => AssetTestFixtures::dataUrl('image/webp', AssetTestFixtures::image('image/webp')),
            'filters' => array_map(
                static fn (string $type): array => ['type' => $type],
                ['contrast', 'saturation', 'blur'],
            ),
        ];
        $snapshot = AssetTestFixtures::dataUrl('image/webp', AssetTestFixtures::image('image/webp', 2, 2));

        $payload = (new FabricCreativePayloadValidator())->validate(AssetTestFixtures::fabric($objects, snapshot: $snapshot));

        self::assertSame('image/webp', $payload->snapshotContentType);
        self::assertSame(2, $payload->width);
        self::assertSame(2, $payload->height);
    }

    public function testRejectsInvalidRootCanvasAndSnapshotContracts(): void
    {
        $valid = $this->decoded();
        $cases = [
            'invalid JSON' => '{',
            'list root' => '[]',
            'wrong discriminator' => $this->encoded(array_replace($valid, ['asset_type' => 'html_ad'])),
            'extra root field' => $this->encoded(array_replace($valid, ['script' => 'alert(1)'])),
            'missing fabric object list' => $this->encoded(array_replace($valid, ['fabric_json' => []])),
            'fabric list root' => $this->encoded(array_replace($valid, ['fabric_json' => []])),
            'unsupported canvas field' => $this->encoded(array_replace($valid, [
                'fabric_json' => ['objects' => [], 'overlayImage' => ['src' => 'https://evil.test/x.png']],
            ])),
            'unsafe canvas color' => $this->encoded(array_replace($valid, [
                'fabric_json' => ['objects' => [], 'backgroundColor' => 'url(https://evil.test/x.png)'],
            ])),
            'invalid version type' => $this->encoded(array_replace($valid, [
                'fabric_json' => ['objects' => [], 'version' => 7],
            ])),
            'too long version' => $this->encoded(array_replace($valid, [
                'fabric_json' => ['objects' => [], 'version' => str_repeat('a', 33)],
            ])),
            'too many objects' => $this->encoded(array_replace($valid, [
                'fabric_json' => ['objects' => array_fill(0, 2, ['id' => 'x', 'type' => 'rect'])],
            ])),
            'snapshot list' => $this->encoded(array_replace($valid, ['snapshot' => []])),
            'snapshot extra field' => $this->encoded(array_replace($valid, [
                'snapshot' => $valid['snapshot'] + ['url' => 'https://evil.test'],
            ])),
            'snapshot wrong usage' => $this->encoded(array_replace($valid, [
                'snapshot' => array_replace($valid['snapshot'], ['usage' => 'runtime']),
            ])),
            'snapshot invalid data URL' => $this->encoded(array_replace($valid, [
                'snapshot' => array_replace($valid['snapshot'], ['data_url' => 'https://evil.test/x.png']),
            ])),
            'snapshot unsupported MIME' => $this->withSnapshot($valid, 'image/jpeg', AssetTestFixtures::image('image/jpeg')),
            'snapshot MIME spoof' => $this->withSnapshot($valid, 'image/png', AssetTestFixtures::image('image/webp')),
            'snapshot invalid dimensions' => $this->withSnapshot($valid, 'image/png', AssetTestFixtures::image()),
        ];

        foreach ($cases as $label => $body) {
            $validator = str_contains($label, 'too many')
                ? new FabricCreativePayloadValidator(maxObjects: 1)
                : (str_contains($label, 'dimensions')
                    ? new FabricCreativePayloadValidator(maxWidth: 1, maxHeight: 1)
                    : new FabricCreativePayloadValidator());
            $this->assertInvalid($validator, $body, $label);
        }
    }

    public function testRejectsUnsafeObjectsFiltersAndSources(): void
    {
        $png = AssetTestFixtures::dataUrl('image/png', AssetTestFixtures::image());
        $cases = [
            'object list item' => [42],
            'missing id' => [['type' => 'rect']],
            'invalid id' => [['id' => '../bad', 'type' => 'rect']],
            'unsupported type' => [['id' => 'x', 'type' => 'group']],
            'clip path' => [['id' => 'x', 'type' => 'rect', 'clipPath' => ['type' => 'rect']]],
            'unsafe color' => [['id' => 'x', 'type' => 'rect', 'fill' => 'url(javascript:alert(1))']],
            'resize filter' => [['id' => 'x', 'type' => 'image', 'src' => $png, 'resizeFilter' => ['type' => 'Resize']]],
            'path' => [['id' => 'x', 'type' => 'textbox', 'text' => 'x', 'path' => ['type' => 'path']]],
            'eraser' => [['id' => 'x', 'type' => 'rect', 'eraser' => ['type' => 'group']]],
            'missing text' => [['id' => 'x', 'type' => 'textbox']],
            'long text' => [['id' => 'x', 'type' => 'text', 'text' => 'ab']],
            'missing image source' => [['id' => 'x', 'type' => 'image']],
            'untrusted image source' => [['id' => 'x', 'type' => 'image', 'src' => 'https://evil.test/x.png']],
            'embedded SVG' => [[
                'id' => 'x',
                'type' => 'image',
                'src' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
            ]],
            'spoofed image MIME' => [[
                'id' => 'x',
                'type' => 'image',
                'src' => AssetTestFixtures::dataUrl('image/png', AssetTestFixtures::image('image/jpeg')),
            ]],
            'oversized embedded image' => [['id' => 'x', 'type' => 'image', 'src' => $png]],
            'filters not list' => [['id' => 'x', 'type' => 'rect', 'filters' => ['type' => 'blur']]],
            'filter not object' => [['id' => 'x', 'type' => 'rect', 'filters' => [42]]],
            'unsupported filter' => [['id' => 'x', 'type' => 'rect', 'filters' => [['type' => 'blendimage']]]],
            'duplicate object id' => [['id' => 'x', 'type' => 'rect'], ['id' => 'x', 'type' => 'circle']],
        ];

        foreach ($cases as $label => $objects) {
            $validator = match ($label) {
                'long text' => new FabricCreativePayloadValidator(maxTextLength: 1),
                'oversized embedded image' => new FabricCreativePayloadValidator(maxEmbeddedImageBytes: 1),
                default => new FabricCreativePayloadValidator(),
            };
            $this->assertInvalid($validator, AssetTestFixtures::fabric($objects, []), $label);
        }
    }

    public function testRejectsInvalidResourceDeclarationsAndDangerousKeys(): void
    {
        $object = ['id' => 'shape-1', 'type' => 'rect'];
        $validResource = ['id' => 'shape-1', 'kind' => 'shape', 'policy' => 'platform-controlled'];
        $cases = [
            'resources object' => ['resources' => ['bad' => $validResource]],
            'resource scalar' => ['resources' => [42]],
            'resource extra field' => ['resources' => [$validResource + ['url' => 'https://evil.test']]],
            'resource invalid id' => ['resources' => [array_replace($validResource, ['id' => '../bad'])]],
            'resource invalid kind' => ['resources' => [array_replace($validResource, ['kind' => 'video'])]],
            'resource invalid policy' => ['resources' => [array_replace($validResource, ['policy' => 'external'])]],
            'resource object mismatch' => ['resources' => [array_replace($validResource, ['id' => 'missing'])]],
            'resource kind mismatch' => ['resources' => [array_replace($validResource, ['kind' => 'text'])]],
            'duplicate resources' => ['resources' => [$validResource, $validResource]],
            'missing resource' => ['resources' => []],
        ];
        foreach ($cases as $label => $override) {
            $this->assertInvalid(
                new FabricCreativePayloadValidator(),
                AssetTestFixtures::fabric([$object], $override['resources']),
                $label,
            );
        }

        foreach (['__proto__', 'prototype', 'CONSTRUCTOR'] as $key) {
            $payload = $this->decoded();
            $payload['fabric_json']['objects'][] = ['id' => 'x', 'type' => 'rect', $key => ['polluted' => true]];
            $payload['resources'][] = ['id' => 'x', 'kind' => 'shape', 'policy' => 'platform-controlled'];
            $this->assertInvalid(new FabricCreativePayloadValidator(), $this->encoded($payload), $key);
        }
    }

    public function testRejectsNoncanonicalAndOversizedSnapshotDataUrls(): void
    {
        $payload = $this->decoded();
        $payload['snapshot']['data_url'] = 'data:image/png;base64,=';
        $this->assertInvalid(new FabricCreativePayloadValidator(), $this->encoded($payload), 'invalid padding');

        $payload = $this->decoded();
        $payload['snapshot']['data_url'] = AssetTestFixtures::dataUrl('image/png', AssetTestFixtures::image());
        $this->assertInvalid(
            new FabricCreativePayloadValidator(maxSnapshotBytes: 1),
            $this->encoded($payload),
            'oversized snapshot',
        );
    }

    public function testRejectsInvalidValidationLimits(): void
    {
        foreach (['maxObjects', 'maxTextLength', 'maxEmbeddedImageBytes', 'maxSnapshotBytes', 'maxWidth', 'maxHeight'] as $field) {
            $arguments = [$field => 0];
            try {
                new FabricCreativePayloadValidator(...$arguments);
                self::fail('Expected invalid Fabric limit.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decoded(): array
    {
        $decoded = json_decode(AssetTestFixtures::fabric(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function encoded(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function withSnapshot(array $payload, string $contentType, string $bytes): string
    {
        $payload['snapshot']['data_url'] = AssetTestFixtures::dataUrl($contentType, $bytes);

        return $this->encoded($payload);
    }

    private function assertInvalid(FabricCreativePayloadValidator $validator, string $body, string $label): void
    {
        try {
            $validator->validate($body);
            self::fail('Expected invalid Fabric payload: ' . $label);
        } catch (AssetValidationException $exception) {
            self::assertSame('asset_fabric_payload_invalid', $exception->errorCode, $label);
        }
    }
}

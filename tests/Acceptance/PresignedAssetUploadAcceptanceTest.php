<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use VertoAD\Tests\Acceptance\PresignedAssetUpload\PresignedAssetUploadAcceptanceHarness;

#[Group('external-tools-integration')]
#[Group('presigned-asset-upload-acceptance')]
final class PresignedAssetUploadAcceptanceTest extends TestCase
{
    private ?PresignedAssetUploadAcceptanceHarness $harness = null;
    private bool $scenarioCompleted = false;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_PRESIGNED_ASSET_UPLOAD_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_PRESIGNED_ASSET_UPLOAD_ACCEPTANCE=1 with real MySQL 8 and Cloudflare R2 settings.',
            );
        }

        $this->harness = PresignedAssetUploadAcceptanceHarness::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->harness === null) {
            return;
        }

        $evidence = $this->harness->cleanup();
        self::assertSame($evidence['objects_before'], $evidence['objects_deleted']);
        self::assertSame(0, $evidence['objects_after']);
        self::assertSame(0, $evidence['database_after']);
        self::assertSame(0, $evidence['workspace_after']);
        if ($this->scenarioCompleted) {
            self::assertSame(4, $evidence['objects_before']);
            self::assertSame(1, $evidence['database_before']);
            self::assertSame(1, $evidence['workspace_before']);
        }
        $this->harness = null;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplicationPresignsRealR2PutAndPersistsOnlyServerVerifiedAsset(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^vertoad_asset_acceptance_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertSame(
            'organizations/' . $harness->organizationId() . '/assets/',
            $harness->objectPrefix(),
        );
        self::assertGreaterThanOrEqual(27, $harness->migrationCount());
        self::assertSame([], $harness->objectKeys());

        $png = $harness->createImageFixture('authoritative.png', 'image/png', 17, 11);
        $jpeg = $harness->createImageFixture('disguised.jpg', 'image/jpeg', 19, 13);
        self::assertCount(2, $harness->workspaceEntries());

        $validIntent = $this->createIntent('verified.png', 'image/png', $png['byte_size'], 'req-asset-intent-valid');
        $this->assertIntentContract($validIntent, 'image/png', $png['byte_size']);
        $validPut = $harness->uploadPresigned($validIntent['upload'], $validIntent['object_key'], $png['path']);
        self::assertSame(200, $validPut['status']);
        self::assertSame($png['byte_size'], $validPut['declared_byte_size']);
        self::assertGreaterThanOrEqual(2, $validPut['signed_header_count']);
        self::assertSame('AWS4-HMAC-SHA256', $validPut['algorithm']);
        self::assertSame('image/png', $validPut['content_type']);
        $validMetadata = $harness->objectMetadata($validIntent['object_key']);
        self::assertSame('image/png', $validMetadata['content_type']);
        self::assertSame($png['byte_size'], $validMetadata['byte_size']);
        self::assertSame($png['byte_size'], $validMetadata['declared_byte_size']);

        $wrongChecksum = $this->confirm($validIntent, 'req-asset-confirm-wrong-checksum', 'sha256:' . str_repeat('0', 64));
        self::assertSame(422, $wrongChecksum->getStatusCode());
        self::assertSame('asset_uploaded_object_mismatch', $this->json($wrongChecksum)['error']['code'] ?? null);
        self::assertSame(0, $this->countRows('creative_assets'));
        self::assertSame('pending_upload', $this->intentStatus((int) $validIntent['id']));

        $confirmed = $this->confirm($validIntent, 'req-asset-confirm-valid');
        $confirmedPayload = $this->json($confirmed);
        self::assertSame(201, $confirmed->getStatusCode());
        self::assertSame('req-asset-confirm-valid', $confirmed->getHeaderLine('X-Request-Id'));
        self::assertSame('req-asset-confirm-valid', $confirmedPayload['request_id'] ?? null);
        self::assertSame($harness->organizationId(), $confirmedPayload['data']['organization_id'] ?? null);
        self::assertSame($harness->userId(), $confirmedPayload['data']['uploader_user_id'] ?? null);
        self::assertSame('image/png', $confirmedPayload['data']['content_type'] ?? null);
        self::assertSame($png['byte_size'], $confirmedPayload['data']['byte_size'] ?? null);
        self::assertSame($png['width'], $confirmedPayload['data']['width'] ?? null);
        self::assertSame($png['height'], $confirmedPayload['data']['height'] ?? null);
        self::assertSame($png['checksum'], $confirmedPayload['data']['checksum'] ?? null);
        self::assertSame('pending_review', $confirmedPayload['data']['status'] ?? null);
        self::assertSame('pending', $confirmedPayload['data']['snapshot_status'] ?? null);
        $finalObjectKey = (string) ($confirmedPayload['data']['object_key'] ?? '');
        $this->assertFinalObjectKey($validIntent, $finalObjectKey, $png['checksum']);
        self::assertNotSame($validIntent['object_key'], $finalObjectKey);
        self::assertSame(
            'https://assets.acceptance.invalid/' . $finalObjectKey,
            $confirmedPayload['data']['source_url'] ?? null,
        );
        $assetId = (int) ($confirmedPayload['data']['id'] ?? 0);
        self::assertGreaterThan(0, $assetId);

        $persisted = $harness->connection()->fetchAssociative(
            'SELECT upload_intent_id, organization_id, uploader_user_id, object_key, content_type, byte_size, '
            . 'width, height, checksum, status, snapshot_status FROM creative_assets WHERE id = ?',
            [$assetId],
        );
        self::assertIsArray($persisted);
        self::assertSame((int) $validIntent['id'], (int) $persisted['upload_intent_id']);
        self::assertSame($harness->organizationId(), (int) $persisted['organization_id']);
        self::assertSame($harness->userId(), (int) $persisted['uploader_user_id']);
        self::assertSame($finalObjectKey, $persisted['object_key']);
        self::assertSame('image/png', $persisted['content_type']);
        self::assertSame($png['byte_size'], (int) $persisted['byte_size']);
        self::assertSame($png['width'], (int) $persisted['width']);
        self::assertSame($png['height'], (int) $persisted['height']);
        self::assertSame($png['checksum'], $persisted['checksum']);
        self::assertSame('pending_review', $persisted['status']);
        self::assertSame('pending', $persisted['snapshot_status']);
        self::assertSame('pending_review', $this->intentStatus((int) $validIntent['id']));
        self::assertSame(1, $this->countRows('creative_assets'));
        self::assertSame(1, $this->countRows('asset_snapshot_jobs'));
        self::assertNotContains($validIntent['object_key'], $harness->objectKeys());
        self::assertContains($finalObjectKey, $harness->objectKeys());
        $finalMetadata = $harness->objectMetadata($finalObjectKey);
        self::assertSame('image/png', $finalMetadata['content_type']);
        self::assertSame($png['byte_size'], $finalMetadata['byte_size']);
        self::assertSame($png['checksum'], $harness->objectChecksum($finalObjectKey));

        // The original presigned URL remains valid briefly, but can now only
        // overwrite the disposable staging key, never the persisted final key.
        $stalePut = $harness->uploadPresigned(
            $validIntent['upload'],
            $validIntent['object_key'],
            $jpeg['path'],
        );
        self::assertSame(200, $stalePut['status']);
        self::assertSame($jpeg['checksum'], $harness->objectChecksum($validIntent['object_key']));
        self::assertSame($png['checksum'], $harness->objectChecksum($finalObjectKey));

        $replayed = $this->confirm($validIntent, 'req-asset-confirm-replay', $png['checksum']);
        $replayedPayload = $this->json($replayed);
        self::assertSame(201, $replayed->getStatusCode());
        self::assertSame($assetId, $replayedPayload['data']['id'] ?? null);
        self::assertSame($png['checksum'], $replayedPayload['data']['checksum'] ?? null);
        self::assertSame(1, $this->countRows('creative_assets'));
        self::assertSame(1, $this->countRows('asset_snapshot_jobs'));
        self::assertNotContains($validIntent['object_key'], $harness->objectKeys());
        self::assertSame($png['checksum'], $harness->objectChecksum($finalObjectKey));

        $sizeIntent = $this->createIntent(
            'size-mismatch.png',
            'image/png',
            $png['byte_size'] + 13,
            'req-asset-intent-size',
        );
        $sizePut = $harness->uploadPresigned($sizeIntent['upload'], $sizeIntent['object_key'], $png['path']);
        self::assertSame($png['byte_size'] + 13, $sizePut['declared_byte_size']);
        $sizeMetadata = $harness->objectMetadata($sizeIntent['object_key']);
        self::assertSame('image/png', $sizeMetadata['content_type']);
        self::assertSame($png['byte_size'], $sizeMetadata['byte_size']);
        self::assertSame($png['byte_size'] + 13, $sizeMetadata['declared_byte_size']);
        $sizeRejected = $this->confirm($sizeIntent, 'req-asset-confirm-size');
        self::assertSame(422, $sizeRejected->getStatusCode());
        self::assertSame('asset_uploaded_object_mismatch', $this->json($sizeRejected)['error']['code'] ?? null);

        $magicIntent = $this->createIntent(
            'magic-mismatch.png',
            'image/png',
            $jpeg['byte_size'],
            'req-asset-intent-magic',
        );
        $magicPut = $harness->uploadPresigned($magicIntent['upload'], $magicIntent['object_key'], $jpeg['path']);
        self::assertSame(200, $magicPut['status']);
        self::assertSame('image/png', $harness->objectMetadata($magicIntent['object_key'])['content_type']);
        $magicRejected = $this->confirm($magicIntent, 'req-asset-confirm-magic');
        self::assertSame(422, $magicRejected->getStatusCode());
        self::assertSame('asset_magic_mismatch', $this->json($magicRejected)['error']['code'] ?? null);

        $mimeIntent = $this->createIntent(
            'mime-mismatch.png',
            'image/png',
            $png['byte_size'],
            'req-asset-intent-mime',
        );
        $mimePut = $harness->uploadPresigned(
            $mimeIntent['upload'],
            $mimeIntent['object_key'],
            $png['path'],
            'image/jpeg',
        );
        self::assertSame(200, $mimePut['status']);
        self::assertSame('image/jpeg', $mimePut['content_type']);
        self::assertSame('image/jpeg', $harness->objectMetadata($mimeIntent['object_key'])['content_type']);
        $mimeRejected = $this->confirm($mimeIntent, 'req-asset-confirm-mime');
        self::assertSame(422, $mimeRejected->getStatusCode());
        self::assertSame('asset_uploaded_object_mismatch', $this->json($mimeRejected)['error']['code'] ?? null);

        self::assertSame(4, $this->countRows('asset_upload_intents'));
        self::assertSame(1, (int) $harness->connection()->fetchOne(
            "SELECT COUNT(*) FROM asset_upload_intents WHERE status = 'pending_review'",
        ));
        self::assertSame(3, (int) $harness->connection()->fetchOne(
            "SELECT COUNT(*) FROM asset_upload_intents WHERE status = 'pending_upload'",
        ));
        self::assertSame(1, $this->countRows('creative_assets'));
        self::assertSame(1, $this->countRows('asset_snapshot_jobs'));
        self::assertCount(4, $harness->objectKeys());
        foreach ($harness->objectKeys() as $objectKey) {
            self::assertStringStartsWith($harness->objectPrefix(), $objectKey);
        }

        $this->scenarioCompleted = true;
    }

    /** @return array<string, mixed> */
    private function createIntent(string $filename, string $contentType, int $byteSize, string $requestId): array
    {
        $response = $this->harness()->request(
            'POST',
            '/api/v1/assets/upload-intents?organization_id=' . $this->harness()->organizationId(),
            [
                'type' => 'image',
                'filename' => $filename,
                'content_type' => $contentType,
                'byte_size' => $byteSize,
            ],
            ['X-Request-Id' => $requestId],
        );
        $payload = $this->json($response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame($requestId, $response->getHeaderLine('X-Request-Id'));
        self::assertSame($requestId, $payload['request_id'] ?? null);
        self::assertIsArray($payload['data'] ?? null);

        return $payload['data'];
    }

    /** @param array<string, mixed> $intent */
    private function confirm(array $intent, string $requestId, ?string $checksum = null): ResponseInterface
    {
        $body = [
            'upload_intent_id' => $intent['id'],
            'object_key' => $intent['object_key'],
            'content_type' => $intent['content_type'],
            'byte_size' => $intent['byte_size'],
        ];
        if ($checksum !== null) {
            $body['checksum'] = $checksum;
        }

        return $this->harness()->request(
            'POST',
            '/api/v1/assets/confirm?organization_id=' . $this->harness()->organizationId(),
            $body,
            ['X-Request-Id' => $requestId],
        );
    }

    /** @param array<string, mixed> $intent */
    private function assertIntentContract(array $intent, string $contentType, int $byteSize): void
    {
        self::assertGreaterThan(0, $intent['id'] ?? 0);
        self::assertSame($this->harness()->organizationId(), $intent['organization_id'] ?? null);
        self::assertSame($this->harness()->userId(), $intent['uploader_user_id'] ?? null);
        self::assertSame('image', $intent['type'] ?? null);
        self::assertSame($contentType, $intent['content_type'] ?? null);
        self::assertSame($byteSize, $intent['byte_size'] ?? null);
        self::assertSame('pending_upload', $intent['status'] ?? null);
        self::assertStringStartsWith($this->harness()->objectPrefix(), (string) ($intent['object_key'] ?? ''));
        self::assertSame('PUT', $intent['upload']['method'] ?? null);
        self::assertSame(200, $intent['upload']['status_code'] ?? null);
        self::assertSame($contentType, $intent['upload']['headers']['Content-Type'] ?? null);
        self::assertSame((string) $byteSize, $intent['upload']['headers']['x-amz-meta-vertoad-byte-size'] ?? null);
    }

    /** @param array<string, mixed> $intent */
    private function assertFinalObjectKey(array $intent, string $finalObjectKey, string $checksum): void
    {
        $stagingObjectKey = (string) ($intent['object_key'] ?? '');
        self::assertMatchesRegularExpression(
            '~^organizations/' . $this->harness()->organizationId()
            . '/assets/staging/([A-Za-z0-9_-]{8,128})\.([a-z0-9]+)$~D',
            $stagingObjectKey,
        );
        preg_match(
            '~^organizations/' . $this->harness()->organizationId()
            . '/assets/staging/([A-Za-z0-9_-]{8,128})\.([a-z0-9]+)$~D',
            $stagingObjectKey,
            $matches,
        );
        self::assertMatchesRegularExpression(
            '~^organizations/' . $this->harness()->organizationId()
            . '/assets/final/' . preg_quote((string) ($matches[1] ?? ''), '~')
            . '/[A-Za-z0-9_-]{16,128}-sha256-'
            . preg_quote(substr($checksum, strlen('sha256:')), '~')
            . '\.' . preg_quote((string) ($matches[2] ?? ''), '~') . '$~D',
            $finalObjectKey,
        );
    }

    private function countRows(string $table): int
    {
        if (!in_array($table, ['asset_upload_intents', 'creative_assets', 'asset_snapshot_jobs'], true)) {
            throw new \LogicException('Unsafe acceptance table name.');
        }

        return (int) $this->harness()->connection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function intentStatus(int $intentId): string
    {
        return (string) $this->harness()->connection()->fetchOne(
            'SELECT status FROM asset_upload_intents WHERE id = ?',
            [$intentId],
        );
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }

    private function harness(): PresignedAssetUploadAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The presigned asset acceptance harness is unavailable.');
    }
}

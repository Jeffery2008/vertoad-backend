<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Assets\ConfirmAssetUploadAction;
use VertoAD\Http\Action\Assets\CreateAssetUploadIntentAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Repository\Assets\AssetRepository;
use VertoAD\Repository\Assets\AssetRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Service\Assets\AssetUploadService;

final class AssetRouteIntegrationTest extends TestCase
{
    public function testAssetsRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->createConnection());

        $unauthenticated = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'type' => 'image',
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
        ]);
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $missingScope = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents', [
            'type' => 'image',
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
        ], 'valid-token');
        self::assertSame('organization_scope_required', $missingScope['error']['code']);

        $invalidConfirmScope = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=0', [], 'valid-token');
        self::assertSame('organization_scope_required', $invalidConfirmScope['error']['code']);
    }

    public function testCreateIntentAndConfirmAssetThroughRoutes(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $intent = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'type' => 'image',
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
        ], 'valid-token');

        self::assertSame(201, $intent['data']['upload']['status_code']);
        self::assertSame('PUT', $intent['data']['upload']['method']);
        self::assertSame('pending_upload', $intent['data']['status']);
        self::assertStringContainsString('/creative-assets/', $intent['data']['upload']['url']);

        $confirmed = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => $intent['data']['id'],
            'object_key' => $intent['data']['object_key'],
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'checksum' => 'sha256:abc',
            'magic_base64' => base64_encode("\x89PNG\r\n\x1A\npayload"),
        ], 'valid-token');

        self::assertSame('pending_review', $confirmed['data']['status']);
        self::assertSame(99, $confirmed['data']['organization_id']);
        self::assertSame(7, $confirmed['data']['uploader_user_id']);
        self::assertSame('image/png', $confirmed['data']['content_type']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_snapshot_jobs'));
    }

    public function testConfirmVideoRouteAcceptsIntegerDuration(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $intent = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'type' => 'video',
            'filename' => 'creative.mp4',
            'content_type' => 'video/mp4',
            'byte_size' => 1024,
        ], 'valid-token');

        $confirmed = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => $intent['data']['id'],
            'object_key' => $intent['data']['object_key'],
            'content_type' => 'video/mp4',
            'byte_size' => 1024,
            'width' => 640,
            'height' => 360,
            'duration_seconds' => 30,
            'magic_base64' => base64_encode("\x00\x00\x00\x18ftypmp42"),
        ], 'valid-token');

        self::assertEqualsWithDelta(30.0, (float) $confirmed['data']['duration_seconds'], 0.001);
    }

    public function testConfirmRouteReturnsValidationEnvelopeForUnsafeMagic(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $intent = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'type' => 'image',
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
        ], 'valid-token');

        $rejected = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => $intent['data']['id'],
            'object_key' => $intent['data']['object_key'],
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'magic_base64' => base64_encode('<script>alert(1)</script>'),
        ], 'valid-token');

        self::assertSame('asset_magic_mismatch', $rejected['error']['code']);
    }

    public function testRoutesReturnInvalidRequestForMissingBodiesAndBadFieldTypes(): void
    {
        $app = $this->createApp($this->createConnection());

        $missingCreateBody = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', null, 'valid-token');
        self::assertSame('invalid_request', $missingCreateBody['error']['code']);

        $missingType = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
        ], 'valid-token');
        self::assertSame('invalid_request', $missingType['error']['code']);

        $badByteSize = $this->handleJson($app, 'POST', '/api/v1/assets/upload-intents?organization_id=99', [
            'type' => 'image',
            'filename' => 'creative.png',
            'content_type' => 'image/png',
            'byte_size' => '1024',
        ], 'valid-token');
        self::assertSame('invalid_request', $badByteSize['error']['code']);

        $missingConfirmBody = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', null, 'valid-token');
        self::assertSame('invalid_request', $missingConfirmBody['error']['code']);

        $badConfirmString = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => 1,
            'object_key' => '',
        ], 'valid-token');
        self::assertSame('invalid_request', $badConfirmString['error']['code']);

        $badConfirmInt = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => 0,
        ], 'valid-token');
        self::assertSame('invalid_request', $badConfirmInt['error']['code']);

        $badDuration = $this->handleJson($app, 'POST', '/api/v1/assets/confirm?organization_id=99', [
            'upload_intent_id' => 1,
            'object_key' => 'organizations/99/assets/route-token.mp4',
            'content_type' => 'video/mp4',
            'byte_size' => 1024,
            'width' => 640,
            'height' => 360,
            'duration_seconds' => '30',
            'magic_base64' => base64_encode("\x00\x00\x00\x18ftypmp42"),
        ], 'valid-token');
        self::assertSame('invalid_request', $badDuration['error']['code']);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?string $bearerToken = null,
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            Connection::class => $connection,
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new class implements FirstPartySessionRepositoryInterface {
                    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                    {
                        return $tokenHash === hash('sha256', 'valid-token')
                            ? new AuthenticatedUser(7, 'assets@example.com', false)
                            : null;
                    }

                    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
                    {
                    }

                    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
                    {
                        return false;
                    }
                },
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            AssetRepositoryInterface::class => static fn (): AssetRepositoryInterface => new AssetRepository($connection),
            ObjectStorageUploadSignerInterface::class => static fn (): ObjectStorageUploadSignerInterface =>
                new DeterministicPresignedUploadSigner([
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'creative-assets',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ]),
            AssetUploadService::class => static fn (
                AssetRepositoryInterface $repository,
                ObjectStorageUploadSignerInterface $signer,
            ): AssetUploadService => new AssetUploadService($repository, $signer, tokenGenerator: static fn (): string => 'route-token'),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/assets/upload-intents', CreateAssetUploadIntentAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/assets/confirm', ConfirmAssetUploadAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        AssetSchema::create($connection);

        return $connection;
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Review\ApproveReviewAction;
use VertoAD\Http\Action\Review\GetReviewStatusAction;
use VertoAD\Http\Action\Review\RejectReviewAction;
use VertoAD\Http\Action\Review\StartAiReviewAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\ReviewService;

final class ReviewRouteIntegrationTest extends TestCase
{
    public function testReviewRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->connectionWithAsset());

        $unauthenticated = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', []);
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $missingScope = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review', [], 'valid-token');
        self::assertSame('organization_scope_required', $missingScope['error']['code']);

        $badScope = $this->handleJson($app, 'GET', '/api/v1/reviews/1?organization_id=0', null, 'valid-token');
        self::assertSame('organization_scope_required', $badScope['error']['code']);
    }

    public function testStartGetApproveAndRejectRoutesReturnEnvelopedReviewPayloads(): void
    {
        $connection = $this->connectionWithAsset();
        $app = $this->createApp($connection);

        $started = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', [
            'landing_url' => 'https://landing.example',
            'copy' => 'Buy now',
        ], 'valid-token');
        self::assertSame('needs_human', $started['data']['status']);
        self::assertSame(1, $started['data']['asset_id']);
        self::assertSame(['manual_review'], $started['data']['ai']['risk_labels']);
        self::assertNull($started['data']['final_decision']);

        $status = $this->handleJson($app, 'GET', '/api/v1/reviews/' . $started['data']['id'] . '?organization_id=99', null, 'valid-token');
        self::assertSame($started['data']['id'], $status['data']['id']);

        $approved = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=99', [
            'reason' => 'Policy checked',
        ], 'valid-token');
        self::assertSame('approved', $approved['data']['status']);
        self::assertSame('approved', $approved['data']['final_decision']);
        self::assertSame('eligible', (string) $connection->fetchOne('SELECT eligibility FROM review_eligibility_events'));

        $connection = $this->connectionWithAsset(2);
        $app = $this->createApp($connection);
        $second = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/2/ai-review?organization_id=99', [], 'valid-token');
        $rejected = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=99', [
            'reason' => 'Unsafe claim',
        ], 'valid-token');
        self::assertSame('rejected', $rejected['data']['status']);
        self::assertSame('ineligible', (string) $connection->fetchOne('SELECT eligibility FROM review_eligibility_events'));
    }

    public function testRoutesReturnStableValidationErrorsForBadInputAndOwnership(): void
    {
        $app = $this->createApp($this->connectionWithAsset());

        $badAssetId = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/abc/ai-review?organization_id=99', [], 'valid-token');
        self::assertSame('invalid_request', $badAssetId['error']['code']);

        $badBody = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', [
            'landing_url' => 123,
        ], 'valid-token');
        self::assertSame('invalid_request', $badBody['error']['code']);

        $wrongOrg = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=100', [], 'valid-token');
        self::assertSame('review_asset_not_found', $wrongOrg['error']['code']);
    }

    public function testRouteErrorBranchesReturnStableEnvelopes(): void
    {
        $connection = $this->connectionWithAsset();
        $app = $this->createApp($connection);

        $badStartBody = $this->handleParsedBody($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', (object) ['bad' => true], 'valid-token');
        self::assertSame('invalid_request', $badStartBody['error']['code']);

        $started = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', [], 'valid-token');

        $badGetId = $this->handleJson($app, 'GET', '/api/v1/reviews/abc?organization_id=99', null, 'valid-token');
        self::assertSame('invalid_request', $badGetId['error']['code']);

        $missingReview = $this->handleJson($app, 'GET', '/api/v1/reviews/999?organization_id=99', null, 'valid-token');
        self::assertSame('review_not_found', $missingReview['error']['code']);

        $badApproveId = $this->handleJson($app, 'POST', '/api/v1/reviews/abc/approve?organization_id=99', [], 'valid-token');
        self::assertSame('invalid_request', $badApproveId['error']['code']);

        $badApproveScope = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=0', [], 'valid-token');
        self::assertSame('organization_scope_required', $badApproveScope['error']['code']);

        $badApproveBody = $this->handleParsedBody($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=99', (object) ['bad' => true], 'valid-token');
        self::assertSame('invalid_request', $badApproveBody['error']['code']);

        $badApproveReason = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=99', [
            'reason' => 123,
        ], 'valid-token');
        self::assertSame('invalid_request', $badApproveReason['error']['code']);

        $approved = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=99', [], 'valid-token');
        self::assertSame('approved', $approved['data']['status']);

        $lateApprove = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $started['data']['id'] . '/approve?organization_id=99', [], 'valid-token');
        self::assertSame('review_transition_invalid', $lateApprove['error']['code']);

        $connection = $this->connectionWithAsset(2);
        $app = $this->createApp($connection);
        $second = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/2/ai-review?organization_id=99', [], 'valid-token');

        $badRejectId = $this->handleJson($app, 'POST', '/api/v1/reviews/abc/reject?organization_id=99', [], 'valid-token');
        self::assertSame('invalid_request', $badRejectId['error']['code']);

        $badRejectScope = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=0', [], 'valid-token');
        self::assertSame('organization_scope_required', $badRejectScope['error']['code']);

        $badRejectBody = $this->handleParsedBody($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=99', (object) ['bad' => true], 'valid-token');
        self::assertSame('invalid_request', $badRejectBody['error']['code']);

        $badRejectReason = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=99', [
            'reason' => 123,
        ], 'valid-token');
        self::assertSame('invalid_request', $badRejectReason['error']['code']);

        $rejected = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=99', [], 'valid-token');
        self::assertSame('rejected', $rejected['data']['status']);

        $lateReject = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $second['data']['id'] . '/reject?organization_id=99', [], 'valid-token');
        self::assertSame('review_transition_invalid', $lateReject['error']['code']);

        self::assertSame(5, \VertoAD\Http\Action\Review\ReviewRequestGuards::positiveRouteInteger(5));
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

    /**
     * @return array<string, mixed>
     */
    private function handleParsedBody(
        \Slim\App $app,
        string $method,
        string $uri,
        mixed $payload,
        ?string $bearerToken = null,
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withParsedBody($payload);

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
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new class implements FirstPartySessionRepositoryInterface {
                    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                    {
                        return $tokenHash === hash('sha256', 'valid-token')
                            ? new AuthenticatedUser(7, 'review@example.com', false)
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
            ReviewRepositoryInterface::class => static fn (): ReviewRepositoryInterface => new ReviewRepository($connection),
            CreativeReviewProviderInterface::class => static fn (): CreativeReviewProviderInterface =>
                new DeterministicCreativeReviewProvider(),
            ReviewService::class => static fn (
                ReviewRepositoryInterface $repository,
                CreativeReviewProviderInterface $provider,
            ): ReviewService => new ReviewService($repository, $provider),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/reviews/assets/{asset_id}/ai-review', StartAiReviewAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/reviews/{review_id}', GetReviewStatusAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/reviews/{review_id}/approve', ApproveReviewAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/reviews/{review_id}/reject', RejectReviewAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function connectionWithAsset(int $assetId = 1): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        ReviewSchema::create($connection);
        $connection->insert('asset_upload_intents', [
            'id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative.png',
            'object_key' => 'organizations/99/assets/route-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'pending_review',
            'expires_at' => '2026-06-08 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $assetId,
            'upload_intent_id' => $assetId,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/99/assets/route-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => 'pending_review',
        ]);

        return $connection;
    }
}

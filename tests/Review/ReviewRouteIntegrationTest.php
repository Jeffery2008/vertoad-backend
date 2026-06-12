<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Review\AiReviewResult;
use VertoAD\Domain\Review\CreativeReview;
use VertoAD\Domain\Review\CreativeReviewAsset;
use VertoAD\Domain\Review\CreativeReviewStatus;
use VertoAD\Http\Action\Review\ApproveReviewAction;
use VertoAD\Http\Action\Review\GetReviewStatusAction;
use VertoAD\Http\Action\Review\ListReviewQueueAction;
use VertoAD\Http\Action\Review\RejectReviewAction;
use VertoAD\Http\Action\Review\StartAiReviewAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;

final class ReviewRouteIntegrationTest extends TestCase
{
    public function testReviewRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->connectionWithAsset());

        $unauthenticated = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', []);
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $unauthenticatedQueue = $this->handleJson($app, 'GET', '/api/v1/reviews?status=needs_human&limit=50');
        self::assertSame('authentication_required', $unauthenticatedQueue['error']['code']);

        $missingScope = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review', [], 'valid-token');
        self::assertSame('organization_scope_required', $missingScope['error']['code']);

        $badScope = $this->handleJson($app, 'GET', '/api/v1/reviews/1?organization_id=0', null, 'valid-token');
        self::assertSame('organization_scope_required', $badScope['error']['code']);

        $queueWithoutScope = $this->handleJson($app, 'GET', '/api/v1/reviews?status=needs_human&limit=50', null, 'valid-token');
        self::assertSame([], $queueWithoutScope['data']['reviews']);
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

    public function testReviewQueueListsNeedsHumanReviewsGloballyAndFiltersByOrganizationInStableOrder(): void
    {
        $connection = $this->connectionWithAsset();
        $this->insertReviewableAsset($connection, 2);
        $this->insertReviewableAsset($connection, 3);
        $this->insertReviewableAsset($connection, 4, 100);
        $app = $this->createApp($connection);

        $first = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/1/ai-review?organization_id=99', [], 'valid-token');
        $second = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/2/ai-review?organization_id=99', [], 'valid-token');
        $third = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/3/ai-review?organization_id=99', [], 'valid-token');
        $otherOrganization = $this->handleJson($app, 'POST', '/api/v1/reviews/assets/4/ai-review?organization_id=100', [], 'valid-token');

        $connection->update('creative_reviews', ['created_at' => '2026-06-08 00:00:03'], ['id' => $first['data']['id']]);
        $connection->update('creative_reviews', ['created_at' => '2026-06-08 00:00:01'], ['id' => $second['data']['id']]);
        $connection->update('creative_reviews', ['created_at' => '2026-06-08 00:00:02'], ['id' => $third['data']['id']]);
        $connection->update('creative_reviews', ['created_at' => '2026-06-08 00:00:00'], ['id' => $otherOrganization['data']['id']]);

        $approved = $this->handleJson($app, 'POST', '/api/v1/reviews/' . $third['data']['id'] . '/approve?organization_id=99', [], 'valid-token');
        self::assertSame('approved', $approved['data']['status']);

        $globalQueue = $this->handleJson($app, 'GET', '/api/v1/reviews?status=needs_human&limit=50', null, 'valid-token');
        self::assertSame([
            $otherOrganization['data']['id'],
            $second['data']['id'],
            $first['data']['id'],
        ], array_column($globalQueue['data']['reviews'], 'id'));
        self::assertSame([100, 99, 99], array_column($globalQueue['data']['reviews'], 'organization_id'));

        $queue = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=50', null, 'valid-token');

        self::assertSame([
            $second['data']['id'],
            $first['data']['id'],
        ], array_column($queue['data']['reviews'], 'id'));
        self::assertSame(['needs_human', 'needs_human'], array_column($queue['data']['reviews'], 'status'));

        $limited = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=1', null, 'valid-token');
        self::assertSame([$second['data']['id']], array_column($limited['data']['reviews'], 'id'));
    }

    public function testReviewQueueRejectsUnsupportedStatusAndInvalidLimit(): void
    {
        $app = $this->createApp($this->connectionWithAsset());

        $defaultLimit = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human', null, 'valid-token');
        self::assertSame([], $defaultLimit['data']['reviews']);

        $emptyLimit = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=', null, 'valid-token');
        self::assertSame([], $emptyLimit['data']['reviews']);

        $unsupportedStatus = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=approved&limit=50', null, 'valid-token');
        self::assertSame('invalid_request', $unsupportedStatus['error']['code']);

        $badLimit = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=0', null, 'valid-token');
        self::assertSame('invalid_request', $badLimit['error']['code']);

        $nonNumericLimit = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=abc', null, 'valid-token');
        self::assertSame('invalid_request', $nonNumericLimit['error']['code']);

        $tooLargeLimit = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=99&status=needs_human&limit=101', null, 'valid-token');
        self::assertSame('invalid_request', $tooLargeLimit['error']['code']);

        $badOrganization = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=0&status=needs_human&limit=50', null, 'valid-token');
        self::assertSame('invalid_request', $badOrganization['error']['code']);

        $nonNumericOrganization = $this->handleJson($app, 'GET', '/api/v1/reviews?organization_id=abc&status=needs_human&limit=50', null, 'valid-token');
        self::assertSame('invalid_request', $nonNumericOrganization['error']['code']);
    }

    public function testReviewQueueActionCoversDefensiveLimitAndServiceValidationBranches(): void
    {
        $action = new ListReviewQueueAction(new ReviewService(
            new ThrowingReviewQueueRepository(),
            new DeterministicCreativeReviewProvider(),
        ));
        $context = new RequestUserContext(new AuthenticatedUser(7, 'review@example.com', false), 99);

        $arrayLimitRequest = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reviews')
            ->withQueryParams([
                'organization_id' => '99',
                'status' => 'needs_human',
                'limit' => ['50'],
            ])
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        $arrayLimitResponse = $action($arrayLimitRequest, (new ResponseFactory())->createResponse(), []);
        $arrayLimitPayload = json_decode((string) $arrayLimitResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $arrayLimitResponse->getStatusCode());
        self::assertSame('invalid_request', $arrayLimitPayload['code']);

        $integerOrganizationRequest = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reviews')
            ->withQueryParams([
                'organization_id' => 99,
                'status' => 'needs_human',
                'limit' => '50',
            ])
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        $integerOrganizationResponse = $action($integerOrganizationRequest, (new ResponseFactory())->createResponse(), []);
        $integerOrganizationPayload = json_decode((string) $integerOrganizationResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $integerOrganizationResponse->getStatusCode());
        self::assertSame('review_queue_unavailable', $integerOrganizationPayload['code']);

        $serviceErrorRequest = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reviews')
            ->withQueryParams([
                'organization_id' => '99',
                'status' => 'needs_human',
                'limit' => '50',
            ])
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        $serviceErrorResponse = $action($serviceErrorRequest, (new ResponseFactory())->createResponse(), []);
        $serviceErrorPayload = json_decode((string) $serviceErrorResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $serviceErrorResponse->getStatusCode());
        self::assertSame('review_queue_unavailable', $serviceErrorPayload['code']);
        self::assertSame('Review queue could not be listed.', $serviceErrorPayload['message']);
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
        $app->get('/api/v1/reviews', ListReviewQueueAction::class)->add(AuthenticateRequestMiddleware::class);
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

    private function insertReviewableAsset(Connection $connection, int $assetId, int $organizationId = 99): void
    {
        $connection->insert('asset_upload_intents', [
            'id' => $assetId,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative-' . $assetId . '.png',
            'object_key' => 'organizations/' . $organizationId . '/assets/route-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'pending_review',
            'expires_at' => '2026-06-08 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $assetId,
            'upload_intent_id' => $assetId,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/' . $organizationId . '/assets/route-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => 'pending_review',
        ]);
    }
}

final class ThrowingReviewQueueRepository implements ReviewRepositoryInterface
{
    public function findAsset(int $assetId, int $organizationId): ?CreativeReviewAsset
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function findByAsset(int $assetId, int $organizationId): ?CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function find(int $reviewId, int $organizationId): ?CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function listForReviewQueue(?int $organizationId, CreativeReviewStatus $status, int $limit): array
    {
        throw new ReviewValidationException(
            'review_queue_unavailable',
            'Review queue could not be listed.',
            409,
        );
    }

    public function leasePendingAiReviews(int $limit): array
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function findAssetForReview(CreativeReview $review): ?CreativeReviewAsset
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function create(CreativeReview $review): CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function updateStatus(int $reviewId, CreativeReviewStatus $from, CreativeReviewStatus $to): CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function recordAiResult(int $reviewId, AiReviewResult $result): CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }

    public function recordHumanDecision(int $reviewId, int $actorUserId, string $decision, ?string $reason): CreativeReview
    {
        throw new \BadMethodCallException(__METHOD__ . ' is not used by this test.');
    }
}

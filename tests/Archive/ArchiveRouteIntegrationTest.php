<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Archive\ArchiveEvent;
use VertoAD\Http\Action\Archive\ArchiveManifestAction;
use VertoAD\Http\Action\Archive\CreateArchiveJobAction;
use VertoAD\Http\Action\Archive\CreateColdQueryAction;
use VertoAD\Http\Action\Archive\GetColdQueryAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveService;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\FixtureColdQueryRunner;

final class ArchiveRouteIntegrationTest extends TestCase
{
    public function testCreateArchiveJobRouteReturnsManifestMetadataInStandardEnvelope(): void
    {
        $app = $this->createApp();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/v1/archive/jobs'));
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(202, $response->getStatusCode());
        self::assertArrayHasKey('data', $decoded);
        self::assertNull($decoded['error']);
        self::assertArrayHasKey('request_id', $decoded);
        self::assertSame('completed', $decoded['data']['status']);
        self::assertSame('parquet', $decoded['data']['format']);
        self::assertSame(1, $decoded['data']['event_count']);
        self::assertSame('event_type=impression/date=2026-06-08/hour=10', $decoded['data']['partitions'][0]['partition']);
    }

    public function testArchiveManifestRouteReturnsStoredManifestAndNotFoundEnvelope(): void
    {
        $app = $this->createApp();
        $created = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/v1/archive/jobs'));
        $createdBody = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $manifestId = $createdBody['data']['manifest_id'];

        $fetched = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/archive/manifests/' . $manifestId));
        $fetchedBody = json_decode((string) $fetched->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $missing = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/archive/manifests/missing'));
        $missingBody = json_decode((string) $missing->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $fetched->getStatusCode());
        self::assertSame($manifestId, $fetchedBody['data']['manifest_id']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('not_found', $missingBody['error']['code']);
    }

    public function testColdQueryRoutesPersistQueuedStatusAndExposeMetadata(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/archive/cold-queries')
            ->withParsedBody([
                'sql' => 'select count(*) from archive where event_type = ?',
                'parameters' => ['impression'],
                'requested_by' => 'admin-user-1',
            ]);

        $created = $app->handle($request);
        $createdBody = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $jobId = $createdBody['data']['job_id'];
        $fetched = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/archive/cold-queries/' . $jobId));
        $fetchedBody = json_decode((string) $fetched->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(202, $created->getStatusCode());
        self::assertSame('queued', $createdBody['data']['status']);
        self::assertNull($createdBody['data']['result_object_key']);
        self::assertSame(0, $createdBody['data']['row_count']);
        self::assertSame(200, $fetched->getStatusCode());
        self::assertSame($jobId, $fetchedBody['data']['job_id']);
        self::assertSame('queued', $fetchedBody['data']['status']);
    }

    public function testColdQueryRoutesReturnValidationAndNotFoundEnvelopes(): void
    {
        $app = $this->createApp();
        $invalid = $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/archive/cold-queries')
                ->withParsedBody(['sql' => '', 'requested_by' => 'admin-user-1']),
        );
        $invalidBody = json_decode((string) $invalid->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $missing = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/archive/cold-queries/missing'));
        $missingBody = json_decode((string) $missing->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('invalid_request', $invalidBody['error']['code']);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('not_found', $missingBody['error']['code']);
    }

    private function createApp(): App
    {
        $repository = new InMemoryArchiveRepository([
            new ArchiveEvent('impression', 'imp-1', new \DateTimeImmutable('2026-06-08T10:15:00+00:00'), ['campaign_id' => 123]),
        ]);
        $container = (new ContainerBuilder())->addDefinitions([
            ArchiveRepositoryInterface::class => static fn (): ArchiveRepositoryInterface => $repository,
            ArchiveJob::class => static fn (): ArchiveJob => new ArchiveJob($repository, 's3://vertoad-archive/raw-events', new DeterministicArchiveWriter()),
            ArchiveService::class => static fn (): ArchiveService => new ArchiveService($repository),
            ColdQueryService::class => static fn (): ColdQueryService => new ColdQueryService($repository, 's3://vertoad-archive/query-results', new FixtureColdQueryRunner()),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->post('/api/v1/archive/jobs', CreateArchiveJobAction::class);
        $app->get('/api/v1/archive/manifests/{manifest_id}', ArchiveManifestAction::class);
        $app->post('/api/v1/archive/cold-queries', CreateColdQueryAction::class);
        $app->get('/api/v1/archive/cold-queries/{job_id}', GetColdQueryAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}

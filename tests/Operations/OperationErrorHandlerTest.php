<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\Error\OperationErrorHandler;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RequestIdMiddleware;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\OperationErrorCaptureService;

final class OperationErrorHandlerTest extends TestCase
{
    public function testHandlerCapturesThrowableAsOperationErrorAndReturnsEnvelope(): void
    {
        $repository = new InMemoryOperationErrorLogRepository();
        $handler = new OperationErrorHandler(
            new ResponseFactory(),
            new OperationErrorCaptureService($repository, new AuditLogService(new HandlerAuditRepository())),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/campaigns')
            ->withHeader('X-Request-Id', 'req-handler-1')
            ->withHeader('Authorization', 'Bearer raw-token');

        $response = $handler($request, new \RuntimeException('Database connection failed'), false, true, false);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $logs = $repository->all();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('req-handler-1', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('req-handler-1', $payload['request_id'] ?? null);
        self::assertSame('operation_error', $payload['error']['code'] ?? null);
        self::assertSame($logs[0]->error_id, $payload['error']['operation_error_id'] ?? null);
        self::assertSame('req-handler-1', $logs[0]->request_id);
        self::assertSame('api', $logs[0]->source);
        self::assertSame('[REDACTED]', $logs[0]->redacted_context['headers']['authorization'] ?? null);
        self::assertSame('Bearer raw-token', $logs[0]->raw_context['headers']['authorization'] ?? null);
    }

    public function testHandlerStoresResolvedIpAddressAndUserAgentInContext(): void
    {
        $repository = new InMemoryOperationErrorLogRepository();
        $handler = new OperationErrorHandler(
            new ResponseFactory(),
            new OperationErrorCaptureService($repository, new AuditLogService(new HandlerAuditRepository())),
            new ClientIpResolver('CF-Connecting-IP', ['203.0.113.9']),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/operations/fails', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Request-Id', 'req-handler-ip')
            ->withHeader('CF-Connecting-IP', '198.51.100.44')
            ->withHeader('User-Agent', 'Ops Browser');

        $handler($request, new \RuntimeException('IP context failed'), false, true, false);

        $log = $repository->all()[0];
        self::assertSame('198.51.100.44', $log->redacted_context['ip_address'] ?? null);
        self::assertSame('Ops Browser', $log->redacted_context['user_agent'] ?? null);
        self::assertSame('198.51.100.44', $log->raw_context['ip_address'] ?? null);
        self::assertSame('Ops Browser', $log->raw_context['user_agent'] ?? null);
    }

    public function testHandlerClassifiesPhpEngineErrorsSeparately(): void
    {
        $repository = new InMemoryOperationErrorLogRepository();
        $handler = new OperationErrorHandler(
            new ResponseFactory(),
            new OperationErrorCaptureService($repository, new AuditLogService(new HandlerAuditRepository())),
        );

        $handler(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reporting'),
            new \TypeError('Bad PHP type'),
            false,
            true,
            false,
        );

        self::assertSame('php', $repository->all()[0]->source);
    }

    public function testHandlerPrefersRequestHeaderOverStaleCurrentContext(): void
    {
        $repository = new InMemoryOperationErrorLogRepository();
        $handler = new OperationErrorHandler(
            new ResponseFactory(),
            new OperationErrorCaptureService($repository, new AuditLogService(new HandlerAuditRepository())),
        );
        RequestIdContext::begin((new ServerRequestFactory())->createServerRequest('GET', '/stale')->withHeader('X-Request-Id', 'req-stale'));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/current')
            ->withHeader('X-Request-Id', 'req-current');

        $response = $handler($request, new \RuntimeException('Current request failed'), false, true, false);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('req-current', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('req-current', $payload['request_id'] ?? null);
        self::assertSame('req-current', $repository->all()[0]->request_id);
        self::assertNull(RequestIdContext::current());
    }

    public function testGeneratedRequestIdIsStableAcrossEnvelopeErrorAndAuditForThrownRoute(): void
    {
        $errors = new InMemoryOperationErrorLogRepository();
        $auditRepository = new CapturingHandlerAuditRepository();
        $audit = new AuditLogService($auditRepository);
        $app = $this->throwingApp($errors, $audit);

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/operations/boom'));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $logs = $errors->all();

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $logs);
        self::assertCount(1, $auditRepository->entries);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) ($payload['request_id'] ?? ''));
        self::assertSame($payload['request_id'], $response->getHeaderLine('X-Request-Id'));
        self::assertSame($payload['request_id'], $logs[0]->request_id);
        self::assertSame($payload['request_id'], $auditRepository->entries[0]->requestId);
    }

    private function throwingApp(
        InMemoryOperationErrorLogRepository $errors,
        AuditLogService $audit,
    ): App {
        $responseFactory = new ResponseFactory();
        $handler = new OperationErrorHandler(
            $responseFactory,
            new OperationErrorCaptureService($errors, $audit),
        );

        $app = SlimAppFactory::create($responseFactory);
        $app->get('/api/v1/operations/boom', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit): ResponseInterface {
            $audit->record(
                action: 'operations.test.before_throw',
                subjectType: 'operation_test',
                requestId: RequestIdContext::fromRequest($request),
            );

            throw new \RuntimeException('Thrown route should keep the generated request id.');
        });
        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->add(new RequestIdMiddleware());
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(false, true, true);
        $errorMiddleware->setDefaultErrorHandler($handler);

        return $app;
    }
}

final class HandlerAuditRepository implements AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
    }
}

final class CapturingHandlerAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

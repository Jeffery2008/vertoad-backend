<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\Error\OperationErrorHandler;
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
}

final class HandlerAuditRepository implements AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
    }
}

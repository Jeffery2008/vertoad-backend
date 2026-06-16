<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Action\Operations\GetOperationRequestCorrelationsAction;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationRequestCorrelationService;

final class GetOperationRequestCorrelationsActionTest extends TestCase
{
    public function testInvalidCorrelationFilterReturnsOperationsJsonError(): void
    {
        $audit = new AuditLogService(new OperationAuditRepository());
        $action = new GetOperationRequestCorrelationsAction(new OperationRequestCorrelationService(
            new OperationErrorCaptureService(new InMemoryOperationErrorLogRepository(), $audit),
            $audit,
            new InMemoryWebhookDeliveryRepository(),
        ));
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/operations/request-correlations?limit=invalid');

        $response = $action($request, (new ResponseFactory())->createResponse(), []);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('invalid_request', $payload['code'] ?? null);
        self::assertSame('limit must be a positive integer.', $payload['message'] ?? null);
    }
}

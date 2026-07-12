<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Doctrine\DBAL\Connection;
use VertoAD\AppFactory;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\AuditLogService;

$rootPath = dirname(__DIR__, 3);
require $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$app = AppFactory::create($rootPath);
$container = $app->getContainer();
if ($container === null) {
    throw new RuntimeException('The error operations acceptance router requires the application container.');
}
$database = $container->get(Connection::class);
$audit = $container->get(AuditLogService::class);

$app->add(function (
    ServerRequestInterface $request,
    RequestHandlerInterface $handler,
) use ($database): ResponseInterface {
    // The acceptance server stays alive across slow browser requests; force a fresh DB socket per request.
    $database->close();
    try {
        return $handler->handle($request);
    } finally {
        $database->close();
    }
});

$app->get('/__acceptance/health', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
): ResponseInterface {
    $requestId = RequestIdContext::ensure($request);
    $response->getBody()->write(json_encode([
        'ready' => true,
        'data' => ['ready' => true],
        'error' => null,
        'meta' => ['api_version' => 'v1'],
        'request_id' => $requestId,
    ], JSON_THROW_ON_ERROR));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('X-Request-Id', $requestId);
});

$app->get('/__acceptance/error/api', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
) use ($audit): ResponseInterface {
    errorOperationsAuditBeforeThrow($audit, $request, 'acceptance.error.api.before_throw');

    throw new RuntimeException('Acceptance API exception reached the operations error handler.');
});

$app->get('/__acceptance/error/php', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
) use ($audit): ResponseInterface {
    errorOperationsAuditBeforeThrow($audit, $request, 'acceptance.error.php.before_throw');

    throw new TypeError('Acceptance PHP engine error reached the operations error handler.');
});

$app->run();

function errorOperationsAuditBeforeThrow(
    AuditLogService $audit,
    ServerRequestInterface $request,
    string $action,
): void {
    $server = $request->getServerParams();
    $audit->record(
        action: $action,
        subjectType: 'operation_acceptance',
        ipAddress: isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
        userAgent: trim($request->getHeaderLine('User-Agent')) ?: null,
        requestId: RequestIdContext::fromRequest($request),
        metadata: [
            'endpoint' => $request->getMethod() . ':' . $request->getUri()->getPath(),
            'fixture' => 'error_operations_dashboard',
        ],
    );
}

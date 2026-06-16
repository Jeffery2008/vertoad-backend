<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\RealTimeGeoLookupInterface;

final readonly class LookupOperationIpGeoAction
{
    public function __construct(
        private RealTimeGeoLookupInterface $lookup,
        private ?AuditLogService $audit = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $ipAddress = is_array($body) ? (string) ($body['ip_address'] ?? '') : '';
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '' || @inet_pton($ipAddress) === false) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => 'ip_address must be a valid IP address.'], 422);
        }

        try {
            $requestId = RequestIdContext::fromRequest($request);
            $result = $this->lookup->lookup($ipAddress, $requestId);
        } catch (InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        $context = RequestUserContext::fromRequest($request);
        $this->audit?->record(
            action: 'operations.ip_geo.lookup',
            subjectType: 'ip_geo_lookup',
            actorUserId: $context->user?->id,
            organizationId: $context->organizationId,
            requestId: $requestId,
            metadata: [
                'ip_address' => $ipAddress,
                'source' => $result['source'] ?? null,
                'endpoint' => '/api/v1/operations/ip-geo/lookup',
                'method' => 'POST',
            ],
        );

        return OperationsJson::write($response, $result);
    }
}

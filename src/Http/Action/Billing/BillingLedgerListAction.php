<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PointsLedgerRepositoryInterface;

final readonly class BillingLedgerListAction
{
    public function __construct(private PointsLedgerRepositoryInterface $ledger)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        $limit = $this->limit($request);
        if ($limit === null) {
            return $this->json($response, [
                'code' => 'invalid_request',
                'message' => 'limit must be a positive integer.',
            ], 422);
        }

        $entries = $this->ledger->listForOrganization((int) $context->organizationId, $limit);

        return $this->json($response, [
            'organization_id' => $context->organizationId,
            'entries' => array_map($this->serializeEntry(...), $entries),
            'limit' => $limit,
        ], 200);
    }

    private function limit(ServerRequestInterface $request): ?int
    {
        $value = $request->getQueryParams()['limit'] ?? 50;
        if (is_string($value) && ctype_digit($value)) {
            return max(1, min(200, (int) $value));
        }

        return is_int($value) ? max(1, min(200, $value)) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(PointsLedgerEntry $entry): array
    {
        return BillingSerializers::ledgerEntry($entry);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}

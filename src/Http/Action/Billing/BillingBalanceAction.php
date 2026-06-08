<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PointsLedgerRepositoryInterface;

final readonly class BillingBalanceAction
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

        $balance = $this->ledger->balanceForOrganization((int) $context->organizationId);

        return $this->json($response, [
            'organization_id' => $context->organizationId,
            'account_type' => 'advertiser_balance',
            'balance_points' => $balance,
            'balance_cny' => number_format($balance / 100, 2, '.', ''),
        ], 200);
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

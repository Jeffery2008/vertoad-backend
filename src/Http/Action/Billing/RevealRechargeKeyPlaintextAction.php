<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Service\RechargeKeyService;

final readonly class RevealRechargeKeyPlaintextAction
{
    public function __construct(
        private RechargeKeyService $rechargeKeys,
        private ClientIpResolver $ipResolver,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        $keyId = $this->keyId($request);
        if ($keyId === null) {
            return $this->json($response, [
                'code' => 'invalid_request',
                'message' => 'key_id must be a positive integer.',
            ], 422);
        }

        try {
            $reveal = $this->rechargeKeys->revealPlaintext(
                keyId: $keyId,
                actorUserId: (int) $context->user->id,
                ipAddress: $this->ipResolver->resolve($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Recharge key was not found.') {
                return $this->json($response, ['code' => 'recharge_key_not_found', 'message' => $exception->getMessage()], 404);
            }

            return $this->json($response, ['code' => 'recharge_key_rejected', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, BillingSerializers::rechargeKey($reveal->key, $reveal->plaintextKey), 200);
    }

    private function keyId(ServerRequestInterface $request): ?int
    {
        $route = $request->getAttribute(RouteContext::ROUTE);
        if (!$route instanceof RouteInterface) {
            return null;
        }

        $keyId = $route->getArgument('key_id');
        if (!is_string($keyId) || !ctype_digit($keyId) || (int) $keyId <= 0) {
            return null;
        }

        return (int) $keyId;
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

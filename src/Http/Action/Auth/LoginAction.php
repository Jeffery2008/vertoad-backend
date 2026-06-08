<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Auth;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Service\AuthService;

final readonly class LoginAction
{
    public function __construct(private AuthService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            return $this->json($response, $this->auth->login(
                $this->stringField($body, 'email'),
                $this->stringField($body, 'password'),
            ), 200);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage() === 'User is not active.' ? 'user_inactive' : 'invalid_credentials';

            return $this->json($response, ['code' => $code, 'message' => $exception->getMessage()], 401);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $field): string
    {
        if (!array_key_exists($field, $body) || !is_string($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        return $body[$field];
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

<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Assets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Assets\AssetUploadIntent;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Assets\AssetValidationException;

final readonly class CreateAssetUploadIntentAction
{
    public function __construct(private AssetUploadService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = AssetsRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => 'JSON object body is required.'], 422);
        }

        try {
            $intent = $this->service->createUploadIntent(
                (int) $context->organizationId,
                (int) $context->user?->id,
                $this->stringField($body, 'type'),
                $this->stringField($body, 'filename'),
                $this->stringField($body, 'content_type'),
                $this->intField($body, 'byte_size'),
            );
        } catch (AssetValidationException $exception) {
            return $this->json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return $this->json($response, $this->intentPayload($intent), 201);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new AssetValidationException('invalid_request', $key . ' is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new AssetValidationException('invalid_request', $key . ' must be a positive integer.');
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(AssetUploadIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'organization_id' => $intent->organizationId,
            'uploader_user_id' => $intent->uploaderUserId,
            'type' => $intent->type->value,
            'object_key' => $intent->objectKey,
            'content_type' => $intent->contentType,
            'byte_size' => $intent->byteSize,
            'status' => $intent->status->value,
            'expires_at' => $intent->expiresAt->format(DATE_ATOM),
            'upload' => [
                'url' => $intent->upload?->url,
                'method' => $intent->upload?->method,
                'status_code' => $intent->upload?->statusCode,
                'headers' => $intent->upload?->headers,
            ],
        ];
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

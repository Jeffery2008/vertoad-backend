<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Assets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Assets\AssetValidationException;

final readonly class ConfirmAssetUploadAction
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
            $asset = $this->service->confirmUploadedAsset(
                (int) $context->organizationId,
                (int) $context->user?->id,
                $this->intField($body, 'upload_intent_id'),
                $this->stringField($body, 'object_key'),
                $this->stringField($body, 'content_type'),
                $this->intField($body, 'byte_size'),
                $this->intField($body, 'width'),
                $this->intField($body, 'height'),
                $this->nullableFloatField($body, 'duration_seconds'),
                isset($body['checksum']) && is_string($body['checksum']) ? $body['checksum'] : null,
                $this->stringField($body, 'magic_base64'),
            );
        } catch (AssetValidationException $exception) {
            return $this->json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return $this->json($response, $this->assetPayload($asset), 201);
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
     * @param array<string, mixed> $body
     */
    private function nullableFloatField(array $body, string $key): ?float
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new AssetValidationException('invalid_request', $key . ' must be numeric when present.');
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(CreativeAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'upload_intent_id' => $asset->uploadIntentId,
            'organization_id' => $asset->organizationId,
            'uploader_user_id' => $asset->uploaderUserId,
            'type' => $asset->type->value,
            'object_key' => $asset->objectKey,
            'content_type' => $asset->contentType,
            'byte_size' => $asset->byteSize,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_seconds' => $asset->durationSeconds,
            'checksum' => $asset->checksum,
            'status' => $asset->status->value,
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

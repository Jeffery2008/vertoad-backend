<?php

declare(strict_types=1);

namespace VertoAD\Service\Review;

use Closure;
use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Domain\Review\AiReviewResult;

final readonly class OpenAiCompatibleCreativeReviewProvider implements CreativeReviewProviderInterface
{
    private Closure $transport;
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private string $prompt;
    private int $timeoutSeconds;
    private int $maxOutputTokens;
    private float $temperature;

    /**
     * @param array<string, mixed> $config
     * @param null|callable(string, array<string, mixed>, array<string, string>, int):array{status:int,body:string} $transport
     */
    public function __construct(array $config, ?callable $transport = null)
    {
        $this->baseUrl = rtrim($this->requiredString($config, 'base_url'), '/');
        $this->apiKey = $this->requiredString($config, 'api_key');
        $this->model = $this->requiredString($config, 'model');
        $this->prompt = $this->optionalString($config['prompt'] ?? null)
            ?? 'You are VertoAD creative safety reviewer. Return strict JSON with risk_score, risk_labels, reasons, and recommendation.';
        $this->timeoutSeconds = max(1, (int) ($config['timeout_seconds'] ?? 60));
        $this->maxOutputTokens = max(1, (int) ($config['max_output_tokens'] ?? 2000));
        $this->temperature = (float) ($config['temperature'] ?? 0.2);
        $this->transport = Closure::fromCallable($transport ?? self::httpTransport());
    }

    public function review(AiReviewInput $input): AiReviewResult
    {
        $response = ($this->transport)(
            $this->baseUrl . '/chat/completions',
            $this->requestPayload($input),
            $this->headers(),
            $this->timeoutSeconds,
        );

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            return $this->providerError('AI review provider returned HTTP ' . $status . '.', $status);
        }

        try {
            $decoded = $this->decodeJsonObject($body);
            $choice = $decoded['choices'][0] ?? null;
            if (!is_array($choice)) {
                return $this->providerError('AI review provider response did not include a completion choice.', $status);
            }

            $message = $choice['message'] ?? null;
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            $parsed = $this->decodeJsonObject(is_string($content) ? $content : '');
        } catch (\JsonException) {
            return $this->providerError('AI review provider returned malformed JSON.', $status);
        }

        $riskLabels = $this->stringList($parsed['risk_labels'] ?? []);
        $reasons = $this->stringList($parsed['reasons'] ?? []);

        return new AiReviewResult(
            provider: 'openai-compatible',
            model: $this->model,
            riskScore: $this->riskScore($parsed['risk_score'] ?? null),
            riskLabels: $riskLabels === [] ? ['manual_review'] : $riskLabels,
            reasons: $reasons === [] ? ['AI review completed without provider reasons.'] : $reasons,
            raw: [
                'provider' => 'openai-compatible',
                'model' => $this->model,
                'http_status' => $status,
                'response_id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : null,
                'finish_reason' => is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
                'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
                'recommendation' => is_string($parsed['recommendation'] ?? null) ? $parsed['recommendation'] : null,
                'risk_labels' => $riskLabels,
                'reasons' => $reasons,
            ],
        );
    }

    /**
     * @return callable(string, array<string, mixed>, array<string, string>, int):array{status:int,body:string}
     */
    public static function httpTransport(): callable
    {
        return static function (string $url, array $payload, array $headers, int $timeoutSeconds): array {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headerLines),
                    'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'ignore_errors' => true,
                    'timeout' => $timeoutSeconds,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);

            return [
                'status' => self::statusCodeFromHeaders($http_response_header ?? [], $body),
                'body' => $body === false ? '' : $body,
            ];
        };
    }

    /**
     * @param list<string> $headers
     */
    public static function statusCodeFromHeaders(array $headers, string|false $body): int
    {
        if (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return $body === false ? 0 : 200;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => 'VertoAD-AI-Review/1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function requestPayload(AiReviewInput $input): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->prompt,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'asset_id' => $input->assetId,
                        'asset_type' => $input->assetType,
                        'object_key' => $input->objectKey,
                        'content_type' => $input->contentType,
                        'landing_url' => $input->landingUrl,
                        'copy' => $input->copy,
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
        ];
    }

    private function providerError(string $reason, int $status): AiReviewResult
    {
        return new AiReviewResult(
            provider: 'openai-compatible',
            model: $this->model,
            riskScore: 1.0,
            riskLabels: ['ai_provider_error'],
            reasons: [$reason],
            raw: [
                'provider' => 'openai-compatible',
                'model' => $this->model,
                'http_status' => $status,
                'error' => $reason,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     */
    private function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new \JsonException('JSON value was not an object.');
    }

    private function riskScore(mixed $value): float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key): string
    {
        return $this->optionalString($config[$key] ?? null)
            ?? throw new \InvalidArgumentException('AI review ' . $key . ' is required.');
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

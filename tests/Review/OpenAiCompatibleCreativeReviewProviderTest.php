<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Review\AiReviewInput;
use VertoAD\Service\Review\OpenAiCompatibleCreativeReviewProvider;

final class OpenAiCompatibleCreativeReviewProviderTest extends TestCase
{
    public function testBuildsOpenAiCompatibleChatCompletionRequestAndParsesJsonResult(): void
    {
        $captured = [];
        $provider = new OpenAiCompatibleCreativeReviewProvider([
            'base_url' => 'https://ai.example.test/v1/',
            'api_key' => 'unit-test-ai-review-key',
            'model' => 'review-model',
            'prompt' => 'Return review JSON.',
            'timeout_seconds' => 12,
            'max_output_tokens' => 321,
            'temperature' => 0.4,
        ], function (string $url, array $payload, array $headers, int $timeoutSeconds) use (&$captured): array {
            $captured = compact('url', 'payload', 'headers', 'timeoutSeconds');

            return [
                'status' => 200,
                'body' => json_encode([
                    'id' => 'chatcmpl_review_1',
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => json_encode([
                                'risk_score' => '0.37',
                                'risk_labels' => [' brand_safety ', '', 404, 'landing_page'],
                                'reasons' => ['Landing page needs manual confirmation.'],
                                'recommendation' => 'needs_human',
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 42],
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $result = $provider->review($this->input());

        self::assertSame('https://ai.example.test/v1/chat/completions', $captured['url']);
        self::assertSame('review-model', $captured['payload']['model']);
        self::assertSame('Return review JSON.', $captured['payload']['messages'][0]['content']);
        self::assertSame('json_object', $captured['payload']['response_format']['type']);
        self::assertSame(321, $captured['payload']['max_tokens']);
        self::assertSame(0.4, $captured['payload']['temperature']);
        self::assertSame(12, $captured['timeoutSeconds']);
        self::assertSame('Bearer unit-test-ai-review-key', $captured['headers']['Authorization']);
        self::assertSame([
            'asset_id' => 10,
            'asset_type' => 'fabric_ad',
            'object_key' => 'organizations/99/assets/creative.json',
            'content_type' => 'application/json',
            'landing_url' => 'https://landing.example',
            'copy' => 'Launch offer',
        ], json_decode($captured['payload']['messages'][1]['content'], true, 512, JSON_THROW_ON_ERROR));

        self::assertSame('openai-compatible', $result->provider);
        self::assertSame('review-model', $result->model);
        self::assertSame(0.37, $result->riskScore);
        self::assertSame(['brand_safety', 'landing_page'], $result->riskLabels);
        self::assertSame(['Landing page needs manual confirmation.'], $result->reasons);
        self::assertSame('chatcmpl_review_1', $result->raw['response_id']);
        self::assertSame('stop', $result->raw['finish_reason']);
        self::assertSame('needs_human', $result->raw['recommendation']);
        self::assertStringNotContainsString('unit-test-ai-review-key', json_encode($result->raw, JSON_THROW_ON_ERROR));
    }

    public function testUsesSafeDefaultsAndClampsProviderRiskScore(): void
    {
        $provider = new OpenAiCompatibleCreativeReviewProvider([
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => 'unit-test-ai-review-key',
            'model' => 'review-model',
            'timeout_seconds' => 0,
            'max_output_tokens' => 0,
        ], function (string $url, array $payload, array $headers, int $timeoutSeconds): array {
            self::assertSame(1, $timeoutSeconds);
            self::assertSame(1, $payload['max_tokens']);
            self::assertStringContainsString('VertoAD creative safety reviewer', $payload['messages'][0]['content']);

            return [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'risk_score' => 2.5,
                                'risk_labels' => [],
                                'reasons' => [],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ];
        });

        $result = $provider->review($this->input(null, null));

        self::assertSame(1.0, $result->riskScore);
        self::assertSame(['manual_review'], $result->riskLabels);
        self::assertSame(['AI review completed without provider reasons.'], $result->reasons);
    }

    public function testUsesSafeDefaultsWhenProviderReturnsWrongFieldTypes(): void
    {
        $provider = new OpenAiCompatibleCreativeReviewProvider([
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => 'unit-test-ai-review-key',
            'model' => 'review-model',
        ], static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'risk_score' => ['not' => 'numeric'],
                            'risk_labels' => 'not-a-list',
                            'reasons' => ['Provider response type mismatch.'],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $result = $provider->review($this->input());

        self::assertSame(1.0, $result->riskScore);
        self::assertSame(['manual_review'], $result->riskLabels);
        self::assertSame(['Provider response type mismatch.'], $result->reasons);
    }

    public function testMapsProviderHttpAndMalformedResponsesToHumanReviewSafeErrors(): void
    {
        $httpFailure = $this->providerReturning(503, '{"error":"down"}')->review($this->input());
        self::assertSame(1.0, $httpFailure->riskScore);
        self::assertSame(['ai_provider_error'], $httpFailure->riskLabels);
        self::assertSame(['AI review provider returned HTTP 503.'], $httpFailure->reasons);
        self::assertSame(503, $httpFailure->raw['http_status']);

        $malformedEnvelope = $this->providerReturning(200, 'not-json')->review($this->input());
        self::assertSame(['AI review provider returned malformed JSON.'], $malformedEnvelope->reasons);

        $missingChoice = $this->providerReturning(200, '{"choices":[]}')->review($this->input());
        self::assertSame(['AI review provider response did not include a completion choice.'], $missingChoice->reasons);

        $malformedContent = $this->providerReturning(200, json_encode([
            'choices' => [['message' => ['content' => 'not-json']]],
        ], JSON_THROW_ON_ERROR))->review($this->input());
        self::assertSame(['AI review provider returned malformed JSON.'], $malformedContent->reasons);
    }

    public function testRejectsIncompleteConfigurationAndExposesHttpStatusParsing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI review api_key is required.');
        new OpenAiCompatibleCreativeReviewProvider([
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => ' ',
            'model' => 'review-model',
        ]);
    }

    public function testStatusCodeParsingAndDefaultHttpTransport(): void
    {
        self::assertSame(202, OpenAiCompatibleCreativeReviewProvider::statusCodeFromHeaders(['HTTP/1.1 202 Accepted'], '{}'));
        self::assertSame(0, OpenAiCompatibleCreativeReviewProvider::statusCodeFromHeaders([], false));
        self::assertSame(200, OpenAiCompatibleCreativeReviewProvider::statusCodeFromHeaders([], '{}'));

        $transport = OpenAiCompatibleCreativeReviewProvider::httpTransport();
        $result = $transport('data://text/plain,%7B%7D', ['model' => 'm'], ['Content-Type' => 'application/json'], 1);
        self::assertSame(['status' => 200, 'body' => '{}'], $result);
    }

    private function providerReturning(int $status, string $body): OpenAiCompatibleCreativeReviewProvider
    {
        return new OpenAiCompatibleCreativeReviewProvider([
            'base_url' => 'https://ai.example.test/v1',
            'api_key' => 'unit-test-ai-review-key',
            'model' => 'review-model',
        ], static fn (): array => ['status' => $status, 'body' => $body]);
    }

    private function input(?string $landingUrl = 'https://landing.example', ?string $copy = 'Launch offer'): AiReviewInput
    {
        return new AiReviewInput(
            assetId: 10,
            assetType: 'fabric_ad',
            objectKey: 'organizations/99/assets/creative.json',
            contentType: 'application/json',
            landingUrl: $landingUrl,
            copy: $copy,
        );
    }
}

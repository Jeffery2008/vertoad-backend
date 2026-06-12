<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Review\AiReviewPolicy;

final class AiReviewPolicyTest extends TestCase
{
    public function testDisabledFallbackCanBeConvertedToProviderConfigWithRuntimeSecret(): void
    {
        $policy = AiReviewPolicy::disabledFallback();

        self::assertFalse($policy->enabled);
        self::assertSame('openai_compatible', $policy->provider);
        self::assertSame(
            [
                'base_url' => 'https://localhost.invalid/v1',
                'api_key' => 'runtime-secret',
                'model' => 'deterministic-v1',
                'prompt' => 'Deterministic local AI review fallback.',
                'timeout_seconds' => 60,
                'max_input_tokens' => 12000,
                'max_output_tokens' => 2000,
                'temperature' => 0.2,
            ],
            $policy->toProviderConfig('runtime-secret'),
        );
    }

    public function testConstructorRejectsNonHttpBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base_url must be an HTTP(S) URL.');

        new AiReviewPolicy(
            enabled: true,
            provider: 'openai_compatible',
            baseUrl: 'ftp://ai.example.test/v1',
            model: 'review-model',
            prompt: 'Return JSON.',
            timeoutSeconds: 60,
            maxInputTokens: 12000,
            maxOutputTokens: 2000,
            temperature: 0.2,
        );
    }

    public function testConstructorRejectsEmptyModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('model must be a non-empty string.');

        new AiReviewPolicy(
            enabled: true,
            provider: 'openai_compatible',
            baseUrl: 'https://ai.example.test/v1',
            model: '   ',
            prompt: 'Return JSON.',
            timeoutSeconds: 60,
            maxInputTokens: 12000,
            maxOutputTokens: 2000,
            temperature: 0.2,
        );
    }

    public function testConstructorRejectsEmptyPrompt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt must be a non-empty string.');

        new AiReviewPolicy(
            enabled: true,
            provider: 'openai_compatible',
            baseUrl: 'https://ai.example.test/v1',
            model: 'review-model',
            prompt: '   ',
            timeoutSeconds: 60,
            maxInputTokens: 12000,
            maxOutputTokens: 2000,
            temperature: 0.2,
        );
    }

    public function testFromArrayRejectsNonIntegerTokenLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_input_tokens must be an integer.');

        AiReviewPolicy::fromArray([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://ai.example.test/v1',
            'model' => 'review-model',
            'prompt' => 'Return JSON.',
            'timeout_seconds' => 60,
            'max_input_tokens' => '12000',
            'max_output_tokens' => 2000,
            'temperature' => 0.2,
        ]);
    }

    public function testFromArrayRejectsNonNumericTemperature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('temperature must be a number.');

        AiReviewPolicy::fromArray([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://ai.example.test/v1',
            'model' => 'review-model',
            'prompt' => 'Return JSON.',
            'timeout_seconds' => 60,
            'max_input_tokens' => 12000,
            'max_output_tokens' => 2000,
            'temperature' => '0.2',
        ]);
    }
}

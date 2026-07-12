<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CronOpenApiContractTest extends TestCase
{
    public function testCronAuthenticationIsHeaderOnly(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        foreach (['/api/v1/cron/status', '/api/v1/cron/jobs/{job_name}/run'] as $path) {
            $operation = $openApi['paths'][$path]['get'] ?? null;
            self::assertIsArray($operation);
            self::assertSame([['CronTokenHeader' => []]], $operation['security'] ?? null);

            $parameters = $operation['parameters'] ?? [];
            self::assertIsArray($parameters);
            $header = array_values(array_filter(
                $parameters,
                static fn (mixed $parameter): bool => is_array($parameter)
                    && ($parameter['in'] ?? null) === 'header'
                    && ($parameter['name'] ?? null) === 'X-Cron-Token',
            ));
            self::assertCount(1, $header);
            self::assertTrue($header[0]['required'] ?? false);
            self::assertSame(
                [],
                array_values(array_filter(
                    $parameters,
                    static fn (mixed $parameter): bool => is_array($parameter)
                        && ($parameter['in'] ?? null) === 'query'
                        && ($parameter['name'] ?? null) === 'token',
                )),
            );
        }

        self::assertArrayNotHasKey('CronTokenQuery', $openApi['components']['securitySchemes'] ?? []);
    }

    public function testCronRunHttpStatusContractSeparatesSuccessLockNotFoundAndFailure(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $responses = $openApi['paths']['/api/v1/cron/jobs/{job_name}/run']['get']['responses'] ?? [];
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertArrayHasKey('500', $responses);
        self::assertSame(
            'cron_job_not_found',
            $responses['404']['content']['application/json']['examples']['unknownJob']['value']['error']['code'] ?? null,
        );
        self::assertSame(
            '#/components/schemas/CronJobFailureEnvelope',
            $responses['500']['content']['application/json']['schema']['$ref'] ?? null,
        );
        self::assertSame(
            ['completed', 'locked'],
            $openApi['components']['schemas']['CronJobRunData']['properties']['status']['enum'] ?? null,
        );
        self::assertSame(
            'cron_job_failed',
            $openApi['components']['schemas']['CronJobFailureError']['properties']['code']['const'] ?? null,
        );
        self::assertSame(
            'failed',
            $openApi['components']['schemas']['CronJobFailureError']['properties']['status']['const'] ?? null,
        );
    }
}

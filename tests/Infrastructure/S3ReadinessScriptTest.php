<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler as HttpMockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Scripts\S3Readiness\S3ReadinessProbe;
use VertoAD\Scripts\S3Readiness\S3ReadinessProfile;
use function VertoAD\Scripts\S3Readiness\main;
use function VertoAD\Scripts\S3Readiness\safeErrorMessage;

require_once dirname(__DIR__, 2) . '/scripts/s3-readiness.php';

final class S3ReadinessScriptTest extends TestCase
{
    public function testProfileLoadsEnvironmentContractsAndRejectsUnsafeConfiguration(): void
    {
        $environment = [
            'APP_ENV' => 'staging',
            'S3_ENDPOINT' => 'https://assets.example.test/',
            'S3_REGION' => '',
            'S3_BUCKET' => 'assets',
            'S3_ACCESS_KEY_ID' => 'asset-key',
            'S3_SECRET_ACCESS_KEY' => 'asset-secret',
            'S3_PATH_STYLE_ENDPOINT' => 'false',
            'ASSET_S3_SERVER_SIDE_ENCRYPTION' => '',
            'WITHDRAWAL_PROOF_S3_ENDPOINT' => 'https://proofs.example.test',
            'WITHDRAWAL_PROOF_S3_BUCKET' => 'proofs',
            'WITHDRAWAL_PROOF_S3_ACCESS_KEY_ID' => 'proof-key',
            'WITHDRAWAL_PROOF_S3_SECRET_ACCESS_KEY' => 'proof-secret',
            'BACKUP_S3_ENDPOINT' => 'https://backup.example.test',
            'BACKUP_S3_BUCKET' => 'backups',
            'BACKUP_S3_ACCESS_KEY_ID' => 'backup-key',
            'BACKUP_S3_SECRET_ACCESS_KEY' => 'backup-secret',
        ];

        $asset = S3ReadinessProfile::fromEnvironment($environment, 'asset');
        self::assertSame('https://assets.example.test', $asset->endpoint);
        self::assertSame('auto', $asset->region);
        self::assertFalse($asset->pathStyleEndpoint);
        self::assertNull($asset->serverSideEncryption);
        self::assertSame('asset-key', $asset->clientConfiguration()['credentials']->getAccessKeyId());
        self::assertSame('AES256', S3ReadinessProfile::fromEnvironment($environment, 'withdrawal-proof')->serverSideEncryption);
        self::assertSame('AES256', S3ReadinessProfile::fromEnvironment($environment, 'backup')->serverSideEncryption);

        foreach ([
            fn () => S3ReadinessProfile::fromEnvironment($environment, 'unknown'),
            fn () => S3ReadinessProfile::fromEnvironment(array_replace($environment, ['S3_ENDPOINT' => 'http://assets.test']), 'asset'),
            fn () => new S3ReadinessProfile('unknown', 'https://example.test', 'auto', 'b', 'a', 's', true, null),
            fn () => new S3ReadinessProfile('asset', 'https://user:pass@example.test', 'auto', 'b', 'a', 's', true, null),
            fn () => new S3ReadinessProfile('asset', 'https://user@example.test', 'auto', 'b', 'a', 's', true, null),
            fn () => new S3ReadinessProfile('asset', 'https://example.test?private=1', 'auto', 'b', 'a', 's', true, null),
            fn () => new S3ReadinessProfile('asset', 'https://example.test', 'auto', '', 'a', 's', true, null),
            fn () => new S3ReadinessProfile('asset', 'https://example.test', 'auto', 'b', 'a', 's', true, 'invalid'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid S3 readiness configuration.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testProbeVerifiesDirectAndPresignedRoundTripsAndCleanup(): void
    {
        $nonce = 'readiness123';
        $directBody = $this->body($nonce, 'direct');
        $presignedBody = $this->body($nonce, 'presigned');
        $aws = new AwsMockHandler();
        $aws->append(new Result([]));
        $aws->append(new Result([]));
        $aws->append(new Result([
            'ContentLength' => strlen($directBody),
            'ServerSideEncryption' => 'AES256',
            '@metadata' => ['headers' => ['content-type' => ['text/plain; charset=utf-8']]],
        ]));
        $aws->append(new Result([
            'Body' => Utils::streamFor($directBody),
        ]));
        $aws->append($this->head($presignedBody));
        $aws->append(new Result(['Body' => Utils::streamFor($presignedBody)]));
        $this->appendSuccessfulCleanup($aws);

        $history = [];
        $stack = HandlerStack::create(new HttpMockHandler([new Response(200)]));
        $stack->push(Middleware::history($history));
        $result = $this->probe($aws, new Client(['handler' => $stack]), $nonce)->run();

        self::assertSame([
            'profile' => 'asset',
            'bucket' => 'assets',
            'direct_round_trip' => 'passed',
            'presigned_put_round_trip' => 'passed',
            'cleanup' => 'passed',
            'probe_objects_remaining' => 0,
            'encryption_evidence' => 'AES256',
        ], $result);
        self::assertCount(1, $history);
        self::assertSame('PUT', $history[0]['request']->getMethod());
        self::assertSame($presignedBody, (string) $history[0]['request']->getBody());
        self::assertSame('text/plain', $history[0]['request']->getHeaderLine('Content-Type'));
        self::assertSame('AES256', $history[0]['request']->getHeaderLine('x-amz-server-side-encryption'));
    }

    public function testR2ManagedEncryptionUsesProviderEvidenceWithoutAwsSseHeaders(): void
    {
        $nonce = 'r2readiness123';
        $directBody = $this->body($nonce, 'direct');
        $presignedBody = $this->body($nonce, 'presigned');
        $directPut = null;
        $aws = new AwsMockHandler();
        $aws->append(new Result([]));
        $aws->append(static function (CommandInterface $command) use (&$directPut): Result {
            $directPut = $command;

            return new Result([]);
        });
        $aws->append(new Result([
            'ContentLength' => strlen($directBody),
            'ContentType' => 'text/plain',
        ]));
        $aws->append(new Result(['Body' => Utils::streamFor($directBody)]));
        $aws->append(new Result([
            'ContentLength' => strlen($presignedBody),
            'ContentType' => 'text/plain',
        ]));
        $aws->append(new Result(['Body' => Utils::streamFor($presignedBody)]));
        $this->appendSuccessfulCleanup($aws);

        $history = [];
        $stack = HandlerStack::create(new HttpMockHandler([new Response(200)]));
        $stack->push(Middleware::history($history));
        $result = $this->probe(
            $aws,
            new Client(['handler' => $stack]),
            $nonce,
            'R2-AES256',
        )->run();

        self::assertSame('cloudflare-r2-managed-aes256', $result['encryption_evidence']);
        self::assertNotNull($directPut);
        self::assertArrayNotHasKey('ServerSideEncryption', $directPut->toArray());
        self::assertCount(1, $history);
        self::assertSame('', $history[0]['request']->getHeaderLine('x-amz-server-side-encryption'));
    }

    public function testProbePreservesPrimaryFailureAndReportsCleanupFailure(): void
    {
        $aws = new AwsMockHandler();
        $aws->append(new Result([]));
        $aws->append($this->failure('PutObject', 403, 'AccessDenied'));
        $aws->append(new Result([]));
        $aws->append($this->missing());
        $aws->append($this->failure('DeleteObject', 503, 'SlowDown'));

        try {
            $this->probe($aws, new Client(['handler' => new HttpMockHandler()]), 'readiness456')->run();
            self::fail('Expected S3 readiness failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cleanup also failed', $exception->getMessage());
            self::assertInstanceOf(AwsException::class, $exception->getPrevious());
        }
    }

    public function testProbeRejectsUnsafeNonceAndVerificationFailures(): void
    {
        $this->assertRuntimeMessage(
            fn () => $this->probe(
                new AwsMockHandler(),
                new Client(['handler' => new HttpMockHandler()]),
                '../unsafe',
            )->run(),
            'unsafe value',
        );

        $nonce = 'readiness789';
        $directBody = $this->body($nonce, 'direct');
        foreach ([
            new Result(['ContentLength' => 1, 'ContentType' => 'text/plain', 'ServerSideEncryption' => 'AES256']),
            new Result(['ContentLength' => strlen($directBody), 'ContentType' => 'application/json', 'ServerSideEncryption' => 'AES256']),
            new Result(['ContentLength' => strlen($directBody), 'ContentType' => 'text/plain']),
        ] as $invalidHead) {
            $aws = new AwsMockHandler();
            $aws->append(new Result([]));
            $aws->append(new Result([]));
            $aws->append($invalidHead);
            $this->appendSuccessfulCleanup($aws);
            $this->assertRuntimeMessage(
                fn () => $this->probe($aws, new Client(['handler' => new HttpMockHandler()]), $nonce)->run(),
                'S3 readiness probe failed',
            );
        }

        $checksum = new AwsMockHandler();
        $checksum->append(new Result([]));
        $checksum->append(new Result([]));
        $checksum->append($this->head($directBody));
        $checksum->append(new Result(['Body' => 'wrong-body']));
        $this->appendSuccessfulCleanup($checksum);
        $this->assertRuntimeMessage(
            fn () => $this->probe($checksum, new Client(['handler' => new HttpMockHandler()]), $nonce)->run(),
            'S3 readiness probe failed',
        );

        $http = new AwsMockHandler();
        $http->append(new Result([]));
        $http->append(new Result([]));
        $http->append($this->head($directBody));
        $http->append(new Result(['Body' => $directBody]));
        $this->appendSuccessfulCleanup($http);
        $this->assertRuntimeMessage(
            fn () => $this->probe($http, new Client(['handler' => new HttpMockHandler([new Response(500)])]), $nonce)->run(),
            'S3 readiness probe failed',
        );
    }

    public function testProbeFailsWhenDeletedObjectStillExists(): void
    {
        $nonce = 'readiness999';
        $directBody = $this->body($nonce, 'direct');
        $presignedBody = $this->body($nonce, 'presigned');
        $aws = new AwsMockHandler();
        $aws->append(new Result([]));
        $aws->append(new Result([]));
        $aws->append($this->head($directBody));
        $aws->append(new Result(['Body' => $directBody]));
        $aws->append($this->head($presignedBody));
        $aws->append(new Result(['Body' => $presignedBody]));
        $aws->append(new Result([]));
        $aws->append(new Result(['ContentLength' => strlen($directBody)]));
        $aws->append(new Result([]));
        $aws->append($this->missing());

        $this->assertRuntimeMessage(
            fn () => $this->probe($aws, new Client(['handler' => new HttpMockHandler([new Response(200)])]), $nonce)->run(),
            'object cleanup failed',
        );
    }

    public function testCliHelpAndErrorRedactionDoNotExposeSignedCredentials(): void
    {
        self::assertSame(0, main(['s3-readiness.php', '--help']));

        $safe = safeErrorMessage(new RuntimeException(
            'GET https://example.test/object?X-Amz-Credential=credential&X-Amz-Signature=signature',
        ));
        self::assertStringNotContainsString('credential', $safe);
        self::assertStringNotContainsString('signature', $safe);
        self::assertSame(2, substr_count($safe, '[redacted]'));
        self::assertSame('S3 readiness failed.', safeErrorMessage(new RuntimeException('')));
        self::assertSame(
            'outer Cause: inner',
            safeErrorMessage(new RuntimeException('outer', previous: new RuntimeException('inner'))),
        );
    }

    private function profile(string $encryption = 'AES256'): S3ReadinessProfile
    {
        return new S3ReadinessProfile(
            'asset',
            $encryption === 'R2-AES256'
                ? 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com'
                : 'https://s3.example.test',
            'auto',
            'assets',
            'access-key',
            'secret-key',
            true,
            $encryption,
        );
    }

    private function client(AwsMockHandler $handler): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => 'https://s3.example.test',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'access-key', 'secret' => 'secret-key'],
            'handler' => $handler,
        ]);
    }

    private function probe(
        AwsMockHandler $handler,
        Client $http,
        string $nonce,
        string $encryption = 'AES256',
    ): S3ReadinessProbe
    {
        return new S3ReadinessProbe(
            $this->profile($encryption),
            $this->client($handler),
            $http,
            static fn (): string => $nonce,
        );
    }

    private function body(string $nonce, string $type): string
    {
        return 'vertoad-s3-readiness:' . $nonce . ':' . $type;
    }

    private function head(string $body): Result
    {
        return new Result([
            'ContentLength' => strlen($body),
            'ContentType' => 'text/plain',
            'ServerSideEncryption' => 'AES256',
        ]);
    }

    private function missing(): AwsException
    {
        return $this->failure('HeadObject', 404, 'NoSuchKey');
    }

    private function failure(string $command, int $status, string $code): AwsException
    {
        return new AwsException($code, new Command($command), [
            'response' => new Response($status),
            'code' => $code,
        ]);
    }

    private function appendSuccessfulCleanup(AwsMockHandler $handler): void
    {
        $handler->append(new Result([]));
        $handler->append($this->missing());
        $handler->append(new Result([]));
        $handler->append($this->missing());
    }

    private function assertRuntimeMessage(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected S3 readiness failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}

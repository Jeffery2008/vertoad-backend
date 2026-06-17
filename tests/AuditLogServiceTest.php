<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\RequestIdContext;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class AuditLogServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

    public function testRecordNormalizesMetadataAndIpAddressBeforeAppending(): void
    {
        $repository = new class implements AuditLogRepositoryInterface {
            public ?AuditLogEntry $entry = null;

            public function append(AuditLogEntry $entry): void
            {
                $this->entry = $entry;
            }
        };

        $service = new AuditLogService($repository);

        $service->record(
            action: ' admin.config.update ',
            subjectType: ' system_config ',
            subjectId: 42,
            actorUserId: 7,
            organizationId: 3,
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0',
            metadata: [
                'z' => 'last',
                'a' => [
                    'second' => 2,
                    'first' => 1,
                ],
                'empty' => null,
            ],
        );

        self::assertNotNull($repository->entry);
        self::assertSame('admin.config.update', $repository->entry->action);
        self::assertSame('system_config', $repository->entry->subjectType);
        self::assertSame("\x7f\x00\x00\x01", $repository->entry->packedIpAddress);
        self::assertSame(
            ['a' => ['first' => 1, 'second' => 2], 'empty' => null, 'z' => 'last'],
            $repository->entry->metadata
        );
    }

    public function testRecordRejectsMissingAction(): void
    {
        $service = new AuditLogService(new class implements AuditLogRepositoryInterface {
            public function append(AuditLogEntry $entry): void
            {
                TestCase::fail('Audit entry should not be appended.');
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit action is required.');

        $service->record(action: ' ', subjectType: 'system_config');
    }

    public function testRecordRejectsMissingSubjectTypeWithoutAppending(): void
    {
        $service = new AuditLogService(new class implements AuditLogRepositoryInterface {
            public function append(AuditLogEntry $entry): void
            {
                TestCase::fail('Audit entry should not be appended.');
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit subject type is required.');

        $service->record(action: 'admin.config.update', subjectType: ' ');
    }

    public function testRecordStoresNullIpUserAgentAndMetadataWhenOptionalValuesAreBlank(): void
    {
        $repository = new class implements AuditLogRepositoryInterface {
            public ?AuditLogEntry $entry = null;

            public function append(AuditLogEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $service = new AuditLogService($repository);

        $service->record(
            action: 'admin.config.update',
            subjectType: 'system_config',
            ipAddress: ' ',
            userAgent: '  CLI  ',
            metadata: null,
        );

        self::assertNotNull($repository->entry);
        self::assertNull($repository->entry->packedIpAddress);
        self::assertSame('CLI', $repository->entry->userAgent);
        self::assertNull($repository->entry->metadata);
    }

    public function testRecordInheritsCurrentRequestIdWhenNotExplicitlyProvided(): void
    {
        $repository = new class implements AuditLogRepositoryInterface {
            public ?AuditLogEntry $entry = null;

            public function append(AuditLogEntry $entry): void
            {
                $this->entry = $entry;
            }
        };
        $service = new AuditLogService($repository);
        RequestIdContext::begin((new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/operations/config/versions')
            ->withHeader('X-Request-Id', 'req-audit-context'));

        $service->record(
            action: 'admin.config.update',
            subjectType: 'system_config',
            metadata: ['endpoint' => '/api/v1/operations/config/versions'],
        );

        self::assertNotNull($repository->entry);
        self::assertSame('req-audit-context', $repository->entry->requestId);
        self::assertSame(['endpoint' => '/api/v1/operations/config/versions'], $repository->entry->metadata);
    }

    public function testRecordKeepsCurrentRequestIdAheadOfMetadataFallbacks(): void
    {
        $repository = new class implements AuditLogRepositoryInterface {
            /** @var list<AuditLogEntry> */
            public array $entries = [];

            public function append(AuditLogEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $service = new AuditLogService($repository);
        RequestIdContext::begin((new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/operations/config/versions')
            ->withHeader('X-Request-Id', 'req-current'));

        $service->record(
            action: 'admin.config.update',
            subjectType: 'system_config',
            metadata: [
                'request_id' => 'req-stale',
                'requestId' => 'req-stale',
                'correlation_id' => 'req-stale',
            ],
        );

        $service->record(
            action: 'admin.config.update',
            subjectType: 'system_config',
            requestId: 'req-explicit',
            metadata: [
                'request_id' => 'req-stale',
                'correlation_id' => 'req-stale',
            ],
        );

        self::assertSame('req-current', $repository->entries[0]->requestId);
        self::assertSame('req-explicit', $repository->entries[1]->requestId);
    }

    public function testRecordRejectsInvalidIpAddress(): void
    {
        $service = new AuditLogService(new class implements AuditLogRepositoryInterface {
            public function append(AuditLogEntry $entry): void
            {
                TestCase::fail('Audit entry should not be appended.');
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit IP address is invalid.');

        $service->record(action: 'admin.config.update', subjectType: 'system_config', ipAddress: 'not-an-ip');
    }
}

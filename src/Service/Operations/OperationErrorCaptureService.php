<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VertoAD\Domain\Operations\OperationErrorLog;
use VertoAD\Domain\Operations\OperationSystemLog;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Operations\OperationErrorLogRepositoryInterface;
use VertoAD\Repository\Operations\OperationSystemLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class OperationErrorCaptureService
{
    private const REDACTED = '[REDACTED]';

    public function __construct(
        private OperationErrorLogRepositoryInterface $errors,
        private AuditLogService $audit,
        private ?OperationSystemLogRepositoryInterface $systemLogs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function captureApiError(
        string $requestId,
        string $severity,
        string $message,
        array $context,
        DateTimeImmutable $occurredAt,
    ): array {
        return $this->capture('api', $requestId, $severity, $message, $context, $occurredAt)->toArray();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function capturePhpError(
        string $requestId,
        string $severity,
        string $message,
        array $context,
        DateTimeImmutable $occurredAt,
    ): array {
        return $this->capture('php', $requestId, $severity, $message, $context, $occurredAt)->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listErrors(?string $requestId = null): array
    {
        return array_map(static fn (OperationErrorLog $entry): array => $entry->toArray(), $this->errors->all($requestId));
    }

    /**
     * @return array<string, mixed>
     */
    public function rawContextFor(string $errorId, RequestUserContext $context): array
    {
        if ($context->user === null || !$context->user->isSuperAdmin) {
            throw new RuntimeException('Raw operation error context requires super administrator access.');
        }

        $entry = $this->errors->find($errorId);
        if ($entry === null) {
            throw new RuntimeException('Operation error log not found.');
        }

        $this->audit->record(
            action: 'operations.error.raw_context.viewed',
            subjectType: 'operation_error_log',
            actorUserId: $context->user->id,
            organizationId: $context->organizationId,
            requestId: $entry->request_id,
            metadata: [
                'error_id' => $entry->error_id,
                'request_id' => $entry->request_id,
            ],
        );

        return $entry->raw_context ?? [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function capture(
        string $source,
        string $requestId,
        string $severity,
        string $message,
        array $context,
        DateTimeImmutable $occurredAt,
    ): OperationErrorLog {
        foreach (['requestId' => $requestId, 'severity' => $severity, 'message' => $message] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field . ' is required.');
            }
        }

        $entry = $this->errors->append(new OperationErrorLog(
            error_id: 'operr_' . sha1($source . '|' . $requestId . '|' . $message . '|' . $occurredAt->format(DATE_ATOM)),
            request_id: $requestId,
            severity: $severity,
            message: $message,
            redacted_context: $this->redact($context),
            raw_context: $context,
            source: $source,
            occurred_at: $occurredAt,
        ));

        $this->appendSystemLog($entry, $context);

        return $entry;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'password') || str_contains($normalized, 'token') || str_contains($normalized, 'secret') || $normalized === 'authorization' || str_contains($normalized, 'api_key')) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function nullableContextString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function appendSystemLog(OperationErrorLog $entry, array $context): void
    {
        if ($this->systemLogs === null) {
            return;
        }

        $method = $this->nullableContextString($context['method'] ?? null);

        try {
            $this->systemLogs->append(new OperationSystemLog(
                log_id: 'syslog_' . sha1($entry->error_id),
                request_id: $entry->request_id,
                level: $entry->severity,
                message: $entry->message,
                endpoint: $this->nullableContextString($context['path'] ?? $context['endpoint'] ?? $context['route'] ?? null),
                http_method: $method === null ? null : strtoupper($method),
                ip_address: $this->nullableContextString($context['ip_address'] ?? null),
                source: $entry->source,
                redacted_context: $entry->redacted_context,
                raw_context: $entry->raw_context,
                occurred_at: $entry->occurred_at,
            ));
        } catch (Throwable) {
            return;
        }
    }
}

<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VertoAD\Domain\Audit\AuditLogRecord;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\RequestIdContext;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\AuditLogQueryRepositoryInterface;
use VertoAD\Repository\AuditLogRepositoryInterface;

final class AuditLogService
{
    public function __construct(private readonly AuditLogRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function record(
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        ?int $actorUserId = null,
        ?int $organizationId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
        ?array $metadata = null,
    ): void {
        $action = trim($action);
        if ($action === '') {
            throw new InvalidArgumentException('Audit action is required.');
        }

        $subjectType = trim($subjectType);
        if ($subjectType === '') {
            throw new InvalidArgumentException('Audit subject type is required.');
        }

        $packedIpAddress = $this->packIpAddress($ipAddress);

        $this->repository->append(new AuditLogEntry(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actorUserId: $actorUserId,
            organizationId: $organizationId,
            packedIpAddress: $packedIpAddress,
            userAgent: $userAgent === null ? null : trim($userAgent),
            requestId: $this->normalizeRequestId($requestId, $metadata),
            metadata: $this->normalizeMetadata($metadata),
        ));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, page: array{limit: int, offset: int, total: int, has_more: bool}}
     */
    public function search(array $filters, RequestUserContext $context): array
    {
        if (!$this->repository instanceof AuditLogQueryRepositoryInterface) {
            throw new InvalidArgumentException('Audit log repository does not support querying.');
        }

        $normalized = $this->normalizeSearchFilters($filters);
        $result = $this->repository->search($normalized);
        $includeRawContext = $context->user?->isSuperAdmin === true;

        return [
            'items' => array_map(
                fn (AuditLogRecord $record): array => $this->recordToArray($record, $includeRawContext),
                $result['items'],
            ),
            'page' => [
                'limit' => (int) $result['limit'],
                'offset' => (int) $result['offset'],
                'total' => (int) $result['total'],
                'has_more' => (bool) $result['has_more'],
            ],
        ];
    }

    private function packIpAddress(?string $ipAddress): ?string
    {
        if ($ipAddress === null || trim($ipAddress) === '') {
            return null;
        }

        $packed = inet_pton(trim($ipAddress));
        if ($packed === false) {
            throw new InvalidArgumentException('Audit IP address is invalid.');
        }

        return $packed;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        ksort($metadata);

        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadata($value);
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|string>
     */
    private function normalizeSearchFilters(array $filters): array
    {
        $normalized = [
            'limit' => $this->positiveIntegerWithDefault($filters['limit'] ?? null, 'limit', 50, 100),
            'offset' => $this->nonNegativeInteger($filters['offset'] ?? null, 'offset', 0),
        ];

        foreach (['action', 'subject_type', 'request_id', 'ip_address', 'endpoint'] as $field) {
            $value = $this->optionalStringFilter($filters[$field] ?? null, $field);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach (['organization_id', 'actor_user_id', 'subject_id'] as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $normalized[$field] = $this->requiredPositiveInteger($value, $field);
            }
        }

        foreach (['created_from', 'created_to'] as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '' && !is_string($value)) {
                throw new InvalidArgumentException($field . ' must be a valid date-time.');
            }
            if (is_string($value) && trim($value) !== '') {
                $normalized[$field] = $this->dateTimeFilter(trim($value), $field);
            }
        }

        if (
            isset($normalized['created_from'], $normalized['created_to'])
            && strcmp((string) $normalized['created_from'], (string) $normalized['created_to']) > 0
        ) {
            throw new InvalidArgumentException('created_from must be before or equal to created_to.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function normalizeRequestId(?string $requestId, ?array $metadata): ?string
    {
        $requestId = $requestId === null ? '' : trim($requestId);
        if ($requestId !== '') {
            return $requestId;
        }

        $current = RequestIdContext::current();
        if (is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        foreach (['request_id', 'requestId', 'correlation_id'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function optionalStringFilter(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveIntegerWithDefault(mixed $value, string $field, int $default, ?int $max = null): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $integer = (int) $value;
        if (
            (!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || $integer <= 0
            || ($max !== null && $integer > $max)
        ) {
            throw new InvalidArgumentException($field . ' must be a positive integer' . ($max === null ? '.' : ' no greater than ' . $max . '.'));
        }

        return $integer;
    }

    private function requiredPositiveInteger(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        $integer = (int) $value;
        if ($integer <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return $integer;
    }

    private function nonNegativeInteger(mixed $value, string $field, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 0) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer.');
        }

        return (int) $value;
    }

    private function dateTimeFilter(string $value, string $field): string
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException($field . ' must be a valid date-time.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function recordToArray(AuditLogRecord $record, bool $includeRawContext): array
    {
        return [
            'id' => $record->id,
            'organization_id' => $record->organizationId,
            'actor_user_id' => $record->actorUserId,
            'action' => $record->action,
            'subject_type' => $record->subjectType,
            'subject_id' => $record->subjectId,
            'ip_address' => $includeRawContext ? $record->ipAddress : null,
            'user_agent' => $includeRawContext ? $record->userAgent : null,
            'request_id' => $record->requestId,
            'metadata' => $includeRawContext ? $record->metadata : null,
            'context_redacted' => !$includeRawContext,
            'created_at' => $record->createdAt,
        ];
    }
}

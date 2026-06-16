<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class OperationRequestCorrelationService
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const AUDIT_QUERY_LIMIT = 100;
    private const OPERATIONS_IP_GEO_LOOKUP_ENDPOINT = '/api/v1/operations/ip-geo/lookup';
    private const OPERATIONS_IP_GEO_LOOKUP_METHOD = 'POST';

    public function __construct(
        private OperationErrorCaptureService $errors,
        private AuditLogService $auditLogs,
        private WebhookDeliveryRepositoryInterface $webhooks,
        private ?IpGeoRepositoryInterface $ipGeoRepository = null,
        private ?AdDecisionRepositoryInterface $servingDecisions = null,
        private ?AdEventRepositoryInterface $servingEvents = null,
        private ?DatabaseAdEventRepository $servingEventHistory = null,
    ) {
    }

    /**
     * @return array{
     *     request_id:string|null,
     *     timeline:list<array<string,mixed>>,
     *     operation_errors:list<array<string,mixed>>,
     *     audit_logs:list<array<string,mixed>>,
     *     webhook_deliveries:list<array<string,mixed>>,
     *     system_logs:list<array<string,mixed>>,
     *     serving_events:list<array<string,mixed>>,
     *     risk_decisions:list<array<string,mixed>>,
     *     ip_geo_lookups:list<array<string,mixed>>,
     *     counts:array<string,int>
     * }
     */
    public function find(string $requestId, RequestUserContext $context): array
    {
        $normalized = $this->normalizeFilters(['request_id' => $requestId, 'limit' => self::MAX_LIMIT]);
        if ($normalized['request_id'] === null) {
            throw new InvalidArgumentException('request_id is required.');
        }
        $matches = $this->buildMatches($normalized, $context);

        return [
            'request_id' => $normalized['request_id'],
            'timeline' => $matches['entries'],
            'operation_errors' => $matches['operation_errors'],
            'audit_logs' => $matches['audit_logs'],
            'webhook_deliveries' => $matches['webhook_deliveries'],
            'system_logs' => $matches['system_logs'],
            'serving_events' => $matches['serving_events'],
            'risk_decisions' => $matches['risk_decisions'],
            'ip_geo_lookups' => $matches['ip_geo_lookups'],
            'counts' => $matches['counts'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     filters:array<string,mixed>,
     *     entries:list<array<string,mixed>>,
     *     page:array{limit:int,offset:int,total:int,has_more:bool},
     *     generated_at:string
     * }
     */
    public function search(array $filters, RequestUserContext $context): array
    {
        $normalized = $this->normalizeFilters($filters);
        $matches = $this->buildMatches($normalized, $context);
        $entries = $matches['entries'];
        $total = count($entries);
        $entries = array_slice($entries, $normalized['offset'], $normalized['limit']);

        return [
            'filters' => $normalized,
            'entries' => $entries,
            'page' => [
                'limit' => $normalized['limit'],
                'offset' => $normalized['offset'],
                'total' => $total,
                'has_more' => $normalized['offset'] + count($entries) < $total,
            ],
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /**
     * @param array{
     *     request_id:string|null,
     *     actor_user_id:int|null,
     *     actor:string|null,
     *     action:string|null,
     *     subject_type:string|null,
     *     subject_id:string|null,
     *     ip_address:string|null,
     *     endpoint:string|null,
     *     occurred_from:string|null,
     *     occurred_to:string|null,
     *     entry_type:list<string>,
     *     limit:int,
     *     offset:int
     * } $normalized
     * @return array{
     *     operation_errors:list<array<string,mixed>>,
     *     audit_logs:list<array<string,mixed>>,
     *     webhook_deliveries:list<array<string,mixed>>,
     *     system_logs:list<array<string,mixed>>,
     *     serving_events:list<array<string,mixed>>,
     *     risk_decisions:list<array<string,mixed>>,
     *     ip_geo_lookups:list<array<string,mixed>>,
     *     entries:list<array<string,mixed>>,
     *     counts:array<string,int>
     * }
     */
    private function buildMatches(array $normalized, RequestUserContext $context): array
    {
        $operationErrors = $this->operationErrorMatches($normalized);
        $auditLogs = $this->auditLogMatches($normalized, $context);
        $webhookDeliveries = $this->webhookDeliveryMatches($normalized);
        $servingDecisions = $this->servingDecisionMatches($normalized);
        $servingEvents = $this->servingEventMatches($normalized);
        $systemLogs = $this->systemLogMatches($operationErrors, $normalized);
        $riskSourceEvents = $this->servingEventMatches($this->withoutSubjectFilters($normalized));
        $riskDecisions = $this->riskDecisionMatches($riskSourceEvents, $normalized);
        $ipGeoLookups = $this->ipGeoLookupMatches($normalized);
        $entries = [
            ...array_map(fn (array $entry): array => $this->operationErrorEntry($entry), $operationErrors),
            ...array_map(fn (array $entry): array => $this->auditLogEntry($entry), $auditLogs),
            ...array_map(fn (array $entry): array => $this->webhookDeliveryEntry($entry), $webhookDeliveries),
            ...array_map(fn (array $entry): array => $this->systemLogEntry($entry), $systemLogs),
            ...array_map(fn (object $decision): array => $this->servingDecisionEntry($decision), $servingDecisions),
            ...array_map(fn (object $event): array => $this->servingEventEntry($event), $servingEvents),
            ...array_map(fn (array $entry): array => $this->riskDecisionEntry($entry), $riskDecisions),
            ...array_map(fn (array $entry): array => $this->ipGeoLookupEntry($entry), $ipGeoLookups),
        ];
        $entries = $this->filterEntryTypes($entries, $normalized['entry_type']);
        usort(
            $entries,
            static fn (array $left, array $right): int =>
                strcmp((string) $right['sort_key'], (string) $left['sort_key']),
        );

        return [
            'operation_errors' => $operationErrors,
            'audit_logs' => $auditLogs,
            'webhook_deliveries' => $webhookDeliveries,
            'system_logs' => $systemLogs,
            'serving_events' => [
                ...array_map(fn (object $decision): array => $this->servingDecisionPayload($decision), $servingDecisions),
                ...array_map(fn (object $event): array => $this->servingEventPayload($event), $servingEvents),
            ],
            'risk_decisions' => $riskDecisions,
            'ip_geo_lookups' => $ipGeoLookups,
            'entries' => $entries,
            'counts' => [
                'operation_errors' => count($operationErrors),
                'audit_logs' => count($auditLogs),
                'webhook_deliveries' => count($webhookDeliveries),
                'system_logs' => count($systemLogs),
                'serving_events' => count($servingDecisions) + count($servingEvents),
                'risk_decisions' => count($riskDecisions),
                'ip_geo_lookups' => count($ipGeoLookups),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     request_id:string|null,
     *     actor_user_id:int|null,
     *     actor:string|null,
     *     action:string|null,
     *     subject_type:string|null,
     *     subject_id:string|null,
     *     ip_address:string|null,
     *     endpoint:string|null,
     *     occurred_from:string|null,
     *     occurred_to:string|null,
     *     entry_type:list<string>,
     *     limit:int,
     *     offset:int
     * }
     */
    private function normalizeFilters(array $filters): array
    {
        $requestId = $this->nullableString($filters['request_id'] ?? null, 'request_id');
        $actorUserId = $this->nullablePositiveInt($filters['actor_user_id'] ?? $filters['user_id'] ?? null, 'actor_user_id');
        $subjectId = $this->nullableString($filters['subject_id'] ?? null, 'subject_id');
        $occurredFrom = $this->nullableDateTime($filters['occurred_from'] ?? $filters['created_from'] ?? $filters['from'] ?? null, 'occurred_from');
        $occurredTo = $this->nullableDateTime($filters['occurred_to'] ?? $filters['created_to'] ?? $filters['to'] ?? null, 'occurred_to');

        if ($occurredFrom !== null && $occurredTo !== null && new DateTimeImmutable($occurredFrom) > new DateTimeImmutable($occurredTo)) {
            throw new InvalidArgumentException('occurred_from must be before or equal to occurred_to.');
        }

        return [
            'request_id' => $requestId,
            'actor_user_id' => $actorUserId,
            'actor' => $this->nullableString($filters['actor'] ?? $filters['user'] ?? null, 'actor'),
            'action' => $this->nullableString($filters['action'] ?? null, 'action'),
            'subject_type' => $this->nullableString($filters['subject_type'] ?? null, 'subject_type'),
            'subject_id' => $subjectId,
            'ip_address' => $this->nullableIpAddress($filters['ip_address'] ?? null),
            'endpoint' => $this->nullableString($filters['endpoint'] ?? null, 'endpoint'),
            'occurred_from' => $occurredFrom,
            'occurred_to' => $occurredTo,
            'entry_type' => $this->entryTypes($filters['entry_type'] ?? null),
            'limit' => $this->positiveInt($filters['limit'] ?? null, 'limit', self::DEFAULT_LIMIT, self::MAX_LIMIT),
            'offset' => $this->nonNegativeInt($filters['offset'] ?? null, 'offset'),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function operationErrorMatches(array $filters): array
    {
        $errors = $this->errors->listErrors($filters['request_id']);
        $items = [];
        foreach ($errors as $entry) {
            if (($filters['action'] !== null && $filters['action'] !== 'operations.error.captured') || $filters['actor_user_id'] !== null || $filters['actor'] !== null) {
                continue;
            }
            if (!$this->matchesSubject('operation_error_log', $entry['error_id'] ?? null, $filters)) {
                continue;
            }
            if (!$this->withinRange($entry['occurred_at'] ?? null, $filters)) {
                continue;
            }
            $context = is_array($entry['redacted_context'] ?? null) ? $entry['redacted_context'] : [];
            if (!$this->matchesEndpoint($context['path'] ?? null, $context['method'] ?? null, $filters['endpoint'])) {
                continue;
            }
            if ($filters['ip_address'] !== null && ($context['ip_address'] ?? null) !== $filters['ip_address']) {
                continue;
            }

            $items[] = $entry;
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function auditLogMatches(array $filters, RequestUserContext $context): array
    {
        $search = [
            'limit' => self::AUDIT_QUERY_LIMIT,
            'offset' => 0,
        ];
        foreach (['request_id', 'action', 'subject_type', 'ip_address', 'endpoint'] as $field) {
            if ($filters[$field] !== null) {
                $search[$field] = $filters[$field];
            }
        }
        foreach (['actor_user_id'] as $field) {
            if ($filters[$field] !== null) {
                $search[$field] = $filters[$field];
            }
        }
        if ($filters['subject_id'] !== null && ctype_digit((string) $filters['subject_id'])) {
            $search['subject_id'] = (int) $filters['subject_id'];
        }
        if ($filters['occurred_from'] !== null) {
            $search['created_from'] = $filters['occurred_from'];
        }
        if ($filters['occurred_to'] !== null) {
            $search['created_to'] = $filters['occurred_to'];
        }

        $result = $this->auditLogs->search($search, $context);
        $items = $result['items'];

        return array_values(array_filter($items, fn (array $entry): bool => $this->matchesActorText($entry, $filters['actor'])));
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function webhookDeliveryMatches(array $filters): array
    {
        $items = [];
        foreach ($this->webhooks->all() as $delivery) {
            if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
                continue;
            }
            if (!$this->matchesSubject('webhook_delivery', $delivery->delivery_id, $filters)) {
                continue;
            }
            $payload = $this->decodeWebhookPayload($delivery->payload_json);
            if ($filters['request_id'] !== null && $this->requestIdFromWebhook($delivery->toArray(), $payload) !== $filters['request_id']) {
                continue;
            }
            if (!$this->withinRange($delivery->created_at, $filters)) {
                continue;
            }
            if ($filters['action'] !== null && $delivery->event_type !== $filters['action']) {
                continue;
            }
            if (!$this->matchesEndpoint($delivery->endpoint_url, 'POST', $filters['endpoint'])) {
                continue;
            }

            $items[] = $delivery->toArray();
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $operationErrors
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function systemLogMatches(array $operationErrors, array $filters): array
    {
        if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
            return [];
        }
        if ($filters['action'] !== null && $filters['action'] !== 'system.log.captured') {
            return [];
        }

        $items = [];
        foreach ($operationErrors as $entry) {
            $systemLog = $this->systemLogPayload($entry);
            if (!$this->matchesSubject('system_log', $systemLog['log_id'], $filters)) {
                continue;
            }
            if (!$this->matchesEndpoint($systemLog['endpoint'], $systemLog['http_method'] ?? null, $filters['endpoint'])) {
                continue;
            }
            if ($filters['ip_address'] !== null && ($systemLog['redacted_context']['ip_address'] ?? null) !== $filters['ip_address']) {
                continue;
            }

            $items[] = $systemLog;
        }

        return $items;
    }

    /**
     * @param list<object> $servingEvents
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function riskDecisionMatches(array $servingEvents, array $filters): array
    {
        if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
            return [];
        }
        $items = [];
        foreach ($servingEvents as $event) {
            if (($event->eventType ?? null) !== 'click' || ($event->valid ?? true) === true) {
                continue;
            }

            $riskDecision = $this->riskDecisionPayload($event);
            if (!$this->matchesSubject($riskDecision['subject_type'], $riskDecision['subject_id'], $filters)) {
                continue;
            }
            if ($filters['action'] !== null && $riskDecision['action'] !== $filters['action']) {
                continue;
            }
            if ($filters['subject_id'] !== null && $riskDecision['subject_id'] !== $filters['subject_id']) {
                continue;
            }
            if (!$this->matchesEndpoint($riskDecision['endpoint'], 'GET', $filters['endpoint'])) {
                continue;
            }
            if ($filters['ip_address'] !== null && $riskDecision['ip_address'] !== $filters['ip_address']) {
                continue;
            }

            $items[] = $riskDecision;
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function ipGeoLookupMatches(array $filters): array
    {
        if ($this->ipGeoRepository === null) {
            return [];
        }

        if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
            return [];
        }
        if ($filters['subject_type'] !== null && $filters['subject_type'] !== 'ip_geo_lookup') {
            return [];
        }
        if ($filters['action'] !== null && $filters['action'] !== 'ip_geo.lookup.queued') {
            return [];
        }
        $items = array_map(
            static fn (object $task): array => $task->toArray(),
            $this->ipGeoRepository->searchLookups([
                'request_id' => $filters['request_id'],
                'ip_address' => $filters['ip_address'],
                'occurred_from' => $filters['occurred_from'],
                'occurred_to' => $filters['occurred_to'],
                'limit' => self::MAX_LIMIT,
            ]),
        );

        if ($filters['endpoint'] === null) {
            return array_values(array_filter(
                $items,
                fn (array $entry): bool => $this->matchesSubject('ip_geo_lookup', $entry['ip_address'] ?? null, $filters),
            ));
        }

        return array_values(array_filter($items, function (array $entry) use ($filters): bool {
            $endpoint = $this->endpointForIpGeoLookup($entry);

            return $this->matchesSubject('ip_geo_lookup', $entry['ip_address'] ?? null, $filters)
                && $this->matchesEndpoint($endpoint['endpoint'], $endpoint['method'], $filters['endpoint']);
        }));
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<object>
     */
    private function servingDecisionMatches(array $filters): array
    {
        if ($this->servingDecisions === null) {
            return [];
        }
        if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
            return [];
        }
        if ($filters['action'] !== null && $filters['action'] !== 'ads.serve') {
            return [];
        }
        if ($filters['endpoint'] !== null && !in_array($filters['endpoint'], ['/api/v1/ads/serve', 'POST:/api/v1/ads/serve', 'GET:/api/v1/ads/serve'], true)) {
            return [];
        }

        $items = $this->servingDecisions->searchDecisions([
            'request_id' => $filters['request_id'],
            'ip_address' => $filters['ip_address'],
            'occurred_from' => $filters['occurred_from'],
            'occurred_to' => $filters['occurred_to'],
            'limit' => self::MAX_LIMIT,
        ]);

        return array_values(array_filter(
            $items,
            fn (object $decision): bool => $this->matchesSubject('ad_decision', $decision->decisionId ?? null, $filters),
        ));
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<object>
     */
    private function servingEventMatches(array $filters): array
    {
        if ($this->servingEvents === null && $this->servingEventHistory === null) {
            return [];
        }
        if ($filters['actor_user_id'] !== null || $filters['actor'] !== null) {
            return [];
        }
        $eventType = null;
        if ($filters['action'] !== null) {
            if ($filters['action'] === 'ads.track.impression') {
                $eventType = 'impression';
            } elseif (in_array($filters['action'], ['ads.click', 'ads.click.invalid'], true)) {
                $eventType = 'click';
            } else {
                return [];
            }
        }
        if ($filters['endpoint'] !== null && !in_array($filters['endpoint'], ['/api/v1/ads/track', 'POST:/api/v1/ads/track', '/api/v1/ads/click', 'GET:/api/v1/ads/click'], true)) {
            return [];
        }

        $search = [
            'request_id' => $filters['request_id'],
            'ip_address' => $filters['ip_address'],
            'event_type' => $eventType,
            'occurred_from' => $filters['occurred_from'],
            'occurred_to' => $filters['occurred_to'],
            'limit' => self::MAX_LIMIT,
        ];
        $items = [];
        if ($this->servingEvents !== null) {
            $items = [
                ...$items,
                ...$this->servingEvents->searchEvents($search),
            ];
        }
        if ($this->servingEventHistory !== null) {
            $items = [
                ...$items,
                ...$this->servingEventHistory->searchEvents($search),
            ];
        }
        $items = $this->uniqueServingEvents($items);
        $items = array_values(array_filter(
            $items,
            fn (object $event): bool => $this->matchesSubject(
                'ad_event',
                (string) ($event->eventType ?? '') . ':' . (string) ($event->eventId ?? ''),
                $filters,
            ),
        ));
        if ($filters['endpoint'] === null) {
            return $items;
        }

        return array_values(array_filter($items, function (object $event) use ($filters): bool {
            $endpoint = $this->endpointForServingEvent($event);

            return $this->matchesEndpoint($endpoint['endpoint'], $endpoint['method'], $filters['endpoint']);
        }));
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function operationErrorEntry(array $entry): array
    {
        $sourceId = (string) $entry['error_id'];
        $context = is_array($entry['redacted_context'] ?? null) ? $entry['redacted_context'] : [];
        $endpoint = $this->endpointFromContext($context);
        $message = (string) ($entry['message'] ?? 'Operation error captured.');

        return [
            'entry_id' => 'operation_error:' . $sourceId,
            'entry_type' => 'operation_error',
            'source_id' => $sourceId,
            'request_id' => (string) $entry['request_id'],
            'occurred_at' => (string) $entry['occurred_at'],
            'sort_key' => $this->sortKey((string) $entry['occurred_at'], 'operation_error', $sourceId),
            'severity' => $this->severity((string) ($entry['severity'] ?? 'error')),
            'action' => 'operations.error.captured',
            'summary' => $message,
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('operation_error_log', $sourceId),
            'ip_address' => null,
            'endpoint' => $endpoint['endpoint'],
            'http_method' => $endpoint['method'],
            'geo' => null,
            'raw_context_available' => true,
            'context_redacted' => true,
            'redacted_context' => $context,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function auditLogEntry(array $entry): array
    {
        $sourceId = (string) $entry['id'];
        $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
        $endpoint = $this->endpointFromContext($metadata);
        $action = (string) ($entry['action'] ?? 'audit_log');

        return [
            'entry_id' => 'audit_log:' . $sourceId,
            'entry_type' => 'audit_log',
            'source_id' => $sourceId,
            'request_id' => (string) ($entry['request_id'] ?? $metadata['request_id'] ?? $metadata['correlation_id'] ?? ''),
            'occurred_at' => (string) ($entry['created_at'] ?? ''),
            'sort_key' => $this->sortKey((string) ($entry['created_at'] ?? ''), 'audit_log', $sourceId),
            'severity' => 'info',
            'action' => $action,
            'summary' => $action,
            'actor' => $this->actor($entry['actor_user_id'] ?? null, $metadata['actor'] ?? null),
            'subject' => $this->subject($entry['subject_type'] ?? null, $entry['subject_id'] ?? null),
            'ip_address' => $entry['ip_address'] ?? null,
            'endpoint' => $endpoint['endpoint'],
            'http_method' => $endpoint['method'],
            'geo' => null,
            'raw_context_available' => false,
            'context_redacted' => (bool) ($entry['context_redacted'] ?? true),
            'redacted_context' => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function webhookDeliveryEntry(array $entry): array
    {
        $sourceId = (string) $entry['delivery_id'];
        $payload = $this->decodeWebhookPayload((string) ($entry['payload_json'] ?? ''));
        $requestId = $this->requestIdFromWebhook($entry, $payload);
        $occurredAt = (string) ($entry['created_at'] ?? '');

        return [
            'entry_id' => 'webhook_delivery:' . $sourceId,
            'entry_type' => 'webhook_delivery',
            'source_id' => $sourceId,
            'request_id' => $requestId,
            'occurred_at' => $occurredAt,
            'sort_key' => $this->sortKey($occurredAt, 'webhook_delivery', $sourceId),
            'severity' => $this->webhookSeverity((string) ($entry['status'] ?? 'queued')),
            'action' => (string) ($entry['event_type'] ?? 'webhook_delivery'),
            'summary' => (string) ($entry['event_type'] ?? 'Webhook delivery'),
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('webhook_delivery', $sourceId),
            'ip_address' => null,
            'endpoint' => (string) ($entry['endpoint_url'] ?? ''),
            'http_method' => 'POST',
            'geo' => null,
            'raw_context_available' => false,
            'context_redacted' => true,
            'redacted_context' => [
                'endpoint_id' => $entry['endpoint_id'] ?? null,
                'status' => $entry['status'] ?? null,
                'retry_count' => $entry['retry_count'] ?? null,
                'last_status_code' => $entry['last_status_code'] ?? null,
                'last_error' => $entry['last_error'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function systemLogPayload(array $entry): array
    {
        $context = is_array($entry['redacted_context'] ?? null) ? $entry['redacted_context'] : [];
        $endpoint = $this->endpointFromContext($context);

        return [
            'log_id' => (string) ($entry['error_id'] ?? sha1(json_encode($entry, JSON_THROW_ON_ERROR))),
            'request_id' => (string) ($entry['request_id'] ?? ''),
            'level' => $this->severity((string) ($entry['severity'] ?? 'error')),
            'message' => (string) ($entry['message'] ?? 'Operation system log captured.'),
            'endpoint' => $endpoint['endpoint'],
            'http_method' => $endpoint['method'],
            'occurred_at' => (string) ($entry['occurred_at'] ?? ''),
            'redacted_context' => $context,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function systemLogEntry(array $entry): array
    {
        $sourceId = (string) $entry['log_id'];

        return [
            'entry_id' => 'system_log:' . $sourceId,
            'entry_type' => 'system_log',
            'source_id' => $sourceId,
            'request_id' => (string) $entry['request_id'],
            'occurred_at' => (string) $entry['occurred_at'],
            'sort_key' => $this->sortKey((string) $entry['occurred_at'], 'system_log', $sourceId),
            'severity' => $this->severity((string) $entry['level']),
            'action' => 'system.log.captured',
            'summary' => (string) $entry['message'],
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('system_log', $sourceId),
            'ip_address' => $entry['redacted_context']['ip_address'] ?? null,
            'endpoint' => $entry['endpoint'] ?? null,
            'http_method' => $entry['http_method'] ?? null,
            'geo' => null,
            'raw_context_available' => true,
            'context_redacted' => true,
            'redacted_context' => $entry['redacted_context'],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function ipGeoLookupEntry(array $entry): array
    {
        $ipAddress = (string) ($entry['ip_address'] ?? '');
        $sourceId = sha1($ipAddress . '|' . (string) ($entry['created_at'] ?? '') . '|' . (string) ($entry['request_id'] ?? ''));
        $occurredAt = (string) (($entry['resolved_at'] ?? null) ?: ($entry['next_attempt_at'] ?? null) ?: ($entry['created_at'] ?? ''));
        $status = (string) ($entry['status'] ?? 'pending');
        $endpoint = $this->endpointForIpGeoLookup($entry);

        return [
            'entry_id' => 'ip_geo_lookup:' . $sourceId,
            'entry_type' => 'ip_geo_lookup',
            'source_id' => $sourceId,
            'request_id' => (string) ($entry['request_id'] ?? ''),
            'occurred_at' => $occurredAt,
            'sort_key' => $this->sortKey($occurredAt, 'ip_geo_lookup', $sourceId),
            'severity' => in_array($status, ['failed', 'dead'], true) ? 'warning' : 'info',
            'action' => 'ip_geo.lookup.queued',
            'summary' => 'IP geo lookup ' . $status,
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('ip_geo_lookup', $ipAddress),
            'ip_address' => $ipAddress,
            'endpoint' => $endpoint['endpoint'],
            'http_method' => $endpoint['method'],
            'geo' => null,
            'raw_context_available' => false,
            'context_redacted' => true,
            'redacted_context' => [
                'source' => $entry['source'] ?? null,
                'status' => $status,
                'attempts' => $entry['attempts'] ?? null,
                'provider_id' => $entry['provider_id'] ?? null,
                'region_hint' => $entry['region_hint'] ?? null,
                'request_ids' => $entry['request_ids'] ?? [],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{endpoint:string|null,method:string|null}
     */
    private function endpointForIpGeoLookup(array $entry): array
    {
        $source = $this->nullableScalar($entry['source'] ?? null);
        if ($source === 'operations_realtime_lookup') {
            return [
                'endpoint' => self::OPERATIONS_IP_GEO_LOOKUP_ENDPOINT,
                'method' => self::OPERATIONS_IP_GEO_LOOKUP_METHOD,
            ];
        }

        return ['endpoint' => null, 'method' => null];
    }

    /**
     * @return array<string,mixed>
     */
    private function servingDecisionPayload(object $decision): array
    {
        return [
            'kind' => 'decision',
            'event_id' => (string) $decision->decisionId,
            'event_type' => 'serve',
            'decision_id' => (string) $decision->decisionId,
            'request_id' => $decision->requestId,
            'endpoint' => '/api/v1/ads/serve',
            'site_id' => $decision->siteId,
            'slot_id' => $decision->slotId,
            'viewer_id' => $decision->viewerId,
            'filled' => $decision->filled,
            'reason' => $decision->reason,
            'ad_id' => $decision->adId,
            'campaign_id' => $decision->campaignId,
            'advertiser_organization_id' => $decision->advertiserOrganizationId,
            'publisher_organization_id' => $decision->publisherOrganizationId,
            'impression_cost_points' => $decision->impressionCostPoints,
            'click_cost_points' => $decision->clickCostPoints,
            'occurred_at' => $decision->decidedAt->format(DATE_ATOM),
            'ip_address' => $decision->ipAddress,
            'user_agent' => $decision->userAgent,
            'geo_code' => $decision->geoCode,
            'geo' => $decision->geoCode === null ? null : ['canonical_geo_code' => $decision->geoCode],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function servingEventPayload(object $event): array
    {
        return [
            'kind' => 'event',
            'event_type' => $event->eventType === 'impression' ? 'track' : (string) $event->eventType,
            'event_id' => (string) $event->eventId,
            'decision_id' => (string) $event->decisionId,
            'request_id' => $event->requestId,
            'endpoint' => $this->endpointForServingEvent($event)['endpoint'],
            'site_id' => $event->siteId,
            'slot_id' => $event->slotId,
            'viewer_id' => $event->viewerId,
            'ad_id' => $event->adId,
            'campaign_id' => $event->campaignId,
            'advertiser_organization_id' => $event->advertiserOrganizationId,
            'publisher_organization_id' => $event->publisherOrganizationId,
            'cost_points' => $event->costPoints,
            'occurred_at' => $event->occurredAt->format(DATE_ATOM),
            'valid' => $event->valid,
            'reason' => $event->reason,
            'visible_ratio' => $event->visibleRatio,
            'visible_ms' => $event->visibleMs,
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
            'geo_code' => $event->geoCode,
            'geo' => $event->geoCode === null ? null : ['canonical_geo_code' => $event->geoCode],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function riskDecisionPayload(object $event): array
    {
        $eventId = (string) ($event->eventId ?? '');
        $reason = trim((string) ($event->reason ?? 'invalid_traffic'));

        return [
            'decision_id' => 'risk:' . $eventId,
            'request_id' => (string) ($event->requestId ?? ''),
            'action' => 'ads.click.invalid',
            'risk_score' => 100,
            'reason_codes' => $reason === '' ? [] : [$reason],
            'subject_type' => 'click',
            'subject_id' => $eventId,
            'ip_address' => $event->ipAddress,
            'endpoint' => '/api/v1/ads/click',
            'occurred_at' => $event->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function riskDecisionEntry(array $entry): array
    {
        $sourceId = (string) $entry['decision_id'];

        return [
            'entry_id' => 'risk_decision:' . $sourceId,
            'entry_type' => 'risk_decision',
            'source_id' => $sourceId,
            'request_id' => (string) $entry['request_id'],
            'occurred_at' => (string) $entry['occurred_at'],
            'sort_key' => $this->sortKey((string) $entry['occurred_at'], 'risk_decision', $sourceId),
            'severity' => 'warning',
            'action' => (string) $entry['action'],
            'summary' => 'Risk decision rejected: ' . implode(', ', $entry['reason_codes']),
            'actor' => $this->actor(null, null),
            'subject' => $this->subject($entry['subject_type'], $entry['subject_id']),
            'ip_address' => $entry['ip_address'],
            'endpoint' => $entry['endpoint'],
            'http_method' => 'GET',
            'geo' => null,
            'raw_context_available' => false,
            'context_redacted' => true,
            'redacted_context' => [
                'risk_score' => $entry['risk_score'],
                'reason_codes' => $entry['reason_codes'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function servingDecisionEntry(object $decision): array
    {
        $sourceId = (string) $decision->decisionId;
        $occurredAt = $decision->decidedAt->format(DATE_ATOM);

        return [
            'entry_id' => 'serving_decision:' . $sourceId,
            'entry_type' => 'serving_event',
            'source_id' => $sourceId,
            'request_id' => (string) ($decision->requestId ?? ''),
            'occurred_at' => $occurredAt,
            'sort_key' => $this->sortKey($occurredAt, 'serving_event', $sourceId),
            'severity' => $decision->filled ? 'info' : 'warning',
            'action' => 'ads.serve',
            'summary' => $decision->filled ? 'Ad served' : 'Ad serve no-fill: ' . (string) $decision->reason,
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('ad_decision', $sourceId),
            'ip_address' => $decision->ipAddress,
            'endpoint' => '/api/v1/ads/serve',
            'http_method' => 'POST',
            'geo' => $decision->geoCode === null ? null : ['canonical_geo_code' => $decision->geoCode],
            'raw_context_available' => false,
            'context_redacted' => true,
            'redacted_context' => [
                'site_id' => $decision->siteId,
                'slot_id' => $decision->slotId,
                'viewer_id' => $decision->viewerId,
                'filled' => $decision->filled,
                'reason' => $decision->reason,
                'ad_id' => $decision->adId,
                'campaign_id' => $decision->campaignId,
                'publisher_organization_id' => $decision->publisherOrganizationId,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function servingEventEntry(object $event): array
    {
        $sourceId = (string) $event->eventType . ':' . (string) $event->eventId;
        $occurredAt = $event->occurredAt->format(DATE_ATOM);
        $endpoint = $this->endpointForServingEvent($event);

        return [
            'entry_id' => 'serving_event:' . $sourceId,
            'entry_type' => $event->eventType === 'click' ? 'click_event' : 'tracking_event',
            'source_id' => $sourceId,
            'request_id' => (string) ($event->requestId ?? ''),
            'occurred_at' => $occurredAt,
            'sort_key' => $this->sortKey($occurredAt, (string) $event->eventType, $sourceId),
            'severity' => $event->valid ? 'info' : 'warning',
            'action' => $event->eventType === 'click'
                ? ($event->valid ? 'ads.click' : 'ads.click.invalid')
                : 'ads.track.impression',
            'summary' => $event->eventType === 'click'
                ? ($event->valid ? 'Ad click accepted' : 'Ad click rejected: ' . (string) $event->reason)
                : 'Ad impression tracked',
            'actor' => $this->actor(null, null),
            'subject' => $this->subject('ad_event', $sourceId),
            'ip_address' => $event->ipAddress,
            'endpoint' => $endpoint['endpoint'],
            'http_method' => $endpoint['method'],
            'geo' => $event->geoCode === null ? null : ['canonical_geo_code' => $event->geoCode],
            'raw_context_available' => false,
            'context_redacted' => true,
            'redacted_context' => [
                'decision_id' => $event->decisionId,
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'site_id' => $event->siteId,
                'slot_id' => $event->slotId,
                'viewer_id' => $event->viewerId,
                'valid' => $event->valid,
                'reason' => $event->reason,
                'ad_id' => $event->adId,
                'campaign_id' => $event->campaignId,
                'cost_points' => $event->costPoints,
                'visible_ratio' => $event->visibleRatio,
                'visible_ms' => $event->visibleMs,
            ],
        ];
    }

    /**
     * @return array{endpoint:string,method:string}
     */
    private function endpointForServingEvent(object $event): array
    {
        return $event->eventType === 'click'
            ? ['endpoint' => '/api/v1/ads/click', 'method' => 'GET']
            : ['endpoint' => '/api/v1/ads/track', 'method' => 'POST'];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param list<string> $entryTypes
     * @return list<array<string,mixed>>
     */
    private function filterEntryTypes(array $entries, array $entryTypes): array
    {
        if ($entryTypes === []) {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => in_array((string) $entry['entry_type'], $entryTypes, true),
        ));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function withoutSubjectFilters(array $filters): array
    {
        $filters['subject_type'] = null;
        $filters['subject_id'] = null;

        return $filters;
    }

    /**
     * @param list<object> $events
     * @return list<object>
     */
    private function uniqueServingEvents(array $events): array
    {
        $unique = [];
        foreach ($events as $event) {
            $key = (string) ($event->eventType ?? '') . ':' . (string) ($event->eventId ?? '');
            if ($key === ':' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $event;
        }

        return array_values($unique);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{endpoint:string|null,method:string|null}
     */
    private function endpointFromContext(array $context): array
    {
        $endpoint = $this->nullableScalar($context['endpoint'] ?? null)
            ?? $this->nullableScalar($context['route'] ?? null)
            ?? $this->nullableScalar($context['path'] ?? null);
        $method = $this->nullableScalar($context['method'] ?? null);

        return [
            'endpoint' => $endpoint,
            'method' => $method === null ? null : strtoupper($method),
        ];
    }

    private function matchesEndpoint(mixed $path, mixed $method, ?string $expected): bool
    {
        if ($expected === null) {
            return true;
        }

        $endpoint = $this->nullableScalar($path);
        $httpMethod = $this->nullableScalar($method);
        $candidates = array_filter([
            $endpoint,
            $httpMethod === null || $endpoint === null ? null : strtoupper($httpMethod) . ':' . $endpoint,
        ]);

        return in_array($expected, $candidates, true);
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function matchesSubject(mixed $subjectType, mixed $subjectId, array $filters): bool
    {
        $expectedType = $filters['subject_type'];
        $expectedId = $filters['subject_id'];
        $actualType = $this->nullableScalar($subjectType);

        if ($expectedType !== null && $actualType !== $expectedType) {
            return false;
        }
        if ($expectedId === null) {
            return true;
        }

        $actualId = $this->nullableScalar($subjectId);

        return $actualId !== null && $actualId === $expectedId;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function matchesActorText(array $entry, ?string $actor): bool
    {
        if ($actor === null) {
            return true;
        }

        $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
        foreach ([$entry['actor_user_id'] ?? null, $metadata['actor'] ?? null, $metadata['actor_email'] ?? null, $metadata['user'] ?? null] as $value) {
            if (is_scalar($value) && str_contains(strtolower((string) $value), strtolower($actor))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function withinRange(mixed $occurredAt, array $filters): bool
    {
        if (!$occurredAt instanceof DateTimeImmutable) {
            if (!is_scalar($occurredAt) || trim((string) $occurredAt) === '') {
                return true;
            }
            $occurredAt = new DateTimeImmutable((string) $occurredAt);
        }

        if ($filters['occurred_from'] !== null && $occurredAt < new DateTimeImmutable((string) $filters['occurred_from'])) {
            return false;
        }

        return $filters['occurred_to'] === null || $occurredAt <= new DateTimeImmutable((string) $filters['occurred_to']);
    }

    /**
     * @return array{actor_type:string,actor_id:string|null,actor_user_id:int|null,display:string|null}
     */
    private function actor(mixed $actorUserId, mixed $display): array
    {
        $actorUserId = is_int($actorUserId) ? $actorUserId : (is_string($actorUserId) && ctype_digit($actorUserId) ? (int) $actorUserId : null);

        return [
            'actor_type' => $actorUserId === null ? 'unknown' : 'user',
            'actor_id' => $actorUserId === null ? null : (string) $actorUserId,
            'actor_user_id' => $actorUserId,
            'display' => $this->nullableScalar($display) ?? ($actorUserId === null ? null : 'user:' . $actorUserId),
        ];
    }

    /**
     * @return array{subject_type:string|null,subject_id:int|string|null}
     */
    private function subject(mixed $subjectType, mixed $subjectId): array
    {
        return [
            'subject_type' => $this->nullableScalar($subjectType),
            'subject_id' => is_int($subjectId) || is_string($subjectId) ? $subjectId : null,
        ];
    }

    private function sortKey(string $occurredAt, string $type, string $sourceId): string
    {
        return $occurredAt . '|' . $type . '|' . $sourceId;
    }

    private function severity(string $severity): string
    {
        return in_array($severity, ['debug', 'info', 'warning', 'error', 'critical'], true) ? $severity : 'error';
    }

    private function webhookSeverity(string $status): string
    {
        return in_array($status, ['failed', 'dead'], true) ? 'warning' : 'info';
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }
        $value = (int) $value;
        if ($value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return $value;
    }

    private function nullableIpAddress(mixed $value): ?string
    {
        $value = $this->nullableString($value, 'ip_address');
        if ($value !== null && @inet_pton($value) === false) {
            throw new InvalidArgumentException('ip_address must be a valid IP address.');
        }

        return $value;
    }

    private function nullableDateTime(mixed $value, string $field): ?string
    {
        $value = $this->nullableString($value, $field);
        if ($value === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (\Exception) {
            throw new InvalidArgumentException($field . ' must be a valid date-time.');
        }
    }

    private function positiveInt(mixed $value, string $field, int $default, int $max): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }
        $value = (int) $value;
        if ($value < 1 || $value > $max) {
            throw new InvalidArgumentException($field . ' must be between 1 and ' . $max . '.');
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer.');
        }
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function entryTypes(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $allowed = [
            'audit_log',
            'operation_error',
            'system_log',
            'webhook_delivery',
            'serving_event',
            'tracking_event',
            'click_event',
            'risk_decision',
            'ip_geo_lookup',
        ];
        $normalized = [];
        foreach ($values as $entryType) {
            if (!is_scalar($entryType)) {
                throw new InvalidArgumentException('entry_type must be a string.');
            }
            $entryType = trim((string) $entryType);
            if ($entryType === '') {
                continue;
            }
            if (!in_array($entryType, $allowed, true)) {
                throw new InvalidArgumentException('entry_type is not supported.');
            }
            $normalized[] = $entryType;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeWebhookPayload(string $payloadJson): array
    {
        try {
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function requestIdFromWebhook(array $entry, array $payload): string
    {
        $storedRequestId = $this->nullableScalar($entry['request_id'] ?? null);
        if ($storedRequestId !== null) {
            return $storedRequestId;
        }

        return self::requestIdFromWebhookPayload($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function requestIdFromWebhookPayload(array $payload): string
    {
        foreach (['request_id', 'correlation_id'] as $key) {
            $requestId = $payload[$key] ?? null;
            if (!is_scalar($requestId)) {
                continue;
            }

            $requestId = trim((string) $requestId);
            if ($requestId !== '') {
                return $requestId;
            }
        }

        return '';
    }
}

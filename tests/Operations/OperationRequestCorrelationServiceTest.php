<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationRequestCorrelationService;

final class OperationRequestCorrelationServiceTest extends TestCase
{
    public function testFindRejectsBlankRequestId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('request_id is required.');

        $this->service()->find('   ', $this->context());
    }

    public function testSearchRejectsInvertedDateRangesAndNormalizesAssociationFilters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('occurred_from must be before or equal to occurred_to.');

        $this->service()->search([
            'request_id' => 'req-1',
            'occurred_from' => '2026-06-09T00:00:00Z',
            'occurred_to' => '2026-06-08T00:00:00Z',
        ], $this->context());
    }

    public function testSearchKeepsIpEndpointAndActionFilters(): void
    {
        $service = $this->service([
            'errors' => [
                [
                    'request_id' => 'req-1',
                    'severity' => 'error',
                    'message' => 'Cache miss.',
                    'redacted_context' => [
                        'path' => '/api/v1/ads/track',
                        'method' => 'POST',
                        'ip_address' => '203.0.113.5',
                    ],
                    'occurred_at' => '2026-06-08T02:14:00Z',
                ],
            ],
            'auditLogs' => [
                [
                    'actor_user_id' => 7,
                    'action' => 'config.rollback',
                    'subject_type' => 'config',
                    'subject_id' => 42,
                    'ip_address' => '203.0.113.5',
                    'request_id' => 'req-audit-only',
                    'metadata' => ['request_id' => 'req-audit-only', 'endpoint' => '/api/v1/operations/config/versions'],
                ],
            ],
            'webhookDeliveries' => [self::webhookDelivery('wh_1', 'req-1')],
        ]);

        $search = $service->search([
            'request_id' => 'req-1',
            'action' => 'operations.error.captured',
            'ip_address' => '203.0.113.5',
            'endpoint' => '/api/v1/ads/track',
            'entry_type' => 'operation_error',
            'limit' => 10,
        ], $this->context());

        self::assertSame(1, $search['page']['total']);
        self::assertSame('operation_error', $search['entries'][0]['entry_type']);
    }

    public function testSearchAppliesOperationErrorRangeAndIpExclusionFilters(): void
    {
        $service = $this->service([
            'errors' => [
                [
                    'request_id' => 'req-filtered',
                    'severity' => 'error',
                    'message' => 'Old error.',
                    'redacted_context' => ['path' => '/api/v1/ads/track', 'method' => 'POST', 'ip_address' => '203.0.113.10'],
                    'occurred_at' => '2026-06-08T01:00:00Z',
                ],
                [
                    'request_id' => 'req-filtered',
                    'severity' => 'warning',
                    'message' => 'Wrong IP.',
                    'redacted_context' => ['path' => '/api/v1/ads/track', 'method' => 'POST', 'ip_address' => '203.0.113.11'],
                    'occurred_at' => '2026-06-08T03:00:00Z',
                ],
            ],
        ]);

        $search = $service->search([
            'request_id' => 'req-filtered',
            'occurred_from' => '2026-06-08T02:00:00Z',
            'ip_address' => '203.0.113.10',
        ], $this->context());

        self::assertSame(0, $search['page']['total']);
    }

    public function testSearchPreservesAuditSubjectDatesEndpointAndMatchingActorText(): void
    {
        $service = $this->service([
            'auditLogs' => [
                [
                    'actor_user_id' => 88,
                    'action' => 'config.publish',
                    'subject_type' => 'config',
                    'subject_id' => 77,
                    'ip_address' => '203.0.113.8',
                    'request_id' => 'req-audit-1',
                    'metadata' => [
                        'request_id' => 'req-audit-1',
                        'endpoint' => '/api/v1/operations/config/versions',
                        'actor' => 'Ops Manager',
                        'actor_email' => 'ops@example.test',
                    ],
                ],
            ],
        ]);

        $search = $service->search([
            'request_id' => 'req-audit-1',
            'actor' => 'ops manager',
            'subject_id' => '77',
            'occurred_from' => '2026-06-08T00:00:00Z',
            'occurred_to' => '2026-06-09T00:00:00Z',
            'endpoint' => '/api/v1/operations/config/versions',
        ], $this->superAdminContext());

        self::assertCount(1, $search['entries']);
        self::assertSame('audit_log', $search['entries'][0]['entry_type']);
        self::assertSame('Ops Manager', $search['entries'][0]['actor']['display']);
        self::assertSame(88, $search['entries'][0]['actor']['actor_user_id']);
    }

    public function testSearchDropsAuditRowsWhenActorTextDoesNotMatch(): void
    {
        $service = $this->service([
            'auditLogs' => [
                [
                    'actor_user_id' => 88,
                    'action' => 'config.publish',
                    'subject_type' => 'config',
                    'subject_id' => 77,
                    'request_id' => 'req-audit-miss',
                    'metadata' => ['actor' => 'Ops Manager', 'actor_email' => 'ops@example.test'],
                ],
            ],
        ]);

        $search = $service->search([
            'request_id' => 'req-audit-miss',
            'actor' => 'finance',
        ], $this->superAdminContext());

        self::assertSame(0, $search['page']['total']);
    }

    public function testSearchIncludesWebhookStoredPayloadAndMissingRequestIds(): void
    {
        $service = $this->service([
            'webhookDeliveries' => [
                self::webhookDelivery('wh_stored', 'req-webhook-stored'),
                [
                    'delivery_id' => 'wh_correlation',
                    'endpoint_url' => 'https://example.test/hooks',
                    'event_type' => 'review.completed',
                    'payload_json' => json_encode(['correlation_id' => 'req-webhook-correlation'], JSON_THROW_ON_ERROR),
                    'request_id' => null,
                    'status' => 'dead',
                    'created_at' => '2026-06-08T02:22:00Z',
                ],
                [
                    'delivery_id' => 'wh_invalid_json',
                    'endpoint_url' => 'https://example.test/hooks',
                    'event_type' => 'review.completed',
                    'payload_json' => '{not-json',
                    'request_id' => null,
                    'status' => 'queued',
                    'created_at' => '2026-06-08T02:23:00Z',
                ],
                [
                    'delivery_id' => 'wh_no_request_id',
                    'endpoint_url' => 'https://example.test/hooks',
                    'event_type' => 'review.completed',
                    'payload_json' => json_encode(['request_id' => [], 'correlation_id' => '   '], JSON_THROW_ON_ERROR),
                    'request_id' => null,
                    'status' => 'queued',
                    'created_at' => '2026-06-08T02:24:00Z',
                ],
            ],
        ]);

        $search = $service->search([
            'entry_type' => 'webhook_delivery',
            'limit' => 10,
        ], $this->context());

        self::assertSame(4, $search['page']['total']);
        $requestIdsBySource = array_column($search['entries'], 'request_id', 'source_id');
        ksort($requestIdsBySource);
        self::assertSame(
            [
                'wh_correlation' => 'req-webhook-correlation',
                'wh_invalid_json' => '',
                'wh_no_request_id' => '',
                'wh_stored' => 'req-webhook-stored',
            ],
            $requestIdsBySource,
        );
    }

    public function testSearchExcludesWebhookRowsByRequestIdAndDateRange(): void
    {
        $service = $this->service([
            'webhookDeliveries' => [
                self::webhookDelivery('wh_request_miss', 'req-other'),
                [
                    ...self::webhookDelivery('wh_old', 'req-webhook-filter'),
                    'created_at' => '2026-06-08T01:00:00Z',
                ],
            ],
        ]);

        $search = $service->search([
            'request_id' => 'req-webhook-filter',
            'occurred_from' => '2026-06-08T02:00:00Z',
            'entry_type' => 'webhook_delivery',
        ], $this->context());

        self::assertSame(0, $search['page']['total']);
    }

    public function testFindIncludesServingEventsRiskDecisionsAndIpGeoLookups(): void
    {
        $ipGeoRepository = $this->ipGeoRepository([]);
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $decision = $this->decision('decision-1', 'req-2', '203.0.113.5', new DateTimeImmutable('2026-06-08T02:17:00Z'));
        $decisions->save($decision);
        $events->recordInvalidClick($decision, 'click-1', new DateTimeImmutable('2026-06-08T02:18:00Z'), 'repeat_click_window', 'req-2');

        $result = $this->service(
            ipGeoRepository: $ipGeoRepository,
            servingDecisions: $decisions,
            servingEvents: $events,
        )->find('req-2', $this->context());

        self::assertSame(2, $result['counts']['serving_events']);
        self::assertSame(1, $result['counts']['risk_decisions']);
        self::assertSame(0, $result['counts']['ip_geo_lookups']);
        self::assertSame('ads.click.invalid', $result['risk_decisions'][0]['action']);
        self::assertSame('click-1', $result['risk_decisions'][0]['subject_id']);
    }

    public function testFindIncludesWebhookCorrelationAndIpGeoLookupPayloadNormalization(): void
    {
        $events = $this->eventRepository([
            $this->adEvent('impression', 'imp-find-1', '2026-06-08T02:21:00Z', 'req-find-1'),
            $this->adEvent('click', 'clk-find-1', '2026-06-08T02:23:00Z', 'req-find-1', false, 'repeat_click_window'),
        ]);
        $service = $this->service(
            fixtures: [
                'webhookDeliveries' => [
                    [
                        'delivery_id' => 'wh_correlation_1',
                        'endpoint_url' => 'https://example.test/hooks',
                        'event_type' => 'review.completed',
                        'payload_json' => json_encode(['correlation_id' => 'req-find-1'], JSON_THROW_ON_ERROR),
                        'request_id' => null,
                        'status' => 'dead',
                        'created_at' => '2026-06-08T02:22:00Z',
                    ],
                ],
            ],
            ipGeoRepository: $this->ipGeoRepository([
                [
                    'ip_address' => '203.0.113.44',
                    'user_agent' => null,
                    'region_hint' => 'CN-SH',
                    'request_id' => 'req-find-1',
                    'request_ids' => 'not-an-array',
                    'source' => 'operations_realtime_lookup',
                    'status' => 'failed',
                    'attempts' => 2,
                    'provider_id' => 'provider-x',
                    'last_error' => 'timeout',
                    'created_at' => '2026-06-08T02:20:00Z',
                    'next_attempt_at' => '2026-06-08T02:25:00Z',
                    'resolved_at' => null,
                ],
            ]),
            servingEvents: $events,
        );

        $result = $service->find('req-find-1', $this->context());
        $webhookEntries = array_values(array_filter(
            $result['timeline'],
            static fn (array $entry): bool => $entry['entry_type'] === 'webhook_delivery',
        ));
        $ipGeoEntries = array_values(array_filter(
            $result['timeline'],
            static fn (array $entry): bool => $entry['entry_type'] === 'ip_geo_lookup',
        ));

        self::assertSame('req-find-1', $webhookEntries[0]['request_id']);
        self::assertSame('/api/v1/operations/ip-geo/lookup', $ipGeoEntries[0]['endpoint']);
        self::assertSame('POST', $ipGeoEntries[0]['http_method']);
        self::assertSame([], $result['ip_geo_lookups'][0]['request_ids']);
        self::assertSame('click', $result['serving_events'][1]['event_type']);
        self::assertSame('ads.click.invalid', $result['risk_decisions'][0]['action']);
    }

    public function testSearchExcludesIpGeoLookupsForActorSubjectAndActionFilters(): void
    {
        $service = $this->service(ipGeoRepository: $this->ipGeoRepository([
            [
                'ip_address' => '203.0.113.45',
                'request_id' => 'req-geo-filter',
                'request_ids' => ['req-geo-filter'],
                'source' => 'operations_realtime_lookup',
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => '2026-06-08T02:20:00Z',
            ],
        ]));

        self::assertSame(0, $service->search(['request_id' => 'req-geo-filter', 'actor_user_id' => 1], $this->context())['page']['total']);
        self::assertSame(0, $service->search(['request_id' => 'req-geo-filter', 'subject_type' => 'campaign'], $this->context())['page']['total']);
        self::assertSame(0, $service->search(['request_id' => 'req-geo-filter', 'action' => 'ip_geo.lookup.resolved'], $this->context())['page']['total']);
    }

    public function testIpGeoLookupWithoutRequestIdStaysNullableInPayloadAndTimeline(): void
    {
        $service = $this->service(ipGeoRepository: $this->ipGeoRepository([
            [
                'ip_address' => '203.0.113.46',
                'request_id' => null,
                'request_ids' => [],
                'source' => 'serving',
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => '2026-06-08T02:20:00Z',
            ],
        ]));

        $result = $service->search(['entry_type' => 'ip_geo_lookup'], $this->context());

        self::assertSame(1, $result['page']['total']);
        self::assertNull($result['entries'][0]['request_id']);
    }

    public function testSearchFiltersServingDecisionsAndEventsByActorActionAndEntryType(): void
    {
        $decision = $this->decision('decision-serve-filter', 'req-serving-filter', '203.0.113.60', new DateTimeImmutable('2026-06-08T02:17:00Z'));
        $decisions = new InMemoryAdDecisionRepository();
        $decisions->save($decision);
        $events = new InMemoryAdEventRepository();
        $events->recordImpression($decision, 'imp-serving-filter', 0.7, 1200, new DateTimeImmutable('2026-06-08T02:18:00Z'), 'req-serving-filter');
        $events->recordClick($decision, 'clk-serving-filter', new DateTimeImmutable('2026-06-08T02:19:00Z'), 'req-serving-filter');
        $service = $this->service(servingDecisions: $decisions, servingEvents: $events);

        self::assertSame(0, $service->search(['request_id' => 'req-serving-filter', 'actor_user_id' => 1], $this->context())['page']['total']);
        self::assertSame(0, $service->search(['request_id' => 'req-serving-filter', 'action' => 'billing.adjusted'], $this->context())['page']['total']);
        self::assertSame(1, $service->search(['request_id' => 'req-serving-filter', 'action' => 'ads.track.impression', 'entry_type' => 'tracking_event'], $this->context())['page']['total']);
        self::assertSame(1, $service->search(['request_id' => 'req-serving-filter', 'action' => 'ads.click', 'entry_type' => 'click_event'], $this->context())['page']['total']);
    }

    public function testSearchDeduplicatesServingEventsFromLiveAndHistoryRepositories(): void
    {
        $event = $this->adEvent('click', 'clk-dupe', '2026-06-08T02:25:00Z', 'req-dupe');
        $service = $this->service(
            servingEvents: $this->eventRepository([$event]),
            servingEventHistory: $this->historyRepository([$event]),
        );

        $search = $service->search([
            'request_id' => 'req-dupe',
            'entry_type' => 'click_event',
        ], $this->context());

        self::assertSame(1, $search['page']['total']);
        self::assertSame('click:clk-dupe', $search['entries'][0]['source_id']);
    }

    public function testSearchAppliesRiskDecisionSubjectAndActionFilters(): void
    {
        $event = $this->adEvent('click', 'clk-risk', '2026-06-08T02:25:00Z', 'req-risk', false, 'repeat_click_window');
        $service = $this->service(servingEvents: $this->eventRepository([$event]));

        self::assertSame(0, $service->search([
            'request_id' => 'req-risk',
            'subject_type' => 'campaign',
            'entry_type' => 'risk_decision',
        ], $this->context())['page']['total']);
        self::assertSame(0, $service->search([
            'request_id' => 'req-risk',
            'action' => 'ads.click',
            'entry_type' => 'risk_decision',
        ], $this->context())['page']['total']);
    }

    public function testSearchAppliesRiskDecisionUntrimmedSubjectGuard(): void
    {
        $event = $this->adEvent('click', ' clk-risk-spaced ', '2026-06-08T02:25:00Z', 'req-risk-spaced', false, 'repeat_click_window');
        $service = $this->service(servingEvents: $this->eventRepository([$event]));

        $search = $service->search([
            'request_id' => 'req-risk-spaced',
            'subject_id' => 'clk-risk-spaced',
            'entry_type' => 'risk_decision',
        ], $this->context());

        self::assertSame(0, $search['page']['total']);
    }

    public function testSystemLogFilterDefensesSkipMismatchedDerivedEntries(): void
    {
        $service = $this->service();
        $operationErrors = [[
            'error_id' => 'operr_system_branch',
            'request_id' => 'req-system-branch',
            'severity' => 'error',
            'message' => 'Captured.',
            'redacted_context' => [
                'path' => '/api/v1/ads/track',
                'method' => 'POST',
                'ip_address' => '203.0.113.5',
            ],
            'occurred_at' => '2026-06-08T02:00:00Z',
        ]];

        self::assertSame([], $this->invokePrivate($service, 'systemLogMatches', [
            $operationErrors,
            $this->filters(['subject_type' => 'system_log', 'subject_id' => 'other-log']),
        ]));
        self::assertSame([], $this->invokePrivate($service, 'systemLogMatches', [
            $operationErrors,
            $this->filters(['endpoint' => '/api/v1/ads/click']),
        ]));
        self::assertSame([], $this->invokePrivate($service, 'systemLogMatches', [
            $operationErrors,
            $this->filters(['ip_address' => '203.0.113.6']),
        ]));
    }

    public function testRiskDecisionFilterDefensesSkipMismatchedEndpointAndIp(): void
    {
        $service = $this->service();
        $event = $this->adEvent('click', 'clk-risk-private', '2026-06-08T02:25:00Z', 'req-risk-private', false, 'repeat_click_window');

        self::assertSame([], $this->invokePrivate($service, 'riskDecisionMatches', [
            [$event],
            $this->filters(['endpoint' => '/api/v1/ads/track']),
        ]));
        self::assertSame([], $this->invokePrivate($service, 'riskDecisionMatches', [
            [$event],
            $this->filters(['ip_address' => '203.0.113.6']),
        ]));
    }

    public function testWithinRangeAllowsMissingOccurrenceValues(): void
    {
        $service = $this->service();

        self::assertTrue($this->invokePrivate($service, 'withinRange', [
            '',
            $this->filters(['occurred_from' => '2026-06-08T00:00:00Z']),
        ]));
    }

    public function testSearchNormalizesEntryTypesAndPagination(): void
    {
        $service = $this->service([
            'errors' => [
                [
                    'request_id' => 'req-page',
                    'severity' => 'error',
                    'message' => 'First.',
                    'redacted_context' => ['path' => '/api/v1/a'],
                    'occurred_at' => '2026-06-08T02:00:00Z',
                ],
                [
                    'request_id' => 'req-page',
                    'severity' => 'warning',
                    'message' => 'Second.',
                    'redacted_context' => ['path' => '/api/v1/b'],
                    'occurred_at' => '2026-06-08T03:00:00Z',
                ],
            ],
        ]);

        $search = $service->search([
            'request_id' => 'req-page',
            'entry_type' => ['', 'operation_error', 'operation_error'],
            'limit' => 1,
            'offset' => 1,
        ], $this->context());

        self::assertSame(['operation_error'], $search['filters']['entry_type']);
        self::assertSame(2, $search['page']['total']);
        self::assertSame(1, $search['page']['offset']);
        self::assertFalse($search['page']['has_more']);
        self::assertCount(1, $search['entries']);
    }

    public function testSearchRejectsInvalidNormalizationValues(): void
    {
        $cases = [
            [['request_id' => []], 'request_id must be a string.'],
            [['actor_user_id' => 'abc'], 'actor_user_id must be a positive integer.'],
            [['actor_user_id' => '0'], 'actor_user_id must be a positive integer.'],
            [['ip_address' => 'not-an-ip'], 'ip_address must be a valid IP address.'],
            [['occurred_from' => 'not-a-date'], 'occurred_from must be a valid date-time.'],
            [['limit' => 'abc'], 'limit must be a positive integer.'],
            [['limit' => 201], 'limit must be between 1 and 200.'],
            [['offset' => 'abc'], 'offset must be a non-negative integer.'],
            [['offset' => -1], 'offset must be a non-negative integer.'],
            [['entry_type' => [new \stdClass()]], 'entry_type must be a string.'],
            [['entry_type' => 'missing'], 'entry_type is not supported.'],
        ];
        $service = $this->service();

        foreach ($cases as [$filters, $message]) {
            try {
                $service->search($filters, $this->context());
                self::fail('Expected InvalidArgumentException for ' . $message);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string,mixed> $fixtures
     */
    private function service(
        ?array $fixtures = null,
        ?IpGeoRepositoryInterface $ipGeoRepository = null,
        ?AdDecisionRepositoryInterface $servingDecisions = null,
        ?AdEventRepositoryInterface $servingEvents = null,
        ?DatabaseAdEventRepository $servingEventHistory = null,
    ): OperationRequestCorrelationService {
        $fixtures ??= [];
        $auditRepository = new OperationAuditRepository();
        $audit = new AuditLogService($auditRepository);
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        foreach ($fixtures['errors'] ?? [] as $error) {
            $errors->captureApiError(
                requestId: (string) ($error['request_id'] ?? ''),
                severity: (string) ($error['severity'] ?? 'error'),
                message: (string) ($error['message'] ?? 'Operation error captured.'),
                context: is_array($error['redacted_context'] ?? null) ? $error['redacted_context'] : [],
                occurredAt: new DateTimeImmutable((string) ($error['occurred_at'] ?? '2026-06-08T00:00:00Z')),
            );
        }
        foreach ($fixtures['auditLogs'] ?? [] as $auditLog) {
            if ($auditLog instanceof AuditLogEntry) {
                $auditRepository->append($auditLog);
                continue;
            }

            $audit->record(
                action: (string) ($auditLog['action'] ?? 'audit_log'),
                subjectType: (string) ($auditLog['subject_type'] ?? 'audit_log'),
                subjectId: isset($auditLog['subject_id']) ? (int) $auditLog['subject_id'] : null,
                actorUserId: isset($auditLog['actor_user_id']) ? (int) $auditLog['actor_user_id'] : null,
                organizationId: isset($auditLog['organization_id']) ? (int) $auditLog['organization_id'] : null,
                ipAddress: $auditLog['ip_address'] ?? null,
                userAgent: $auditLog['user_agent'] ?? null,
                requestId: $auditLog['request_id'] ?? null,
                metadata: is_array($auditLog['metadata'] ?? null) ? $auditLog['metadata'] : null,
            );
        }

        $webhooks = new class($fixtures['webhookDeliveries'] ?? []) implements WebhookDeliveryRepositoryInterface {
            /**
             * @param list<array<string,mixed>> $deliveries
             */
            public function __construct(private array $deliveries)
            {
            }

            public function all(): array
            {
                return array_map(static fn (array $row): WebhookDelivery => new WebhookDelivery(
                    delivery_id: (string) ($row['delivery_id'] ?? 'wh_1'),
                    organization_id: (int) ($row['organization_id'] ?? 1),
                    webhook_endpoint_id: (int) ($row['webhook_endpoint_id'] ?? 1),
                    endpoint_id: (string) ($row['endpoint_id'] ?? 'endpoint_1'),
                    endpoint_url: (string) ($row['endpoint_url'] ?? 'https://example.test/hooks'),
                    event_type: (string) ($row['event_type'] ?? 'review.completed'),
                    payload_json: (string) ($row['payload_json'] ?? '{}'),
                    request_id: $row['request_id'] ?? null,
                    status: (string) ($row['status'] ?? 'failed'),
                    retry_count: (int) ($row['retry_count'] ?? 0),
                    next_attempt_at: new DateTimeImmutable((string) ($row['next_attempt_at'] ?? '2026-06-08T02:31:00Z')),
                    last_attempt_at: isset($row['last_attempt_at']) && $row['last_attempt_at'] !== null ? new DateTimeImmutable((string) $row['last_attempt_at']) : null,
                    last_status_code: isset($row['last_status_code']) ? (int) $row['last_status_code'] : null,
                    last_error: $row['last_error'] ?? null,
                    signature_header: $row['signature_header'] ?? null,
                    created_at: new DateTimeImmutable((string) ($row['created_at'] ?? '2026-06-08T02:30:00Z')),
                    delivered_at: isset($row['delivered_at']) && $row['delivered_at'] !== null ? new DateTimeImmutable((string) $row['delivered_at']) : null,
                ), $this->deliveries);
            }

            public function queueForEndpoint(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookDelivery
            {
                throw new \BadMethodCallException('Not used by this test fixture.');
            }

            public function save(WebhookDelivery $delivery): WebhookDelivery
            {
                return $delivery;
            }

            public function find(string $deliveryId): ?WebhookDelivery
            {
                foreach ($this->all() as $delivery) {
                    if ($delivery->delivery_id === $deliveryId) {
                        return $delivery;
                    }
                }

                return null;
            }

            public function pendingRetry(int $limit, ?DateTimeImmutable $now = null, int $maxRetryCount = 3): array
            {
                return [];
            }

            public function markDueRetriesExhausted(int $limit, ?DateTimeImmutable $now = null, int $maxRetryCount = 3): array
            {
                return [];
            }

            public function listForOrganization(int $organizationId, ?string $endpointId = null, ?string $status = null, int $limit = 50): array
            {
                return [];
            }

            public function recordAttempt(
                string $deliveryId,
                int $attemptNumber,
                ?int $statusCode,
                ?string $error,
                ?string $signatureHeader,
                DateTimeImmutable $attemptedAt,
                int $durationMs,
            ): void {
            }

            public function attemptsForDelivery(string $deliveryId): array
            {
                return [];
            }
        };

        return new OperationRequestCorrelationService(
            new OperationErrorCaptureService($errorRepository, $audit),
            $audit,
            $webhooks,
            $ipGeoRepository,
            $servingDecisions,
            $servingEvents,
            $servingEventHistory,
        );
    }

    private function context(): RequestUserContext
    {
        return new RequestUserContext(null, null);
    }

    private function superAdminContext(): RequestUserContext
    {
        return new RequestUserContext(new AuthenticatedUser(1, 'admin@example.test', true), null);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function filters(array $overrides = []): array
    {
        return array_replace([
            'request_id' => null,
            'actor_user_id' => null,
            'actor' => null,
            'action' => null,
            'subject_type' => null,
            'subject_id' => null,
            'ip_address' => null,
            'endpoint' => null,
            'occurred_from' => null,
            'occurred_to' => null,
            'entry_type' => [],
            'limit' => 50,
            'offset' => 0,
        ], $overrides);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokePrivate(OperationRequestCorrelationService $service, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($service, ...$arguments);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function ipGeoRepository(array $rows): IpGeoRepositoryInterface
    {
        return new class($rows) implements IpGeoRepositoryInterface {
            /**
             * @param list<array<string,mixed>> $rows
             */
            public function __construct(private array $rows)
            {
            }

            public function findResolved(string $ipAddress): ?GeoIpRecord
            {
                return null;
            }

            public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
            {
            }

            public function searchLookups(array $filters): array
            {
                return array_map(
                    static fn (array $row): object => new class($row) {
                        public function __construct(private array $row)
                        {
                        }

                        public function toArray(): array
                        {
                            return $this->row;
                        }
                    },
                    $this->rows,
                );
            }

            public function leasePending(int $limit, DateTimeImmutable $now): array
            {
                return [];
            }

            public function markResolved(GeoIpRecord $record, ?string $leaseToken = null): void
            {
            }

            public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds, ?string $leaseToken = null): void
            {
            }
        };
    }

    /**
     * @param list<AdEvent> $events
     */
    private function eventRepository(array $events): InMemoryAdEventRepository
    {
        $repository = new InMemoryAdEventRepository();
        foreach ($events as $event) {
            $decision = $this->decisionFromEvent($event);
            if ($event->eventType === 'impression') {
                $repository->recordImpression($decision, $event->eventId, $event->visibleRatio ?? 0.0, $event->visibleMs ?? 0, $event->occurredAt, $event->requestId);
                continue;
            }
            if ($event->valid) {
                $repository->recordClick($decision, $event->eventId, $event->occurredAt, $event->requestId);
                continue;
            }

            $repository->recordInvalidClick($decision, $event->eventId, $event->occurredAt, (string) ($event->reason ?? 'invalid_traffic'), $event->requestId);
        }

        return $repository;
    }

    /**
     * @param list<AdEvent> $events
     */
    private function historyRepository(array $events): DatabaseAdEventRepository
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createServingHistorySchema($connection);
        $repository = new DatabaseAdEventRepository($connection);
        foreach ($events as $event) {
            $repository->persist($event);
        }

        return $repository;
    }

    private function createServingHistorySchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE ad_serving_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(32) NOT NULL,
                event_id VARCHAR(160) NOT NULL,
                decision_id VARCHAR(160) NOT NULL,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                viewer_id VARCHAR(160) NOT NULL,
                ad_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                advertiser_organization_id INTEGER NULL,
                publisher_organization_id INTEGER NULL,
                cost_points INTEGER NULL,
                occurred_at DATETIME NOT NULL,
                valid INTEGER NOT NULL,
                reason VARCHAR(120) NULL,
                visible_ratio NUMERIC NULL,
                visible_ms INTEGER NULL,
                request_id VARCHAR(160) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(512) NULL,
                geo_code VARCHAR(64) NULL,
                billing_status VARCHAR(32) NOT NULL DEFAULT "pending",
                billed_points INTEGER NOT NULL DEFAULT 0,
                publisher_earning_points INTEGER NOT NULL DEFAULT 0,
                billing_reason VARCHAR(120) NULL,
                billing_processed_at DATETIME NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_type, event_id, occurred_at)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_serving_event_dedup (
                event_type VARCHAR(32) NOT NULL,
                event_id VARCHAR(160) NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (event_type, event_id)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE raw_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_uuid VARCHAR(255) NOT NULL,
                organization_id INTEGER NULL,
                site_id INTEGER NULL,
                ad_slot_id INTEGER NULL,
                campaign_id INTEGER NULL,
                creative_id INTEGER NULL,
                event_type VARCHAR(64) NOT NULL,
                occurred_at DATETIME NOT NULL,
                received_at DATETIME NOT NULL,
                request_id VARCHAR(160) NULL,
                request_ip BLOB NULL,
                user_agent VARCHAR(512) NULL,
                payload_json TEXT NOT NULL,
                processed_at DATETIME NULL,
                UNIQUE (event_uuid, occurred_at)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE raw_event_dedup (
                event_uuid VARCHAR(255) NOT NULL PRIMARY KEY,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
    }

    private function decision(string $decisionId, string $requestId, string $ipAddress, DateTimeImmutable $decidedAt): AdDecision
    {
        return new AdDecision(
            decisionId: $decisionId,
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: '<iframe></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://example.test',
            decidedAt: $decidedAt,
            requestId: $requestId,
            ipAddress: $ipAddress,
            userAgent: 'browser',
            geoCode: 'CN-SH',
        );
    }

    private function decisionFromEvent(AdEvent $event): AdDecision
    {
        return new AdDecision(
            decisionId: $event->decisionId,
            siteId: $event->siteId,
            slotId: $event->slotId,
            viewerId: $event->viewerId,
            filled: true,
            reason: null,
            iframeHtml: '<iframe></iframe>',
            width: 300,
            height: 250,
            adId: $event->adId,
            campaignId: $event->campaignId,
            advertiserOrganizationId: $event->advertiserOrganizationId,
            publisherOrganizationId: $event->publisherOrganizationId,
            impressionCostPoints: $event->eventType === 'impression' ? $event->costPoints : 10,
            clickCostPoints: $event->eventType === 'click' ? $event->costPoints : 20,
            landingUrl: 'https://example.test',
            decidedAt: $event->occurredAt,
            requestId: $event->requestId,
            ipAddress: $event->ipAddress,
            userAgent: $event->userAgent,
            geoCode: $event->geoCode,
        );
    }

    private function adEvent(string $type, string $id, string $occurredAt, string $requestId, bool $valid = true, ?string $reason = null): AdEvent
    {
        return new AdEvent(
            eventType: $type,
            eventId: $id,
            decisionId: 'decision-' . $id,
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            costPoints: $type === 'impression' ? 10 : 20,
            occurredAt: new DateTimeImmutable($occurredAt),
            valid: $valid,
            reason: $reason,
            visibleRatio: $type === 'impression' ? 0.75 : null,
            visibleMs: $type === 'impression' ? 1500 : null,
            requestId: $requestId,
            ipAddress: '203.0.113.44',
            userAgent: 'browser',
            geoCode: 'CN-SH',
        );
    }

    private static function webhookDelivery(string $id, string $requestId): array
    {
        return [
            'delivery_id' => $id,
            'endpoint_url' => 'https://example.test/hooks',
            'event_type' => 'review.completed',
            'payload_json' => json_encode(['request_id' => $requestId], JSON_THROW_ON_ERROR),
            'request_id' => $requestId,
            'status' => 'failed',
            'retry_count' => 1,
            'last_error' => null,
            'signature_header' => null,
            'created_at' => '2026-06-08T02:30:00Z',
            'delivered_at' => null,
        ];
    }
}

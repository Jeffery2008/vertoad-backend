<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Operations\OperationRiskDecisionLog;

final readonly class DatabaseOperationRiskDecisionLogRepository implements OperationRiskDecisionLogRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(OperationRiskDecisionLog $entry): OperationRiskDecisionLog
    {
        $this->connection->insert('operation_risk_decision_logs', [
            'decision_id' => $entry->decision_id,
            'request_id' => $entry->request_id,
            'action' => $entry->action,
            'risk_score' => $entry->risk_score,
            'reason_codes_json' => json_encode($entry->reason_codes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'ip_address' => $entry->ip_address,
            'endpoint' => $entry->endpoint,
            'http_method' => $entry->http_method,
            'user_agent' => $entry->user_agent,
            'site_id' => $entry->site_id,
            'slot_id' => $entry->slot_id,
            'campaign_id' => $entry->campaign_id,
            'viewer_id' => $entry->viewer_id,
            'ad_decision_id' => $entry->ad_decision_id,
            'occurred_at' => $this->formatDate($entry->occurred_at),
        ]);

        return $entry;
    }

    public function find(string $decisionId): ?OperationRiskDecisionLog
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_risk_decision_logs')
            ->where('decision_id = :decision_id')
            ->setParameter('decision_id', trim($decisionId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function search(array $filters = []): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('operation_risk_decision_logs')
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('decision_id', 'DESC')
            ->setMaxResults($this->limit($filters['limit'] ?? null));
        foreach (['request_id', 'action', 'subject_type', 'subject_id', 'ip_address'] as $field) {
            $value = $this->nullableString($filters[$field] ?? null);
            if ($value !== null) {
                $query->andWhere($field . ' = :' . $field)->setParameter($field, $value);
            }
        }
        foreach (['occurred_from' => '>=', 'occurred_to' => '<='] as $field => $operator) {
            $value = $this->nullableString($filters[$field] ?? null);
            if ($value !== null) {
                $query->andWhere('occurred_at ' . $operator . ' :' . $field)->setParameter($field, $this->formatDate(new DateTimeImmutable($value)));
            }
        }
        $endpoint = $this->endpointFilter($filters['endpoint'] ?? null);
        if ($endpoint['endpoint'] !== null) {
            $query->andWhere('endpoint = :endpoint')->setParameter('endpoint', $endpoint['endpoint']);
        }
        if ($endpoint['method'] !== null) {
            $query->andWhere('http_method = :http_method')->setParameter('http_method', $endpoint['method']);
        }

        return array_map(fn (array $row): OperationRiskDecisionLog => $this->hydrate($row), $query->fetchAllAssociative());
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'decision_id',
            'request_id',
            'action',
            'risk_score',
            'reason_codes_json',
            'subject_type',
            'subject_id',
            'ip_address',
            'endpoint',
            'http_method',
            'user_agent',
            'site_id',
            'slot_id',
            'campaign_id',
            'viewer_id',
            'ad_decision_id',
            'occurred_at',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OperationRiskDecisionLog
    {
        return new OperationRiskDecisionLog(
            decision_id: (string) $row['decision_id'],
            request_id: (string) $row['request_id'],
            action: (string) $row['action'],
            risk_score: $row['risk_score'] === null ? null : (int) $row['risk_score'],
            reason_codes: $this->decodeReasonCodes((string) $row['reason_codes_json']),
            subject_type: $row['subject_type'] === null ? null : (string) $row['subject_type'],
            subject_id: $row['subject_id'] === null ? null : (string) $row['subject_id'],
            ip_address: $row['ip_address'] === null ? null : (string) $row['ip_address'],
            endpoint: $row['endpoint'] === null ? null : (string) $row['endpoint'],
            http_method: $row['http_method'] === null ? null : (string) $row['http_method'],
            user_agent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            site_id: $row['site_id'] === null ? null : (int) $row['site_id'],
            slot_id: $row['slot_id'] === null ? null : (int) $row['slot_id'],
            campaign_id: $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
            viewer_id: $row['viewer_id'] === null ? null : (string) $row['viewer_id'],
            ad_decision_id: $row['ad_decision_id'] === null ? null : (string) $row['ad_decision_id'],
            occurred_at: new DateTimeImmutable((string) $row['occurred_at'], new DateTimeZone('UTC')),
        );
    }

    /**
     * @return list<string>
     */
    private function decodeReasonCodes(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): ?string => is_scalar($value) ? trim((string) $value) : null, $decoded),
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }

    /**
     * @return array{endpoint:string|null,method:string|null}
     */
    private function endpointFilter(mixed $value): array
    {
        $value = $this->nullableString($value);
        if ($value !== null && preg_match('/^(GET|POST|PUT|PATCH|DELETE):(.+)$/', $value, $matches) === 1) {
            return ['method' => $matches[1], 'endpoint' => $matches[2]];
        }

        return ['method' => null, 'endpoint' => $value];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function limit(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(1, min(200, (int) $value)) : 50;
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
